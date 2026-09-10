<?php

declare(strict_types=1);

/**
 * Endpoint do widget público — o agente servido para um site qualquer.
 *
 * Duas ações no mesmo arquivo, de propósito: as guardas (token, origem,
 * limite, CORS) valem para as duas, e separar em dois arquivos criaria a
 * chance de alguém proteger só um deles.
 *
 *   ?acao=config   JSON com aparência e saudação, para o widget se montar
 *   (padrão)       turno de conversa em streaming (SSE)
 *
 * Este arquivo NÃO tem sessão de admin. `api/chat.php` continua sendo o do
 * painel; o que os dois compartilham mora em `app/Http/`.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Atendimento\Fila;
use SimpleAIman\Atendimento\Roteador;
use SimpleAIman\Canais\CanalPublico;
use SimpleAIman\Http\Sse;
use SimpleAIman\Llm\ChatService;
use SimpleAIman\Llm\ErroAgente;

$origem = $_SERVER['HTTP_ORIGIN'] ?? null;
$acao = (string) ($_GET['acao'] ?? '');

$canal = CanalPublico::porToken((string) ($_GET['t'] ?? ''));

// Token inválido e origem não autorizada devolvem a MESMA resposta. Distinguir
// as duas contaria a quem está sondando se o token existe.
if ($canal === null || !$canal->origemPermitida($origem)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['erro' => 'Canal indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Devolve a origem específica, nunca "*": com curinga qualquer site poderia
// embutir o widget e gastar a cota do dono do canal.
if ($origem !== null && $origem !== '') {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    http_response_code(204);
    exit;
}

if ($acao === 'config') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=300');

    // O idioma sai daqui para o ditado do navegador reconhecer certo: a
    // SpeechRecognition com `lang` errado transcreve mal, e o widget não tem
    // como adivinhar sozinho qual agente responde por este canal.
    $idioma = Database::connection()->prepare(
        'SELECT a.idioma FROM canais c JOIN agentes a ON a.id = c.agente_id WHERE c.id = :id'
    );
    $idioma->execute(['id' => (int) $canal->canal['id']]);

    echo json_encode([
        'titulo' => $canal->titulo(),
        'saudacao' => $canal->saudacao(),
        'cor' => $canal->cor(),
        'idioma' => $idioma->fetchColumn() ?: 'pt-BR',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// Polling do atendimento humano
//
// Deliberadamente NÃO usa SSE. Um atendimento dura minutos, e SSE manteria um
// processo PHP preso esse tempo todo; em hospedagem compartilhada, com 10 a 30
// processos no total, meia dúzia de visitantes esperando derrubaria o site.
// Uma consulta indexada a cada poucos segundos custa ordens de grandeza menos.
//
// Também não consome a cota do canal: o limite existe para proteger a conta do
// provedor de LLM, e aqui não há chamada nenhuma a provedor.
// ---------------------------------------------------------------------
if ($acao === 'mensagens') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sessao'] ?? '')) ?: '';
    $desde = max(0, (int) ($_GET['desde'] ?? 0));

    if ($sessao === '') {
        echo json_encode(['modo' => 'bot', 'mensagens' => []]);
        exit;
    }

    // Fecha o laço de quem ficou esperando e ninguém assumiu. Roda aqui porque
    // é justamente o momento em que existe alguém do outro lado olhando — não
    // dá para depender só do cron, que em compartilhada pode nem existir.
    Fila::expirarAbandonadas();

    $stmt = Database::connection()->prepare(
        'SELECT id, modo FROM conversas WHERE canal_id = :canal AND externo_id = :externo LIMIT 1'
    );
    $stmt->execute(['canal' => (int) $canal->canal['id'], 'externo' => 'web-' . $sessao]);
    $conversa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversa) {
        echo json_encode(['modo' => 'bot', 'mensagens' => []]);
        exit;
    }

    $novas = Fila::mensagensDesde((int) $conversa['id'], $desde, apenasParaVisitante: true);

    // Numa consulta so, para a tela nao fazer uma por mensagem.
    $anexosNovos = \SimpleAIman\Canais\Anexos::porMensagens(array_map(
        static fn (array $m): int => (int) $m['id'],
        $novas
    ));

    $mensagens = array_map(
        static fn (array $m): array => [
            'id' => (int) $m['id'],
            'quem' => $m['autor_tipo'],
            // Só o nome de exibição atravessa; o usuário de LOGIN não — ele é
            // credencial, e vazá-lo para o site do cliente não traz ganho algum.
            // Sem nome cadastrado, "Atendente" resolve sem expor nada.
            'autor' => $m['autor_tipo'] === 'atendente'
                ? (trim((string) ($m['autor_nome'] ?? '')) ?: 'Atendente')
                : null,
            'texto' => $m['conteudo'],
            // HTML já formatado, vindo do servidor. Uma implementação só, em
            // PHP, em vez de uma cópia em JS aqui e outra no painel — três
            // versões da mesma regra divergiriam na primeira correção.
            //
            // Inserir isto com innerHTML é seguro porque formatar_whatsapp()
            // ESCAPA antes de formatar: as únicas tags no resultado são as que
            // ela mesma criou. Se um dia alguém trocar a ordem lá, isto vira
            // XSS no site do cliente.
            'html' => formatar_whatsapp((string) $m['conteudo'])
                . anexos_html_publico($anexosNovos[(int) $m['id']] ?? [], (string) ($_GET['t'] ?? ''), $sessao),
            'hora' => date('H:i', strtotime((string) $m['criado_em'])),
        ],
        $novas
    );

    echo json_encode([
        'modo' => $conversa['modo'],
        'mensagens' => $mensagens,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// Turno de conversa
// ---------------------------------------------------------------------

Sse::abrir();

$pergunta = texto_utf8(trim((string) ($_GET['q'] ?? '')));

// A sessão vem do navegador do visitante, então é entrada hostil: só o que
// sobrevive a esta faxina vira identificador de conversa.
$sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sessao'] ?? '')) ?: '';

if ($pergunta === '') {
    Sse::falhar('Envie uma pergunta.');
}

// Uma pergunta absurdamente longa é texto colado, não conversa — e cada token
// dela é dinheiro. Cortar é melhor que recusar: quase sempre o que importa
// está no começo.
if (mb_strlen($pergunta) > 2000) {
    $pergunta = mb_substr($pergunta, 0, 2000);
}

if ($sessao === '') {
    Sse::falhar('Não consegui iniciar a conversa. Recarregue a página, por favor.');
}

$ip = client_ip();

// O modo do agente decide QUAL limite vale. Um roteador não chama provedor
// nenhum, então a cota diária — que é proteção de gasto — não faz sentido; só
// a proteção contra enxurrada continua.
$limite = $canal->estadoDoLimite($ip, $canal->modoDoAgente() === 'roteador');

// Enxurrada: corta seco. Fazer qualquer trabalho aqui derrotaria a proteção,
// que existe justamente para o caso de alguém (ou algo) batendo sem parar.
if ($limite === 'minuto') {
    Sse::falhar(CanalPublico::mensagemDeLimite('minuto'));
}

// Cota do dia: é proteção de GASTO, não de carga. Cortar seco criava um beco
// sem saída — a mensagem dizia "deixe seu contato que alguém retorna" e não
// havia caminho nenhum para deixar. A pessoa repetia, e recebia a mesma frase.
//
// Daqui em diante nada toca o provedor: o roteador é PHP e banco. Ela ainda
// chega aos contatos dos setores, e o contato que deixar é registrado de
// verdade, pela mesma captação de sempre.
if ($limite === 'dia') {
    $stmt = Database::connection()->prepare(
        'SELECT id FROM conversas WHERE canal_id = :canal AND externo_id = :externo ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['canal' => (int) $canal->canal['id'], 'externo' => 'web-' . $sessao]);
    $conversaId = (int) ($stmt->fetchColumn() ?: 0);

    if ($conversaId > 0) {
        Sse::evento('inicio', ['conversa' => $conversaId]);

        $texto = Roteador::responder(
            $conversaId,
            $pergunta,
            'Conversamos bastante hoje e preciso dar uma pausa por aqui. Mas posso te encaminhar:',
            captarContato: true,
        );

        Sse::evento('pedaco', ['texto' => $texto]);
        Sse::evento('fim', ['latencia' => 0, 'html' => formatar_whatsapp($texto)]);
        exit;
    }

    Sse::falhar(CanalPublico::mensagemDeLimite('dia'));
}

$inicio = microtime(true);

try {
    $svc = ChatService::paraAgente($canal->agenteId());

    // O prefixo evita que uma sessão do widget colida com uma conversa do
    // playground que por acaso tenha o mesmo identificador.
    $conversa = $svc->conversa((int) $canal->canal['id'], 'web-' . $sessao, $ip);

    Sse::evento('inicio', ['conversa' => $conversa]);

    // Escreveu de novo depois de o atendimento ter sido encerrado: o assunto
    // volta para o assistente. Sem isto a mensagem seria gravada e ninguém
    // responderia — nem o bot, que está calado, nem o atendente, que já saiu.
    Fila::reabrirSeEncerrada($conversa);

    // Conversa em modo humano: grava e cala a boca. Sem isto o bot responde
    // por cima do atendente.
    if (!$svc->botDeveResponder($conversa)) {
        $svc->gravarMensagem($conversa, 'usuario', $pergunta);

        // Quem espera na fila e acabou de ser convidado a se identificar: o que
        // ele escrever agora pode ser o nome e o contato.
        //
        // A captacao so acontece porque o CONVITE foi feito — no modo roteador,
        // junto da confirmacao da transferencia; no modo IA, pela ferramenta.
        // Extrair de qualquer mensagem seria coletar dado pessoal sem pedir, o
        // que este projeto ja decidiu nao fazer.
        \SimpleAIman\Atendimento\Fila::anotarContatoDeEspera($conversa, $pergunta);

        Sse::evento('fim', ['latencia' => 0, 'modo' => 'humano']);
        exit;
    }

    \Turno::iniciar('widget');

    $resposta = '';

    foreach ($svc->stream($conversa, $pergunta) as $pedaco) {
        $resposta .= $pedaco;
        Sse::evento('pedaco', ['texto' => $pedaco]);
    }

    // Só o rótulo do documento atravessa. O id do chunk fica no banco, para
    // auditoria — o visitante não tem o que fazer com ele, e expor id interno
    // é dar pista da estrutura sem nenhum ganho.
    if ($svc->ultimasFontes !== []) {
        Sse::evento('fontes', array_map(
            static fn (array $f): array => ['numero' => $f['numero'], 'rotulo' => $f['rotulo']],
            $svc->ultimasFontes
        ));
    }

    // O texto vai cru durante o streaming (pedaço a pedaço não dá para
    // formatar: o marcador de abertura chega num pedaço e o de fechamento em
    // outro) e a versão formatada vai inteira no fim, para o widget trocar.
    // Assim continua existindo UMA implementação de formatação, em PHP.
    Sse::evento('fim', [
        'latencia' => (int) ((microtime(true) - $inicio) * 1000),
        'html' => formatar_whatsapp($resposta),
    ]);
} catch (ErroAgente $e) {
    \Log::erro('publico_falhou', ['codigo' => $e->codigo, 'detalhe' => $e->paraLog()]);
    Sse::evento('erro', ['mensagem' => $e->mensagemPublica()]);
} catch (Throwable $e) {
    \Log::erro('publico_inesperado', ['erro' => $e->getMessage()]);
    Sse::evento('erro', ['mensagem' => 'Não consegui responder agora. Quer que eu registre sua dúvida para alguém retornar?']);
}

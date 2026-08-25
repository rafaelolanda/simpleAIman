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

    echo json_encode([
        'titulo' => $canal->titulo(),
        'saudacao' => $canal->saudacao(),
        'cor' => $canal->cor(),
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
            'html' => formatar_whatsapp((string) $m['conteudo']),
            'hora' => date('H:i', strtotime((string) $m['criado_em'])),
        ],
        Fila::mensagensDesde((int) $conversa['id'], $desde, apenasParaVisitante: true)
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
$limite = $canal->estadoDoLimite($ip);

if ($limite !== 'ok') {
    // Sai ANTES de tocar no provedor: o limite não serve para nada se a
    // chamada cara já tiver acontecido.
    Sse::falhar(CanalPublico::mensagemDeLimite($limite));
}

$inicio = microtime(true);

try {
    $svc = ChatService::paraAgente($canal->agenteId());

    // O prefixo evita que uma sessão do widget colida com uma conversa do
    // playground que por acaso tenha o mesmo identificador.
    $conversa = $svc->conversa((int) $canal->canal['id'], 'web-' . $sessao, $ip);

    Sse::evento('inicio', ['conversa' => $conversa]);

    // Conversa em modo humano: grava e cala a boca. Sem isto o bot responde
    // por cima do atendente.
    if (!$svc->botDeveResponder($conversa)) {
        $svc->gravarMensagem($conversa, 'usuario', $pergunta);
        Sse::evento('fim', ['latencia' => 0, 'modo' => 'humano']);
        exit;
    }

    foreach ($svc->stream($conversa, $pergunta) as $pedaco) {
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

    Sse::evento('fim', ['latencia' => (int) ((microtime(true) - $inicio) * 1000)]);
} catch (ErroAgente $e) {
    error_log('[simpleAIman] publico: ' . $e->paraLog());
    Sse::evento('erro', ['mensagem' => $e->mensagemPublica()]);
} catch (Throwable $e) {
    error_log('[simpleAIman] publico inesperado: ' . $e->getMessage());
    Sse::evento('erro', ['mensagem' => 'Não consegui responder agora. Quer que eu registre sua dúvida para alguém retornar?']);
}

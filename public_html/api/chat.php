<?php

declare(strict_types=1);

/**
 * Turno de conversa em streaming (SSE).
 *
 * Atende o playground do admin, com sessão. O canal público por token vive
 * em `api/publico.php` e reaproveita o mesmo ChatService -- separados porque
 * este aqui exige login e aquele exige token, origem e limite de uso.
 *
 * Eventos emitidos:
 *   inicio  {conversa}
 *   pedaco  {texto}
 *   fontes  [{numero, rotulo}]   <- documentos que embasaram a resposta
 *   fim     {latencia}
 *   erro    {mensagem}   <- SEMPRE a mensagem pública, nunca o detalhe técnico
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Http\Sse;
use SimpleAIman\Llm\ChatService;
use SimpleAIman\Llm\ErroAgente;

Auth::start();

if (!Auth::check()) {
    http_response_code(403);
    exit('Acesso negado.');
}

$usuarioId = Auth::userId();

// O playground gasta token de verdade. Estar logado não basta: quem não pode
// abrir a tela também não pode chamar o endpoint dela por baixo — senão a
// trava do painel viraria enfeite para quem souber montar a URL.
$stmt = Database::connection()->prepare('SELECT papel FROM admin_users WHERE id = :id');
$stmt->execute(['id' => $usuarioId]);

if (!Painel::podeVer(Painel::papelValido($stmt->fetchColumn()), 'playground.php')) {
    http_response_code(403);
    exit('Acesso negado.');
}

/**
 * Libera o arquivo de sessão ANTES de começar o streaming.
 *
 * A sessão do PHP é travada em exclusividade enquanto o script roda. Como
 * este endpoint fica aberto os segundos inteiros da resposta, ele segurava a
 * trava — e qualquer outra aba do mesmo navegador ficava esperando. O sintoma
 * é o painel inteiro congelar durante uma conversa, o que parece lock de
 * banco e não é: o SQLite escreve em milissegundos aqui.
 *
 * Depois desta linha a sessão vira somente leitura, então tudo que vem dela
 * precisa ter sido lido antes.
 */
session_write_close();

// O encanamento do SSE (cabecalhos anti-buffering, padding, ignore_user_abort)
// mora em app/Http/Sse.php desde que o endpoint publico apareceu: sao as
// mesmas quarenta linhas nos dois, e duplicar significaria consertar um bug
// de streaming duas vezes -- a segunda seria esquecida.
Sse::abrir();

function sse(string $evento, array $dados): void
{
    Sse::evento($evento, $dados);
}

$pergunta = trim((string) ($_GET['q'] ?? ''));
$agenteId = (int) ($_GET['agente'] ?? 0);
$sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sessao'] ?? '')) ?: 'admin-' . $usuarioId;

if ($pergunta === '') {
    sse('erro', ['mensagem' => 'Envie uma pergunta.']);
    exit;
}

$inicio = microtime(true);

try {
    $svc = $agenteId > 0
        ? ChatService::paraAgente($agenteId)
        : ChatService::paraAgente((int) Database::connection()->query('SELECT agente_padrao_id FROM config WHERE id = 1')->fetchColumn());

    $conversa = $svc->conversa(null, $sessao, client_ip());

    sse('inicio', ['conversa' => $conversa]);

    // Mesma armadilha do widget: conversa encerrada engoliria a mensagem em
    // silêncio. Escreveu de novo, o assunto volta para o assistente.
    \SimpleAIman\Atendimento\Fila::reabrirSeEncerrada($conversa);

    // Conversa em modo humano: grava e cala a boca. Sem isto o bot responde
    // por cima do atendente.
    if (!$svc->botDeveResponder($conversa)) {
        $svc->gravarMensagem($conversa, 'usuario', $pergunta);
        sse('fim', ['latencia' => 0, 'modo' => 'humano']);
        exit;
    }

    $resposta = '';

    foreach ($svc->stream($conversa, $pergunta) as $pedaco) {
        $resposta .= $pedaco;
        sse('pedaco', ['texto' => $pedaco]);
    }

    // Só o rótulo do documento atravessa. O id do chunk fica no banco, para
    // auditoria — o visitante não tem o que fazer com ele, e expor id interno
    // é dar pista da estrutura sem nenhum ganho.
    if ($svc->ultimasFontes !== []) {
        sse('fontes', array_map(
            static fn (array $f): array => ['numero' => $f['numero'], 'rotulo' => $f['rotulo']],
            $svc->ultimasFontes
        ));
    }

    sse('fim', [
        'latencia' => (int) ((microtime(true) - $inicio) * 1000),
        'html' => formatar_whatsapp($resposta),
    ]);
} catch (ErroAgente $e) {
    // Só a mensagem pública atravessa. O detalhe técnico fica no log —
    // nome de modelo, provedor e status HTTP não podem chegar ao visitante.
    error_log('[simpleAIman] ' . $e->paraLog());
    sse('erro', ['mensagem' => $e->mensagemPublica()]);
} catch (Throwable $e) {
    error_log('[simpleAIman] inesperado: ' . $e->getMessage());
    sse('erro', ['mensagem' => 'Não consegui responder agora. Quer que eu registre sua dúvida para alguém retornar?']);
}

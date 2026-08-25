<?php

declare(strict_types=1);

/**
 * Turno de conversa em streaming (SSE).
 *
 * Nesta etapa só atende o playground do admin, com sessão. O canal público
 * por token entra na etapa 9, reaproveitando o mesmo ChatService.
 *
 * Eventos emitidos:
 *   inicio  {conversa}
 *   pedaco  {texto}
 *   fontes  [{numero, rotulo}]   <- documentos que embasaram a resposta
 *   fim     {latencia}
 *   erro    {mensagem}   <- SEMPRE a mensagem pública, nunca o detalhe técnico
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Llm\ChatService;
use SimpleAIman\Llm\ErroAgente;

Auth::start();

if (!Auth::check()) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ---------------------------------------------------------------------
// Cabeçalhos anti-buffering.
//
// Apache com mod_deflate/proxy segura o stream e o chat parece travado. Sem
// isto o SSE "funciona" no servidor embutido e falha no servidor real — o
// pior tipo de bug, porque só aparece em produção.
// ---------------------------------------------------------------------
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');       // nginx
header('Content-Encoding: none');      // impede o mod_deflate de bufferizar

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

while (ob_get_level() > 0) {
    ob_end_flush();
}

ob_implicit_flush(true);

// Alguns proxies só liberam o primeiro byte depois de encher um buffer
// mínimo. Um comentário SSE de padding destrava sem sujar o stream.
echo ':' . str_repeat(' ', 4096) . "\n\n";

// O visitante fechar a aba não pode deixar a resposta pela metade sem gravar.
ignore_user_abort(true);
set_time_limit(120);

function sse(string $evento, array $dados): void
{
    echo 'event: ' . $evento . "\n";
    echo 'data: ' . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

$pergunta = trim((string) ($_GET['q'] ?? ''));
$agenteId = (int) ($_GET['agente'] ?? 0);
$sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sessao'] ?? '')) ?: 'admin-' . Auth::userId();

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

    // Conversa em modo humano: grava e cala a boca. Sem isto o bot responde
    // por cima do atendente.
    if (!$svc->botDeveResponder($conversa)) {
        $svc->gravarMensagem($conversa, 'usuario', $pergunta);
        sse('fim', ['latencia' => 0, 'modo' => 'humano']);
        exit;
    }

    foreach ($svc->stream($conversa, $pergunta) as $pedaco) {
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

    sse('fim', ['latencia' => (int) ((microtime(true) - $inicio) * 1000)]);
} catch (ErroAgente $e) {
    // Só a mensagem pública atravessa. O detalhe técnico fica no log —
    // nome de modelo, provedor e status HTTP não podem chegar ao visitante.
    error_log('[simpleAIman] ' . $e->paraLog());
    sse('erro', ['mensagem' => $e->mensagemPublica()]);
} catch (Throwable $e) {
    error_log('[simpleAIman] inesperado: ' . $e->getMessage());
    sse('erro', ['mensagem' => 'Não consegui responder agora. Quer que eu registre sua dúvida para alguém retornar?']);
}

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

<?php

declare(strict_types=1);

/**
 * Webhook do WhatsApp (Cloud API da Meta).
 *
 *   GET   verificação do webhook (a Meta chama uma vez, ao configurar)
 *   POST  eventos de mensagem
 *
 * Duas regras mandam neste arquivo:
 *
 * **Responder rápido.** A Meta espera 200 em poucos segundos e reenvia se
 * demorar — e reenvio vira resposta duplicada para a pessoa. Então aqui só se
 * valida e enfileira; quem conversa com a LLM é o worker, depois.
 *
 * **Confiar só na assinatura.** O endpoint é público. Sem conferir o
 * `X-Hub-Signature-256`, qualquer um injeta mensagem falsa e faz o agente
 * responder a quem quiser, gastando a conta do cliente.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Canais\CanalWhatsapp;
use SimpleAIman\Jobs\Queue;

// ---------------------------------------------------------------------
// Verificação (GET) — só acontece ao configurar o webhook no painel da Meta
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $canal = CanalWhatsapp::primeiro();
    $enviado = (string) ($_GET['hub_verify_token'] ?? '');
    $esperado = $canal?->segredo('VERIFY_TOKEN') ?? '';

    // Token vazio nunca confere: senão uma instalação sem `.env` configurado
    // aceitaria qualquer verificação.
    if ($esperado !== '' && hash_equals($esperado, $enviado) && ($_GET['hub_mode'] ?? '') === 'subscribe') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo (string) ($_GET['hub_challenge'] ?? '');
        exit;
    }

    http_response_code(403);
    exit('Verificação recusada.');
}

// ---------------------------------------------------------------------
// Eventos (POST)
// ---------------------------------------------------------------------
$corpo = (string) file_get_contents('php://input');
$dados = json_decode($corpo, true);

if (!is_array($dados)) {
    http_response_code(400);
    exit;
}

// O número de destino identifica o canal — não o remetente. Assim uma
// instalação com dois números atende os dois, cada um com seu agente.
$valor = $dados['entry'][0]['changes'][0]['value'] ?? [];
$phoneNumberId = (string) ($valor['metadata']['phone_number_id'] ?? '');
$canal = CanalWhatsapp::porNumero($phoneNumberId);

if ($canal === null || !$canal->assinaturaConfere($corpo, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null)) {
    // Canal desconhecido e assinatura inválida devolvem a MESMA resposta:
    // distinguir contaria a quem sonda quais números existem aqui.
    http_response_code(403);
    exit;
}

// Status de entrega (enviado, lido, falhou) chegam neste mesmo webhook e não
// são conversa. Ignorar em silêncio com 200 evita que a Meta os reenvie.
foreach ($valor['messages'] ?? [] as $mensagem) {
    $tipo = (string) ($mensagem['type'] ?? '');
    $de = (string) ($mensagem['from'] ?? '');
    $wamid = (string) ($mensagem['id'] ?? '');

    if ($de === '' || $wamid === '') {
        continue;
    }

    // Só texto por enquanto. Áudio, imagem e documento pedem a máquina de
    // mídia, que ainda não existe — e ficar em silêncio faria a pessoa achar
    // que a mensagem sumiu.
    $texto = $tipo === 'text'
        ? trim((string) ($mensagem['text']['body'] ?? ''))
        : '';

    Queue::enfileirar('entrada_whatsapp', [
        'canal_id' => (int) $canal->canal['id'],
        'wamid' => $wamid,
        'de' => $de,
        'tipo' => $tipo,
        'texto' => $texto,
        'nome' => (string) ($valor['contacts'][0]['profile']['name'] ?? ''),
    ]);
}

// 200 sempre, mesmo sem nada para fazer: qualquer outra coisa faz a Meta
// reenviar o evento indefinidamente.
http_response_code(200);
echo 'ok';

<?php

declare(strict_types=1);

/**
 * Serve ao VISITANTE do widget um arquivo que o atendente mandou.
 *
 * Gêmeo do `admin/anexo.php`, com a autorização do outro lado: lá é sessão de
 * painel, aqui é o par token-do-canal + sessão do visitante — o mesmo par que
 * já guarda o histórico da conversa no `api/publico.php`. Não é login, e não
 * pretende ser: é o modelo do widget inteiro, e um arquivo não pode ser mais
 * frouxo nem mais rígido que a conversa a que pertence.
 *
 * O que este arquivo NÃO faz, de propósito:
 *
 * - Não confere Origin. Uma tag `<img>` não manda esse cabeçalho, então exigir
 *   quebraria toda imagem em vez de proteger alguma coisa.
 * - Não serve anexo de OUTRA conversa. É a única regra que importa aqui, e
 *   está no SQL abaixo: o anexo precisa pertencer a uma conversa daquele canal
 *   E daquela sessão.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Canais\Anexos;
use SimpleAIman\Canais\CanalPublico;

$canal = CanalPublico::porToken((string) ($_GET['t'] ?? ''));
$sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sessao'] ?? '')) ?: '';
$anexoId = (int) ($_GET['id'] ?? 0);

if ($canal === null || $sessao === '' || $anexoId <= 0) {
    http_response_code(404);
    exit;
}

// Uma consulta só, e ela É a autorização: o anexo tem de estar numa mensagem
// de uma conversa deste canal e desta sessão. Buscar o anexo primeiro e
// conferir depois deixaria a porta aberta para alguém esquecer a conferência.
$stmt = Database::connection()->prepare(
    'SELECT a.* FROM mensagem_anexos a
     JOIN mensagens m ON m.id = a.mensagem_id
     JOIN conversas c ON c.id = m.conversa_id
     WHERE a.id = :anexo AND c.canal_id = :canal AND c.externo_id = :externo'
);
$stmt->execute([
    'anexo' => $anexoId,
    'canal' => (int) $canal->canal['id'],
    'externo' => 'web-' . $sessao,
]);

$anexo = $stmt->fetch(PDO::FETCH_ASSOC);

// Anexo de outra conversa e anexo inexistente devolvem a MESMA resposta:
// distinguir contaria a quem sonda quais ids existem.
if (!$anexo) {
    http_response_code(404);
    exit;
}

if ($anexo['removido_em'] !== null) {
    http_response_code(410);
    exit;
}

$caminho = Anexos::caminhoAbsoluto($anexo);
$real = realpath($caminho);
$base = realpath(caminho_storage());

if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
    http_response_code(404);
    exit;
}

$mime = (string) $anexo['mime'];

$emLinha = str_starts_with($mime, 'image/')
    || str_starts_with($mime, 'audio/')
    || str_starts_with($mime, 'video/');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($emLinha ? 'inline' : 'attachment'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($real);

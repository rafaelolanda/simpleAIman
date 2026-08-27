<?php

declare(strict_types=1);

/**
 * Serve um arquivo recebido numa conversa.
 *
 * Existe porque o arquivo mora FORA da raiz web — proposital, e é o que separa
 * "só o atendente logado vê a foto" de "quem descobrir a URL vê a foto".
 * Servir de `assets/` seria uma linha de código a menos e um vazamento a mais:
 * bastaria um id aparecer num print para o arquivo ficar aberto, sem login,
 * para sempre.
 *
 * Autenticação vem do `_init.php`, igual a qualquer tela do painel. Nada aqui
 * confia no id da URL para além de procurar a linha.
 */

require_once __DIR__ . '/_init.php';

use SimpleAIman\Canais\Anexos;

$anexo = Anexos::porId((int) ($_GET['id'] ?? 0));

if ($anexo === null) {
    http_response_code(404);
    exit('Anexo não encontrado.');
}

// Apagado pela retenção. 410 e não 404: a diferença entre "nunca existiu" e
// "existiu e foi apagado" é exatamente o que se precisa responder a um titular
// que pergunta o que aconteceu com o arquivo dele.
if ($anexo['removido_em'] !== null) {
    http_response_code(410);
    exit('Este anexo foi apagado pela política de retenção.');
}

$caminho = Anexos::caminhoAbsoluto($anexo);
$real = realpath($caminho);
$base = realpath(caminho_storage());

// O `caminho` vem do banco, gravado por nós — mas conferir custa uma chamada e
// transforma "confio na minha própria escrita" em "não importa quem escreveu".
if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
    http_response_code(404);
    exit('Arquivo indisponível.');
}

$mime = (string) $anexo['mime'];

// Imagem, áudio e vídeo abrem na própria tela; o resto baixa. `attachment`
// para os demais não é gosto: PDF e HTML renderizados em linha executam
// conteúdo de terceiro dentro do domínio do painel.
$emLinha = str_starts_with($mime, 'image/')
    || str_starts_with($mime, 'audio/')
    || str_starts_with($mime, 'video/');

$nome = (string) ($anexo['nome_original'] ?? '') !== ''
    ? (string) $anexo['nome_original']
    : 'anexo-' . (int) $anexo['id'] . '.' . pathinfo($real, PATHINFO_EXTENSION);

// O nome vem de quem enviou: sem limpeza, uma aspa ou quebra de linha aqui
// injeta cabeçalho na resposta.
$nome = preg_replace('/[^\w.\- ]+/u', '_', $nome) ?? 'anexo';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($emLinha ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($real);

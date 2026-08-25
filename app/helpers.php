<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function slugify(string $text): string
{
    if (class_exists('Normalizer')) {
        $normalizado = Normalizer::normalize($text, Normalizer::FORM_D);
        if ($normalizado !== false) {
            $text = preg_replace('/\p{Mn}/u', '', $normalizado) ?? $text;
        }
    } else {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    }

    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

/**
 * Cor hex convertida em "r, g, b" para uso dentro de rgba() no CSS — degradês
 * precisam de alfa, e não dá pra aplicar alfa sobre uma custom property que guarda hex.
 */
function cor_rgb(?string $hex, string $padrao = '37, 99, 235'): string
{
    $hex = ltrim((string) $hex, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return $padrao;
    }

    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

/**
 * Preto ou branco, conforme o que melhor contrasta com a cor de fundo informada.
 */
function cor_contraste(?string $hex, string $clara = '#ffffff', string $escura = '#14151a'): string
{
    $hex = ltrim((string) $hex, '#');

    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return $clara;
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // luminância percebida (fórmula YIQ)
    $brilho = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

    return $brilho > 150 ? $escura : $clara;
}

/**
 * "?v=<mtime>" no caminho do asset, pra invalidar cache do navegador a cada deploy.
 * Recebe o caminho relativo à public_html, sem barra inicial.
 */
function asset_ver(string $caminho): string
{
    $caminho = ltrim($caminho, '/');
    $arquivo = __DIR__ . '/../public_html/' . $caminho;
    $mtime = @filemtime($arquivo);

    return $caminho . ($mtime !== false ? '?v=' . $mtime : '');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}

function old(string $key, string $default = ''): string
{
    return e($_SESSION['_old'][$key] ?? $default);
}

function flash_set(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function caminho_uploads(string $subpasta = ''): string
{
    $base = __DIR__ . '/../public_html/assets/uploads';
    return $subpasta === '' ? $base : $base . '/' . trim($subpasta, '/');
}

/**
 * Link de WhatsApp com mensagem já preenchida. Centralizado porque o número
 * aparece em vários pontos (setor, rodapé, widget) e todos precisam levar a
 * mesma marcação de origem.
 */
function whatsapp_url(?string $numero, string $mensagem = ''): string
{
    $numero = preg_replace('/\D+/', '', (string) $numero) ?? '';

    if ($numero === '') {
        return '';
    }

    $url = 'https://wa.me/' . $numero;

    return $mensagem !== '' ? $url . '?text=' . rawurlencode($mensagem) : $url;
}

/**
 * Enumerações validadas em PHP, nunca em CHECK: SQLite não permite ALTER de
 * constraint, então enumerar no banco vira dívida — mudar o conjunto exigiria
 * recriar a tabela. Ver ARQUITETURA.md §4.
 */
function valor_em(mixed $valor, array $permitidos, string $padrao): string
{
    return in_array($valor, $permitidos, true) ? (string) $valor : $padrao;
}

/**
 * Garante que o texto seja UTF-8 válido.
 *
 * Texto malformado NÃO dá erro ao ser gravado no SQLite — ele entra, fica
 * lá, e só explode lá na frente, no `json_encode` que monta a requisição
 * para a LLM: "Malformed UTF-8 characters". O turno inteiro morre, e o rastro
 * aponta para dentro do cliente HTTP, longe da origem real.
 *
 * As fontes de texto ruim são todas comuns:
 *   - colar conteúdo vindo de Word ou de sistema legado no admin;
 *   - PDF e DOCX cujo extrator devolve bytes em CP1252;
 *   - importação de outro sistema.
 *
 * Por isso a limpeza fica na entrada — no que é salvo e no que é indexado —
 * e não numa checagem antes de cada chamada de API.
 */
function texto_utf8(?string $texto): string
{
    $texto = (string) $texto;

    if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
        return $texto;
    }

    // CP1252 antes de ISO-8859-1: é o que o Windows produz, e cobre aspas
    // curvas e travessão, que o ISO-8859-1 puro transformaria em lixo.
    $convertido = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');

    if (mb_check_encoding($convertido, 'UTF-8')) {
        return $convertido;
    }

    // Último recurso: descarta as sequências que sobraram inválidas. Perder
    // um caractere é melhor que derrubar o atendimento inteiro.
    return mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
}

function formatar_bytes(int $bytes): string
{
    $unidades = ['B', 'KB', 'MB', 'GB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($unidades) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, $i === 0 ? 0 : 1) . ' ' . $unidades[$i];
}

function json_ou_nulo(mixed $valor): ?string
{
    if ($valor === null || $valor === '' || $valor === []) {
        return null;
    }

    return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
}

function json_para_array(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $dados = json_decode($json, true);

    return is_array($dados) ? $dados : [];
}

function svg_icon(string $nome, int $tamanho = 20): string
{
    $paths = [
        'bot' => '<rect x="4" y="7" width="16" height="12" rx="3"/><path d="M12 7V4"/><circle cx="9" cy="13" r="1"/><circle cx="15" cy="13" r="1"/>',
        'base' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'arquivo' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'faq' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.7"/><circle cx="12" cy="17" r=".5"/>',
        'ferramenta' => '<path d="M14.7 6.3a4 4 0 0 0 5 5L15 16l-3 3-4-4 3-3z"/><path d="M8 15l-4 4"/>',
        'setor' => '<path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.5 7.2L3 21l1.8-6.5A8 8 0 1 1 21 12z"/>',
        'lead' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="8" r="4"/>',
        'chamado' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'plug' => '<path d="M9 3v6"/><path d="M15 3v6"/><path d="M6 9h12v3a6 6 0 0 1-12 0z"/><path d="M12 18v3"/>',
        'grafico' => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'chave' => '<circle cx="8" cy="14" r="4"/><path d="M11 11l9-9"/><path d="M17 5l2 2"/>',
        'log' => '<path d="M5 3h14v18H5z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>',
        'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M9.9 5.2A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.3 6.3A17 17 0 0 0 2 12s3.6 7 10 7c2 0 3.7-.7 5.1-1.6"/><path d="M3 3l18 18"/>',
    ];

    $d = $paths[$nome] ?? $paths['bot'];

    return '<svg width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true">' . $d . '</svg>';
}

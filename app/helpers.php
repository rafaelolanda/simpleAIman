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

/**
 * Normaliza e confere um CPF pelos dígitos verificadores.
 *
 * Devolve os 11 dígitos, ou null se não confere.
 *
 * Validar aqui evita mandar lixo ao CRM e, principalmente, evita descobrir o
 * erro tarde demais: um CPF malformado seria recusado lá na frente, quando
 * ninguém mais estiver na conversa para corrigir. Conferido enquanto a pessoa
 * está falando, ela repete na hora.
 *
 * Sequências repetidas (111.111.111-11) passam no cálculo mas nunca são CPF
 * real — e são justamente o que alguém digita para se livrar do formulário.
 */
function cpf_normalizar(?string $cpf): ?string
{
    $digitos = preg_replace('/\D+/', '', (string) $cpf) ?? '';

    // A retrovinculacao  e o que reprova 111.111.111-11 e similares:
    // sequencias repetidas passam no calculo dos digitos verificadores,
    // mas nunca sao CPF real — e sao exatamente o que alguem digita para
    // se livrar do formulario.
    if (strlen($digitos) !== 11 || preg_match('/^(\d)\1{10}$/', $digitos) === 1) {
        return null;
    }

    foreach ([9, 10] as $posicao) {
        $soma = 0;

        for ($i = 0; $i < $posicao; $i++) {
            $soma += ((int) $digitos[$i]) * ($posicao + 1 - $i);
        }

        $resto = ($soma * 10) % 11;
        $esperado = $resto === 10 ? 0 : $resto;

        if ((int) $digitos[$posicao] !== $esperado) {
            return null;
        }
    }

    return $digitos;
}

/**
 * CPF mascarado para exibição: 123.***.**9-00 vira ***.456.789-**
 *
 * Mostra o suficiente para conferir de qual pessoa se trata, sem expor o
 * número inteiro em tela que alguém pode estar compartilhando.
 */
function cpf_mascarar(?string $cpf): string
{
    $d = preg_replace('/\D+/', '', (string) $cpf) ?? '';

    if (strlen($d) !== 11) {
        return (string) $cpf;
    }

    return '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**';
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

/**
 * Markdown do modelo traduzido para os marcadores do WhatsApp.
 *
 * O modelo escreve Markdown — ninguém pediu, é como ele foi treinado — e o
 * WhatsApp não entende Markdown. `**negrito**` chega ao telefone com os
 * asteriscos à mostra, `### Título` vira uma linha começando com cerquilhas,
 * e uma resposta do RAG com lista e destaques fica ilegível.
 *
 * A tradução acontece na SAÍDA, não na gravação: o banco guarda o texto do
 * modelo como veio. Converter antes de gravar perderia o original e faria o
 * painel mostrar uma coisa e o log outra.
 *
 * O que não tem equivalente vira texto simples em vez de sumir — link em
 * Markdown vira `texto (endereço)`, porque no telefone o endereço é o que a
 * pessoa consegue usar.
 */
function markdown_para_whatsapp(?string $texto): string
{
    $texto = (string) $texto;

    // Negrito do Markdown é `**x**`; no WhatsApp é `*x*`. Feito antes de
    // qualquer regra de itálico: `**x**` contém `*x*` e seria capturado pela
    // metade se a ordem invertesse.
    $texto = preg_replace('/\*\*([^*\n]+)\*\*/u', '*$1*', $texto) ?? $texto;
    $texto = preg_replace('/__([^_\n]+)__/u', '*$1*', $texto) ?? $texto;

    // Títulos não existem no WhatsApp — viram negrito, que é o mais próximo
    // do papel que cumprem.
    $texto = preg_replace('/^\s{0,3}#{1,6}\s+(.+?)\s*$/mu', '*$1*', $texto) ?? $texto;

    // Marcador de lista: o asterisco de bullet seria lido como negrito aberto.
    $texto = preg_replace('/^(\s*)[-*+]\s+/mu', '$1• ', $texto) ?? $texto;

    // `[texto](url)` — no telefone o que serve é o endereço.
    $texto = preg_replace_callback(
        '/\[([^\]\n]*)\]\((\S+?)\)/u',
        static fn (array $m): string => trim($m[1]) === '' || trim($m[1]) === $m[2]
            ? $m[2]
            : $m[1] . ' (' . $m[2] . ')',
        $texto
    ) ?? $texto;

    return $texto;
}

/**
 * Texto convertido em HTML seguro, para o widget e o painel.
 *
 * Entende as duas marcações que chegam aqui:
 *
 *   do atendente digitando   *negrito*  _itálico_  ~riscado~  `mono`
 *   do modelo, em Markdown   **negrito**  ### título  - item  [t](url)
 *
 * `*x*` é negrito e não itálico: é o que o atendente digita e o que o WhatsApp
 * faz. O itálico do Markdown vira negrito nesse caso — perda pequena e
 * previsível, melhor que exibir o asterisco cru, que foi o que acontecia com
 * toda resposta vinda do RAG.
 *
 * A ordem aqui é a segurança: **escapa primeiro**, formata depois. Assim o que
 * vira tag é só o que esta função criou, e nada que tenha vindo do visitante,
 * do modelo ou de um documento ingerido.
 */
function formatar_whatsapp(?string $texto): string
{
    $texto = (string) $texto;

    // O trecho monoespaçado é separado ANTES de qualquer outra regra: dentro
    // dele, asterisco é asterisco, não negrito.
    $partes = preg_split('/(`[^`\n]+`)/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$texto];
    $saida = '';

    foreach ($partes as $parte) {
        if ($parte === '') {
            continue;
        }

        if (str_starts_with($parte, '`') && str_ends_with($parte, '`') && mb_strlen($parte) > 2) {
            $saida .= '<code>' . e(mb_substr($parte, 1, -1)) . '</code>';
            continue;
        }

        $t = e($parte);

        // Markdown primeiro, reduzido aos marcadores do WhatsApp. `e()` não
        // toca em asterisco, cerquilha nem colchete, então a normalização
        // funciona igual sobre o texto já escapado — e escapado é onde ela
        // precisa acontecer, para não criar tag a partir do conteúdo.
        $t = markdown_para_whatsapp($t);

        // As bordas (?<![\w…]) e (?![\w…]) evitam que um sublinhado no meio de
        // nome_de_variavel vire itálico — que é a queixa clássica.
        $t = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '<em>$1</em>', $t) ?? $t;
        $t = preg_replace('/(?<![\w~])~([^~\n]+)~(?![\w~])/u', '<s>$1</s>', $t) ?? $t;

        $saida .= $t;
    }

    // Sem nl2br de propósito: quem renderiza usa `white-space: pre-wrap`, e os
    // dois juntos DOBRAM a quebra de linha — a tag <br> mais a quebra original,
    // que o pre-wrap preserva. Deixar a quebra por conta do CSS mantém o texto
    // idêntico ao que foi digitado.
    return $saida;
}

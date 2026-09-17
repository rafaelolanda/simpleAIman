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
 * Pasta de arquivos privados — FORA da raiz web, ao contrário de
 * `caminho_uploads()`.
 *
 * A diferença é o tipo de arquivo, não o gosto: o que passa por aqui é
 * conteúdo de conversa — a foto que alguém mandou, o áudio que gravou, o
 * documento que anexou. Servido por URL direta, bastaria um id vazar num
 * print para o arquivo ficar aberto a qualquer um, sem login, para sempre.
 *
 * Cria a pasta na primeira chamada e planta um `.htaccess` de recusa. Estando
 * fora da raiz, o `.htaccess` é redundante — e é essa a intenção: se um dia
 * alguém apontar um vhost para o diretório errado, o segundo cadeado segura.
 */
/**
 * Entrega a resposta ao cliente e deixa o PHP seguir trabalhando.
 *
 * O webhook do WhatsApp e o kick do worker respondem primeiro e trabalham
 * depois: a Meta tolera poucos segundos antes de reenviar o evento, e quem
 * cutuca o worker não pode esperar a chamada ao modelo terminar.
 *
 * Até 11/09/2026 isso só funcionava sob PHP-FPM. O código chamava apenas
 * `fastcgi_finish_request()`, que não existe no LiteSpeed — o servidor da
 * Hostinger, onde o equivalente é `litespeed_finish_request()`. Lá a conexão
 * ficava aberta até quem chamou desistir, e o processo podia ser encerrado no
 * meio do turno. O sintoma em produção foi o WhatsApp devolvendo só o aviso de
 * turno interrompido.
 *
 * Devolve o mecanismo usado, e o guarda em `$GLOBALS['__liberacao']` para o
 * batimento do worker registrar em que condição cada execução rodou — é o que
 * permite ver na tela de Infraestrutura se o servidor oferece algum.
 *
 * @return 'fastcgi'|'litespeed'|'nenhum'
 */
function liberar_conexao(): string
{
    // Antes do flush: se o cliente fechar enquanto o buffer desce, o PHP não
    // pode tomar isso como sinal para parar.
    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    flush();

    $mecanismo = 'nenhum';

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $mecanismo = 'fastcgi';
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        $mecanismo = 'litespeed';
    }

    $GLOBALS['__liberacao'] = $mecanismo;

    return $mecanismo;
}

function caminho_storage(string $subpasta = ''): string
{
    $base = __DIR__ . '/../storage';

    if (!is_dir($base)) {
        @mkdir($base, 0770, true);

        $htaccess = $base . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }

    if ($subpasta === '') {
        return $base;
    }

    $caminho = $base . '/' . trim($subpasta, '/');

    if (!is_dir($caminho)) {
        @mkdir($caminho, 0770, true);
    }

    return $caminho;
}

/**
 * Anexos de uma mensagem, em HTML, para o painel.
 *
 * Imagem, áudio e vídeo aparecem na própria tela; o resto vira link. O
 * atendente precisa VER a foto do comprovante sem sair da conversa — mandar
 * baixar cada arquivo transformaria um atendimento de dois minutos em cinco.
 *
 * Todo endereço aponta para `anexo.php`, que exige sessão. Nenhum arquivo é
 * servido por caminho direto — é o que impede a foto de vazar por URL.
 *
 * @param list<array<string, mixed>> $anexos
 */
function anexos_html(array $anexos): string
{
    if ($anexos === []) {
        return '';
    }

    $saida = '';

    foreach ($anexos as $anexo) {
        $url = 'anexo.php?id=' . (int) $anexo['id'];
        $mime = (string) $anexo['mime'];

        // Apagado pela retenção: a linha fica para dizer que existiu. Sumir em
        // silêncio faria parecer que a pessoa nunca mandou nada.
        if (($anexo['removido_em'] ?? null) !== null) {
            $saida .= '<div class="anexo anexo-removido">Anexo apagado pela política de retenção</div>';
            continue;
        }

        if (str_starts_with($mime, 'image/')) {
            $saida .= '<a class="anexo anexo-imagem" href="' . e($url) . '" target="_blank" rel="noopener">'
                . '<img src="' . e($url) . '" alt="Imagem enviada na conversa" loading="lazy"></a>';
            continue;
        }

        if (str_starts_with($mime, 'audio/')) {
            $saida .= '<audio class="anexo anexo-audio" controls preload="none" src="' . e($url) . '"></audio>';
            continue;
        }

        if (str_starts_with($mime, 'video/')) {
            $saida .= '<video class="anexo anexo-video" controls preload="none" src="' . e($url) . '"></video>';
            continue;
        }

        $nome = trim((string) ($anexo['nome_original'] ?? '')) ?: 'arquivo';
        $saida .= '<a class="anexo anexo-arquivo" href="' . e($url) . '">📎 ' . e($nome)
            . ' <span class="anexo-tamanho">(' . e(tamanho_legivel((int) $anexo['tamanho'])) . ')</span></a>';
    }

    return $saida;
}

/**
 * Por quanto tempo um link de anexo do widget continua valendo.
 *
 * Seis horas: sobra para quem deixa a janela aberta a manhã inteira, e é curto
 * o bastante para um endereço que vazou parar de funcionar no mesmo dia. Ao
 * recarregar, o widget recebe assinatura nova, então o visitante nunca esbarra
 * no prazo.
 */
const ANEXO_PUBLICO_VALIDADE_SEG = 21600;

/**
 * Assina o link do anexo para o widget, com prazo.
 *
 * Sem isto o endereço é uma **URL-capacidade**: quem o tiver, tem o arquivo,
 * de qualquer navegador e para sempre. O identificador de sessão viaja na
 * própria URL, então basta ela vazar — histórico, print, log de proxy, um
 * "abrir imagem em nova aba" — para o arquivo ficar acessível a quem não
 * participou da conversa.
 *
 * A conferência de dono continua existindo no endpoint, e não é substituída
 * por esta: uma diz que o anexo pertence àquela conversa, a outra diz que o
 * link foi emitido por nós e ainda está no prazo. As duas juntas é que fecham.
 *
 * `SESSION_SECRET` é a chave. Instalação que deixou o texto de exemplo no
 * `.env` tem assinatura previsível — está avisado na tela de instalação, e é o
 * mesmo segredo que já protege a sessão do painel.
 */
function anexo_assinatura(int $anexoId, string $token, string $sessao): string
{
    $expira = time() + ANEXO_PUBLICO_VALIDADE_SEG;
    $assinatura = hash_hmac('sha256', $anexoId . '|' . $sessao . '|' . $expira, SESSION_SECRET);

    return '&t=' . rawurlencode($token)
        . '&sessao=' . rawurlencode($sessao)
        . '&exp=' . $expira
        . '&sig=' . $assinatura;
}

/**
 * A assinatura confere e ainda está no prazo?
 */
function anexo_assinatura_valida(int $anexoId, string $sessao, int $expira, string $assinatura): bool
{
    if ($expira < time()) {
        return false;
    }

    // `hash_equals` e não `===`: comparação de segredo em tempo constante.
    return hash_equals(
        hash_hmac('sha256', $anexoId . '|' . $sessao . '|' . $expira, SESSION_SECRET),
        $assinatura
    );
}

/**
 * Os mesmos anexos, mas para o widget, que roda no site do cliente.
 *
 * Duas diferenças em relação ao painel, e as duas vêm de onde a página está:
 *
 * - **URL absoluta.** O widget vive no domínio do cliente; um endereço
 *   relativo apontaria para o site dele, não para o nosso.
 * - **Autorização por token e sessão**, e não por login — o mesmo par que já
 *   guarda o histórico dessa conversa.
 *
 * @param list<array<string, mixed>> $anexos
 */
function anexos_html_publico(array $anexos, string $token, string $sessao): string
{
    if ($anexos === []) {
        return '';
    }

    $saida = '';
    $base = APP_URL . '/api/anexo-publico.php?id=';

    foreach ($anexos as $anexo) {
        if (($anexo['removido_em'] ?? null) !== null) {
            continue;
        }

        $sufixo = anexo_assinatura((int) $anexo['id'], $token, $sessao);

        $url = $base . (int) $anexo['id'] . $sufixo;
        $mime = (string) $anexo['mime'];

        if (str_starts_with($mime, 'image/')) {
            $saida .= '<a class="sa-anexo" href="' . e($url) . '" target="_blank" rel="noopener">'
                . '<img src="' . e($url) . '" alt="Arquivo enviado no atendimento" loading="lazy"></a>';
            continue;
        }

        if (str_starts_with($mime, 'audio/')) {
            $saida .= '<audio class="sa-anexo" controls preload="none" src="' . e($url) . '"></audio>';
            continue;
        }

        if (str_starts_with($mime, 'video/')) {
            $saida .= '<video class="sa-anexo" controls preload="none" src="' . e($url) . '"></video>';
            continue;
        }

        $nome = trim((string) ($anexo['nome_original'] ?? '')) ?: 'arquivo';
        $saida .= '<a class="sa-anexo sa-anexo-arquivo" href="' . e($url) . '" target="_blank" rel="noopener">📎 '
            . e($nome) . '</a>';
    }

    return $saida;
}

/**
 * Como chamar quem está do outro lado da conversa.
 *
 * Ordem de preferência: o nome que ela deu, o telefone formatado, e só então
 * "Visitante". Nunca o `#131`, que é número de linha de banco e não diz nada a
 * ninguém — era o que a tela mostrava antes.
 *
 * @param array<string, mixed> $conversa
 */
function nome_do_contato(array $conversa): string
{
    $nome = trim((string) ($conversa['contato_nome'] ?? ''));

    if ($nome !== '') {
        return $nome;
    }

    // No WhatsApp o `externo_id` É o telefone; no widget é um identificador de
    // sessão, que não serve para ninguém ler.
    if (($conversa['canal_tipo'] ?? '') === 'whatsapp') {
        $tel = telefone_legivel((string) ($conversa['contato_valor'] ?? $conversa['externo_id'] ?? ''));

        if ($tel !== '') {
            return $tel;
        }
    }

    return 'Visitante';
}

/**
 * Iniciais para o avatar, e uma cor estável derivada do nome.
 *
 * Não temos foto de ninguém, e um ícone genérico repetido em toda linha não
 * ajuda a distinguir uma conversa da outra. Duas letras e uma cor resolvem: a
 * cor sai de um hash do próprio nome, então a mesma pessoa aparece sempre igual
 * e a lista fica reconhecível de relance.
 *
 * @return array{iniciais: string, cor: string}
 */
function avatar_do_contato(string $nome): array
{
    $limpo = trim(preg_replace('/[^\p{L}\s]+/u', '', $nome) ?? '');
    $partes = preg_split('/\s+/u', $limpo, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($partes === []) {
        // Sem letra nenhuma é o contato que só tem telefone. Os dois últimos
        // dígitos distinguem uma linha da outra melhor que uma interrogação
        // repetida em todas.
        $digitos = preg_replace('/\D+/', '', $nome) ?? '';
        $iniciais = $digitos !== '' ? mb_substr($digitos, -2) : '?';
    } elseif (count($partes) === 1) {
        $iniciais = mb_strtoupper(mb_substr($partes[0], 0, 2));
    } else {
        $iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr((string) end($partes), 0, 1));
    }

    // Matiz do hash, com saturação e luminosidade fixas: garante contraste com
    // o texto branco em qualquer nome, sem sortear cor ilegível.
    $matiz = crc32(mb_strtolower($nome)) % 360;

    return ['iniciais' => $iniciais, 'cor' => 'hsl(' . $matiz . ', 45%, 42%)'];
}

/**
 * "agora", "3 min", "2 h", "ontem", "12/08".
 *
 * Hora exata numa lista de conversas obriga a pessoa a fazer a conta de quanto
 * tempo faz — e é a conta, não o horário, que decide o que atender primeiro.
 */
function tempo_relativo(?string $quando): string
{
    $ts = $quando ? strtotime($quando) : false;

    if ($ts === false) {
        return '';
    }

    $seg = time() - $ts;

    if ($seg < 60) {
        return 'agora';
    }

    if ($seg < 3600) {
        return intdiv($seg, 60) . ' min';
    }

    if ($seg < 86400 && date('d', $ts) === date('d')) {
        return date('H:i', $ts);
    }

    if ($seg < 172800) {
        return 'ontem';
    }

    return date('d/m', $ts);
}

/**
 * Telefone em E.164 escrito como gente lê.
 *
 * O WhatsApp entrega `555599544904`; o atendente precisa reconhecer aquilo
 * como um número, e ler quatorze dígitos grudados atrasa cada atendimento um
 * pouco. Formata o padrão brasileiro e devolve o resto com um `+` na frente —
 * chutar máscara de país que não conhecemos deixaria pior que o cru.
 */
function telefone_legivel(?string $numero): string
{
    $d = preg_replace('/\D+/', '', (string) $numero) ?? '';

    if ($d === '') {
        return '';
    }

    if (str_starts_with($d, '55') && (strlen($d) === 12 || strlen($d) === 13)) {
        $ddd = substr($d, 2, 2);
        $resto = substr($d, 4);
        $meio = strlen($resto) === 9 ? substr($resto, 0, 5) : substr($resto, 0, 4);
        $fim = strlen($resto) === 9 ? substr($resto, 5) : substr($resto, 4);

        return "+55 ({$ddd}) {$meio}-{$fim}";
    }

    return '+' . $d;
}

function tamanho_legivel(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }

    return round($bytes / 1048576, 1) . ' MB';
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
        // Traco simples, como os vizinhos: o conjunto usa contorno de 1.6 e
        // nenhum preenchimento, e um icone fora do padrao salta na barra.
        'engrenagem' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 3v2.2M12 18.8V21M21 12h-2.2M5.2 12H3M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6M18.4 18.4l-1.6-1.6M7.2 7.2 5.6 5.6"/>',
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
        // Icones dos tres mapas. Nenhum reaproveita o 'grafico' do Dashboard:
        // na mesma secao do menu, dois itens com o mesmo desenho viram um so
        // para quem navega pelo formato, sem ler o rotulo.
        'mapa' => '<circle cx="5" cy="7" r="2.2"/><circle cx="5" cy="17" r="2.2"/>'
            . '<circle cx="19" cy="12" r="2.2"/><path d="M7.2 7.9 16.9 11.2"/><path d="M7.2 16.1 16.9 12.8"/>',
        'fone' => '<path d="M4 13v-1a8 8 0 0 1 16 0v1"/>'
            . '<path d="M4 13h2.6a1 1 0 0 1 1 1v3.4a1 1 0 0 1-1 1H5.6A1.6 1.6 0 0 1 4 16.8z"/>'
            . '<path d="M20 13h-2.6a1 1 0 0 0-1 1v3.4a1 1 0 0 0 1 1h1a1.6 1.6 0 0 0 1.6-1.6z"/>',
        // Um icone por item do menu. Repetido, ele deixa de identificar e passa
        // a confundir: quem navega pelo formato — e todo mundo navega, depois da
        // primeira semana — clica no vizinho.
        'lupa' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
        'livro' => '<path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v16H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5V4.5"/><path d="M8 7h8"/>',
        'cronometro' => '<circle cx="12" cy="13" r="8"/><path d="M12 13V9"/><path d="M9.5 2h5"/><path d="M19 6l1.5-1.5"/>',
        'servidor' => '<rect x="3" y="4" width="18" height="6" rx="1.6"/><rect x="3" y="14" width="18" height="6" rx="1.6"/><path d="M7 7h.01"/><path d="M7 17h.01"/>',
        'antena' => '<circle cx="12" cy="12" r="2"/><path d="M8.5 8.5a5 5 0 0 0 0 7"/><path d="M15.5 15.5a5 5 0 0 0 0-7"/><path d="M6 6a8.5 8.5 0 0 0 0 12"/><path d="M18 18a8.5 8.5 0 0 0 0-12"/>',
        'faisca' => '<path d="M13 2 4.5 13.5H11l-1 8.5 9-12h-6.5z"/>',
        'escudo' => '<path d="M12 3l8 3v5.5c0 4.6-3.2 8.8-8 10.5-4.8-1.7-8-5.9-8-10.5V6z"/><path d="M9.2 12.2l2 2 3.6-3.8"/>',
        'pulso' => '<path d="M2 12h4l2.5-6 4 13 3-9 2.5 2H22"/>',
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
    return tabela_markdown_para_html($saida);
}

/**
 * Converte tabela em markdown (linhas com `|`) em `<table>`.
 *
 * Roda DEPOIS do escape e do negrito: o que chega aqui já é HTML seguro, e as
 * células herdam a formatação inline. Por isso o conteúdo da célula entra sem
 * novo escape — escapar de novo transformaria `<strong>` em texto.
 *
 * Existe porque o agente responde simulação de custo em tabela, que é o
 * formato certo para o dado, e o widget mostrava os canos crus: `| Disciplina
 * | Créditos |`, linha a linha, com os hífens do separador. Visto em uso real
 * em 16/09/2026.
 *
 * No WhatsApp não há conversão possível — o aplicativo não tem tabela. Lá
 * quem resolve é a regra de formato no prompt, que pede lista.
 */
function tabela_markdown_para_html(string $html): string
{
    if (!str_contains($html, '|')) {
        return $html;
    }

    $linhas = explode("\n", $html);
    $saida = [];
    $total = count($linhas);

    for ($i = 0; $i < $total; $i++) {
        $atual = trim($linhas[$i]);
        $proxima = isset($linhas[$i + 1]) ? trim($linhas[$i + 1]) : '';

        // Uma tabela precisa do cabeçalho E do separador logo abaixo. Sem
        // essa exigência, qualquer frase com `|` viraria tabela de uma coluna.
        $ehTabela = str_starts_with($atual, '|')
            && $proxima !== ''
            && preg_match('/^\|(?:\s*:?-{2,}:?\s*\|)+$/', $proxima) === 1;

        if (!$ehTabela) {
            $saida[] = $linhas[$i];
            continue;
        }

        $cabecalho = celulas_da_linha($atual);
        $corpo = [];
        $i += 2;

        while ($i < $total && str_starts_with(trim($linhas[$i]), '|')) {
            $corpo[] = celulas_da_linha(trim($linhas[$i]));
            $i++;
        }

        $i--;

        $tabela = '<table class="tabela-resposta"><thead><tr>';

        foreach ($cabecalho as $c) {
            $tabela .= '<th>' . $c . '</th>';
        }

        $tabela .= '</tr></thead><tbody>';

        foreach ($corpo as $linha) {
            $tabela .= '<tr>';

            foreach ($linha as $c) {
                $tabela .= '<td>' . $c . '</td>';
            }

            $tabela .= '</tr>';
        }

        $saida[] = $tabela . '</tbody></table>';
    }

    return implode("\n", $saida);
}

/**
 * Células de uma linha de tabela markdown, sem os canos das pontas.
 *
 * @return list<string>
 */
function celulas_da_linha(string $linha): array
{
    $linha = trim($linha, '|');

    return array_map('trim', explode('|', $linha));
}

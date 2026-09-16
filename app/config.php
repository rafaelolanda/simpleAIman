<?php

declare(strict_types=1);

function env_load(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo .env não encontrado em: {$path}");
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

$envPath = __DIR__ . '/../.env';
$env = env_load($envPath);

/**
 * O array cru do .env fica disponível porque a tabela `provedores` e a tabela
 * `ferramentas` guardam apenas o NOME da variável que contém o segredo
 * (auth_ref), nunca o segredo em si — quem resolve o nome é env_secret().
 * Chave no banco vazaria em backup e em log.
 */
$GLOBALS['__env'] = $env;

function env_secret(?string $nome): ?string
{
    if ($nome === null || $nome === '') {
        return null;
    }

    return $GLOBALS['__env'][$nome] ?? null;
}

define('APP_ENV', $env['APP_ENV'] ?? 'production');
define('APP_URL', rtrim($env['APP_URL'] ?? '', '/'));
define('APP_TIMEZONE', $env['APP_TIMEZONE'] ?? 'America/Sao_Paulo');
define('APP_NOME', $env['APP_NOME'] ?? 'simpleAIman');

define('DB_PATH', __DIR__ . '/' . ($env['DB_PATH'] ?? '../database/simpleaiman.sqlite'));
define('DB_BUSY_TIMEOUT_MS', (int) ($env['DB_BUSY_TIMEOUT_MS'] ?? 5000));

define('SESSION_SECRET', $env['SESSION_SECRET'] ?? '');
define('SESSION_NAME', $env['SESSION_NAME'] ?? 'simpleaiman_sess');
define('LOGIN_MAX_TENTATIVAS', (int) ($env['LOGIN_MAX_TENTATIVAS'] ?? 5));
define('LOGIN_BLOQUEIO_MINUTOS', (int) ($env['LOGIN_BLOQUEIO_MINUTOS'] ?? 15));

define('MAIL_DRIVER', $env['MAIL_DRIVER'] ?? 'mail');
define('MAIL_FROM', $env['MAIL_FROM'] ?? 'nao-responda@localhost');
define('MAIL_FROM_NOME', $env['MAIL_FROM_NOME'] ?? 'simpleAIman');
define('MAIL_SMTP_HOST', $env['MAIL_SMTP_HOST'] ?? '');
define('MAIL_SMTP_PORT', (int) ($env['MAIL_SMTP_PORT'] ?? 587));
define('MAIL_SMTP_USUARIO', $env['MAIL_SMTP_USUARIO'] ?? '');
define('MAIL_SMTP_SENHA', $env['MAIL_SMTP_SENHA'] ?? '');
define('MAIL_SMTP_SEGURANCA', $env['MAIL_SMTP_SEGURANCA'] ?? 'tls');

// ---------------------------------------------------------------------
// Upload e ingestão
// ---------------------------------------------------------------------
define('UPLOAD_MAX_MB', (int) ($env['UPLOAD_MAX_MB'] ?? 20));

// Teto da midia recebida pelo WhatsApp. Separado do UPLOAD_MAX_MB porque quem
// envia e diferente: o admin sobe um documento uma vez, uma pessoa qualquer
// manda video de graca, quantas vezes quiser, na cota de disco do cliente.
define('MIDIA_MAX_MB', (int) ($env['MIDIA_MAX_MB'] ?? 16));
define('WORKER_LOTE', (int) ($env['WORKER_LOTE'] ?? 25));
define('WORKER_TEMPO_MAX_S', (int) ($env['WORKER_TEMPO_MAX_S'] ?? 20));
define('WORKER_TOKEN', $env['WORKER_TOKEN'] ?? '');

// ---------------------------------------------------------------------
// Relógios do atendimento
//
// Ficam no .env, e não no banco, porque são operacionais e não regra de
// negócio: quem ajusta isso é quem opera a instância, e a mesma pessoa já
// mexe aqui para o worker e os limites. Antes estavam fixos no código.
//
// Zero desliga a regra correspondente.
// ---------------------------------------------------------------------

// Quanto tempo alguém espera na fila antes de o assistente retomar.
define('ESPERA_MAX_MIN', (int) ($env['ESPERA_MAX_MIN'] ?? 5));

// Visitante calado numa conversa JÁ em atendimento humano. O primeiro prazo
// só avisa o atendente por nota interna — pode ser que a pessoa tenha ido
// buscar um documento, e encerrar por baixo dela seria grosseiro. O segundo
// encerra.
define('INATIVIDADE_AVISO_MIN', (int) ($env['INATIVIDADE_AVISO_MIN'] ?? 10));
define('INATIVIDADE_HUMANO_MIN', (int) ($env['INATIVIDADE_HUMANO_MIN'] ?? 30));

// Conversa só com o assistente, parada. Encerra em silêncio: não há ninguém
// olhando, e escrever numa sala vazia não serve a ninguém. Se a pessoa voltar
// e escrever, reabrirSeEncerrada() retoma.
define('INATIVIDADE_BOT_MIN', (int) ($env['INATIVIDADE_BOT_MIN'] ?? 60));

// Presenca do atendente: quanto tempo sem batimento ate ele sumir da fila.
// Generoso de proposito — o navegador estrangula temporizadores em aba de
// fundo, e o painel consulta a cada 4s. Uma janela curta derrubaria quem
// apenas minimizou a janela.
define('PRESENCA_JANELA_SEG', (int) ($env['PRESENCA_JANELA_SEG'] ?? 120));

// Conversa presa com quem sumiu volta para a fila depois disto.
define('PRESENCA_ORFA_MIN', (int) ($env['PRESENCA_ORFA_MIN'] ?? 3));

// ---------------------------------------------------------------------
// Guarda da camada de ferramentas HTTP
//
// Allowlist de hosts fica AQUI, no .env, e nunca como campo editável no admin:
// uma ferramenta HTTP configurável é, na prática, um cliente HTTP arbitrário
// exposto a quem conversa com o bot. Ver ARQUITETURA.md §7.
// ---------------------------------------------------------------------
define('TOOLS_HOSTS_PERMITIDOS', array_values(array_filter(array_map(
    'trim',
    explode(',', $env['TOOLS_HOSTS_PERMITIDOS'] ?? '')
))));
define('TOOLS_TIMEOUT_MS', (int) ($env['TOOLS_TIMEOUT_MS'] ?? 8000));
// 16 KB ~= 4 mil tokens. O teto anterior, 64 KB, cabia em quase nenhum
// contexto: a resposta inteira da ferramenta entra no prompt da chamada
// seguinte. Em 16/09/2026 uma ferramenta que devolvia a lista de todos
// os cursos levou o pedido a 15.972 tokens contra os 8.000 do plano
// gratuito da Groq — HTTP 413, e o turno degradou para o menu.
define('TOOLS_RESPOSTA_MAX_KB', (int) ($env['TOOLS_RESPOSTA_MAX_KB'] ?? 16));

date_default_timezone_set(APP_TIMEZONE);

if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

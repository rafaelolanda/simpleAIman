<?php

declare(strict_types=1);

/**
 * Piso de versão verificado antes de qualquer outra coisa.
 *
 * Rodar num PHP mais antigo não falha aqui — falha torto lá na frente, com erro
 * que não aponta para a causa. Numa hospedagem compartilhada, onde a versão do
 * PHP muda por painel de controle e sem aviso, essa mensagem economiza uma
 * sessão inteira de depuração.
 *
 * O piso é 8.4 porque Pdo\Sqlite::loadExtension() só existe a partir dele, e é o
 * único caminho limpo para o sqlite-vec. Ver ARQUITETURA.md §1 e §8.
 */
if (PHP_VERSION_ID < 80400) {
    $msg = 'simpleAIman exige PHP 8.4 ou superior. Em uso: ' . PHP_VERSION
        . ' (' . PHP_BINARY . ')';

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg;
    }

    exit(1);
}

/**
 * Na LINHA DE COMANDO, erro fatal vai para a tela. Na web, não.
 *
 * Hospedagem compartilhada vem com `display_errors` desligado, e isso está
 * certo para o site: erro na tela vaza caminho de arquivo e trecho de código
 * para o visitante. Mas em CLI o mesmo ajuste transforma qualquer falha em
 * SILÊNCIO ABSOLUTO — e foi o que aconteceu em 10/09/2026, na instalação da
 * Hostinger: `php database/migrate.php` com o `.env` ainda não ajustado não
 * imprimiu uma linha sequer. A mensagem existia (`config.php` lança
 * "Arquivo .env não encontrado em: ..."), mas exceção não capturada respeita
 * o `display_errors`, então ninguém a viu.
 *
 * `stderr` e não `1`: assim a saída de erro não se mistura com a saída útil
 * de quem canaliza o comando, e o `2>>` do cron continua separando as duas.
 *
 * Vale para `migrate.php`, `bin/worker.php` e todo script de `bin/` — sem
 * depender do `php.ini` do host. É o mesmo princípio que ARQUITETURA.md §11
 * já registra sobre o worker morrer em silêncio.
 */
if (PHP_SAPI === 'cli') {
    ini_set('display_errors', 'stderr');
}

require_once __DIR__ . '/config.php';

// vendor/ é commitado (ver ARQUITETURA.md §1), mas a etapa 1 do projeto ainda não
// depende de biblioteca externa — o autoload só entra quando existir, pra que o
// esqueleto rode antes do primeiro `composer install`.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
// Turno antes de Log: Log consulta o turno aberto para carimbar a linha.
require_once __DIR__ . '/Turno.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Painel.php';
require_once __DIR__ . '/Metrics.php';
require_once __DIR__ . '/Mailer.php';

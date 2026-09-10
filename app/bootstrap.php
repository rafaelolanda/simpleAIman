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

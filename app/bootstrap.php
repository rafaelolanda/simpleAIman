<?php

declare(strict_types=1);

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
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Metrics.php';
require_once __DIR__ . '/Mailer.php';

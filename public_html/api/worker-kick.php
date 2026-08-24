<?php

declare(strict_types=1);

/**
 * Dispara o worker logo após um upload, sem esperar o cron.
 *
 * O cron é a rede de segurança; este endpoint é o caminho normal. Em
 * hospedagem compartilhada o intervalo mínimo do cron costuma ser de 5
 * minutos — esperar isso para ver o primeiro chunk aparecer faz o painel
 * parecer travado.
 *
 * Protegido por WORKER_TOKEN: sem isso, qualquer pessoa poderia disparar
 * processamento à vontade e queimar a cota do provedor.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use SimpleAIman\Jobs\Worker;

$token = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');

if (WORKER_TOKEN === '' || !hash_equals(WORKER_TOKEN, $token)) {
    http_response_code(403);
    exit('nao autorizado');
}

// Responde ANTES de trabalhar: quem chamou não deve esperar a ingestão.
ignore_user_abort(true);
set_time_limit(WORKER_TEMPO_MAX_S + 30);

http_response_code(202);
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: 3');
header('Connection: close');
echo 'ok';

while (ob_get_level() > 0) {
    ob_end_flush();
}

flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Trava por arquivo: o cron pode estar rodando neste exato momento, e dois
// workers no mesmo lote só desperdiçam cota.
$trava = sys_get_temp_dir() . '/simpleaiman-worker-' . md5(DB_PATH) . '.lock';
$fp = fopen($trava, 'c');

if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit;
}

try {
    (new Worker(WORKER_TEMPO_MAX_S, WORKER_LOTE))->executar(
        static function (string $m): void {
            error_log('[simpleAIman worker] ' . $m);
        }
    );
} catch (Throwable $e) {
    error_log('[simpleAIman worker] abortou: ' . $e->getMessage());
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

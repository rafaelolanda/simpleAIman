<?php

declare(strict_types=1);

/**
 * Processa a fila de ingestão.
 *
 *   php bin/worker.php              # respeita WORKER_TEMPO_MAX_S do .env
 *   php bin/worker.php --tempo=60   # sobrescreve o orçamento de tempo
 *   php bin/worker.php --silencioso # sem saída, para o cron
 *
 * No cron (a cada 1–5 minutos, conforme o host permitir):
 *   php /caminho/bin/worker.php --silencioso
 *
 * O cron é a REDE DE SEGURANÇA, não o mecanismo principal: o upload dispara
 * um "kick" logo depois de gravar o artefato, para o usuário não esperar o
 * próximo ciclo. Se o kick falhar, o cron pega no ciclo seguinte.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use SimpleAIman\Jobs\Queue;
use SimpleAIman\Jobs\Worker;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$opcoes = getopt('', ['tempo::', 'lote::', 'silencioso', 'status']);
$silencioso = isset($opcoes['silencioso']);

$log = $silencioso
    ? static fn (string $m): null => null
    : static function (string $m): void {
        echo '[' . date('H:i:s') . '] ' . $m . "\n";
    };

if (isset($opcoes['status'])) {
    $resumo = Queue::resumo();
    foreach ($resumo as $estado => $total) {
        printf("  %-12s %d\n", $estado, $total);
    }
    exit(0);
}

// Trava por arquivo: o cron pode disparar enquanto a execução anterior ainda
// roda, e dois workers no mesmo lote só desperdiçam cota do provedor.
$trava = sys_get_temp_dir() . '/simpleaiman-worker-' . md5(DB_PATH) . '.lock';
$fp = fopen($trava, 'c');

if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    $log('Outro worker já está rodando. Saindo.');
    exit(0);
}

$tempo = (int) ($opcoes['tempo'] ?? WORKER_TEMPO_MAX_S);
$lote = (int) ($opcoes['lote'] ?? WORKER_LOTE);

$log("Worker iniciado (tempo={$tempo}s, lote={$lote}).");

try {
    $placar = (new Worker($tempo, $lote))->executar($log);

    $log(sprintf(
        'Fim: %d job(s) processado(s) · %d concluído(s) · %d pausado(s) · %d falha(s).',
        $placar['processados'],
        $placar['concluidos'],
        $placar['pausas'],
        $placar['falhas'],
    ));

    $codigo = $placar['falhas'] > 0 ? 1 : 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'Worker abortou: ' . $e->getMessage() . "\n");
    $codigo = 1;
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

exit($codigo);

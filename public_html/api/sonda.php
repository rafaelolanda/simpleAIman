<?php

declare(strict_types=1);

/**
 * Sonda: quanto tempo um processo web sobrevive depois de responder.
 *
 * Responde uma pergunta que nenhuma leitura de configuração responde: quando o
 * webhook do WhatsApp ou o kick do worker entregam a resposta e seguem
 * trabalhando, o servidor deixa o PHP terminar? LiteSpeed, PHP-FPM e o limite
 * de cada host decidem isso, e cada um decide de um jeito.
 *
 * Imita o kick: responde 202, libera a conexão e segue. Em vez de chamar o
 * modelo, marca "ainda vivo" num arquivo a cada segundo. Quem lê o arquivo
 * depois sabe em que segundo o processo parou — e uma resposta do modelo que
 * demore mais que isso é exatamente o turno interrompido do WhatsApp.
 *
 * `liberar=0` reproduz o comportamento anterior a 11/09/2026, que respondia
 * sem chamar a função de liberação, para comparar os dois no mesmo servidor.
 *
 * Protegida pelo mesmo WORKER_TOKEN do kick: sem ele, qualquer um prenderia
 * processos do servidor à vontade. Dispare pela tela de Infraestrutura ou por
 * `php bin/diagnostico.php --sonda`.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');

if (WORKER_TOKEN === '' || !hash_equals(WORKER_TOKEN, $token)) {
    http_response_code(403);
    exit('nao autorizado');
}

$id = substr((string) preg_replace('/[^a-f0-9]/', '', (string) ($_GET['id'] ?? '')), 0, 24);

if ($id === '') {
    http_response_code(400);
    exit('id');
}

$segundos = min(120, max(5, (int) ($_GET['segundos'] ?? 60)));
$liberar = ($_GET['liberar'] ?? '1') !== '0';

// Mesma folga do kick. No Linux o limite conta só tempo de CPU — `sleep()` e
// espera de rede não entram —, então quase nunca é ele que para a sonda. Se
// ela parar, quem parou foi o servidor.
set_time_limit($segundos + 30);

http_response_code(202);
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: 3');
header('Connection: close');
echo 'ok';

if ($liberar) {
    $mecanismo = liberar_conexao();
} else {
    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    flush();
    $mecanismo = 'desligado';
}

$arquivo = caminho_storage('sondas') . '/' . $id . '.json';
$inicio = time();

for ($s = 0; $s <= $segundos; $s++) {
    @file_put_contents($arquivo, (string) json_encode([
        'inicio' => $inicio,
        'vivo_em' => time(),
        'segundos' => $s,
        'meta' => $segundos,
        'liberar' => $liberar,
        'mecanismo' => $mecanismo,
        'sapi' => PHP_SAPI,
        'servidor' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'terminou' => $s === $segundos,
    ]), LOCK_EX);

    if ($s < $segundos) {
        sleep(1);
    }
}

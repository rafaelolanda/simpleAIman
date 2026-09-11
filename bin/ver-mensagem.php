<?php

declare(strict_types=1);

/**
 * Mostra o conteúdo CRU das últimas mensagens do bot.
 *
 *   php bin/ver-mensagem.php                 # as 5 últimas do bot
 *   php bin/ver-mensagem.php 20              # as 20 últimas
 *   php bin/ver-mensagem.php --conversa=12   # só desta conversa
 *
 * Existe porque a tela renderiza o texto, e é justamente a renderização que
 * esconde o problema: marcador de raciocínio do modelo (`<think>`, `<|channel|>`),
 * asterisco solto e linha em branco somem na leitura e aparecem aqui, com os
 * caracteres invisíveis escapados.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$quantas = 5;
$conversa = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--conversa=(\d+)$/', $arg, $m)) {
        $conversa = (int) $m[1];
    } elseif (ctype_digit($arg)) {
        $quantas = max(1, min(100, (int) $arg));
    }
}

$sql = "SELECT id, conversa_id, autor_tipo, conteudo, latencia_ms, trace_id, criado_em
          FROM mensagens
         WHERE autor_tipo IN ('bot', 'sistema')"
    . ($conversa !== null ? ' AND conversa_id = :c' : '')
    . ' ORDER BY id DESC LIMIT :l';

$stmt = Database::connection()->prepare($sql);

if ($conversa !== null) {
    $stmt->bindValue('c', $conversa, PDO::PARAM_INT);
}

$stmt->bindValue('l', $quantas, PDO::PARAM_INT);
$stmt->execute();

foreach (array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC)) as $m) {
    $texto = (string) $m['conteudo'];

    $suspeitas = [];

    foreach (['<think>', '</think>', '<|', 'assistantfinal', 'analysis'] as $marca) {
        if (stripos($texto, $marca) !== false) {
            $suspeitas[] = $marca;
        }
    }

    if (preg_match('/(?<!\S)(?:\*[ \t]*){2,}(?!\S)/u', $texto)) {
        $suspeitas[] = 'asteriscos soltos';
    }

    if (preg_match('/\n{3,}/', $texto)) {
        $suspeitas[] = 'linhas em branco';
    }

    echo str_repeat('=', 70) . "\n";
    printf(
        "#%d · conversa %d · %s · %s · %s · %d bytes\n",
        $m['id'],
        $m['conversa_id'],
        $m['autor_tipo'],
        $m['criado_em'],
        (string) ($m['trace_id'] ?? '—'),
        strlen($texto)
    );

    echo 'suspeitas: ' . ($suspeitas === [] ? 'nenhuma' : implode(', ', $suspeitas)) . "\n";
    echo str_repeat('-', 70) . "\n";

    // json_encode escapa quebra de linha e caractere de controle: é o que
    // torna visível o que a tela engole.
    echo json_encode($texto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

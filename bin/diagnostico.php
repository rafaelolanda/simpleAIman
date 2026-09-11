<?php

declare(strict_types=1);

/**
 * Diagnóstico da infraestrutura, pela linha de comando.
 *
 *   php bin/diagnostico.php                 # tudo, inclusive disco e rede
 *   php bin/diagnostico.php --rapido        # sem escrita em disco e sem rede
 *   php bin/diagnostico.php --sonda         # mede quanto um processo web sobrevive
 *   php bin/diagnostico.php --sonda=30 --sem-liberar
 *
 * Os testes completos fazem UMA chamada real de embedding ao fornecedor
 * padrão — é o que dá o custo de indexar cada trecho.
 *
 * Atenção: este é o PHP da linha de comando. OPcache e o comportamento do
 * servidor web só existem no processo do site; para esses, use a tela
 * Sistema › Infraestrutura no painel. A sonda é a exceção: ela é disparada
 * daqui, mas roda no servidor web, então mede o que interessa.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/DiagnosticoInfra.php';

use SimpleAIman\Jobs\Batimento;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$opcoes = getopt('', ['rapido', 'sonda::', 'sem-liberar']);

$rotulo = static fn (string $estado): string => match ($estado) {
    'ok' => '[  OK  ]',
    'alerta' => '[ALERTA]',
    'erro' => '[ ERRO ]',
    default => '[ info ]',
};

echo "simpleAIman — diagnóstico da infraestrutura\n";

$verificacoes = DiagnosticoInfra::executar(!isset($opcoes['rapido']));
$grupoAtual = '';
$erros = 0;

foreach ($verificacoes as $v) {
    if ($v['grupo'] !== $grupoAtual) {
        $grupoAtual = $v['grupo'];
        echo "\n" . $grupoAtual . "\n" . str_repeat('-', mb_strlen($grupoAtual)) . "\n";
    }

    // mb_str_pad e não printf("%-24s"): o printf conta bytes, e "Liberação" e
    // "Última" têm acento — a coluna saía torta justamente nessas linhas.
    echo '  ' . $rotulo($v['estado']) . ' ' . mb_str_pad($v['item'], 24) . ' ' . $v['valor'] . "\n";

    // O detalhe só aparece quando pede ação: num relatório em que tudo passou,
    // explicação em cada linha vira ruído. A exceção é a rede, cujo detalhe é o
    // próprio resultado — ali está a estimativa de quanto demora indexar.
    if (($v['grupo'] === 'Rede' || in_array($v['estado'], ['alerta', 'erro'], true)) && $v['detalhe'] !== '') {
        echo '             ' . wordwrap($v['detalhe'], 78, "\n             ") . "\n";
    }

    $erros += $v['estado'] === 'erro' ? 1 : 0;
}

// ---------------------------------------------------------------------
// Últimas execuções do worker
// ---------------------------------------------------------------------
echo "\nÚltimas execuções do worker\n---------------------------\n";

$execucoes = array_slice(Batimento::ler(), 0, 8);

if ($execucoes === []) {
    echo "  nenhuma registrada.\n";
}

foreach ($execucoes as $e) {
    $duracao = $e['fim'] !== null ? ((int) $e['fim'] - (int) $e['inicio']) . ' s' : '—';

    printf(
        "  %s  %-4s  %-6s  %-3s jobs  %s\n",
        date('d/m H:i:s', (int) $e['inicio']),
        $e['origem'],
        $duracao,
        $e['jobs'] ?? '—',
        $e['situacao']
    );
}

// ---------------------------------------------------------------------
// Sonda
// ---------------------------------------------------------------------
if (array_key_exists('sonda', $opcoes)) {
    $segundos = min(120, max(5, (int) ($opcoes['sonda'] ?: 60)));
    $liberar = !isset($opcoes['sem-liberar']);

    echo "\nSonda de processo em segundo plano\n----------------------------------\n";
    printf("  %d s, %s liberar a conexão. Disparando pelo mesmo caminho do kick...\n", $segundos, $liberar ? 'com' : 'SEM');

    $disparo = DiagnosticoInfra::dispararSonda($segundos, $liberar);

    if ($disparo === null) {
        echo "  [ ERRO ] WORKER_TOKEN vazio no .env: a sonda usa o mesmo token do kick.\n";
        exit(1);
    }

    if (!$disparo['aceito']) {
        echo "  [ALERTA] O servidor não confirmou o pedido em 3 s. Sem a função de liberação, o\n"
            . "           LiteSpeed segura a resposta até o fim do script — era o que acontecia com o\n"
            . "           kick antes da correção. Acompanhando pelo arquivo mesmo assim...\n";
    }

    $id = $disparo['id'];

    $prazo = time() + $segundos + 15;
    $estado = ['estado' => 'aguardando'];
    $ultimoRelato = -1;

    while (time() < $prazo) {
        sleep(1);
        $estado = DiagnosticoInfra::lerSonda($id);

        if (in_array($estado['estado'], ['terminou', 'morreu'], true)) {
            break;
        }

        $viveu = (int) ($estado['viveu'] ?? 0);

        if ($viveu > 0 && $viveu % 10 === 0 && $viveu !== $ultimoRelato) {
            echo "  ... viva há {$viveu} s\n";
            $ultimoRelato = $viveu;
        }
    }

    echo "\n";

    match ($estado['estado']) {
        'terminou' => printf("  [  OK  ] Sobreviveu os %d s. O servidor deixa o trabalho em segundo plano terminar.\n", $segundos),
        'morreu' => printf(
            "  [ ERRO ] Encerrada aos %d s. Uma resposta do modelo que demore mais que isso morre no meio.\n",
            (int) $estado['viveu']
        ),
        default => print("  [ ERRO ] A sonda não chegou a rodar. O pedido foi aceito mas nada foi gravado em storage/sondas.\n"),
    };

    if (isset($estado['servidor'])) {
        printf("  servidor: %s · processo: %s · liberação: %s\n", $estado['servidor'] ?: '—', $estado['sapi'], $estado['mecanismo']);
    }

    $erros += $estado['estado'] === 'terminou' ? 0 : 1;
}

echo "\n";

exit($erros > 0 ? 1 : 0);

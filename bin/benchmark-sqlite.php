<?php

declare(strict_types=1);

/**
 * Mede, NO HOST REAL, as três coisas que decidem a viabilidade da rota SQLite.
 * Rode antes de fechar a hospedagem de um cliente:
 *
 *   php bin/benchmark-sqlite.php
 *
 * 1. Escrita concorrente — se o home estiver em storage de rede (NFS), o lock
 *    do SQLite fica lento e não confiável. É o único cenário que inviabilizaria
 *    a arquitetura inteira, e é barato descobrir cedo.
 * 2. Custo do cosseno em PHP — é o teto real da busca vetorial, e é CPU, não
 *    banco. Define quantos chunks cabem por base neste host.
 * 3. sqlite-vec carrega? — se sim, existe caminho de otimização 10–30x quando
 *    a medição pedir. Se não, o SqliteVectorStore em PHP é o piso garantido.
 *
 * Não escreve no banco da aplicação: usa um arquivo temporário próprio.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

function titulo(string $texto): void
{
    echo "\n" . $texto . "\n" . str_repeat('=', strlen($texto)) . "\n";
}

function veredito(bool $ok, string $simOk, string $seNao): void
{
    echo ($ok ? '  [OK]    ' : '  [ALERTA] ') . ($ok ? $simOk : $seNao) . "\n";
}

echo "simpleAIman — benchmark do host\n";
echo 'PHP ' . PHP_VERSION . ' · ' . PHP_OS_FAMILY . "\n";

// =====================================================================
titulo('1. Ambiente');
// =====================================================================

$dir = dirname(DB_PATH);
echo '  banco:            ' . DB_PATH . "\n";
echo '  espaco livre:     ' . formatar_bytes((int) @disk_free_space($dir)) . "\n";
echo '  memory_limit:     ' . ini_get('memory_limit') . "\n";
echo '  max_execution:    ' . ini_get('max_execution_time') . "s\n";
echo '  SQLite:           ' . (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn() . "\n";

$temFts = false;
try {
    $mem = new PDO('sqlite::memory:');
    $mem->exec('CREATE VIRTUAL TABLE t USING fts5(x)');
    $temFts = true;
} catch (Throwable $e) {
    $temFts = false;
}
veredito($temFts, 'FTS5 disponível (metade lexical da busca híbrida).', 'FTS5 AUSENTE — a busca ficaria só vetorial.');

// =====================================================================
titulo('2. Escrita concorrente (detecta storage de rede)');
// =====================================================================

$arquivo = sys_get_temp_dir() . '/simpleaiman_bench_' . getmypid() . '.sqlite';
@unlink($arquivo);

$abrir = static function (string $arquivo): PDO {
    $pdo = new PDO('sqlite:' . $arquivo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    return $pdo;
};

$a = $abrir($arquivo);
$a->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

// Uma transação por turno de conversa é o padrão real da aplicação.
$turnos = 200;
$stmt = $a->prepare('INSERT INTO t (v) VALUES (?)');

$inicio = microtime(true);
for ($i = 0; $i < $turnos; $i++) {
    $a->beginTransaction();
    // um turno grava ~6 linhas: mensagem do usuário, resposta, fontes, execuções, métrica
    for ($j = 0; $j < 6; $j++) {
        $stmt->execute(['linha ' . $i . '.' . $j]);
    }
    $a->commit();
}
$ms = (microtime(true) - $inicio) * 1000;
$porTurno = $ms / $turnos;

printf("  %d turnos (6 linhas cada) em %.0f ms → %.2f ms por turno\n", $turnos, $ms, $porTurno);
veredito(
    $porTurno < 5,
    sprintf('Escrita local rápida (%.2f ms/turno). Storage de rede improvável.', $porTurno),
    sprintf('Escrita LENTA (%.2f ms/turno). Suspeite de NFS/storage de rede — verifique com o suporte do host.', $porTurno)
);

// Segundo escritor: com busy_timeout, deve esperar em vez de estourar.
$b = $abrir($arquivo);
$busy = 0;
$inicio = microtime(true);
for ($i = 0; $i < 50; $i++) {
    try {
        $a->exec("INSERT INTO t (v) VALUES ('a')");
        $b->exec("INSERT INTO t (v) VALUES ('b')");
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'locked') || str_contains($e->getMessage(), 'busy')) {
            $busy++;
        } else {
            throw $e;
        }
    }
}
printf("  2 conexões alternando 100 escritas em %.0f ms · %d SQLITE_BUSY\n", (microtime(true) - $inicio) * 1000, $busy);
veredito($busy === 0, 'Nenhum lock estourado — busy_timeout fazendo o trabalho.', "{$busy} locks estourados mesmo com busy_timeout de 5s.");

unset($a, $b);
foreach ([$arquivo, $arquivo . '-wal', $arquivo . '-shm'] as $f) {
    @unlink($f);
}

// =====================================================================
titulo('3. Custo do cosseno em PHP (teto da busca vetorial)');
// =====================================================================

/** Vetor normalizado, empacotado como float32 — mesmo formato de embeddings.vetor. */
$vetorAleatorio = static function (int $dim): string {
    $v = [];
    $soma = 0.0;
    for ($i = 0; $i < $dim; $i++) {
        $x = mt_rand(-1000, 1000) / 1000;
        $v[$i] = $x;
        $soma += $x * $x;
    }
    $norma = sqrt($soma) ?: 1.0;
    for ($i = 0; $i < $dim; $i++) {
        $v[$i] /= $norma;
    }
    return pack('f*', ...$v);
};

echo "  (vetores normalizados; o produto interno é o próprio cosseno)\n\n";
printf("  %-8s %-10s %-12s %s\n", 'dim', 'chunks', 'tempo', 'veredito');
printf("  %s\n", str_repeat('-', 52));

foreach ([768, 1536] as $dim) {
    $consulta = unpack('f*', $vetorAleatorio($dim));

    foreach ([1000, 5000, 20000] as $total) {
        // Gera um lote menor e reusa, só para medir o custo de varredura.
        $amostra = [];
        for ($i = 0; $i < 100; $i++) {
            $amostra[] = $vetorAleatorio($dim);
        }

        $inicio = microtime(true);
        $melhor = -1.0;
        for ($i = 0; $i < $total; $i++) {
            $v = unpack('f*', $amostra[$i % 100]);
            $soma = 0.0;
            for ($k = 1; $k <= $dim; $k++) {
                $soma += $consulta[$k] * $v[$k];
            }
            if ($soma > $melhor) {
                $melhor = $soma;
            }
        }
        $ms = (microtime(true) - $inicio) * 1000;

        $ok = $ms < 500;
        printf(
            "  %-8d %-10d %-12s %s\n",
            $dim,
            $total,
            sprintf('%.0f ms', $ms),
            $ok ? 'ok' : ($ms < 1500 ? 'aceitável' : 'lento')
        );
    }
    echo "\n";
}

echo "  Regra prática: acima de ~500 ms por busca, reduza a dimensão do embedding\n";
echo "  (768 em vez de 1536), pré-filtre por FTS5 antes do cosseno, ou avalie o\n";
echo "  sqlite-vec conforme o item 4.\n";

// =====================================================================
titulo('4. sqlite-vec carrega neste PHP?');
// =====================================================================

$podeCarregar = false;
$motivo = '';

// Atenção ao nome: NÃO é PDO::loadExtension(). O método vive na subclasse
// Pdo\Sqlite, introduzida no PHP 8.4, e só uma conexão criada por
// PDO::connect() é instância dela — `new PDO()` devolve um PDO puro, sem o
// método. Procurar em PDO dá falso negativo. Verificado no 8.4.21.
if (method_exists('Pdo\Sqlite', 'loadExtension')) {
    $podeCarregar = true;
    $motivo = 'Pdo\Sqlite::loadExtension() disponível (PHP >= 8.4).';
} elseif (class_exists('SQLite3') && method_exists('SQLite3', 'loadExtension')) {
    $podeCarregar = true;
    $motivo = 'Pdo\Sqlite ausente; caindo em SQLite3::loadExtension().';
} else {
    $motivo = 'Nem Pdo\Sqlite::loadExtension() (PHP >= 8.4) nem SQLite3::loadExtension() existem neste PHP.';
}

echo '  ' . $motivo . "\n";

if ($podeCarregar) {
    // A API existir não garante que o carregamento esteja habilitado — muita build
    // usa SQLITE_OMIT_LOAD_EXTENSION, e o PHP ainda desliga isso por php.ini.
    //
    // Atenção: loadExtension() sinaliza por WARNING, não por exceção. Um
    // try/catch sozinho não pega nada e o teste dá falso positivo — por isso a
    // detecção converte o warning em exceção antes de chamar.
    set_error_handler(static function (int $no, string $str): bool {
        throw new ErrorException($str, 0, $no);
    });

    try {
        if (method_exists('Pdo\Sqlite', 'loadExtension')) {
            $mem = PDO::connect('sqlite::memory:');
        } else {
            $mem = new SQLite3(':memory:');
        }

        $mem->loadExtension('inexistente_apenas_para_testar');
        echo "  Carregamento habilitado (a extensão de teste nem existe, então o silêncio já é resposta).\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();

        if (stripos($msg, 'disabled') !== false || stripos($msg, 'not authorized') !== false) {
            $podeCarregar = false;
            $motivo = 'A API existe, mas o carregamento de extensões está DESABILITADO nesta build/php.ini.';
            echo '  ' . $motivo . "\n";
        } else {
            // Falhou por não achar o arquivo: o mecanismo em si funciona.
            echo "  Carregamento habilitado (a extensão de teste falhou por não existir, como esperado).\n";
        }
    } finally {
        restore_error_handler();
    }
}

veredito(
    $podeCarregar,
    'sqlite-vec é viável neste host — vira opção se a medição do item 3 pedir.',
    'sqlite-vec indisponível. SqliteVectorStore em PHP é o caminho (e é o piso garantido).'
);

echo "\n  Lembrete: sqlite-vec NÃO faz busca aproximada (ANN). É a mesma força bruta,\n";
echo "  só que em C com SIMD. Ganho de constante, não de escalabilidade — e o custo\n";
echo "  é passar a versionar binário por arquitetura. Ver ARQUITETURA.md §8.\n";

echo "\nBenchmark concluído.\n";

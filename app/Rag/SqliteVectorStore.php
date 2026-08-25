<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Database;
use PDO;

/**
 * Busca vetorial por força bruta, em PHP, sobre BLOBs no SQLite.
 *
 * Sem índice aproximado: compara a pergunta com TODOS os vetores da base.
 * Medido nesta máquina, por busca: 768 dimensões levam ~230 ms com 5 mil
 * chunks e ~920 ms com 20 mil; 1536 dimensões dobram esses números. Para o
 * volume de um cliente (FAQ + editais + tabelas) sobra folga.
 *
 * Três decisões que sustentam esse desempenho:
 *
 *  1. Vetor gravado como float32 (`pack('f*')`), metade do espaço de float64.
 *  2. Norma pré-calculada na ingestão, para o cosseno não recalcular a
 *     magnitude de cada documento em toda busca.
 *  3. Leitura em CURSOR, um registro por vez. Um `fetchAll()` de 20 mil
 *     vetores traria 60 MB de BLOB para a memória de uma vez, o que derruba
 *     o processo num host com 128 MB.
 *
 * Quando o volume crescer, a saída é o pré-filtro lexical (ver `Retriever`)
 * e depois o sqlite-vec — a mesma força bruta, só que em C.
 */
final class SqliteVectorStore implements VectorStore
{
    public function similares(array $consulta, array $bases, int $k = 5, ?array $candidatos = null): array
    {
        if ($consulta === [] || $bases === []) {
            return [];
        }

        // Pré-filtro sem nenhum candidato significa que a etapa lexical não
        // achou nada. Varrer a base inteira aqui contrariaria o pedido de
        // quem chamou, então devolvemos vazio e deixamos a decisão com ele.
        if ($candidatos !== null && $candidatos === []) {
            return [];
        }

        $normaConsulta = 0.0;

        foreach ($consulta as $v) {
            $normaConsulta += $v * $v;
        }

        $normaConsulta = sqrt($normaConsulta) ?: 1.0;

        $dimensao = count($consulta);

        // Índice base 1 para casar com o que `unpack('f*')` devolve, evitando
        // um array_values() por vetor comparado.
        $q = [];
        foreach ($consulta as $i => $v) {
            $q[$i + 1] = $v;
        }

        $placeholdersBase = implode(',', array_fill(0, count($bases), '?'));
        $sql = 'SELECT chunk_id, vetor, norma FROM embeddings WHERE base_id IN (' . $placeholdersBase . ')';
        $params = array_map('intval', $bases);

        if ($candidatos !== null) {
            $sql .= ' AND chunk_id IN (' . implode(',', array_fill(0, count($candidatos), '?')) . ')';
            $params = array_merge($params, array_map('intval', $candidatos));
        }

        // Só compara vetor da mesma dimensão. Base reindexada pela metade,
        // ou com o modelo trocado no meio, teria vetores de tamanhos
        // diferentes — e o produto interno entre eles não significa nada.
        $sql .= ' AND dimensoes = ?';
        $params[] = $dimensao;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        $melhores = [];
        $piorDoTopo = -INF;

        while (($linha = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $v = unpack('f*', (string) $linha['vetor']);

            if ($v === false) {
                continue;
            }

            $produto = 0.0;

            for ($i = 1; $i <= $dimensao; $i++) {
                $produto += $q[$i] * $v[$i];
            }

            $score = $produto / ($normaConsulta * ((float) $linha['norma'] ?: 1.0));

            // Só entra no topo quem supera o pior colocado. Evita ordenar a
            // base inteira: guardamos no máximo k+1 elementos.
            if (count($melhores) < $k) {
                $melhores[] = ['chunk_id' => (int) $linha['chunk_id'], 'score' => $score];

                if (count($melhores) === $k) {
                    usort($melhores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
                    $piorDoTopo = $melhores[$k - 1]['score'];
                }

                continue;
            }

            if ($score <= $piorDoTopo) {
                continue;
            }

            $melhores[$k - 1] = ['chunk_id' => (int) $linha['chunk_id'], 'score' => $score];
            usort($melhores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $piorDoTopo = $melhores[$k - 1]['score'];
        }

        usort($melhores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($melhores, 0, $k);
    }

    public function contar(array $bases): int
    {
        if ($bases === []) {
            return 0;
        }

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM embeddings WHERE base_id IN (' . implode(',', array_fill(0, count($bases), '?')) . ')'
        );
        $stmt->execute(array_map('intval', $bases));

        return (int) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Database;
use PDO;
use SimpleAIman\Llm\ProviderFactory;

/**
 * Busca híbrida: lexical (FTS5/BM25) + semântica (cosseno), fundidas por RRF.
 *
 * As duas se cobrem em pontos opostos, e é por isso que valem mais juntas:
 *
 *  - O FTS5 acerta o termo exato — número de artigo, sigla, nome de curso —
 *    mas NÃO faz stemming de português: buscar "matricula" não encontra
 *    "rematrícula" (verificado). Acento ele resolve; radicalização, não.
 *  - O cosseno entende que "quanto custa" e "valor da mensalidade" são a
 *    mesma pergunta, mas erra quando tudo depende de um código literal.
 *
 * A fusão é Reciprocal Rank Fusion: cada lista contribui 1/(K + posição).
 * Usa POSIÇÃO e não a nota bruta de propósito — BM25 e cosseno vivem em
 * escalas incomparáveis, e normalizar uma para a outra seria inventar
 * equivalência que não existe.
 */
final class Retriever
{
    /**
     * Constante do RRF. 60 é o valor consagrado na literatura: alto o
     * bastante para que o 1º lugar não domine os demais, baixo o bastante
     * para a posição ainda importar.
     */
    private const RRF_K = 60;

    /**
     * Quantos candidatos cada método traz antes da fusão.
     *
     * Maior que o `top_k` final de propósito: um documento que ficou em 8º
     * no cosseno e em 2º no lexical merece subir na fusão, e isso só é
     * possível se ele estiver nas duas listas.
     */
    private const CANDIDATOS = 30;

    /**
     * Tempo da última busca, em milissegundos, separado por etapa.
     *
     * Exposto aqui porque medir de fora exigiria embeddar a pergunta duas
     * vezes — uma para cronometrar e outra dentro da busca —, dobrando a
     * chamada de rede, que é justamente a parte cara. A separação importa:
     * numa base pequena o embedding responde por mais de 99% do tempo, e sem
     * ver isso alguém otimizaria o cosseno achando que é o gargalo.
     *
     * @var array{embedding: float, vetorial: float, lexical: float, total: float}
     */
    public array $tempos = ['embedding' => 0.0, 'vetorial' => 0.0, 'lexical' => 0.0, 'total' => 0.0];

    public function __construct(
        private readonly VectorStore $store = new SqliteVectorStore(),
    ) {
    }

    /**
     * @param list<int> $bases
     * @return list<array{
     *     chunk_id: int, conteudo: string, metadados: array<string,mixed>,
     *     artefato: string, base: string, score: float,
     *     pos_vetorial: int|null, score_vetorial: float|null,
     *     pos_lexical: int|null, score_lexical: float|null
     * }>
     */
    public function buscar(
        string $pergunta,
        array $bases,
        int $k = 5,
        float $limiar = 0.0,
        bool $usarLexical = true,
        bool $usarVetorial = true,
        ?array $vetorPronto = null,
    ): array {
        $pergunta = trim($pergunta);
        $this->tempos = ['embedding' => 0.0, 'vetorial' => 0.0, 'lexical' => 0.0, 'total' => 0.0];

        if ($pergunta === '' || $bases === []) {
            return [];
        }

        $inicioTotal = microtime(true);

        $marco = microtime(true);
        $lexical = $usarLexical ? $this->lexical($pergunta, $bases, self::CANDIDATOS) : [];
        $this->tempos['lexical'] = (microtime(true) - $marco) * 1000;

        $marco = microtime(true);
        $vetorial = $usarVetorial ? $this->vetorial($pergunta, $bases, self::CANDIDATOS, $vetorPronto) : [];
        $this->tempos['vetorial'] = (microtime(true) - $marco) * 1000 - $this->tempos['embedding'];

        $this->tempos['total'] = (microtime(true) - $inicioTotal) * 1000;

        $fundido = $this->fundir($vetorial, $lexical);

        $fundido = $this->cortar($fundido, $limiar);

        return $this->hidratar(array_slice($fundido, 0, $k));
    }

    /**
     * Margem, em pontos de cosseno, abaixo do melhor resultado.
     *
     * Medido em 2026-08-24 com `gemini-embedding-001`: as notas são
     * COMPRIMIDAS e dependem de como a pergunta foi escrita. O mesmo trecho
     * correto pontua 0.77 numa pergunta bem formada ("qual o valor da
     * mensalidade de Direito?") e 0.68 na versão coloquial ("quais cursos vcs
     * tem"), enquanto trechos irrelevantes chegam a 0.66. As faixas se
     * sobrepõem, e por isso NENHUM corte absoluto separa bem.
     *
     * O ranqueamento, porém, é bom: o trecho certo ficou em 1º em todas as
     * perguntas testadas. Daí a estratégia — cortar em relação ao melhor da
     * própria busca, que se ajusta sozinho à formulação da pergunta.
     */
    private const MARGEM_RELATIVA = 0.05;

    /**
     * Aplica dois cortes de naturezas diferentes.
     *
     * O `limiar` age como PISO ABSOLUTO: existe para descartar busca que não
     * casou com nada, não para escolher entre bons resultados.
     *
     * A margem relativa é quem faz a seleção fina: mantém o melhor resultado
     * e quem chegou perto dele. Um corte só absoluto derrubava respostas
     * corretas de perguntas informais — foi o que aconteceu com "quais cursos
     * vcs tem", cujo trecho certo pontuou 0.683 contra um limiar de 0.70.
     *
     * @param list<array<string, mixed>> $fundido
     * @return list<array<string, mixed>>
     */
    private function cortar(array $fundido, float $limiar): array
    {
        if ($fundido === []) {
            return [];
        }

        if ($limiar > 0.0) {
            $fundido = array_values(array_filter(
                $fundido,
                static fn (array $r): bool => $r['score_vetorial'] === null || $r['score_vetorial'] >= $limiar
            ));
        }

        $notas = array_filter(array_column($fundido, 'score_vetorial'), static fn ($v): bool => $v !== null);

        if ($notas === []) {
            return $fundido;
        }

        $piso = max($notas) - self::MARGEM_RELATIVA;

        return array_values(array_filter(
            $fundido,
            // Resultado só lexical (sem nota de cosseno) passa: ele veio de
            // casamento de termo exato, que é justamente o que a semântica
            // costuma errar.
            static fn (array $r): bool => $r['score_vetorial'] === null || $r['score_vetorial'] >= $piso
        ));
    }

    /**
     * Metade semântica.
     *
     * A pergunta é embeddada com RETRIEVAL_QUERY, enquanto os chunks foram
     * indexados com RETRIEVAL_DOCUMENT. São espaços otimizados diferentes;
     * usar o mesmo dos dois lados custa recall de graça.
     *
     * @return list<array{chunk_id: int, score: float}>
     */
    private function vetorial(string $pergunta, array $bases, int $quantos, ?array $vetorPronto = null): array
    {
        // Vetor reaproveitado quando o chamador já embeddou a pergunta.
        //
        // Sem isso, um turno com FAQ e RAG ligados embedda a MESMA frase duas
        // vezes — medido: 589 ms jogados fora em cada turno, mais que o dobro
        // do custo somado de toda a busca. A chamada de rede é a parte cara;
        // repeti-la é o erro mais fácil de cometer aqui.
        if ($vetorPronto !== null && $vetorPronto !== []) {
            return $this->store->similares($vetorPronto, $bases, $quantos);
        }

        $provedorId = $this->provedorDaBase($bases);

        $fabrica = $provedorId !== null
            ? ProviderFactory::porId($provedorId)
            : ProviderFactory::padraoEmbedding();

        $marco = microtime(true);
        $vetor = $fabrica->embeddings(ProviderFactory::TAREFA_CONSULTAR)->embedText($pergunta);
        $this->tempos['embedding'] = (microtime(true) - $marco) * 1000;

        return $this->store->similares($vetor, $bases, $quantos);
    }

    /**
     * Metade lexical, por BM25 do FTS5.
     *
     * O bm25() do SQLite devolve valor NEGATIVO, e mais negativo é melhor.
     * Invertemos o sinal para que "maior é melhor" valha nas duas listas —
     * uma inversão esquecida aqui inverteria silenciosamente o ranking.
     *
     * @return list<array{chunk_id: int, score: float}>
     */
    private function lexical(string $pergunta, array $bases, int $quantos): array
    {
        $consulta = $this->prepararConsultaFts($pergunta);

        if ($consulta === '') {
            return [];
        }

        $sql = 'SELECT c.id AS chunk_id, -bm25(chunks_fts) AS score
                FROM chunks_fts
                JOIN chunks c ON c.id = chunks_fts.rowid
                WHERE chunks_fts MATCH ? AND c.base_id IN ('
                    . implode(',', array_fill(0, count($bases), '?')) . ')
                ORDER BY score DESC LIMIT ?';

        $stmt = Database::connection()->prepare($sql);
        $params = array_merge([$consulta], array_map('intval', $bases), [$quantos]);

        try {
            $stmt->execute($params);
        } catch (\PDOException) {
            // Sintaxe do FTS5 é exigente e a pergunta vem do usuário final.
            // Uma busca lexical que falha não pode derrubar a resposta —
            // a metade semântica dá conta sozinha.
            return [];
        }

        $saida = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[] = ['chunk_id' => (int) $l['chunk_id'], 'score' => (float) $l['score']];
        }

        return $saida;
    }

    /**
     * Converte a pergunta em expressão do FTS5.
     *
     * Aspas, asteriscos e operadores (`AND`, `NEAR`, `^`) têm significado
     * sintático ali. Uma pergunta comum como `posso me matricular "no meio"
     * do semestre?` quebraria a consulta — então cada termo vira uma unidade
     * entre aspas, e os termos são unidos por OR: casar com parte da
     * pergunta é suficiente, já que a fusão cuida do ranqueamento.
     */
    private function prepararConsultaFts(string $pergunta): string
    {
        $limpa = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $pergunta) ?? $pergunta;
        $termos = preg_split('/\s+/u', trim($limpa), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Palavra de uma letra só produz ruído; duas ainda pega siglas como UF.
        $termos = array_filter($termos, static fn (string $t): bool => mb_strlen($t) >= 2);

        if ($termos === []) {
            return '';
        }

        $termos = array_slice($termos, 0, 12);

        return implode(' OR ', array_map(static fn (string $t): string => '"' . $t . '"', $termos));
    }

    /**
     * Reciprocal Rank Fusion.
     *
     * @param list<array{chunk_id: int, score: float}> $vetorial
     * @param list<array{chunk_id: int, score: float}> $lexical
     * @return list<array<string, mixed>>
     */
    private function fundir(array $vetorial, array $lexical): array
    {
        $acumulado = [];

        foreach ([['vetorial', $vetorial], ['lexical', $lexical]] as [$origem, $lista]) {
            foreach ($lista as $posicao => $item) {
                $id = $item['chunk_id'];

                $acumulado[$id] ??= [
                    'chunk_id' => $id,
                    'score' => 0.0,
                    'pos_vetorial' => null,
                    'score_vetorial' => null,
                    'pos_lexical' => null,
                    'score_lexical' => null,
                ];

                $acumulado[$id]['score'] += 1 / (self::RRF_K + $posicao + 1);
                $acumulado[$id]['pos_' . $origem] = $posicao + 1;
                $acumulado[$id]['score_' . $origem] = $item['score'];
            }
        }

        $lista = array_values($acumulado);

        usort($lista, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $lista;
    }

    /**
     * @param list<array<string, mixed>> $resultados
     * @return list<array<string, mixed>>
     */
    private function hidratar(array $resultados): array
    {
        if ($resultados === []) {
            return [];
        }

        $ids = array_column($resultados, 'chunk_id');

        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.conteudo, c.metadados, a.titulo AS artefato, b.nome AS base
             FROM chunks c
             JOIN artefatos a ON a.id = c.artefato_id
             JOIN bases b ON b.id = c.base_id
             WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        $porId = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $porId[(int) $l['id']] = $l;
        }

        $saida = [];

        foreach ($resultados as $r) {
            $dados = $porId[$r['chunk_id']] ?? null;

            // Chunk apagado entre a busca e a hidratação: some do resultado
            // em vez de virar item vazio na resposta.
            if ($dados === null) {
                continue;
            }

            $saida[] = $r + [
                'conteudo' => (string) $dados['conteudo'],
                'metadados' => json_para_array($dados['metadados']),
                'artefato' => (string) $dados['artefato'],
                'base' => (string) $dados['base'],
            ];
        }

        return $saida;
    }

    /** @param list<int> $bases */
    private function provedorDaBase(array $bases): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT provedor_embedding_id FROM bases
             WHERE id IN (' . implode(',', array_fill(0, count($bases), '?')) . ')
               AND provedor_embedding_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute(array_map('intval', $bases));

        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    /** @param list<int> $bases */
    public function totalIndexado(array $bases): int
    {
        return $this->store->contar($bases);
    }
}

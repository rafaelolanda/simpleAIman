<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

/**
 * Busca por similaridade.
 *
 * Existe para que NENHUM cálculo de similaridade seja escrito fora daqui.
 * É essa disciplina que torna sqlite-vec e pgvector uma troca de classe em
 * vez de uma reescrita — ver ARQUITETURA.md §8. Um `SELECT` com cosseno
 * espalhado por um controlador anula a abstração inteira.
 */
interface VectorStore
{
    /**
     * Chunks mais próximos do vetor da pergunta.
     *
     * @param list<float> $consulta vetor da pergunta
     * @param list<int> $bases      restringe às bases indicadas
     * @param list<int>|null $candidatos ids de chunk aos quais limitar a
     *        varredura (pré-filtro lexical). null = varre a base inteira.
     * @return list<array{chunk_id: int, score: float}> ordenado por score desc
     */
    public function similares(array $consulta, array $bases, int $k = 5, ?array $candidatos = null): array;

    /** Quantos vetores existem nas bases indicadas — dimensiona o custo da busca. */
    public function contar(array $bases): int;
}

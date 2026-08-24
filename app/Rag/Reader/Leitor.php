<?php

declare(strict_types=1);

namespace SimpleAIman\Rag\Reader;

use Generator;

/**
 * Extrai texto de um arquivo, em PEDAÇOS.
 *
 * Devolve um Generator e não uma string por um motivo prático: um PDF de 300
 * páginas concatenado em memória derruba o processo num host com 128–256 MB,
 * que é o alvo de deploy. Cada pedaço vem com seus metadados (página, seção),
 * que viram `chunks.metadados` e permitem citar a fonte com precisão.
 */
interface Leitor
{
    /** Extensões que este leitor atende, em minúsculas e sem ponto. */
    public function extensoes(): array;

    /**
     * @return Generator<int, array{texto: string, metadados: array<string, mixed>}>
     */
    public function ler(string $caminho): Generator;
}

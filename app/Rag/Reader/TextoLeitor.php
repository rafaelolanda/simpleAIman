<?php

declare(strict_types=1);

namespace SimpleAIman\Rag\Reader;

use Generator;
use RuntimeException;

/**
 * Texto puro e Markdown.
 *
 * No Markdown, corta por cabeçalho em vez de por tamanho: um documento bem
 * escrito já vem dividido por assunto, e respeitar essa divisão produz chunk
 * com contexto próprio — muito melhor que fatiar no meio de uma seção. O
 * título vai nos metadados e serve de citação.
 */
final class TextoLeitor implements Leitor
{
    public function extensoes(): array
    {
        return ['txt', 'md', 'markdown', 'csv'];
    }

    public function ler(string $caminho): Generator
    {
        $conteudo = file_get_contents($caminho);

        if ($conteudo === false) {
            throw new RuntimeException("Não foi possível ler {$caminho}.");
        }

        $conteudo = $this->normalizar($conteudo);
        $extensao = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

        if (!in_array($extensao, ['md', 'markdown'], true)) {
            yield ['texto' => $conteudo, 'metadados' => []];
            return;
        }

        $secao = '';
        $titulo = null;
        $nivel = 0;

        foreach (explode("\n", $conteudo) as $linha) {
            if (preg_match('/^(#{1,6})\s+(.+)$/', $linha, $m)) {
                if (trim($secao) !== '') {
                    yield ['texto' => trim($secao), 'metadados' => array_filter(['secao' => $titulo, 'nivel' => $nivel])];
                }

                $titulo = trim($m[2]);
                $nivel = strlen($m[1]);
                $secao = $linha . "\n";
                continue;
            }

            $secao .= $linha . "\n";
        }

        if (trim($secao) !== '') {
            yield ['texto' => trim($secao), 'metadados' => array_filter(['secao' => $titulo, 'nivel' => $nivel])];
        }
    }

    private function normalizar(string $texto): string
    {
        // BOM atrapalha a primeira busca e é invisível na inspeção manual.
        $texto = preg_replace('/^\x{FEFF}/u', '', $texto) ?? $texto;

        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
        }

        return str_replace(["\r\n", "\r"], "\n", $texto);
    }
}

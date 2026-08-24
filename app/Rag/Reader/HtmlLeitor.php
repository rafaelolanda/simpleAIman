<?php

declare(strict_types=1);

namespace SimpleAIman\Rag\Reader;

use Generator;
use RuntimeException;

/**
 * HTML: remove script, style, nav, header e rodapé antes de extrair o texto.
 *
 * Sem essa limpeza, menu e rodapé se repetem em TODO chunk do site e passam a
 * dominar a busca — a pergunta "onde fica a secretaria" acaba casando com o
 * menu de navegação em vez do conteúdo da página.
 */
final class HtmlLeitor implements Leitor
{
    public function extensoes(): array
    {
        return ['html', 'htm'];
    }

    public function ler(string $caminho): Generator
    {
        $html = file_get_contents($caminho);

        if ($html === false) {
            throw new RuntimeException("Não foi possível ler {$caminho}.");
        }

        $texto = self::extrair($html);

        if ($texto === '') {
            throw new RuntimeException('A página não tem texto extraível.');
        }

        yield ['texto' => $texto, 'metadados' => []];
    }

    public static function extrair(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|nav|footer|header|aside)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;

        // Blocos viram quebra de linha para o chunker não colar parágrafos.
        $html = preg_replace('#</(p|div|h[1-6]|li|tr)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;

        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\n\s*\n\s*\n+/', "\n\n", $texto) ?? $texto);
    }
}

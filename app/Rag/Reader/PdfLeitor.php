<?php

declare(strict_types=1);

namespace SimpleAIman\Rag\Reader;

use Generator;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDF via smalot/pdfparser — PHP puro, sem binário externo.
 *
 * O PdfReader do Neuron usa symfony/process para chamar `pdftotext`, que não
 * existe em hospedagem compartilhada. Ver ARQUITETURA.md §1.
 *
 * Emite PÁGINA A PÁGINA, e a página vai nos metadados: é o que permite o
 * agente citar "página 12 do edital" em vez de apontar o arquivo inteiro.
 */
final class PdfLeitor implements Leitor
{
    public function extensoes(): array
    {
        return ['pdf'];
    }

    public function ler(string $caminho): Generator
    {
        try {
            $documento = (new Parser())->parseFile($caminho);
        } catch (Throwable $e) {
            throw new RuntimeException('PDF ilegível ou protegido: ' . $e->getMessage(), 0, $e);
        }

        $paginas = $documento->getPages();

        if ($paginas === []) {
            // PDF de imagem escaneada devolve zero texto. Sem esta mensagem o
            // artefato ficaria "processado com 0 chunks" e ninguém entenderia.
            throw new RuntimeException(
                'O PDF não tem texto extraível. Provavelmente é digitalizado (imagem) e precisaria de OCR.'
            );
        }

        $vazias = 0;

        foreach ($paginas as $indice => $pagina) {
            try {
                $texto = trim($pagina->getText());
            } catch (Throwable) {
                $texto = '';
            }

            if ($texto === '') {
                $vazias++;
                continue;
            }

            yield [
                'texto' => $this->limpar($texto),
                'metadados' => ['pagina' => $indice + 1],
            ];
        }

        if ($vazias === count($paginas)) {
            throw new RuntimeException(
                'Nenhuma página do PDF tem texto extraível. Provavelmente é digitalizado e precisaria de OCR.'
            );
        }
    }

    private function limpar(string $texto): string
    {
        // Hifenização de quebra de linha: "matri-\ncula" volta a ser uma
        // palavra só. Sem isso nem a busca lexical nem o embedding a reconhecem.
        $texto = preg_replace('/(\p{L})-\n(\p{L})/u', '$1$2', $texto) ?? $texto;

        // Espaço múltiplo e linha em branco em excesso são ruído típico de PDF.
        $texto = preg_replace('/[ \t]{2,}/', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto);
    }
}

<?php

declare(strict_types=1);

namespace SimpleAIman\Rag\Reader;

use Generator;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Throwable;

/**
 * DOCX via phpoffice/phpword. Exige a extensão `zip` — um .docx é um zip.
 *
 * Segue os títulos do documento como delimitador de seção, pela mesma razão
 * do Markdown: a divisão que o autor fez é melhor do que a que cortaríamos
 * por contagem de caracteres.
 */
final class DocxLeitor implements Leitor
{
    public function extensoes(): array
    {
        return ['docx'];
    }

    public function ler(string $caminho): Generator
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('A extensão PHP `zip` não está habilitada — sem ela não dá para ler .docx.');
        }

        try {
            $doc = IOFactory::load($caminho);
        } catch (Throwable $e) {
            throw new RuntimeException('DOCX ilegível: ' . $e->getMessage(), 0, $e);
        }

        $secao = '';
        $titulo = null;

        foreach ($doc->getSections() as $s) {
            foreach ($this->percorrer($s) as [$tipo, $texto]) {
                if ($texto === '') {
                    continue;
                }

                if ($tipo === 'titulo') {
                    if (trim($secao) !== '') {
                        yield ['texto' => trim($secao), 'metadados' => array_filter(['secao' => $titulo])];
                    }

                    $titulo = $texto;
                    $secao = $texto . "\n";
                    continue;
                }

                $secao .= $texto . "\n";
            }
        }

        if (trim($secao) === '') {
            throw new RuntimeException('O documento não tem texto extraível.');
        }

        yield ['texto' => trim($secao), 'metadados' => array_filter(['secao' => $titulo])];
    }

    /** @return Generator<int, array{0: string, 1: string}> */
    private function percorrer(AbstractContainer $container): Generator
    {
        foreach ($container->getElements() as $el) {
            if ($el instanceof Title) {
                yield ['titulo', trim($this->texto($el->getText()))];
                continue;
            }

            if ($el instanceof Text) {
                yield [$this->ehTitulo($el) ? 'titulo' : 'texto', trim($el->getText())];
                continue;
            }

            if ($el instanceof TextRun) {
                yield [$this->ehTitulo($el) ? 'titulo' : 'texto', trim($this->texto($el))];
                continue;
            }

            // Tabela vira linha com células separadas por " | ". Perde o
            // layout, mas preserva a relação entre os valores — que é o que
            // importa numa tabela de cursos ou de prazos.
            if ($el instanceof Table) {
                foreach ($el->getRows() as $linha) {
                    $celulas = [];

                    foreach ($linha->getCells() as $celula) {
                        $partes = [];

                        foreach ($this->percorrer($celula) as [, $t]) {
                            if ($t !== '') {
                                $partes[] = $t;
                            }
                        }

                        $celulas[] = implode(' ', $partes);
                    }

                    $texto = trim(implode(' | ', $celulas), ' |');

                    if ($texto !== '') {
                        yield ['texto', $texto];
                    }
                }

                continue;
            }

            if ($el instanceof AbstractContainer) {
                yield from $this->percorrer($el);
            }
        }
    }

    /**
     * O elemento é um cabeçalho?
     *
     * Ao LER um .docx, o PhpWord raramente devolve objetos `Title`: um
     * parágrafo com estilo de título volta como `TextRun` comum, e o que
     * identifica o cabeçalho é o nome do estilo de parágrafo (`Heading1`,
     * `Título 1`, conforme o idioma do Word que gerou o arquivo). Confiar só
     * na classe `Title` deixaria a detecção sem efeito em documento real.
     */
    private function ehTitulo(object $elemento): bool
    {
        if (!method_exists($elemento, 'getParagraphStyle')) {
            return false;
        }

        $estilo = $elemento->getParagraphStyle();

        $nome = match (true) {
            is_string($estilo) => $estilo,
            is_object($estilo) && method_exists($estilo, 'getStyleName') => (string) $estilo->getStyleName(),
            default => '',
        };

        return $nome !== '' && preg_match('/^(heading|t[íi]tulo|titre|berschrift)\s*\d*/iu', $nome) === 1;
    }

    private function texto(mixed $elemento): string
    {
        if (is_string($elemento)) {
            return $elemento;
        }

        if (!$elemento instanceof AbstractContainer) {
            return '';
        }

        $partes = [];

        foreach ($elemento->getElements() as $filho) {
            if ($filho instanceof Text) {
                $partes[] = $filho->getText();
            }
        }

        return implode('', $partes);
    }
}

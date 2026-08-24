<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Generator;

/**
 * Corta texto em pedaços do tamanho certo para embeddar.
 *
 * A regra é cortar sempre na maior fronteira disponível, nesta ordem:
 * parágrafo → frase → palavra. Cortar no meio de uma frase produz chunk que
 * começa em "…e o prazo termina em" — inútil tanto para o embedding quanto
 * para exibir como citação.
 *
 * `sobreposicao` repete o fim do chunk anterior no começo do seguinte. É o
 * que evita perder a resposta que cai exatamente na emenda: a pergunta casa
 * com um chunk que tem a frase inteira, em vez de com dois pela metade.
 */
final class Chunker
{
    public function __construct(
        private readonly int $tamanho = 800,
        private readonly int $sobreposicao = 120,
    ) {
    }

    /**
     * @param array<string, mixed> $metadados herdados do leitor (página, seção)
     * @return Generator<int, array{texto: string, metadados: array<string, mixed>}>
     */
    public function dividir(string $texto, array $metadados = []): Generator
    {
        $texto = trim($this->normalizar($texto));

        if ($texto === '') {
            return;
        }

        if (mb_strlen($texto) <= $this->tamanho) {
            yield ['texto' => $texto, 'metadados' => $metadados];
            return;
        }

        $inicio = 0;
        $total = mb_strlen($texto);
        $anterior = '';

        while ($inicio < $total) {
            $bruto = mb_substr($texto, $inicio, $this->tamanho);

            // Só procura fronteira se ainda houver texto depois — o último
            // pedaço não deve ser encurtado à toa.
            $fim = ($inicio + $this->tamanho) < $total
                ? $this->fronteira($bruto)
                : mb_strlen($bruto);

            $pedaco = trim(mb_substr($bruto, 0, $fim));

            if ($pedaco !== '') {
                $prefixo = $anterior !== '' ? $anterior . ' ' : '';
                yield ['texto' => $prefixo . $pedaco, 'metadados' => $metadados];

                $anterior = $this->sobreposicao > 0
                    ? trim(mb_substr($pedaco, -$this->sobreposicao))
                    : '';
            }

            // Avanço mínimo de 1: sem isso, um texto sem nenhuma fronteira
            // encontrável faria o laço girar para sempre.
            $inicio += max(1, $fim);
        }
    }

    /**
     * Posição do melhor ponto de corte dentro do pedaço.
     *
     * Procura da metade em diante: uma fronteira logo no início cortaria um
     * chunk minúsculo e desperdiçaria a janela.
     */
    private function fronteira(string $pedaco): int
    {
        $tamanho = mb_strlen($pedaco);
        $minimo = (int) ($tamanho * 0.5);

        foreach (["\n\n", '. ', '! ', '? ', ";\n", "\n", ' '] as $marca) {
            $pos = mb_strrpos($pedaco, $marca);

            if ($pos !== false && $pos >= $minimo) {
                return $pos + mb_strlen($marca);
            }
        }

        return $tamanho;
    }

    private function normalizar(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);

        return preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto;
    }

    /**
     * Estimativa de tokens, sem depender de tokenizador.
     *
     * ~4 caracteres por token é a média boa o bastante para dimensionar lote
     * e custo. Precisão real exigiria a lib do fornecedor, que muda por
     * modelo — não vale a dependência para um número que só orienta.
     */
    public static function tokensAproximados(string $texto): int
    {
        return (int) ceil(mb_strlen($texto) / 4);
    }
}

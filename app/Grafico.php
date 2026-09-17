<?php

declare(strict_types=1);

// Classe GLOBAL, como Painel, DiagnosticoInfra e Mapa: o autoload do composer
// mapeia apenas os subnamespaces.

/**
 * Gráfico de barras em SVG, montado no servidor.
 *
 * ## Uma série por gráfico, e nunca dois eixos
 *
 * Conversas e mensagens vivem em ordens de grandeza diferentes — uma conversa
 * tem dezenas de mensagens. Empilhar as duas no mesmo eixo esconde a menor; dar
 * um eixo para cada é o erro clássico de painel, porque a posição relativa das
 * duas linhas passa a depender da escala escolhida, e não do dado. Aqui a saída
 * é a honesta: um gráfico por medida, lado a lado, cada um com seu próprio
 * máximo.
 *
 * ## Sem biblioteca, como o resto do painel
 *
 * Mesma razão do `Mapa`: o desenho é simples, e uma dependência de JS traria
 * build e CDN para um projeto cujo deploy é `git pull`. O SVG sai pronto do
 * servidor e escala com a largura do cartão.
 *
 * ## O que é deliberado no desenho
 *
 * - Valor escrito só no MAIOR e no ÚLTIMO ponto. Número em cima de toda barra
 *   vira ruído e some na leitura — o resto lê-se pela altura, e o detalhe exato
 *   está na tabela que acompanha o gráfico.
 * - Uma linha de grade só, no topo, com o valor do máximo. Grade cheia compete
 *   com o dado.
 * - Cada barra carrega um `<title>`: passar o mouse diz o dia e o número, sem
 *   depender de JS.
 * - Barra com valor zero desenha um traço de 2px no lugar de nada, senão o dia
 *   vazio some e o eixo parece ter menos dias do que tem.
 */
final class Grafico
{
    private const LARGURA = 620;
    private const ALTURA = 150;
    private const TOPO = 22;
    private const BASE = 124;

    /**
     * @param array<string, int> $serie data (Y-m-d) => valor, em ordem cronológica
     */
    public static function barras(array $serie, string $unidade = ''): string
    {
        if ($serie === []) {
            return '';
        }

        $valores = array_values($serie);
        $dias = array_keys($serie);
        $maximo = max($valores);
        $quantos = count($valores);

        // Escala com piso 1: com tudo zerado, dividir pelo máximo estouraria.
        $escala = static fn (int $v): float => $maximo > 0 ? ($v / $maximo) : 0.0;

        $vaoTotal = self::LARGURA - 8;
        $passo = $vaoTotal / $quantos;
        $larguraBarra = max(3.0, $passo - 6);

        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" class="gr" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">',
            self::LARGURA,
            self::ALTURA,
            e('Barras por dia' . ($unidade !== '' ? ' — ' . $unidade : ''))
        );

        // Grade: só o teto e a base. O resto seria concorrência com o dado.
        $svg .= sprintf('<line x1="0" y1="%d" x2="%d" y2="%d" class="gr-grade"/>', self::TOPO, self::LARGURA, self::TOPO);
        $svg .= sprintf('<line x1="0" y1="%d" x2="%d" y2="%d" class="gr-eixo"/>', self::BASE, self::LARGURA, self::BASE);
        $svg .= sprintf('<text x="2" y="%d" class="gr-escala">%s</text>', self::TOPO - 6, e((string) $maximo));

        $ultimo = $quantos - 1;
        $indiceMaximo = array_search($maximo, $valores, true);

        foreach ($valores as $i => $valor) {
            $altura = max(2.0, $escala($valor) * (self::BASE - self::TOPO));
            $x = 4 + $i * $passo + ($passo - $larguraBarra) / 2;
            $y = self::BASE - $altura;
            $dia = date('d/m', strtotime($dias[$i]));

            $svg .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" class="gr-barra%s">'
                . '<title>%s · %d%s</title></rect>',
                $x,
                $y,
                $larguraBarra,
                $altura,
                $valor === 0 ? ' vazia' : '',
                $dia,
                $valor,
                $unidade !== '' ? ' ' . $unidade : ''
            );

            // Rótulo do dia a cada dois, para não empilhar texto em 14 barras.
            if ($i % 2 === 0 || $i === $ultimo) {
                $svg .= sprintf(
                    '<text x="%.1f" y="%d" class="gr-dia" text-anchor="middle">%s</text>',
                    $x + $larguraBarra / 2,
                    self::BASE + 16,
                    e(date('d', strtotime($dias[$i])))
                );
            }

            // Valor só no maior e no último: os dois pontos que se procura.
            if (($i === $indiceMaximo && $maximo > 0) || ($i === $ultimo && $valor > 0 && $i !== $indiceMaximo)) {
                $svg .= sprintf(
                    '<text x="%.1f" y="%.1f" class="gr-valor" text-anchor="middle">%d</text>',
                    $x + $larguraBarra / 2,
                    $y - 5,
                    $valor
                );
            }
        }

        return $svg . '</svg>';
    }

    /**
     * Preenche os dias sem registro com zero.
     *
     * A métrica só grava o dia em que algo aconteceu. Sem completar a série, um
     * fim de semana parado desaparece do eixo e o gráfico mente sobre o ritmo:
     * seis dias de movimento parecem seis dias seguidos.
     *
     * @param array<string, array<string, int>> $serie saída de Metrics::serieDiaria()
     * @return array<string, int>
     */
    public static function completar(array $serie, string $tipo, int $dias = 14): array
    {
        $saida = [];

        for ($i = $dias - 1; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-{$i} day"));
            $saida[$data] = (int) ($serie[$data][$tipo] ?? 0);
        }

        return $saida;
    }
}

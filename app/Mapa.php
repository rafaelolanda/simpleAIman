<?php

declare(strict_types=1);

// Classe GLOBAL, como Painel e DiagnosticoInfra: o autoload do composer mapeia
// apenas os subnamespaces (SimpleAIman\Llm e afins), e criar um so para esta
// classe exigiria regerar o autoloader num projeto cujo deploy e `git pull`.

/**
 * Desenha um grafo em camadas como SVG, no servidor.
 *
 * Existe porque duas telas do painel precisam do MESMO desenho com dados
 * diferentes — o mapa da configuração (provedores, agentes, canais, bases,
 * ferramentas) e o do atendimento (setores e quem atende). A primeira versão
 * tinha o layout embutido na tela; a segunda cópia seria o começo de duas
 * verdades sobre como uma caixa é posicionada.
 *
 * ## Por que sem biblioteca
 *
 * O grafo é pequeno e em camadas: uma instalação típica tem 1 a 3 agentes e
 * meia dúzia de setores. Isso dispensa motor de layout, e manter o desenho em
 * PHP preserva a premissa de deploy do projeto — `git pull`, sem build e sem
 * CDN. Ver ARQUITETURA.md §1.
 *
 * ## Como se usa
 *
 *   $mapa = new Mapa([20, 290, 560]);
 *   $mapa->coluna(0, $provedores);        // nós, na ordem de cima para baixo
 *   $mapa->coluna(1, $agentes);
 *   $mapa->montar();                      // calcula posições e centra
 *   $mapa->ligar('prov-1', 'agente-2');   // arestas já sabem as posições
 *   echo $mapa->svg('Mapa da configuração');
 *
 * Cada nó é um array com `chave`, `titulo`, `sub`, `href`, `classe`, e
 * opcionalmente `inativo` e `grupo` (que vira rótulo dentro da coluna).
 */
final class Mapa
{
    public const LARGURA_NO = 210;
    public const ALTURA_NO = 56;
    public const ESPACO = 16;
    public const ALTURA_ROTULO = 30;

    /** @var array<int, list<array<string, mixed>>> */
    private array $colunas = [];

    /** @var array<string, array<string, mixed>> */
    private array $porChave = [];

    /** @var list<array{texto: string, x: int, y: int}> */
    private array $rotulos = [];

    /** @var list<array{d: string, fraca: bool, seta: bool}> */
    private array $arestas = [];

    /** @var array<string, true> */
    private array $comAlerta = [];

    private int $altura = 0;

    private int $largura = 0;

    /** @param list<int> $colunasX posição horizontal de cada coluna */
    public function __construct(private readonly array $colunasX)
    {
    }

    /**
     * @param list<array<string, mixed>> $itens
     */
    public function coluna(int $indice, array $itens): void
    {
        $this->colunas[$indice] = $itens;
    }

    /** Marca um nó como problemático: ele ganha borda de alerta. */
    public function alertar(string $chave): void
    {
        $this->comAlerta[$chave] = true;
    }

    /**
     * Calcula as posições.
     *
     * Centra cada coluna pela mais alta. Alinhadas pelo topo, uma coluna de
     * duas caixas ao lado de outra com doze deixa as ligações em diagonal
     * atravessando o desenho — e o desenho existe para ser lido de uma olhada.
     */
    public function montar(): void
    {
        $alturas = [];

        foreach ($this->colunas as $indice => $itens) {
            $alturas[$indice] = $this->alturaDaColuna($itens);
        }

        $maisAlta = $alturas === [] ? 0 : max($alturas);

        foreach ($this->colunas as $indice => $itens) {
            $x = $this->colunasX[$indice] ?? 20;
            $y = 40 + (int) (($maisAlta - $alturas[$indice]) / 2);
            $grupoAnterior = null;
            $posicionados = [];

            foreach ($itens as $item) {
                $grupo = $item['grupo'] ?? null;

                if ($grupo !== null && $grupo !== $grupoAnterior) {
                    $y += self::ALTURA_ROTULO;
                    $this->rotulos[] = ['texto' => (string) $grupo, 'x' => $x, 'y' => $y - 12];
                    $grupoAnterior = $grupo;
                }

                $item['x'] = $x;
                $item['y'] = $y;
                $posicionados[] = $item;
                $this->porChave[(string) $item['chave']] = $item;

                $y += self::ALTURA_NO + self::ESPACO;
            }

            $this->colunas[$indice] = $posicionados;
            $this->altura = max($this->altura, $y);
            $this->largura = max($this->largura, $x + self::LARGURA_NO);
        }
    }

    /** Liga dois nós de colunas diferentes, da borda direita à esquerda. */
    public function ligar(string $de, string $para, bool $fraca = false, bool $seta = false): void
    {
        if (!isset($this->porChave[$de], $this->porChave[$para])) {
            return;
        }

        $a = $this->porChave[$de];
        $b = $this->porChave[$para];

        $x1 = $a['x'] + self::LARGURA_NO;
        $y1 = $a['y'] + self::ALTURA_NO / 2;
        $x2 = $b['x'];
        $y2 = $b['y'] + self::ALTURA_NO / 2;
        $meio = ($x2 - $x1) / 2;

        $this->arestas[] = [
            'd' => sprintf('M %d %d C %d %d, %d %d, %d %d', $x1, $y1, $x1 + $meio, $y1, $x2 - $meio, $y2, $x2, $y2),
            'fraca' => $fraca,
            'seta' => $seta,
        ];
    }

    /**
     * Liga dois nós da MESMA coluna, contornando pela esquerda.
     *
     * As duas caixas têm o mesmo x, então a curva normal viraria um risco reto
     * por cima delas. O arco cresce com a distância vertical, mas é limitado ao
     * vão entre as colunas: sem o limite, dois nós distantes na lista fariam a
     * linha passar por cima da coluna anterior.
     */
    public function ligarNaColuna(string $de, string $para, bool $fraca = false, bool $seta = true): void
    {
        if (!isset($this->porChave[$de], $this->porChave[$para])) {
            return;
        }

        $a = $this->porChave[$de];
        $b = $this->porChave[$para];

        $x = $a['x'];
        $y1 = $a['y'] + self::ALTURA_NO / 2;
        $y2 = $b['y'] + self::ALTURA_NO / 2;

        $anterior = 0;

        foreach ($this->colunasX as $cx) {
            if ($cx < $x) {
                $anterior = max($anterior, $cx + self::LARGURA_NO);
            }
        }

        $vao = max(20, $x - $anterior - 8);
        $arco = min($vao, 26 + (int) (abs($y2 - $y1) / 6));

        $this->arestas[] = [
            'd' => sprintf('M %d %d C %d %d, %d %d, %d %d', $x, $y1, $x - $arco, $y1, $x - $arco, $y2, $x, $y2),
            'fraca' => $fraca,
            'seta' => $seta,
        ];
    }

    /** O desenho pronto. */
    public function svg(string $descricao): string
    {
        $largura = $this->largura + 40;
        $altura = $this->altura + 24;

        $html = sprintf(
            '<svg viewBox="0 0 %d %d" width="%d" height="%d" class="mapa-svg" role="img" aria-label="%s">',
            $largura,
            $altura,
            $largura,
            $altura,
            e($descricao)
        );

        $html .= '<defs><marker id="ponta" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6"'
            . ' orient="auto-start-reverse"><path d="M 0 0 L 8 4 L 0 8 z" class="mapa-ponta"></path></marker></defs>';

        foreach ($this->rotulos as $rotulo) {
            $html .= sprintf(
                '<text x="%d" y="%d" class="mapa-grupo">%s</text>',
                $rotulo['x'],
                $rotulo['y'],
                e($rotulo['texto'])
            );
        }

        foreach ($this->arestas as $aresta) {
            $html .= sprintf(
                '<path d="%s" class="mapa-linha%s%s" fill="none"%s></path>',
                e($aresta['d']),
                $aresta['fraca'] ? ' fraca' : '',
                $aresta['seta'] ? ' dependencia' : '',
                $aresta['seta'] ? ' marker-end="url(#ponta)"' : ''
            );
        }

        foreach ($this->colunas as $itens) {
            foreach ($itens as $no) {
                $html .= $this->caixa($no);
            }
        }

        return $html . '</svg>';
    }

    /** @param array<string, mixed> $no */
    private function caixa(array $no): string
    {
        $inativo = !empty($no['inativo']);
        $classes = 'mapa-no ' . (string) ($no['classe'] ?? '')
            . ($inativo ? ' inativo' : '')
            . (isset($this->comAlerta[(string) $no['chave']]) ? ' alerta' : '');

        $x = (int) $no['x'];
        $y = (int) $no['y'];

        return sprintf(
            '<a href="%s" class="%s">'
            . '<rect x="%d" y="%d" width="%d" height="%d" rx="10"></rect>'
            . '<text x="%d" y="%d" class="mapa-titulo">%s</text>'
            . '<text x="%d" y="%d" class="mapa-sub">%s</text>'
            . '<title>%s</title></a>',
            e((string) $no['href']),
            e($classes),
            $x,
            $y,
            self::LARGURA_NO,
            self::ALTURA_NO,
            $x + 14,
            $y + 24,
            e(mb_strimwidth((string) $no['titulo'], 0, 26, '…')),
            $x + 14,
            $y + 42,
            e(mb_strimwidth((string) $no['sub'], 0, 30, '…') . ($inativo ? ' · inativo' : '')),
            e((string) $no['titulo'] . ' — ' . (string) $no['sub'])
        );
    }

    /** @param list<array<string, mixed>> $itens */
    private function alturaDaColuna(array $itens): int
    {
        if ($itens === []) {
            return 0;
        }

        $altura = count($itens) * (self::ALTURA_NO + self::ESPACO) - self::ESPACO;
        $grupoAnterior = null;

        foreach ($itens as $item) {
            $grupo = $item['grupo'] ?? null;

            if ($grupo !== null && $grupo !== $grupoAnterior) {
                $altura += self::ALTURA_ROTULO;
                $grupoAnterior = $grupo;
            }
        }

        return $altura;
    }
}

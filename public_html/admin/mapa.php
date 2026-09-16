<?php

declare(strict_types=1);

/**
 * Mapa de ligações: quem está conectado a quem, e o que ficou solto.
 *
 * A configuração do sistema é um grafo — provedor alimenta agente, agente
 * consulta base e chama ferramenta, canal aponta para agente —, mas até aqui
 * ela só existia espalhada por cinco telas. A pergunta "está tudo ligado como
 * eu penso que está?" exigia abrir todas e cruzar de cabeça.
 *
 * Nada aqui é inferido: cada linha do desenho é uma ligação explícita no banco
 * (`agentes.provedor_id`, `agente_bases`, `agente_ferramentas`,
 * `canais.agente_id`, `bases.provedor_embedding_id`).
 *
 * ## SVG montado no servidor, sem biblioteca
 *
 * O grafo é pequeno e em camadas — instalação típica tem 1 a 3 agentes —, então
 * o posicionamento é colunar e determinístico, e não precisa de motor de
 * layout. Isso mantém a premissa de deploy do projeto: `git pull`, sem build e
 * sem CDN. Ver ARQUITETURA.md §1.
 *
 * ## Por que os avisos são a parte importante
 *
 * Desenho bonito não paga o próprio custo. O que paga é ver, de uma olhada, o
 * que hoje só se descobre conversando com o bot: agente sem base responde
 * sempre "não encontrei nos documentos"; ferramenta ativa e não ligada a
 * ninguém nunca é chamada (foi o caso do "Registrar chamado" em 16/09/2026);
 * base indexada por um provedor de embedding diferente do padrão devolve ruído
 * sem dar erro.
 */

require_once __DIR__ . '/_init.php';

$paginaAtual = 'mapa.php';
$tituloPagina = 'Mapa de ligações';

$padraoChat = (int) ($config['provedor_chat_padrao_id'] ?? 0);
$padraoEmbedding = (int) ($config['provedor_embedding_padrao_id'] ?? 0);

$provedores = $pdo->query(
    'SELECT id, nome, papel, ativo, modelo_chat, modelo_embedding FROM provedores ORDER BY nome'
)->fetchAll(PDO::FETCH_ASSOC);

$agentes = $pdo->query(
    'SELECT id, nome, ativo, modo, provedor_id, modelo, usa_rag, usa_faq FROM agentes ORDER BY nome'
)->fetchAll(PDO::FETCH_ASSOC);

$canais = $pdo->query(
    'SELECT id, nome, tipo, ativo, agente_id FROM canais ORDER BY nome'
)->fetchAll(PDO::FETCH_ASSOC);

$bases = $pdo->query(
    'SELECT b.id, b.nome, b.ativo, b.provedor_embedding_id,
            (SELECT COUNT(*) FROM chunks c WHERE c.base_id = b.id) AS chunks,
            (SELECT COUNT(*) FROM embeddings e WHERE e.base_id = b.id) AS vetores
       FROM bases b ORDER BY b.nome'
)->fetchAll(PDO::FETCH_ASSOC);

$ferramentas = $pdo->query(
    'SELECT id, nome, slug, tipo, ativo FROM ferramentas ORDER BY nome'
)->fetchAll(PDO::FETCH_ASSOC);

/** @return array<int, list<int>> agente → ids ligados */
$ligacoes = static function (PDO $pdo, string $tabela, string $coluna): array {
    $mapa = [];

    foreach ($pdo->query("SELECT agente_id, {$coluna} AS alvo FROM {$tabela}") as $l) {
        $mapa[(int) $l['agente_id']][] = (int) $l['alvo'];
    }

    return $mapa;
};

$basesDoAgente = $ligacoes($pdo, 'agente_bases', 'base_id');
$ferramentasDoAgente = $ligacoes($pdo, 'agente_ferramentas', 'ferramenta_id');

$nomePor = static function (array $lista, int $id): string {
    foreach ($lista as $item) {
        if ((int) $item['id'] === $id) {
            return (string) $item['nome'];
        }
    }

    return '';
};

// -------------------------------------------------------------------------
// Diagnóstico
//
// Cada item aponta um problema de LIGAÇÃO, com o efeito no atendimento — não
// só "está faltando algo". Quem lê precisa saber o que o visitante veria.
// -------------------------------------------------------------------------
$alertas = [];
$nosComAlerta = [];

$marcar = static function (string $chave) use (&$nosComAlerta): void {
    $nosComAlerta[$chave] = true;
};

$provedorAtivo = [];
$provedorPapel = [];

foreach ($provedores as $p) {
    $provedorAtivo[(int) $p['id']] = (int) $p['ativo'] === 1;
    $provedorPapel[(int) $p['id']] = (string) $p['papel'];
}

$padraoChatOk = $padraoChat > 0 && ($provedorAtivo[$padraoChat] ?? false);
$padraoEmbeddingOk = $padraoEmbedding > 0 && ($provedorAtivo[$padraoEmbedding] ?? false);

if (!$padraoChatOk) {
    $alertas[] = [
        'grave' => true,
        'texto' => 'Não há provedor padrão de chat ativo. Todo agente que não define o provedor dele fica sem responder.',
        'onde' => 'configuracoes.php',
        'rotulo' => 'Configurações',
    ];
}

if (!$padraoEmbeddingOk) {
    $alertas[] = [
        'grave' => true,
        'texto' => 'Não há provedor padrão de embedding ativo. A busca por significado e a FAQ param; '
            . 'sobra apenas a busca por palavra.',
        'onde' => 'configuracoes.php',
        'rotulo' => 'Configurações',
    ];
}

foreach ($agentes as $a) {
    $id = (int) $a['id'];
    $ativo = (int) $a['ativo'] === 1;
    $roteador = ($a['modo'] ?? 'ia') === 'roteador';

    if (!$ativo) {
        continue;
    }

    $provedorId = (int) ($a['provedor_id'] ?? 0);

    if (!$roteador && $provedorId > 0 && !($provedorAtivo[$provedorId] ?? false)) {
        $marcar('agente-' . $id);
        $marcar('prov-' . $provedorId);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O agente "' . $a['nome'] . '" aponta para um provedor inativo. Ele não responde, '
                . 'e no site o visitante cai no menu de setores.',
            'onde' => 'agentes.php?editar=' . $id,
            'rotulo' => 'Abrir agente',
        ];
    }

    if (!$roteador && $provedorId === 0 && !$padraoChatOk) {
        $marcar('agente-' . $id);
    }

    $minhasBases = $basesDoAgente[$id] ?? [];

    if (!$roteador && (int) $a['usa_rag'] === 1 && $minhasBases === []) {
        $marcar('agente-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O agente "' . $a['nome'] . '" usa documentos, mas nenhuma base está marcada para ele. '
                . 'Ele responde sempre que não encontrou a informação.',
            'onde' => 'agentes.php?editar=' . $id . '#conhecimento',
            'rotulo' => 'Aba Conhecimento',
        ];
    }

    foreach ($minhasBases as $baseId) {
        foreach ($bases as $b) {
            if ((int) $b['id'] === $baseId && (int) $b['ativo'] !== 1) {
                $marcar('base-' . $baseId);
                $alertas[] = [
                    'grave' => false,
                    'texto' => 'A base "' . $b['nome'] . '" está marcada no agente "' . $a['nome']
                        . '", mas está inativa — a busca não a consulta.',
                    'onde' => 'bases.php?editar=' . $baseId,
                    'rotulo' => 'Abrir base',
                ];
            }
        }
    }
}

$agentesAtivosPorId = [];

foreach ($agentes as $a) {
    if ((int) $a['ativo'] === 1) {
        $agentesAtivosPorId[(int) $a['id']] = true;
    }
}

$basesUsadas = [];

foreach ($basesDoAgente as $agenteId => $ids) {
    if (isset($agentesAtivosPorId[$agenteId])) {
        foreach ($ids as $bid) {
            $basesUsadas[$bid] = true;
        }
    }
}

foreach ($bases as $b) {
    $id = (int) $b['id'];

    if ((int) $b['ativo'] === 1 && !isset($basesUsadas[$id])) {
        $marcar('base-' . $id);
        $alertas[] = [
            'grave' => false,
            'texto' => 'A base "' . $b['nome'] . '" está ativa, mas nenhum agente ativo a consulta. '
                . 'O que está nela não chega a ninguém.',
            'onde' => 'bases.php?editar=' . $id,
            'rotulo' => 'Abrir base',
        ];
    }

    if ((int) $b['chunks'] > 0 && (int) $b['vetores'] === 0) {
        $marcar('base-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'A base "' . $b['nome'] . '" tem texto indexado mas nenhum vetor. Só a busca por '
                . 'palavra funciona nela — reindexe.',
            'onde' => 'artefatos.php',
            'rotulo' => 'Artefatos',
        ];
    }

    $embId = (int) ($b['provedor_embedding_id'] ?? 0);

    if ($embId > 0 && $padraoEmbedding > 0 && $embId !== $padraoEmbedding) {
        $marcar('base-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'A base "' . $b['nome'] . '" usa um provedor de embedding diferente do padrão. '
                . 'Vetor de modelo diferente não é comparável: a busca devolve resultado ruim sem dar erro.',
            'onde' => 'bases.php?editar=' . $id,
            'rotulo' => 'Abrir base',
        ];
    }

    if ($embId > 0 && !($provedorAtivo[$embId] ?? false)) {
        $marcar('base-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O provedor de embedding da base "' . $b['nome'] . '" está inativo. A indexação falha '
                . 'e a busca por significado fica desligada nessa base.',
            'onde' => 'provedores.php?editar=' . $embId,
            'rotulo' => 'Abrir provedor',
        ];
    }
}

$ferramentasUsadas = [];

foreach ($ferramentasDoAgente as $agenteId => $ids) {
    if (isset($agentesAtivosPorId[$agenteId])) {
        foreach ($ids as $fid) {
            $ferramentasUsadas[$fid] = true;
        }
    }
}

foreach ($ferramentas as $f) {
    $id = (int) $f['id'];

    if ((int) $f['ativo'] === 1 && !isset($ferramentasUsadas[$id])) {
        $marcar('ferr-' . $id);
        $alertas[] = [
            'grave' => false,
            'texto' => 'A ferramenta "' . $f['nome'] . '" está ativa, mas não está ligada a nenhum agente ativo. '
                . 'Ela nunca é chamada.',
            'onde' => 'ferramentas.php?editar=' . $id,
            'rotulo' => 'Abrir ferramenta',
        ];
    }
}

foreach ($canais as $c) {
    $id = (int) $c['id'];
    $agenteId = (int) ($c['agente_id'] ?? 0);

    if ((int) $c['ativo'] !== 1) {
        continue;
    }

    if ($agenteId === 0) {
        $marcar('canal-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O canal "' . $c['nome'] . '" está ativo e sem agente. Quem escrever por ele não é atendido.',
            'onde' => 'canais.php?editar=' . $id,
            'rotulo' => 'Abrir canal',
        ];
    } elseif (!isset($agentesAtivosPorId[$agenteId])) {
        $marcar('canal-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O canal "' . $c['nome'] . '" aponta para um agente inativo.',
            'onde' => 'canais.php?editar=' . $id,
            'rotulo' => 'Abrir canal',
        ];
    }
}

// -------------------------------------------------------------------------
// Layout: quatro colunas, posição calculada aqui e desenhada em SVG.
// -------------------------------------------------------------------------
const LARGURA_NO = 210;
const ALTURA_NO = 56;
const ESPACO = 16;
const COLUNAS_X = [20, 290, 560, 830];
const ALTURA_ROTULO = 30;
const ROTULOS = ['canal' => 'Canais', 'base' => 'Bases', 'ferramenta' => 'Ferramentas'];

/** Altura que uma coluna ocupa, contando os rótulos de grupo. */
$alturaDaColuna = static function (array $itens): int {
    $altura = count($itens) * (ALTURA_NO + ESPACO) - ESPACO;
    $grupoAnterior = null;

    foreach ($itens as $item) {
        $grupo = $item['grupo'] ?? null;

        if ($grupo !== null && $grupo !== $grupoAnterior) {
            $altura += ALTURA_ROTULO;
            $grupoAnterior = $grupo;
        }
    }

    return max($altura, 0);
};

/**
 * Empilha uma coluna, abrindo espaço e rótulo a cada troca de grupo.
 *
 * @param list<array<string, mixed>> $itens
 * @return array{0: list<array<string, mixed>>, 1: list<array{texto: string, x: int, y: int}>}
 */
$empilhar = static function (array $itens, int $x, int $topo): array {
    $y = $topo;
    $saida = [];
    $rotulos = [];
    $grupoAnterior = null;

    foreach ($itens as $item) {
        $grupo = $item['grupo'] ?? null;

        if ($grupo !== null && $grupo !== $grupoAnterior) {
            $y += ALTURA_ROTULO;
            $rotulos[] = ['texto' => ROTULOS[$grupo] ?? $grupo, 'x' => $x, 'y' => $y - 12];
            $grupoAnterior = $grupo;
        }

        $item['x'] = $x;
        $item['y'] = $y;
        $saida[] = $item;
        $y += ALTURA_NO + ESPACO;
    }

    return [$saida, $rotulos];
};

$colProvedores = [];

foreach ($provedores as $p) {
    $id = (int) $p['id'];
    $papel = (string) $p['papel'];
    $legenda = [];

    if ($id === $padraoChat) {
        $legenda[] = 'padrão de chat';
    }

    if ($id === $padraoEmbedding) {
        $legenda[] = 'padrão de embedding';
    }

    if ($legenda === []) {
        $legenda[] = match ($papel) {
            'chat' => 'somente chat',
            'embedding' => 'somente embedding',
            default => 'chat e embedding',
        };
    }

    $colProvedores[] = [
        'chave' => 'prov-' . $id,
        'titulo' => (string) $p['nome'],
        'sub' => implode(' · ', $legenda),
        'href' => 'provedores.php?editar=' . $id,
        'inativo' => (int) $p['ativo'] !== 1,
    ];
}

$colAgentes = [];

foreach ($agentes as $a) {
    $id = (int) $a['id'];
    $provedorId = (int) ($a['provedor_id'] ?? 0);
    $roteador = ($a['modo'] ?? 'ia') === 'roteador';

    $colAgentes[] = [
        'chave' => 'agente-' . $id,
        'titulo' => (string) $a['nome'],
        'sub' => $roteador
            ? 'modo roteador — sem LLM'
            : ($provedorId > 0 ? 'provedor próprio' : 'herda o padrão de chat'),
        'href' => 'agentes.php?editar=' . $id,
        'inativo' => (int) $a['ativo'] !== 1,
        'id' => $id,
        'provedor_id' => $provedorId,
        'roteador' => $roteador,
    ];
}

$colDireita = [];

foreach ($canais as $c) {
    $colDireita[] = [
        'chave' => 'canal-' . (int) $c['id'],
        'titulo' => (string) $c['nome'],
        'sub' => 'canal · ' . (string) $c['tipo'],
        'href' => 'canais.php?editar=' . (int) $c['id'],
        'inativo' => (int) $c['ativo'] !== 1,
        'grupo' => 'canal',
        'id' => (int) $c['id'],
        'agente_id' => (int) ($c['agente_id'] ?? 0),
    ];
}

foreach ($bases as $b) {
    $embDaBase = $nomePor($provedores, (int) ($b['provedor_embedding_id'] ?? 0));

    $colDireita[] = [
        'chave' => 'base-' . (int) $b['id'],
        'titulo' => (string) $b['nome'],
        // Quem indexou a base entra no rótulo, e não como linha de volta ao
        // provedor: a seta cruzaria o desenho inteiro para dizer algo que
        // cabe em duas palavras. Embedding trocado e o erro mais silencioso
        // do RAG, entao precisa estar visivel sem clique.
        'sub' => 'base · ' . (int) $b['vetores'] . ' vetores · '
            . ($embDaBase === '' ? 'padrão' : $embDaBase),
        'href' => 'bases.php?editar=' . (int) $b['id'],
        'inativo' => (int) $b['ativo'] !== 1,
        'grupo' => 'base',
        'id' => (int) $b['id'],
        'embedding_id' => (int) ($b['provedor_embedding_id'] ?? 0),
    ];
}

foreach ($ferramentas as $f) {
    $colDireita[] = [
        'chave' => 'ferr-' . (int) $f['id'],
        'titulo' => (string) $f['nome'],
        'sub' => 'ferramenta · ' . (string) $f['tipo'],
        'href' => 'ferramentas.php?editar=' . (int) $f['id'],
        'inativo' => (int) $f['ativo'] !== 1,
        'grupo' => 'ferramenta',
        'id' => (int) $f['id'],
    ];
}

// Colunas centradas pela mais alta.
//
// Uma instalação pequena tem 1 ou 2 agentes e uma dúzia de itens à direita;
// alinhadas pelo topo, as caixas da esquerda ficam num canto e as linhas
// atravessam o desenho na diagonal. Centrar deixa as ligações curtas e o
// desenho legível de uma olhada só.
$alturas = [
    $alturaDaColuna($colProvedores),
    $alturaDaColuna($colAgentes),
    $alturaDaColuna($colDireita),
];
$maisAlta = max($alturas);

[$colProvedores, $rotulosProv] = $empilhar($colProvedores, COLUNAS_X[0], 40 + (int) (($maisAlta - $alturas[0]) / 2));
[$colAgentes, $rotulosAgentes] = $empilhar($colAgentes, COLUNAS_X[1], 40 + (int) (($maisAlta - $alturas[1]) / 2));
[$colDireita, $rotulosDireita] = $empilhar($colDireita, COLUNAS_X[2], 40 + (int) (($maisAlta - $alturas[2]) / 2));

$rotulos = [...$rotulosProv, ...$rotulosAgentes, ...$rotulosDireita];

/** @var array<string, array<string, mixed>> $porChave */
$porChave = [];

foreach ([...$colProvedores, ...$colAgentes, ...$colDireita] as $no) {
    $porChave[$no['chave']] = $no;
}

/**
 * Curva de um nó a outro, da borda direita à borda esquerda.
 *
 * @return array{0: string, 1: bool}|null caminho e se é ligação problemática
 */
$curva = static function (string $de, string $para) use ($porChave): ?string {
    if (!isset($porChave[$de], $porChave[$para])) {
        return null;
    }

    $a = $porChave[$de];
    $b = $porChave[$para];

    $x1 = $a['x'] + LARGURA_NO;
    $y1 = $a['y'] + ALTURA_NO / 2;
    $x2 = $b['x'];
    $y2 = $b['y'] + ALTURA_NO / 2;
    $meio = ($x2 - $x1) / 2;

    return sprintf('M %d %d C %d %d, %d %d, %d %d', $x1, $y1, $x1 + $meio, $y1, $x2 - $meio, $y2, $x2, $y2);
};

$arestas = [];

foreach ($colAgentes as $a) {
    if ($a['provedor_id'] > 0) {
        $arestas[] = ['d' => $curva('prov-' . $a['provedor_id'], $a['chave']), 'fraca' => $a['inativo']];
    } elseif (!$a['roteador'] && $padraoChat > 0) {
        $arestas[] = ['d' => $curva('prov-' . $padraoChat, $a['chave']), 'fraca' => true];
    }

    foreach (($basesDoAgente[$a['id']] ?? []) as $bid) {
        $arestas[] = ['d' => $curva($a['chave'], 'base-' . $bid), 'fraca' => $a['inativo']];
    }

    foreach (($ferramentasDoAgente[$a['id']] ?? []) as $fid) {
        $arestas[] = ['d' => $curva($a['chave'], 'ferr-' . $fid), 'fraca' => $a['inativo']];
    }
}

foreach ($colDireita as $no) {
    if (($no['grupo'] ?? '') === 'canal' && ($no['agente_id'] ?? 0) > 0) {
        // O canal aponta para o agente, mas fica à direita dele: a curva volta,
        // e por isso ela é desenhada do agente para o canal — o sentido da
        // LEITURA é quem manda no desenho, não o da chave estrangeira.
        $arestas[] = ['d' => $curva('agente-' . $no['agente_id'], $no['chave']), 'fraca' => $no['inativo']];
    }
}

$arestas = array_values(array_filter($arestas, static fn (array $a): bool => $a['d'] !== null));

$alturaMax = 40;

foreach ([$colProvedores, $colAgentes, $colDireita] as $coluna) {
    if ($coluna !== []) {
        $ultimo = $coluna[count($coluna) - 1];
        $alturaMax = max($alturaMax, $ultimo['y'] + ALTURA_NO);
    }
}

$alturaSvg = $alturaMax + 40;
$larguraSvg = COLUNAS_X[2] + LARGURA_NO + 40;

$graves = count(array_filter($alertas, static fn (array $a): bool => $a['grave']));

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Mapa de ligações</h1>
        <p>
            Como a configuração está conectada hoje. Cada linha é uma ligação real no banco, não uma
            suposição — e cada caixa abre a tela onde ela se configura.
        </p>
    </div>
</div>

<?php if ($alertas !== []): ?>
    <div class="panel">
        <h2 class="card-title">
            <?= count($alertas) ?> ponto<?= count($alertas) > 1 ? 's' : '' ?> de atenção
            <?php if ($graves > 0): ?><span class="tag tag-alerta"><?= $graves ?> que afeta<?= $graves > 1 ? 'm' : '' ?> o atendimento</span><?php endif; ?>
        </h2>
        <ul class="mapa-alertas">
            <?php foreach ($alertas as $alerta): ?>
                <li class="<?= $alerta['grave'] ? 'grave' : '' ?>">
                    <?= e($alerta['texto']) ?>
                    <a href="<?= e($alerta['onde']) ?>"><?= e($alerta['rotulo']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="empty-state">Nenhuma ligação solta. Provedores, agentes, canais, bases e ferramentas estão coerentes entre si.</div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="mapa-legenda">
        <span><i class="mapa-chip col-prov"></i> Provedores</span>
        <span><i class="mapa-chip col-agente"></i> Agentes</span>
        <span><i class="mapa-chip col-canal"></i> Canais</span>
        <span><i class="mapa-chip col-base"></i> Bases</span>
        <span><i class="mapa-chip col-ferr"></i> Ferramentas</span>
        <span><i class="mapa-chip col-alerta"></i> Com ponto de atenção</span>
        <span><i class="mapa-chip col-inativo"></i> Inativo</span>
    </div>

    <div class="mapa-rolagem">
        <svg viewBox="0 0 <?= $larguraSvg ?> <?= $alturaSvg ?>" width="<?= $larguraSvg ?>" height="<?= $alturaSvg ?>"
             class="mapa-svg" role="img" aria-label="Diagrama das ligações entre provedores, agentes, canais, bases e ferramentas">
            <?php foreach ($rotulos as $rotulo): ?>
                <text x="<?= (int) $rotulo['x'] ?>" y="<?= (int) $rotulo['y'] ?>" class="mapa-grupo"><?= e($rotulo['texto']) ?></text>
            <?php endforeach; ?>

            <?php foreach ($arestas as $aresta): ?>
                <path d="<?= e($aresta['d']) ?>" class="mapa-linha<?= $aresta['fraca'] ? ' fraca' : '' ?>" fill="none"></path>
            <?php endforeach; ?>

            <?php
            $desenhar = static function (array $no, string $classe) use ($nosComAlerta): void {
                $alerta = isset($nosComAlerta[$no['chave']]);
                $classes = 'mapa-no ' . $classe
                    . ($no['inativo'] ? ' inativo' : '')
                    . ($alerta ? ' alerta' : '');
                ?>
                <a href="<?= e($no['href']) ?>" class="<?= e($classes) ?>">
                    <rect x="<?= (int) $no['x'] ?>" y="<?= (int) $no['y'] ?>" width="<?= LARGURA_NO ?>" height="<?= ALTURA_NO ?>" rx="10"></rect>
                    <text x="<?= (int) $no['x'] + 14 ?>" y="<?= (int) $no['y'] + 24 ?>" class="mapa-titulo">
                        <?= e(mb_strimwidth((string) $no['titulo'], 0, 26, '…')) ?>
                    </text>
                    <text x="<?= (int) $no['x'] + 14 ?>" y="<?= (int) $no['y'] + 42 ?>" class="mapa-sub">
                        <?= e(mb_strimwidth((string) $no['sub'], 0, 30, '…')) ?><?= $no['inativo'] ? ' · inativo' : '' ?>
                    </text>
                    <title><?= e($no['titulo'] . ' — ' . $no['sub']) ?></title>
                </a>
                <?php
            };

            foreach ($colProvedores as $no) {
                $desenhar($no, 'col-prov');
            }

            foreach ($colAgentes as $no) {
                $desenhar($no, 'col-agente');
            }

            foreach ($colDireita as $no) {
                $desenhar($no, 'col-' . match ($no['grupo']) {
                    'canal' => 'canal',
                    'base' => 'base',
                    default => 'ferr',
                });
            }
            ?>
        </svg>
    </div>

    <p class="page-sub" style="margin-top:.6rem;">
        A linha mais clara é ligação que existe mas não está em uso — agente inativo, ou provedor herdado do
        padrão em vez de escolhido no agente. Bases aparecem com a contagem de vetores: base com zero vetores
        só responde pela busca por palavra.
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

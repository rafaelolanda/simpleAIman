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
require_once __DIR__ . '/../../app/Mapa.php';

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
    'SELECT id, nome, slug, tipo, ativo, depende_de FROM ferramentas ORDER BY nome'
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
// Desenho: quatro colunas, montadas por SimpleAIman\Mapa.
//
// Provedores → Agentes → (Canais, Bases, Ferramentas) → quem indexa as bases.
// -------------------------------------------------------------------------
$mapa = new Mapa([20, 290, 560, 830]);

$colProvedores = [];

foreach ($provedores as $p) {
    $id = (int) $p['id'];
    $legenda = [];

    if ($id === $padraoChat) {
        $legenda[] = 'padrão de chat';
    }

    if ($id === $padraoEmbedding) {
        $legenda[] = 'padrão de embedding';
    }

    if ($legenda === []) {
        $legenda[] = match ((string) $p['papel']) {
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
        'classe' => 'col-prov',
    ];
}

$colAgentes = [];

foreach ($agentes as $a) {
    $id = (int) $a['id'];
    $provedorId = (int) ($a['provedor_id'] ?? 0);
    $roteador = ($a['modo'] ?? 'ia') === 'roteador';

    // O MODO vem primeiro, sempre visível.
    //
    // Antes ele só aparecia quando era roteador, e "provedor próprio" no modo
    // IA não dizia qual dos dois modos estava valendo. Quem olha o mapa para
    // entender por que um agente não usa a LLM precisa ver isso sem clicar.
    $colAgentes[] = [
        'chave' => 'agente-' . $id,
        'titulo' => (string) $a['nome'],
        'sub' => $roteador
            ? 'roteador · menu, sem LLM'
            : 'IA · ' . ($provedorId > 0 ? 'provedor próprio' : 'padrão de chat'),
        'href' => 'agentes.php?editar=' . $id,
        'inativo' => (int) $a['ativo'] !== 1,
        'classe' => 'col-agente',
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
        'classe' => 'col-canal',
        'grupo' => 'Canais',
        'tipo' => 'canal',
        'id' => (int) $c['id'],
        'agente_id' => (int) ($c['agente_id'] ?? 0),
    ];
}

foreach ($bases as $b) {
    $embDaBase = $nomePor($provedores, (int) ($b['provedor_embedding_id'] ?? 0));

    $colDireita[] = [
        'chave' => 'base-' . (int) $b['id'],
        'titulo' => (string) $b['nome'],
        'sub' => (int) $b['vetores'] . ' vetores · ' . ($embDaBase === '' ? 'padrão' : $embDaBase),
        'href' => 'bases.php?editar=' . (int) $b['id'],
        'inativo' => (int) $b['ativo'] !== 1,
        'classe' => 'col-base',
        'grupo' => 'Bases',
        'tipo' => 'base',
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
        'classe' => 'col-ferr',
        'grupo' => 'Ferramentas',
        'tipo' => 'ferramenta',
        'id' => (int) $f['id'],
        'depende_de' => (int) ($f['depende_de'] ?? 0),
    ];
}

// Quarta coluna: quem INDEXOU cada base.
//
// O provedor de embedding já aparece à esquerda quando também faz chat, mas
// ligar a base até lá cruzaria o desenho inteiro. Repetir o provedor à direita
// mantém toda leitura no mesmo sentido.
$usadosNoEmbedding = [];

foreach ($bases as $b) {
    $emb = (int) ($b['provedor_embedding_id'] ?? 0) ?: $padraoEmbedding;

    if ($emb > 0) {
        $usadosNoEmbedding[$emb] = true;
    }
}

$colEmbedding = [];

foreach ($provedores as $p) {
    $id = (int) $p['id'];

    if (!isset($usadosNoEmbedding[$id])) {
        continue;
    }

    $colEmbedding[] = [
        'chave' => 'emb-' . $id,
        'titulo' => (string) $p['nome'],
        'sub' => 'indexa · ' . (trim((string) $p['modelo_embedding']) ?: 'modelo não definido'),
        'href' => 'provedores.php?editar=' . $id,
        'inativo' => (int) $p['ativo'] !== 1,
        'classe' => 'col-prov',
    ];
}

$mapa->coluna(0, $colProvedores);
$mapa->coluna(1, $colAgentes);
$mapa->coluna(2, $colDireita);
$mapa->coluna(3, $colEmbedding);
$mapa->montar();

foreach ($nosComAlerta as $chave => $_) {
    $mapa->alertar((string) $chave);
}

foreach ($colAgentes as $a) {
    if ($a['provedor_id'] > 0) {
        $mapa->ligar('prov-' . $a['provedor_id'], $a['chave'], $a['inativo']);
    } elseif (!$a['roteador'] && $padraoChat > 0) {
        $mapa->ligar('prov-' . $padraoChat, $a['chave'], true);
    }

    foreach (($basesDoAgente[$a['id']] ?? []) as $bid) {
        $mapa->ligar($a['chave'], 'base-' . $bid, $a['inativo']);
    }

    foreach (($ferramentasDoAgente[$a['id']] ?? []) as $fid) {
        $mapa->ligar($a['chave'], 'ferr-' . $fid, $a['inativo']);
    }
}

foreach ($colDireita as $no) {
    if ($no['tipo'] === 'canal' && $no['agente_id'] > 0) {
        // O canal aponta para o agente, mas fica à direita dele: a curva é
        // desenhada do agente para o canal, porque quem manda no desenho é o
        // sentido da LEITURA, não o da chave estrangeira.
        $mapa->ligar('agente-' . $no['agente_id'], $no['chave'], $no['inativo']);
    }

    if ($no['tipo'] === 'base') {
        $emb = $no['embedding_id'] ?: $padraoEmbedding;

        if ($emb > 0) {
            // Herdado do padrão sai mais claro que o escolhido na base: a
            // diferença entre "é o padrão" e "alguém mudou aqui" é justamente
            // o que se quer enxergar de longe.
            $mapa->ligar($no['chave'], 'emb-' . $emb, $no['embedding_id'] === 0);
        }
    }

    if ($no['tipo'] === 'ferramenta' && $no['depende_de'] > 0) {
        // Trava de ordem: o Executor RECUSA a chamada se a pré-requisito não
        // rodou na conversa. É regra, não dica — por isso a seta.
        $mapa->ligarNaColuna('ferr-' . $no['depende_de'], $no['chave'], $no['inativo']);
    }
}

$graves = count(array_filter($alertas, static fn (array $a): bool => $a['grave']));

$contar = static fn (array $lista): int => count(array_filter(
    $lista,
    static fn (array $i): bool => (int) ($i['ativo'] ?? 1) === 1
));

$resumo = [
    ['rotulo' => 'Provedores ativos', 'valor' => $contar($provedores), 'de' => count($provedores)],
    ['rotulo' => 'Agentes ativos', 'valor' => $contar($agentes), 'de' => count($agentes)],
    ['rotulo' => 'Canais ativos', 'valor' => $contar($canais), 'de' => count($canais)],
    ['rotulo' => 'Bases ativas', 'valor' => $contar($bases), 'de' => count($bases)],
    ['rotulo' => 'Ferramentas em uso', 'valor' => count($ferramentasUsadas), 'de' => count($ferramentas)],
    ['rotulo' => 'Vetores indexados', 'valor' => array_sum(array_column($bases, 'vetores')), 'de' => null],
];

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

<div class="panel">
    <div class="mapa-resumo">
        <?php foreach ($resumo as $item): ?>
            <div>
                <strong><?= (int) $item['valor'] ?><?= $item['de'] !== null ? '<span>de ' . (int) $item['de'] . '</span>' : '' ?></strong>
                <span><?= e($item['rotulo']) ?></span>
            </div>
        <?php endforeach; ?>
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
        <span><i class="mapa-chip col-seta"></i> Ferramenta que exige outra antes</span>
    </div>

    <div class="mapa-rolagem">
        <?= $mapa->svg('Ligações entre provedores, agentes, canais, bases e ferramentas') ?>
    </div>

    <p class="page-sub" style="margin-top:.6rem;">
        A linha mais clara é ligação herdada ou fora de uso — agente inativo, provedor vindo do padrão em vez
        de escolhido. À direita das bases aparece quem as indexou: se uma base apontar para um provedor
        diferente das outras, os vetores dela não se comparam com o resto, e a busca piora sem dar erro. A
        seta entre ferramentas é a trava de ordem (<code>depende_de</code>): o sistema recusa a segunda
        enquanto a primeira não tiver rodado na conversa.
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

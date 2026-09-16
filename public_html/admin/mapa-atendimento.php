<?php

declare(strict_types=1);

/**
 * Mapa do atendimento humano: setores, quem atende e o que chega neles.
 *
 * O mapa da configuração responde "o robô está ligado direito?". Este responde
 * a outra pergunta, que só aparece quando alguém do outro lado pede ajuda:
 * **tem gente para receber?**
 *
 * A diferença entre os dois é a razão de existirem separados. Um setor bonito,
 * com e-mail e telefone preenchidos, ainda assim não recebe transferência
 * nenhuma se ninguém estiver marcado como atendente dele — e isso não aparece
 * em lugar nenhum até um visitante pedir "quero falar com uma pessoa" e ficar
 * esperando.
 *
 * ## Disponível de verdade são três coisas
 *
 * `atende` é permissão, `disponivel` é intenção, e `visto_em` é presença. A
 * fila só entrega para quem tem os três — ver Fila::disponiveis() e
 * ARQUITETURA.md §9. Por isso o desenho mostra os três estados em vez de um
 * "online" que mentiria: intenção esquecida ligada faz o agente transferir para
 * uma sala vazia.
 */

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../app/Mapa.php';

use SimpleAIman\Atendimento\Fila;

$paginaAtual = 'mapa-atendimento.php';
$tituloPagina = 'Mapa do atendimento';

$setores = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM chamados c WHERE c.setor_id = s.id AND c.status = \'aberto\') AS chamados,
            (SELECT COUNT(*) FROM ferramentas f WHERE f.setor_id = s.id AND f.ativo = 1) AS ferramentas,
            (SELECT COUNT(*) FROM faq q WHERE q.setor_id = s.id AND q.ativo = 1) AS faq
       FROM setores s ORDER BY s.ordem, s.nome'
)->fetchAll(PDO::FETCH_ASSOC);

$pessoas = $pdo->query(
    'SELECT id, nome, usuario, papel, setor_id, atende, disponivel, visto_em
       FROM admin_users ORDER BY nome, usuario'
)->fetchAll(PDO::FETCH_ASSOC);

$aguardando = (int) $pdo->query("SELECT COUNT(*) FROM conversas WHERE modo = 'aguardando'")->fetchColumn();
$emAtendimento = (int) $pdo->query("SELECT COUNT(*) FROM conversas WHERE modo = 'humano'")->fetchColumn();

/**
 * Presença de verdade: permissão + intenção + sinal recente.
 *
 * O mesmo critério da fila. Repetir a regra aqui com outro limite faria o mapa
 * dizer "disponível" para quem a fila não escolhe — e a tela existe justamente
 * para explicar por que ninguém recebeu a conversa.
 */
$presente = static function (array $u): bool {
    return (int) $u['atende'] === 1
        && (int) $u['disponivel'] === 1
        && $u['visto_em'] !== null
        && strtotime((string) $u['visto_em']) > time() - PRESENCA_JANELA_SEG;
};

$alertas = [];
$nosComAlerta = [];

$marcar = static function (string $chave) use (&$nosComAlerta): void {
    $nosComAlerta[$chave] = true;
};

// -------------------------------------------------------------------------
// Diagnóstico
// -------------------------------------------------------------------------
$temPadrao = false;
$atendentesPorSetor = [];
$semSetor = [];

foreach ($pessoas as $u) {
    if ((int) $u['atende'] !== 1) {
        continue;
    }

    $setorId = (int) ($u['setor_id'] ?? 0);

    if ($setorId === 0) {
        $semSetor[] = $u;
        continue;
    }

    $atendentesPorSetor[$setorId][] = $u;
}

foreach ($setores as $s) {
    $id = (int) $s['id'];
    $ativo = (int) $s['ativo'] === 1;

    if ((int) $s['padrao'] === 1 && $ativo) {
        $temPadrao = true;
    }

    if (!$ativo) {
        continue;
    }

    $meus = $atendentesPorSetor[$id] ?? [];

    if ($meus === []) {
        $marcar('setor-' . $id);
        $alertas[] = [
            'grave' => false,
            'texto' => 'O setor "' . $s['nome'] . '" não tem nenhum atendente marcado. Ele serve como contato, '
                . 'mas não recebe transferência de conversa.',
            'onde' => 'usuarios.php',
            'rotulo' => 'Usuários',
        ];
    }

    $contato = trim((string) ($s['email'] ?? '')) !== ''
        || trim((string) ($s['telefone'] ?? '')) !== ''
        || trim((string) ($s['whatsapp'] ?? '')) !== '';

    if (!$contato) {
        $marcar('setor-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O setor "' . $s['nome'] . '" não tem e-mail, telefone nem WhatsApp. Quando o agente '
                . 'encaminha para ele, não há o que informar — e é aí que um telefone inventado apareceria.',
            'onde' => 'setores.php?editar=' . $id,
            'rotulo' => 'Abrir setor',
        ];
    }

    if (trim((string) ($s['email'] ?? '')) === '' && (int) $s['chamados'] > 0) {
        $marcar('setor-' . $id);
        $alertas[] = [
            'grave' => true,
            'texto' => 'O setor "' . $s['nome'] . '" tem ' . (int) $s['chamados'] . ' chamado(s) aberto(s) e '
                . 'nenhum e-mail: ninguém foi avisado por e-mail de nenhum deles.',
            'onde' => 'setores.php?editar=' . $id,
            'rotulo' => 'Abrir setor',
        ];
    }
}

if (!$temPadrao) {
    $alertas[] = [
        'grave' => true,
        'texto' => 'Nenhum setor ativo está marcado como padrão. Quando o agente não identifica o assunto, '
            . 'fica sem destino para encaminhar.',
        'onde' => 'setores.php',
        'rotulo' => 'Setores',
    ];
}

foreach ($semSetor as $u) {
    $marcar('pessoa-' . (int) $u['id']);
    $alertas[] = [
        'grave' => false,
        'texto' => (string) ($u['nome'] ?: $u['usuario']) . ' está marcado como atendente, mas sem setor. '
            . 'Recebe da fila geral, e não das transferências dirigidas a um setor.',
        'onde' => 'usuarios.php',
        'rotulo' => 'Usuários',
    ];
}

$disponiveisAgora = array_filter($pessoas, $presente);

if ($aguardando > 0 && $disponiveisAgora === []) {
    $alertas[] = [
        'grave' => true,
        'texto' => $aguardando . ' conversa(s) esperando atendimento humano e ninguém disponível agora. '
            . 'O bot devolve a conversa sozinho depois do tempo de espera.',
        'onde' => 'atendimento.php',
        'rotulo' => 'Atendimento',
    ];
}

foreach ($pessoas as $u) {
    if ((int) $u['atende'] !== 1 || (int) $u['disponivel'] !== 1) {
        continue;
    }

    $visto = $u['visto_em'] !== null ? strtotime((string) $u['visto_em']) : 0;

    if ($visto < time() - PRESENCA_JANELA_SEG) {
        $marcar('pessoa-' . (int) $u['id']);
        $alertas[] = [
            'grave' => false,
            'texto' => (string) ($u['nome'] ?: $u['usuario']) . ' está marcado como disponível, mas não abre o '
                . 'painel há um tempo. A fila não conta com quem está ausente — disponível é intenção, presença '
                . 'é o painel aberto.',
            'onde' => 'usuarios.php',
            'rotulo' => 'Usuários',
        ];
    }
}

// -------------------------------------------------------------------------
// Desenho: pessoas → setores → o que chega no setor
// -------------------------------------------------------------------------
$mapa = new Mapa([20, 290, 560]);

$colPessoas = [];

foreach ($pessoas as $u) {
    $atende = (int) $u['atende'] === 1;

    // Quem não atende aparece assim mesmo, em cinza: metade das dúvidas sobre
    // a fila ("por que fulano não recebe?") se responde vendo que a pessoa
    // existe e não está marcada.
    $estado = match (true) {
        !$atende => 'não atende · ' . (string) $u['papel'],
        $presente($u) => 'disponível agora',
        (int) $u['disponivel'] === 1 => 'marcado, mas ausente',
        default => 'atende · indisponível',
    };

    $colPessoas[] = [
        'chave' => 'pessoa-' . (int) $u['id'],
        'titulo' => (string) ($u['nome'] ?: $u['usuario']),
        'sub' => $estado,
        'href' => 'usuarios.php',
        'inativo' => !$atende,
        'classe' => $presente($u) ? 'col-agente' : 'col-ferr',
        'id' => (int) $u['id'],
        'setor_id' => (int) ($u['setor_id'] ?? 0),
        'atende' => $atende,
    ];
}

$colSetores = [];

foreach ($setores as $s) {
    $id = (int) $s['id'];
    $contatos = [];

    foreach (['email' => 'e-mail', 'telefone' => 'telefone', 'whatsapp' => 'WhatsApp'] as $campo => $rotulo) {
        if (trim((string) ($s[$campo] ?? '')) !== '') {
            $contatos[] = $rotulo;
        }
    }

    $colSetores[] = [
        'chave' => 'setor-' . $id,
        'titulo' => (string) $s['nome'] . ((int) $s['padrao'] === 1 ? ' ★' : ''),
        'sub' => $contatos === [] ? 'sem contato' : implode(', ', $contatos),
        'href' => 'setores.php?editar=' . $id,
        'inativo' => (int) $s['ativo'] !== 1,
        'classe' => 'col-canal',
        'id' => $id,
    ];
}

$colCarga = [];

foreach ($setores as $s) {
    $id = (int) $s['id'];

    if ((int) $s['chamados'] > 0) {
        $colCarga[] = [
            'chave' => 'chamados-' . $id,
            'titulo' => (int) $s['chamados'] . ' chamado(s) aberto(s)',
            'sub' => (string) $s['nome'],
            'href' => 'chamados.php',
            'inativo' => false,
            'classe' => 'col-base',
            'grupo' => 'Fila do setor',
            'setor_id' => $id,
        ];
    }
}

foreach ($setores as $s) {
    $id = (int) $s['id'];

    if ((int) $s['ferramentas'] > 0 || (int) $s['faq'] > 0) {
        $partes = [];

        if ((int) $s['ferramentas'] > 0) {
            $partes[] = (int) $s['ferramentas'] . ' ferramenta(s)';
        }

        if ((int) $s['faq'] > 0) {
            $partes[] = (int) $s['faq'] . ' FAQ';
        }

        $colCarga[] = [
            'chave' => 'conteudo-' . $id,
            'titulo' => implode(' · ', $partes),
            'sub' => (string) $s['nome'],
            'href' => 'ferramentas.php',
            'inativo' => (int) $s['ativo'] !== 1,
            'classe' => 'col-prov',
            'grupo' => 'Ligado ao setor',
            'setor_id' => $id,
        ];
    }
}

$mapa->coluna(0, $colPessoas);
$mapa->coluna(1, $colSetores);
$mapa->coluna(2, $colCarga);
$mapa->montar();

foreach ($nosComAlerta as $chave => $_) {
    $mapa->alertar((string) $chave);
}

foreach ($colPessoas as $p) {
    if ($p['setor_id'] > 0) {
        $mapa->ligar($p['chave'], 'setor-' . $p['setor_id'], !$p['atende']);
    }
}

foreach ($colCarga as $c) {
    $mapa->ligar('setor-' . $c['setor_id'], $c['chave'], $c['inativo']);
}

$graves = count(array_filter($alertas, static fn (array $a): bool => $a['grave']));
$queAtendem = count(array_filter($pessoas, static fn (array $u): bool => (int) $u['atende'] === 1));

$resumo = [
    ['rotulo' => 'Setores ativos', 'valor' => count(array_filter($setores, static fn (array $s): bool => (int) $s['ativo'] === 1)), 'de' => count($setores)],
    ['rotulo' => 'Marcados como atendentes', 'valor' => $queAtendem, 'de' => count($pessoas)],
    ['rotulo' => 'Disponíveis agora', 'valor' => count($disponiveisAgora), 'de' => $queAtendem],
    ['rotulo' => 'Conversas esperando', 'valor' => $aguardando, 'de' => null],
    ['rotulo' => 'Em atendimento humano', 'valor' => $emAtendimento, 'de' => null],
    ['rotulo' => 'Chamados abertos', 'valor' => array_sum(array_column($setores, 'chamados')), 'de' => null],
];

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Mapa do atendimento</h1>
        <p>
            Quem recebe o quê quando a conversa sai do robô. Setor sem atendente marcado continua servindo
            como contato, mas não recebe transferência — e isso não aparece até alguém ficar esperando.
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
        <div class="empty-state">Setores e atendentes coerentes: todo setor ativo tem contato e alguém marcado.</div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="mapa-legenda">
        <span><i class="mapa-chip col-agente"></i> Disponível agora</span>
        <span><i class="mapa-chip col-ferr"></i> Atendente fora do ar</span>
        <span><i class="mapa-chip col-canal"></i> Setores</span>
        <span><i class="mapa-chip col-base"></i> Chamados abertos</span>
        <span><i class="mapa-chip col-alerta"></i> Com ponto de atenção</span>
        <span><i class="mapa-chip col-inativo"></i> Não atende / inativo</span>
    </div>

    <div class="mapa-rolagem">
        <?= $mapa->svg('Ligações entre atendentes, setores e o que chega em cada setor') ?>
    </div>

    <p class="page-sub" style="margin-top:.6rem;">
        O ★ marca o setor padrão, destino de quem o agente não consegue classificar. "Disponível agora" exige
        três coisas ao mesmo tempo: estar marcado como atendente, ter ligado a disponibilidade e ter o painel
        aberto há pouco — é o mesmo critério que a fila usa para escolher para quem mandar.
    </p>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

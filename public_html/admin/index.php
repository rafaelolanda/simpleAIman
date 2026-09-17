<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../app/Grafico.php';

$paginaAtual = 'index.php';
$tituloPagina = 'Dashboard';

$contar = static function (PDO $pdo, string $sql): int {
    return (int) $pdo->query($sql)->fetchColumn();
};

/**
 * Quem atende vê o MESMO painel, com os dados dele.
 *
 * Não é uma tela separada, e isso é de propósito: o recorte por pessoa cabe
 * numa cláusula `WHERE`, enquanto uma segunda tela seria outro arquivo para
 * manter em dia. O que muda é o conteúdo — nada de configuração, de alerta de
 * infraestrutura ou de número dos colegas, que em atendimento vira placar.
 */
$souAtendente = $meuPapel === 'atendente';
$meuId = (int) (Auth::userId() ?? 0);

if (!$souAtendente) {

$totais = [
    'agentes' => $contar($pdo, 'SELECT COUNT(*) FROM agentes WHERE ativo = 1'),
    'bases' => $contar($pdo, 'SELECT COUNT(*) FROM bases WHERE ativo = 1'),
    'artefatos' => $contar($pdo, 'SELECT COUNT(*) FROM artefatos'),
    'chunks' => $contar($pdo, 'SELECT COUNT(*) FROM chunks'),
    'faq' => $contar($pdo, 'SELECT COUNT(*) FROM faq WHERE ativo = 1'),
    'ferramentas' => $contar($pdo, 'SELECT COUNT(*) FROM ferramentas WHERE ativo = 1'),
    'conversas' => $contar($pdo, 'SELECT COUNT(*) FROM conversas'),
    'leads' => $contar($pdo, 'SELECT COUNT(*) FROM leads'),
];

// ---------------------------------------------------------------------
// Estado do sistema
//
// Cada item aqui é uma coisa que, silenciosamente errada, faz o agente parecer
// "quebrado" sem mensagem de erro clara. Melhor dizer na cara.
// ---------------------------------------------------------------------
$alertas = [];

$provedorAtivo = $pdo->query('SELECT * FROM provedores WHERE ativo = 1 LIMIT 1')->fetch();

if (!$provedorAtivo) {
    $alertas[] = ['erro', 'Nenhum provedor de LLM ativo. Cadastre a chave no <code>.env</code> e ative o provedor.'];
} elseif (env_secret($provedorAtivo['auth_ref'] ?? null) === null || env_secret($provedorAtivo['auth_ref']) === '') {
    $alertas[] = [
        'erro',
        'O provedor <strong>' . e($provedorAtivo['nome']) . '</strong> está ativo, mas a variável <code>'
            . e((string) $provedorAtivo['auth_ref']) . '</code> está vazia no <code>.env</code>.',
    ];
}

// Base com dimensão diferente da que já foi gravada nos embeddings: trocar o
// modelo invalida os vetores existentes, e o sintoma é resultado ruim sem erro.
$basesInconsistentes = $pdo->query(
    'SELECT b.nome, b.dimensoes AS esperada, e.dimensoes AS gravada, COUNT(*) AS qtd
     FROM embeddings e
     JOIN bases b ON b.id = e.base_id
     WHERE b.dimensoes IS NOT NULL AND e.dimensoes != b.dimensoes
     GROUP BY b.id'
)->fetchAll();

foreach ($basesInconsistentes as $b) {
    $alertas[] = [
        'erro',
        'A base <strong>' . e($b['nome']) . '</strong> espera ' . (int) $b['esperada'] . ' dimensões, mas tem '
            . (int) $b['qtd'] . ' vetores gravados com ' . (int) $b['gravada'] . '. Reindexe a base.',
    ];
}

if ($artefatosComErro > 0) {
    $alertas[] = ['aviso', $artefatosComErro . ' artefato(s) com erro de ingestão. Veja em Artefatos.'];
}

$jobsPresos = $contar($pdo, "SELECT COUNT(*) FROM jobs WHERE status = 'processando' AND lock_ate < '" . now() . "'");
if ($jobsPresos > 0) {
    $alertas[] = ['aviso', $jobsPresos . ' job(s) com lock expirado — o worker pode não estar rodando (confira o cron).'];
}

// Artefato parado em `pendente` há muito tempo significa que NINGUÉM está
// consumindo a fila: nem o "kick" do upload, nem o cron.
//
// Esse alerta existe porque o kick falha em silêncio de propósito — a ideia é
// que o cron sirva de rede de segurança. Mas se os dois estiverem quebrados
// (APP_URL errada, cron não configurado), o sintoma é um arquivo que fica
// "pendente" para sempre, sem nenhuma mensagem de erro em lugar nenhum.
$paradosDesde = date('Y-m-d H:i:s', time() - 600);
$parados = $contar($pdo, "SELECT COUNT(*) FROM artefatos WHERE status = 'pendente' AND criado_em < '" . $paradosDesde . "'");

if ($parados > 0) {
    $alertas[] = [
        'erro',
        $parados . ' artefato(s) parado(s) em "pendente" há mais de 10 minutos. Ninguém está processando a fila: '
            . 'confira o <code>cron</code> do <code>bin/worker.php</code> e se <code>APP_URL</code> no <code>.env</code> '
            . 'aponta para o endereço real desta instância (é por ele que o upload dispara o worker).',
    ];
}

// Sinal de vida do worker, lido do batimento que cada execução deixa.
//
// Os dois alertas acima são indiretos: só aparecem depois que um job travou ou
// um arquivo ficou parado. Este diz direto se alguém está consumindo a fila —
// antes de algo dar errado. Ver SimpleAIman\Jobs\Batimento.
$batimento = \SimpleAIman\Jobs\Batimento::resumo();
$verInfra = $meuPapel === 'admin' ? ' Veja em <a href="infra.php">Diagnóstico</a>.' : '';

if ($batimento['ultima'] === null) {
    $alertas[] = ['erro', 'O worker nunca registrou uma execução. Confira o <code>cron</code> do <code>bin/worker.php</code>.' . $verInfra];
} elseif (time() - (int) $batimento['ultima'] > 600) {
    $alertas[] = [
        'erro',
        'O worker está calado há ' . intdiv(time() - (int) $batimento['ultima'], 60) . ' min — ninguém está processando a fila.' . $verInfra,
    ];
}

if ($batimento['interrompidas_kick'] > 0) {
    $alertas[] = [
        'aviso',
        $batimento['interrompidas_kick'] . ' execução(ões) do worker disparada(s) pela web morreram no meio do trabalho. '
            . 'É o servidor encerrando o processo — a causa do turno interrompido no WhatsApp.' . $verInfra,
    ];
}

// Setor com contato nunca revisado: contato errado é pior que contato nenhum,
// porque o agente entrega o número errado com toda a confiança do mundo.
$setoresSemRevisao = $contar(
    $pdo,
    "SELECT COUNT(*) FROM setores WHERE ativo = 1 AND (atualizado_em IS NULL OR atualizado_em < date('now', '-180 day'))"
);
if ($setoresSemRevisao > 0) {
    $alertas[] = ['aviso', $setoresSemRevisao . ' setor(es) sem revisão de contato há mais de 6 meses.'];
}

} // fim do bloco que só o admin e o editor enxergam

$hoje = today();
$inicio = date('Y-m-d', strtotime('-13 day'));
$seteDias = date('Y-m-d H:i:s', strtotime('-6 day midnight'));

if ($souAtendente) {
    // Série do atendente: quantas conversas ele atendeu em cada dia.
    //
    // Contada pela MENSAGEM que ele enviou, e não por `conversas.atendente_id`:
    // aquela coluna guarda quem está com a conversa AGORA, e é limpa quando a
    // conversa volta ao bot. Quem atendeu ontem sumiria do próprio painel.
    $minhas = $pdo->prepare(
        "SELECT date(criado_em) AS dia,
                COUNT(DISTINCT conversa_id) AS conversas,
                COUNT(*) AS mensagens
           FROM mensagens
          WHERE autor_tipo = 'atendente' AND autor_id = :eu AND date(criado_em) >= :inicio
          GROUP BY date(criado_em)"
    );
    $minhas->execute(['eu' => $meuId, 'inicio' => $inicio]);

    $porDia = [];

    foreach ($minhas->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $porDia[(string) $linha['dia']] = [
            'conversa_atendida' => (int) $linha['conversas'],
            'mensagem_atendente' => (int) $linha['mensagens'],
        ];
    }

    $serieConversas = Grafico::completar($porDia, 'conversa_atendida');
    $serieMensagens = Grafico::completar($porDia, 'mensagem_atendente');

    $comigoAgora = (int) $pdo->query(
        "SELECT COUNT(*) FROM conversas WHERE modo = 'humano' AND atendente_id = " . $meuId
    )->fetchColumn();

    $ultimasConversas = $pdo->prepare(
        "SELECT c.id, c.modo, c.criado_em, a.nome AS agente,
                (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS msgs,
                (SELECT MAX(m.criado_em) FROM mensagens m WHERE m.conversa_id = c.id) AS ultima
           FROM conversas c
           LEFT JOIN agentes a ON a.id = c.agente_id
          WHERE c.id IN (SELECT DISTINCT conversa_id FROM mensagens
                          WHERE autor_tipo = 'atendente' AND autor_id = :eu)
          ORDER BY ultima DESC LIMIT 8"
    );
    $ultimasConversas->execute(['eu' => $meuId]);
    $ultimasConversas = $ultimasConversas->fetchAll(PDO::FETCH_ASSOC);
} else {
    $serie = Metrics::serieDiaria($inicio, $hoje);
    $serieConversas = Grafico::completar($serie, 'conversa_iniciada');
    $serieMensagens = Grafico::completar($serie, 'mensagem_enviada');

    $ultimasConversas = $pdo->query(
        'SELECT c.id, c.titulo, c.modo, c.criado_em, a.nome AS agente,
                (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS msgs
         FROM conversas c
         LEFT JOIN agentes a ON a.id = c.agente_id
         ORDER BY c.id DESC LIMIT 8'
    )->fetchAll();
}

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1><?= $souAtendente ? 'Meu atendimento' : 'Dashboard' ?></h1>
    <p class="page-sub">
        <?php if ($souAtendente): ?>
            O que passou pelas suas mãos nos últimos 14 dias.
        <?php else: ?>
            Visão geral da instância <strong><?= e($config['nome_instancia']) ?></strong>.
        <?php endif; ?>
    </p>
</div>

<?php if ($souAtendente): ?>
    <div class="stat-grid">
        <a class="stat-card" href="atendimento.php">
            <span class="stat-icon"><?= svg_icon('chat', 22) ?></span>
            <span class="stat-valor"><?= (int) $comigoAgora ?></span>
            <span class="stat-rotulo">Com você agora</span>
        </a>
        <a class="stat-card" href="atendimento.php">
            <span class="stat-icon"><?= svg_icon('usuario', 22) ?></span>
            <span class="stat-valor"><?= (int) ($filaAtendimento ?? 0) ?></span>
            <span class="stat-rotulo">Esperando na fila</span>
        </a>
        <span class="stat-card">
            <span class="stat-icon"><?= svg_icon('fone', 22) ?></span>
            <span class="stat-valor"><?= array_sum(array_slice($serieConversas, -7)) ?></span>
            <span class="stat-rotulo">Atendidas nos últimos 7 dias</span>
        </span>
        <span class="stat-card">
            <span class="stat-icon"><?= svg_icon('log', 22) ?></span>
            <span class="stat-valor"><?= array_sum(array_slice($serieMensagens, -7)) ?></span>
            <span class="stat-rotulo">Mensagens que você enviou (7 dias)</span>
        </span>
    </div>
<?php endif; ?>

<?php if (!$souAtendente && $alertas): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <h2 class="card-title">Estado do sistema</h2>
        <ul class="lista-alertas">
            <?php foreach ($alertas as [$nivel, $texto]): ?>
                <li class="alerta alerta-<?= e($nivel) ?>"><?= $texto ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php elseif (!$souAtendente): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-ok">Nenhum alerta. Provedor configurado e ingestão sem pendências.</p>
    </div>
<?php endif; ?>

<?php if (!$souAtendente): ?>
<div class="stat-grid">
    <?php
    $cards = [
        ['agentes', 'Agentes ativos', 'bot', 'agentes.php'],
        ['bases', 'Bases', 'base', 'bases.php'],
        ['artefatos', 'Artefatos', 'arquivo', 'artefatos.php'],
        ['chunks', 'Chunks indexados', 'base', 'artefatos.php'],
        ['faq', 'FAQ ativas', 'faq', 'faq.php'],
        ['ferramentas', 'Ferramentas', 'ferramenta', 'ferramentas.php'],
        ['conversas', 'Conversas', 'chat', 'conversas.php'],
        ['leads', 'Leads', 'lead', 'leads.php'],
    ];
    foreach ($cards as [$chave, $rotulo, $icone, $link]):
        ?>
        <a class="stat-card" href="<?= e($link) ?>">
            <span class="stat-icon"><?= svg_icon($icone, 22) ?></span>
            <span class="stat-valor"><?= number_format($totais[$chave], 0, ',', '.') ?></span>
            <span class="stat-rotulo"><?= e($rotulo) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// Dois gráficos, e não um com dois eixos.
//
// Uma conversa tem dezenas de mensagens: no mesmo eixo, a série menor vira uma
// linha rente ao chão. Dois eixos "resolveriam" o desenho e estragariam a
// leitura, porque a posição relativa das curvas passaria a depender da escala
// escolhida. Cada medida com seu próprio máximo diz a verdade sobre as duas.
$totalConversas = array_sum($serieConversas);
$totalMensagens = array_sum($serieMensagens);
?>
<div class="card">
    <h2 class="card-title">
        <?= $souAtendente ? 'Seu movimento nos últimos 14 dias' : 'Atividade dos últimos 14 dias' ?>
    </h2>

    <?php if ($totalConversas === 0 && $totalMensagens === 0): ?>
        <p class="vazio">
            <?= $souAtendente
                ? 'Você ainda não respondeu nenhuma conversa neste período.'
                : 'Sem métricas ainda — elas começam a aparecer quando o agente entrar em uso.' ?>
        </p>
    <?php else: ?>
        <div class="graficos-lado">
            <div>
                <p class="grafico-titulo">
                    <strong><?= number_format($totalConversas, 0, ',', '.') ?></strong>
                    <?= $souAtendente ? 'conversas atendidas' : 'conversas iniciadas' ?>
                </p>
                <?= Grafico::barras($serieConversas, 'conversas') ?>
            </div>
            <div>
                <p class="grafico-titulo">
                    <strong><?= number_format($totalMensagens, 0, ',', '.') ?></strong>
                    <?= $souAtendente ? 'mensagens suas' : 'mensagens trocadas' ?>
                </p>
                <?= Grafico::barras($serieMensagens, 'mensagens') ?>
            </div>
        </div>

        <?php // A tabela continua existindo, recolhida: o gráfico dá a forma, e
              // quem precisa do número exato de um dia abre aqui. ?>
        <details class="card-recolhivel" style="margin-top:1rem;">
            <summary><span class="card-title">Ver os números dia a dia</span></summary>
            <table class="tabela" style="margin-top:.7rem;">
                <thead>
                <tr>
                    <th>Dia</th>
                    <th><?= $souAtendente ? 'Conversas atendidas' : 'Conversas' ?></th>
                    <th><?= $souAtendente ? 'Mensagens suas' : 'Mensagens' ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_reverse($serieConversas, true) as $dia => $quantas): ?>
                    <tr>
                        <td><?= e(date('d/m', strtotime($dia))) ?></td>
                        <td><?= (int) $quantas ?></td>
                        <td><?= (int) ($serieMensagens[$dia] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title"><?= $souAtendente ? 'Suas últimas conversas' : 'Últimas conversas' ?></h2>
    <?php if (!$ultimasConversas): ?>
        <p class="vazio">
            <?= $souAtendente ? 'Você ainda não atendeu nenhuma conversa.' : 'Nenhuma conversa registrada.' ?>
        </p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>#</th>
                <th>Agente</th>
                <th>Modo</th>
                <th>Msgs</th>
                <th>Início</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ultimasConversas as $c): ?>
                <tr>
                    <td><a href="conversas.php?ver=<?= (int) $c['id'] ?>"><?= (int) $c['id'] ?></a></td>
                    <td><?= e($c['agente'] ?? '—') ?></td>
                    <td><span class="tag"><?= e($c['modo']) ?></span></td>
                    <td><?= (int) $c['msgs'] ?></td>
                    <td><?= e(date('d/m/Y H:i', strtotime($c['criado_em']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

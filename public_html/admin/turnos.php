<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

/**
 * Diagnóstico de turnos.
 *
 * Um turno é uma pergunta e o que ela provocou. A tabela `turnos` guarda uma
 * linha por resposta, com o caminho que ela tomou e o tempo gasto em cada
 * etapa; esta tela é o único lugar que a lê.
 *
 * Ela responde três perguntas, nesta ordem de urgência:
 *
 *  1. O atendimento está saudável? (erro, degradação, latência típica)
 *  2. Onde o tempo está indo? (embedding, busca, modelo, ferramenta)
 *  3. O que aconteceu NESTE atendimento? (a visão de um trace só)
 *
 * A terceira é a razão de a tabela existir. As duas primeiras servem para
 * chegar até ela — ninguém abre um diagnóstico já sabendo qual turno
 * investigar.
 *
 * Conteúdo de mensagem aparece SÓ no detalhe de um turno, e lido de
 * `mensagens`, nunca de uma cópia: assim o que a retenção expurga desaparece
 * daqui junto. As visões agregadas são todas de metadado.
 */

$paginaAtual = 'turnos.php';
$tituloPagina = 'Diagnóstico';

// ---------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------
$dias = (int) valor_em((string) ($_GET['dias'] ?? '7'), ['1', '7', '30', '90'], '7');
$agenteFiltro = (int) ($_GET['agente'] ?? 0);
$canalFiltro = trim((string) ($_GET['canal'] ?? ''));
$statusFiltro = valor_em((string) ($_GET['status'] ?? ''), ['', 'ok', 'erro', 'degradado'], '');
$traceFiltro = trim((string) ($_GET['trace'] ?? ''));

$onde = ['t.criado_em >= :desde'];
$params = ['desde' => date('Y-m-d H:i:s', strtotime("-{$dias} days"))];

if ($agenteFiltro > 0) {
    $onde[] = 't.agente_id = :agente';
    $params['agente'] = $agenteFiltro;
}

if ($canalFiltro !== '') {
    $onde[] = 't.canal = :canal';
    $params['canal'] = $canalFiltro;
}

if ($statusFiltro !== '') {
    $onde[] = 't.status = :status';
    $params['status'] = $statusFiltro;
}

$filtro = implode(' AND ', $onde);

$consultar = static function (PDO $pdo, string $sql) use ($params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

// ---------------------------------------------------------------------
// Resumo do período
// ---------------------------------------------------------------------
$resumo = $consultar($pdo, "
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN t.status = 'erro' THEN 1 ELSE 0 END) AS erros,
           SUM(CASE WHEN t.status = 'degradado' THEN 1 ELSE 0 END) AS degradados,
           SUM(COALESCE(t.tokens_in, 0)) AS tin,
           SUM(COALESCE(t.tokens_out, 0)) AS tout,
           AVG(t.ms_embedding) AS emb,
           AVG(t.ms_busca) AS bus,
           AVG(t.ms_inferencia) AS inf,
           AVG(t.ms_ferramentas) AS fer
      FROM turnos t WHERE {$filtro}
")[0] ?? [];

$total = (int) ($resumo['total'] ?? 0);

/**
 * Percentil sem função de janela.
 *
 * O SQLite embarcado no PHP de hospedagem compartilhada não traz
 * `percentile()`, e ordenar em PHP exigiria carregar a coluna inteira na
 * memória. `OFFSET` sobre o resultado ordenado custa uma varredura de índice
 * e devolve o valor exato.
 *
 * A MEDIANA importa mais que a média: uma única chamada que bateu no timeout
 * de 40s desloca a média do dia inteiro e faz parecer que tudo piorou.
 */
$percentil = static function (PDO $pdo, string $filtro, array $params, float $p): ?int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM turnos t WHERE {$filtro} AND t.ms_total IS NOT NULL");
    $stmt->execute($params);
    $n = (int) $stmt->fetchColumn();

    if ($n === 0) {
        return null;
    }

    $offset = max(0, (int) floor($n * $p) - 1);

    // OFFSET é inteiro derivado de COUNT, nunca de entrada do usuário.
    $stmt = $pdo->prepare(
        "SELECT t.ms_total FROM turnos t WHERE {$filtro} AND t.ms_total IS NOT NULL
         ORDER BY t.ms_total LIMIT 1 OFFSET {$offset}"
    );
    $stmt->execute($params);

    $v = $stmt->fetchColumn();

    return $v === false ? null : (int) $v;
};

$mediana = $percentil($pdo, $filtro, $params, 0.50);
$p95 = $percentil($pdo, $filtro, $params, 0.95);

$caminhos = $consultar($pdo, "
    -- Turno que falhou nao chegou a ter caminho: `registrarFalha()` marca a
    -- causa, nao a rota. Rotular de 'sem resposta' e mais util que
    -- 'indefinido', que soa como defeito de registro.
    SELECT COALESCE(t.caminho, 'sem resposta') AS caminho, COUNT(*) AS n, AVG(t.ms_total) AS media
      FROM turnos t WHERE {$filtro}
     GROUP BY t.caminho ORDER BY n DESC
");

$semModelo = 0;

foreach ($caminhos as $c) {
    if (in_array($c['caminho'], ['roteador', 'faq'], true)) {
        $semModelo += (int) $c['n'];
    }
}

$falhas = $consultar($pdo, "
    SELECT COALESCE(t.erro_codigo, 'sem codigo') AS codigo, COUNT(*) AS n, MAX(t.criado_em) AS ultima
      FROM turnos t WHERE {$filtro} AND t.status IN ('erro', 'degradado')
     GROUP BY t.erro_codigo ORDER BY n DESC LIMIT 10
");

$lentos = $consultar($pdo, "
    SELECT t.*, a.nome AS agente
      FROM turnos t LEFT JOIN agentes a ON a.id = t.agente_id
     WHERE {$filtro} AND t.ms_total IS NOT NULL
     ORDER BY t.ms_total DESC LIMIT 15
");

$recentes = $consultar($pdo, "
    SELECT t.*, a.nome AS agente
      FROM turnos t LEFT JOIN agentes a ON a.id = t.agente_id
     WHERE {$filtro}
     ORDER BY t.id DESC LIMIT 25
");

// ---------------------------------------------------------------------
// Detalhe de um turno
//
// O `trace_id` é a chave que atravessa tudo: a linha do turno, a mensagem que
// saiu e as ferramentas chamadas. Reunir isso numa tela é o que substitui o
// garimpo manual que existia antes de haver correlação.
//
// Fica FORA do bloco de resumo de propósito: um trace continua abrindo mesmo
// quando o filtro de período não o alcança — quem chega aqui por um link não
// deveria precisar acertar o período antes.
// ---------------------------------------------------------------------
$detalhe = null;
$mensagensDoTurno = [];
$ferramentasDoTurno = [];

if ($traceFiltro !== '') {
    $stmt = $pdo->prepare(
        'SELECT t.*, a.nome AS agente FROM turnos t
         LEFT JOIN agentes a ON a.id = t.agente_id WHERE t.trace_id = :t'
    );
    $stmt->execute(['t' => $traceFiltro]);
    $detalhe = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmt = $pdo->prepare(
        'SELECT id, autor_tipo, conteudo, latencia_ms, criado_em
         FROM mensagens WHERE trace_id = :t ORDER BY id'
    );
    $stmt->execute(['t' => $traceFiltro]);
    $mensagensDoTurno = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT fe.*, f.nome AS ferramenta FROM ferramenta_execucoes fe
         LEFT JOIN ferramentas f ON f.id = fe.ferramenta_id
         WHERE fe.trace_id = :t ORDER BY fe.id'
    );
    $stmt->execute(['t' => $traceFiltro]);
    $ferramentasDoTurno = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$agentes = $pdo->query('SELECT id, nome FROM agentes ORDER BY nome')->fetchAll();
$canaisUsados = $pdo->query('SELECT DISTINCT canal FROM turnos WHERE canal IS NOT NULL ORDER BY canal')
    ->fetchAll(PDO::FETCH_COLUMN);

/** Link preservando os filtros, trocando só o que muda. */
$comFiltros = static function (array $troca) use ($dias, $agenteFiltro, $canalFiltro, $statusFiltro): string {
    $base = array_filter([
        'dias' => $dias,
        'agente' => $agenteFiltro ?: null,
        'canal' => $canalFiltro ?: null,
        'status' => $statusFiltro ?: null,
    ], static fn ($v): bool => $v !== null);

    return 'turnos.php?' . http_build_query(array_merge($base, $troca));
};

$ms = static fn (mixed $v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.') . ' ms';
$seg = static fn (?int $v): string => $v === null ? '—' : number_format($v / 1000, 1, ',', '.') . 's';

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Diagnóstico de turnos</h1>
    <p class="page-sub">
        Um turno é uma pergunta e o que ela provocou. Aqui se vê para onde o tempo foi e o que
        falhou. Os números são de <strong>metadado</strong>; o texto das conversas fica em
        Conversas, sob a retenção configurada.
    </p>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <form method="get" class="form-grid" style="margin-bottom:0">
        <?php if ($traceFiltro !== ''): ?>
            <input type="hidden" name="trace" value="<?= e($traceFiltro) ?>">
        <?php endif; ?>

        <label>
            Período
            <select name="dias" onchange="this.form.submit()">
                <?php
                // Chave numerica de array vira INT em PHP, entao a comparacao
                // precisa ser numerica: `(string) $dias === $v` nunca casava e
                // o periodo escolhido voltava sem estar marcado.
                foreach ([1 => 'Últimas 24h', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $v => $rot): ?>
                    <option value="<?= (int) $v ?>" <?= $dias === $v ? 'selected' : '' ?>><?= e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Agente
            <select name="agente" onchange="this.form.submit()">
                <option value="0">Todos</option>
                <?php foreach ($agentes as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= $agenteFiltro === (int) $a['id'] ? 'selected' : '' ?>>
                        <?= e($a['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Canal
            <select name="canal" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($canaisUsados as $c): ?>
                    <option value="<?= e((string) $c) ?>" <?= $canalFiltro === $c ? 'selected' : '' ?>><?= e((string) $c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Situação
            <select name="status" onchange="this.form.submit()">
                <?php foreach (['' => 'Todas', 'ok' => 'Só as que deram certo', 'erro' => 'Só erros', 'degradado' => 'Só degradados'] as $v => $rot): ?>
                    <option value="<?= e($v) ?>" <?= $statusFiltro === $v ? 'selected' : '' ?>><?= e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</div>

<?php if ($total === 0): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="vazio">
            Nenhum turno neste período. Os registros aparecem conforme o assistente responde, e
            começam a existir a partir da versão que introduziu o diagnóstico — uma instalação
            recém-atualizada começa vazia mesmo tendo conversas antigas.
        </p>
    </div>
<?php else: ?>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-valor"><?= number_format($total, 0, ',', '.') ?></span>
        <span class="stat-rotulo">turnos</span>
    </div>
    <div class="stat-card">
        <span class="stat-valor"><?= e($seg($mediana)) ?></span>
        <span class="stat-rotulo">mediana</span>
    </div>
    <div class="stat-card">
        <span class="stat-valor"><?= e($seg($p95)) ?></span>
        <span class="stat-rotulo">p95 — 1 em 20 é pior que isto</span>
    </div>
    <div class="stat-card">
        <span class="stat-valor"><?= number_format((int) $resumo['erros'] / $total * 100, 1, ',', '.') ?>%</span>
        <span class="stat-rotulo">
            erros<?= (int) $resumo['degradados'] > 0 ? ' · ' . (int) $resumo['degradados'] . ' degradados' : '' ?>
        </span>
    </div>
    <div class="stat-card">
        <span class="stat-valor"><?= number_format($semModelo / $total * 100, 0, ',', '.') ?>%</span>
        <span class="stat-rotulo">respondidos sem chamar o modelo</span>
    </div>
    <div class="stat-card">
        <span class="stat-valor"><?= number_format((int) $resumo['tin'] + (int) $resumo['tout'], 0, ',', '.') ?></span>
        <span class="stat-rotulo">tokens no período</span>
    </div>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Para onde vai o tempo</h2>
    <p class="dica-painel">
        Média por etapa. Quando a resposta demora e ninguém sabe por quê, é aqui que se separa
        rede até o fornecedor, busca local e endpoint externo lento.
    </p>

    <?php
    $etapas = [
        ['Embedding da pergunta', (float) ($resumo['emb'] ?? 0), 'chamada de rede ao provedor de embedding'],
        ['Busca nas bases', (float) ($resumo['bus'] ?? 0), 'cosseno e busca lexical, dentro do SQLite'],
        ['Inferência', (float) ($resumo['inf'] ?? 0), 'o modelo; soma as duas voltas quando há ferramenta'],
        ['Ferramentas', (float) ($resumo['fer'] ?? 0), 'trabalho real: HTTP, banco, e-mail'],
    ];

    $maior = max(array_map(static fn (array $et): float => $et[1], $etapas)) ?: 1.0;
    ?>

    <table class="tabela">
        <tbody>
        <?php foreach ($etapas as [$rotulo, $valor, $nota]): ?>
            <tr>
                <td style="width:230px">
                    <strong><?= e($rotulo) ?></strong>
                    <br><small style="opacity:.6"><?= e($nota) ?></small>
                </td>
                <td>
                    <div class="barra" style="max-width:100%">
                        <span style="width:<?= (int) round($valor / $maior * 100) ?>%"></span>
                    </div>
                </td>
                <td style="width:110px;text-align:right"><code><?= e($ms($valor)) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Por onde as respostas saíram</h2>
    <p class="dica-painel">
        <code>roteador</code> e <code>faq</code> não chamam o modelo — é resposta sem custo de
        inferência. Subir essa proporção é a forma mais direta de baixar a conta sem piorar o
        atendimento.
    </p>
    <table class="tabela">
        <thead><tr><th>Caminho</th><th>Turnos</th><th>Proporção</th><th>Tempo médio</th></tr></thead>
        <tbody>
        <?php foreach ($caminhos as $c): ?>
            <tr>
                <td><span class="tag"><?= e((string) $c['caminho']) ?></span></td>
                <td><?= (int) $c['n'] ?></td>
                <td>
                    <div class="barra"><span style="width:<?= (int) round((int) $c['n'] / $total * 100) ?>%"></span></div>
                    <small style="opacity:.6"><?= number_format((int) $c['n'] / $total * 100, 1, ',', '.') ?>%</small>
                </td>
                <td><code><?= e($ms($c['media'])) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($falhas): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <h2 class="card-title">Falhas por causa</h2>
        <p class="dica-painel">
            O código é nosso, já traduzido do erro do fornecedor — por isso dá para agrupar sem
            reler texto de exceção.
        </p>
        <table class="tabela">
            <thead><tr><th>Código</th><th>Ocorrências</th><th>Última</th></tr></thead>
            <tbody>
            <?php foreach ($falhas as $f): ?>
                <tr>
                    <td><span class="tag tag-erro"><?= e((string) $f['codigo']) ?></span></td>
                    <td><?= (int) $f['n'] ?></td>
                    <td><small><?= e((string) $f['ultima']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
/** A mesma tabela serve às duas listas; duas cópias divergiriam na primeira coluna nova. */
$listar = static function (array $linhas) use ($comFiltros, $ms): void {
    ?>
    <div class="table-wrap">
        <table class="tabela cards-mobile">
            <thead>
            <tr>
                <th>Quando</th><th>Agente</th><th>Canal</th><th>Caminho</th>
                <th>Total</th><th>Modelo</th><th>Ferr.</th><th>Situação</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($linhas as $t): ?>
                <tr>
                    <td data-label="Quando"><small><?= e(date('d/m H:i', strtotime((string) $t['criado_em']))) ?></small></td>
                    <td data-label="Agente"><?= e((string) ($t['agente'] ?? '—')) ?></td>
                    <td data-label="Canal"><span class="tag"><?= e((string) ($t['canal'] ?? '—')) ?></span></td>
                    <td data-label="Caminho"><span class="tag"><?= e((string) ($t['caminho'] ?? '—')) ?></span></td>
                    <td data-label="Total"><code><?= e($ms($t['ms_total'])) ?></code></td>
                    <td data-label="Modelo"><code><?= e($ms($t['ms_inferencia'])) ?></code></td>
                    <td data-label="Ferr."><?= (int) $t['n_ferramentas'] ?></td>
                    <td data-label="Situação">
                        <span class="tag tag-<?= $t['status'] === 'ok' ? 'ok' : ($t['status'] === 'erro' ? 'erro' : 'neutro') ?>">
                            <?= e((string) $t['status']) ?>
                        </span>
                        <?php if (!empty($t['erro_codigo'])): ?>
                            <br><small style="opacity:.7"><?= e((string) $t['erro_codigo']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="acoes">
                        <a href="<?= e($comFiltros(['trace' => $t['trace_id']])) ?>#detalhe" class="btn btn-secondary btn-sm">Abrir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Os 15 mais lentos</h2>
    <p class="dica-painel">Por onde começar quando alguém reclama de lentidão.</p>
    <?php $listar($lentos); ?>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <h2 class="card-title">Últimos turnos</h2>
    <?php $listar($recentes); ?>
</div>

<?php endif; ?>

<?php if ($traceFiltro !== ''): ?>
    <div class="card" id="detalhe">
        <h2 class="card-title">Turno <code><?= e($traceFiltro) ?></code></h2>

        <?php if ($detalhe === null): ?>
            <p class="vazio">
                Nenhum resumo com este identificador. Ou a conversa foi apagada, ou o turno morreu
                antes de ser fechado — uma falha do PHP no meio da requisição, por exemplo. As
                mensagens e ferramentas abaixo, se houver, ainda aparecem.
            </p>
        <?php else: ?>
            <div class="stat-grid" style="margin-bottom:1rem">
                <div class="stat-card">
                    <span class="stat-valor"><?= e($ms($detalhe['ms_total'])) ?></span>
                    <span class="stat-rotulo">total</span>
                </div>
                <div class="stat-card">
                    <span class="stat-valor"><?= e($ms($detalhe['ms_embedding'])) ?></span>
                    <span class="stat-rotulo">embedding</span>
                </div>
                <div class="stat-card">
                    <span class="stat-valor"><?= e($ms($detalhe['ms_busca'])) ?></span>
                    <span class="stat-rotulo">busca · <?= (int) $detalhe['n_trechos'] ?> trechos</span>
                </div>
                <div class="stat-card">
                    <span class="stat-valor"><?= e($ms($detalhe['ms_inferencia'])) ?></span>
                    <span class="stat-rotulo">modelo</span>
                </div>
                <div class="stat-card">
                    <span class="stat-valor"><?= e($ms($detalhe['ms_ferramentas'])) ?></span>
                    <span class="stat-rotulo"><?= (int) $detalhe['n_ferramentas'] ?> ferramenta(s)</span>
                </div>
                <div class="stat-card">
                    <span class="stat-valor">
                        <?= $detalhe['tokens_in'] === null
                            ? '—'
                            : number_format((int) $detalhe['tokens_in'] + (int) $detalhe['tokens_out'], 0, ',', '.') ?>
                    </span>
                    <span class="stat-rotulo">tokens</span>
                </div>
            </div>

            <?php if ($detalhe['tokens_in'] === null && $detalhe['caminho'] === 'rag'): ?>
                <p class="dica-painel">
                    Tokens em branco é esperado no streaming: o consumo vem no evento final do
                    handler e ainda não há gancho confiável para lê-lo. Ver ARQUITETURA.md §12.
                </p>
            <?php endif; ?>

            <table class="tabela" style="margin-bottom:1rem">
                <tbody>
                <tr><td style="width:160px"><strong>Agente</strong></td><td><?= e((string) ($detalhe['agente'] ?? '—')) ?></td></tr>
                <tr><td><strong>Canal</strong></td><td><?= e((string) ($detalhe['canal'] ?? '—')) ?></td></tr>
                <tr><td><strong>Caminho</strong></td><td><?= e((string) ($detalhe['caminho'] ?? '—')) ?></td></tr>
                <tr><td><strong>Modelo</strong></td><td><code><?= e((string) ($detalhe['modelo'] ?? '—')) ?></code></td></tr>
                <tr>
                    <td><strong>Situação</strong></td>
                    <td>
                        <?= e((string) $detalhe['status']) ?>
                        <?= $detalhe['erro_codigo'] ? ' — ' . e((string) $detalhe['erro_codigo']) : '' ?>
                    </td>
                </tr>
                <tr><td><strong>Quando</strong></td><td><?= e((string) $detalhe['criado_em']) ?></td></tr>
                <tr>
                    <td><strong>Conversa</strong></td>
                    <td>
                        <?php if ($detalhe['conversa_id']): ?>
                            <a href="conversas.php?ver=<?= (int) $detalhe['conversa_id'] ?>">
                                #<?= (int) $detalhe['conversa_id'] ?> — ver a conversa inteira
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="font-size:0.95rem;margin:1.2rem 0 0.6rem">Mensagens deste turno</h3>
        <?php if (!$mensagensDoTurno): ?>
            <p class="vazio">
                Nenhuma mensagem com este identificador — o conteúdo pode ter sido expurgado pela
                retenção, ou a falha ocorreu antes de qualquer gravação.
            </p>
        <?php else: ?>
            <table class="tabela">
                <thead><tr><th style="width:110px">Autor</th><th>Texto</th><th style="width:110px">Latência</th></tr></thead>
                <tbody>
                <?php foreach ($mensagensDoTurno as $m): ?>
                    <tr>
                        <td><span class="tag"><?= e((string) $m['autor_tipo']) ?></span></td>
                        <td>
                            <?= e(mb_substr((string) $m['conteudo'], 0, 400)) ?><?= mb_strlen((string) $m['conteudo']) > 400 ? '…' : '' ?>
                        </td>
                        <td><code><?= e($ms($m['latencia_ms'])) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="font-size:0.95rem;margin:1.2rem 0 0.6rem">Ferramentas chamadas</h3>
        <?php if (!$ferramentasDoTurno): ?>
            <p class="vazio">Nenhuma ferramenta neste turno.</p>
        <?php else: ?>
            <table class="tabela">
                <thead><tr><th>Ferramenta</th><th>Situação</th><th>Duração</th><th>Parâmetros</th></tr></thead>
                <tbody>
                <?php foreach ($ferramentasDoTurno as $f): ?>
                    <tr>
                        <td><strong><?= e((string) ($f['ferramenta'] ?? '—')) ?></strong></td>
                        <td>
                            <span class="tag tag-<?= $f['status'] === 'ok' ? 'ok' : 'erro' ?>"><?= e((string) $f['status']) ?></span>
                            <?php if (!empty($f['erro'])): ?>
                                <br><small style="opacity:.7"><?= e(mb_substr((string) $f['erro'], 0, 160)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($ms($f['duracao_ms'])) ?></code></td>
                        <td><small><code><?= e(mb_substr((string) ($f['params'] ?? ''), 0, 200)) ?></code></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="dica-painel" style="margin-top:1rem">
            O resto do que aconteceu neste turno está no log de erros do servidor, sob o mesmo
            identificador: <code>grep '"trace":"<?= e($traceFiltro) ?>"'</code>
        </p>

        <div class="form-acoes">
            <a href="<?= e($comFiltros([])) ?>" class="btn btn-secondary">Fechar detalhe</a>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>

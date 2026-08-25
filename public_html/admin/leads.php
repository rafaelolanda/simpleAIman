<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Jobs\Queue;

$paginaAtual = 'leads.php';
$tituloPagina = 'Leads';

$filtro = valor_em($_GET['status'] ?? 'todos', ['todos', 'pendente', 'erro', 'ok', 'descartado'], 'todos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('leads.php');
    }

    $acao = $_POST['acao'] ?? '';

    // Reenvio manual da dead letter. Zera as tentativas para o backoff
    // recomeçar do início — quem clica aqui normalmente acabou de consertar
    // algo do lado do destino.
    if ($acao === 'reenviar') {
        $destinoId = (int) ($_POST['destino_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT lead_id FROM lead_destinos WHERE id = :id');
        $stmt->execute(['id' => $destinoId]);
        $leadId = (int) $stmt->fetchColumn();

        if ($leadId > 0) {
            $pdo->prepare(
                'UPDATE lead_destinos SET status = \'pendente\', tentativas = 0, erro = NULL,
                        proxima_tentativa_em = :agora, editado_em = :agora
                 WHERE id = :id'
            )->execute(['agora' => now(), 'id' => $destinoId]);

            Queue::enfileirar('entrega_lead', ['lead_id' => $leadId], 1);
            Queue::cutucarWorker();

            Auth::log('lead_reenviado', 'destino=' . $destinoId);
            flash_set('sucesso', 'Reenvio enfileirado.');
        }

        redirect('leads.php?status=' . $filtro);
    }

    if ($acao === 'exportar') {
        // CSV para quem não tem integração: ainda assim o lead precisa sair
        // do sistema de alguma forma.
        $linhas = $pdo->query(
            'SELECT l.id, l.nome, l.email, l.telefone, l.campos_extra, l.criado_em, a.nome AS agente
             FROM leads l LEFT JOIN agentes a ON a.id = l.agente_id ORDER BY l.id DESC'
        )->fetchAll();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');

        $saida = fopen('php://output', 'w');
        // BOM para o Excel abrir acentuação corretamente.
        fwrite($saida, "\xEF\xBB\xBF");
        fputcsv($saida, ['id', 'nome', 'email', 'telefone', 'extras', 'agente', 'criado_em'], ';');

        foreach ($linhas as $l) {
            fputcsv($saida, [
                $l['id'], $l['nome'], $l['email'], $l['telefone'],
                (string) $l['campos_extra'], $l['agente'], $l['criado_em'],
            ], ';');
        }

        fclose($saida);
        exit;
    }
}

$sql = 'SELECT l.*, a.nome AS agente,
               (SELECT COUNT(*) FROM lead_destinos d WHERE d.lead_id = l.id) AS destinos
        FROM leads l LEFT JOIN agentes a ON a.id = l.agente_id';

if ($filtro !== 'todos') {
    $sql .= " WHERE EXISTS (SELECT 1 FROM lead_destinos d WHERE d.lead_id = l.id AND d.status = '" . $filtro . "')";
}

$leads = $pdo->query($sql . ' ORDER BY l.id DESC LIMIT 200')->fetchAll();

$entregas = [];

if ($leads !== []) {
    $ids = implode(',', array_map(static fn (array $l): int => (int) $l['id'], $leads));

    foreach ($pdo->query(
        'SELECT d.*, f.nome AS ferramenta FROM lead_destinos d
         LEFT JOIN ferramentas f ON f.id = d.ferramenta_id
         WHERE d.lead_id IN (' . $ids . ') ORDER BY d.id'
    )->fetchAll() as $d) {
        $entregas[(int) $d['lead_id']][] = $d;
    }
}

$contagem = [];

foreach ($pdo->query('SELECT status, COUNT(*) t FROM lead_destinos GROUP BY status')->fetchAll() as $r) {
    $contagem[$r['status']] = (int) $r['t'];
}

$comErro = $contagem['erro'] ?? 0;

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Leads</h1>
    <p class="page-sub">
        Contatos captados pelo agente. O lead é gravado <strong>aqui primeiro</strong> e só depois
        enviado ao destino — se a integração falhar, ele não se perde.
    </p>
</div>

<?php if ($comErro > 0): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-erro">
            <?= $comErro ?> entrega(s) desistiram depois de 5 tentativas. São leads reais que
            <strong>não chegaram ao destino</strong> — confira o motivo e reenvie.
        </p>
    </div>
<?php endif; ?>

<div class="card">
    <div class="chat-topo">
        <h2 class="card-title" style="margin:0"><?= count($leads) ?> lead(s)</h2>
        <div class="form-acoes">
            <?php foreach (['todos' => 'Todos', 'pendente' => 'Na fila', 'erro' => 'Falharam', 'descartado' => 'Descartados', 'ok' => 'Entregues'] as $k => $rotulo): ?>
                <a href="?status=<?= e($k) ?>" class="btn btn-<?= $filtro === $k ? 'primary' : 'secondary' ?> btn-sm">
                    <?= e($rotulo) ?><?= isset($contagem[$k]) ? ' (' . $contagem[$k] . ')' : '' ?>
                </a>
            <?php endforeach; ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="exportar">
                <button type="submit" class="btn btn-secondary btn-sm">Exportar CSV</button>
            </form>
        </div>
    </div>

    <?php if (!$leads): ?>
        <p class="vazio">
            Nenhum lead ainda. Para o agente captar, ligue a ferramenta de captura em
            <a href="ferramentas.php">Ferramentas</a> e vincule-a a um agente.
        </p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>#</th>
                <th>Contato</th>
                <th>Origem</th>
                <th>Entrega</th>
                <th>Quando</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l): ?>
                <tr>
                    <td><?= (int) $l['id'] ?></td>
                    <td>
                        <strong><?= e((string) ($l['nome'] ?? '—')) ?></strong>
                        <br><small style="opacity:.75">
                            <?php if ($l['email']): ?><?= e((string) $l['email']) ?><?php endif; ?>
                            <?php if ($l['telefone']): ?><?= $l['email'] ? ' · ' : '' ?><?= e((string) $l['telefone']) ?><?php endif; ?>
                        </small>
                        <?php $extras = json_para_array($l['campos_extra'] ?? null); ?>
                        <?php if ($extras): ?>
                            <br><small style="opacity:.55">
                                <?php foreach ($extras as $k => $v): ?>
                                    <?= e((string) $k) ?>:
                                    <?php // CPF mascarado na listagem: mostra o suficiente para
                                          // identificar de quem se trata, sem expor o número
                                          // inteiro numa tela que alguém pode estar projetando. ?>
                                    <?= e($k === 'cpf' ? cpf_mascarar((string) $v) : (string) $v) ?>
                                <?php endforeach; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= e((string) ($l['agente'] ?? '—')) ?></small>
                        <?php if ($l['conversa_id']): ?>
                            <br><a href="conversas.php?ver=<?= (int) $l['conversa_id'] ?>" style="font-size:.78rem">ver conversa</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($entregas[(int) $l['id']])): ?>
                            <span class="tag tag-neutro">só no painel</span>
                        <?php else: ?>
                            <?php foreach ($entregas[(int) $l['id']] as $d): ?>
                                <?php
                                $classe = match ($d['status']) {
                                    'ok' => 'ok',
                                    'erro' => 'erro',
                                    'descartado' => 'neutro',
                                    default => 'neutro',
                                };
                                ?>
                                <div style="margin-bottom:.35rem">
                                    <span class="tag tag-<?= $classe ?>"><?= e((string) $d['status']) ?></span>
                                    <small style="opacity:.7"><?= e((string) ($d['ferramenta'] ?? '—')) ?></small>

                                    <?php if ($d['erro']): ?>
                                        <br><small style="color:#b91c1c"><?= e(mb_substr((string) $d['erro'], 0, 90)) ?></small>
                                    <?php endif; ?>

                                    <?php if ((int) $d['tentativas'] > 0): ?>
                                        <br><small style="opacity:.55"><?= (int) $d['tentativas'] ?> tentativa(s)</small>
                                    <?php endif; ?>

                                    <?php if (in_array($d['status'], ['erro', 'descartado'], true)): ?>
                                        <form method="post" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="reenviar">
                                            <input type="hidden" name="destino_id" value="<?= (int) $d['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Reenviar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><small style="opacity:.6"><?= e(date('d/m/Y H:i', strtotime((string) $l['criado_em']))) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

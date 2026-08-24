<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'logs.php';
$tituloPagina = 'Logs';

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 40;
$offset = ($pagina - 1) * $porPagina;

$total = (int) $pdo->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn();
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$stmt = $pdo->prepare(
    'SELECT admin_logs.*, admin_users.usuario
     FROM admin_logs
     LEFT JOIN admin_users ON admin_users.id = admin_logs.admin_user_id
     ORDER BY admin_logs.id DESC
     LIMIT :limite OFFSET :offset'
);
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Logs do admin</h1>
        <p>Histórico de ações realizadas no painel (<?= $total ?> registros).</p>
    </div>
</div>

<div class="panel">
    <?php if (empty($logs)): ?>
        <div class="empty-state">Nenhum registro ainda.</div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="cards-mobile">
            <thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>Detalhes</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td data-label="Quando"><?= e($log['criado_em']) ?></td>
                    <td data-label="Usuário"><?= e($log['usuario'] ?? '—') ?></td>
                    <td data-label="Ação"><?= e($log['acao']) ?></td>
                    <td data-label="Detalhes"><?= e($log['detalhes'] ?? '') ?></td>
                    <td data-label="IP"><?= e($log['ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
            <div class="actions-row" style="justify-content:center;">
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <a href="logs.php?pagina=<?= $p ?>" class="btn <?= $p === $pagina ? '' : 'btn-secondary' ?> btn-sm"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

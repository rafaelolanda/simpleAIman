<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'conversas.php';
$tituloPagina = 'Conversas';

$abrindo = (int) ($_GET['ver'] ?? 0);
$filtro = trim((string) ($_GET['q'] ?? ''));

// -------------------------------------------------------------------------
// Conversa aberta
// -------------------------------------------------------------------------
$conversa = null;
$mensagens = [];

if ($abrindo > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.*, a.nome AS agente, ca.nome AS canal, ca.tipo AS canal_tipo
         FROM conversas c
         LEFT JOIN agentes a ON a.id = c.agente_id
         LEFT JOIN canais ca ON ca.id = c.canal_id
         WHERE c.id = :id'
    );
    $stmt->execute(['id' => $abrindo]);
    $conversa = $stmt->fetch() ?: null;

    if ($conversa) {
        $stmt = $pdo->prepare('SELECT * FROM mensagens WHERE conversa_id = :id ORDER BY id');
        $stmt->execute(['id' => $abrindo]);
        $mensagens = $stmt->fetchAll();

        // Fontes por mensagem, com o rótulo já resolvido: o atendente precisa
        // saber DE ONDE o bot tirou a resposta, não do id do chunk.
        $fontes = [];
        $stmt = $pdo->prepare(
            'SELECT f.mensagem_id, f.tipo, f.score,
                    COALESCE(a.titulo, q.pergunta) AS rotulo,
                    json_extract(c.metadados, \'$.secao\') AS secao
             FROM mensagem_fontes f
             LEFT JOIN chunks c ON f.tipo = \'chunk\' AND c.id = f.referencia_id
             LEFT JOIN artefatos a ON a.id = c.artefato_id
             LEFT JOIN faq q ON f.tipo = \'faq\' AND q.id = f.referencia_id
             WHERE f.mensagem_id IN (SELECT id FROM mensagens WHERE conversa_id = :id)
             ORDER BY f.score DESC'
        );
        $stmt->execute(['id' => $abrindo]);

        foreach ($stmt->fetchAll() as $f) {
            $fontes[(int) $f['mensagem_id']][] = $f;
        }
    }
}

// -------------------------------------------------------------------------
// Listagem
// -------------------------------------------------------------------------
$sql = 'SELECT c.id, c.modo, c.criado_em, c.editado_em, c.externo_id,
               a.nome AS agente, ca.tipo AS canal_tipo,
               (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id) AS msgs,
               (SELECT COUNT(*) FROM mensagens m WHERE m.conversa_id = c.id AND m.autor_tipo = \'sistema\') AS falhas,
               (SELECT m.conteudo FROM mensagens m WHERE m.conversa_id = c.id AND m.autor_tipo = \'usuario\'
                ORDER BY m.id LIMIT 1) AS primeira
        FROM conversas c
        LEFT JOIN agentes a ON a.id = c.agente_id
        LEFT JOIN canais ca ON ca.id = c.canal_id';

$params = [];

if ($filtro !== '') {
    $sql .= ' WHERE EXISTS (SELECT 1 FROM mensagens m WHERE m.conversa_id = c.id AND m.conteudo LIKE :q)';
    $params['q'] = '%' . $filtro . '%';
}

$sql .= ' ORDER BY c.id DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$conversas = $stmt->fetchAll();

/**
 * Perguntas que o agente NÃO conseguiu responder pelos documentos.
 *
 * É a lista mais valiosa da tela: cada uma dessas é candidata a virar FAQ
 * curada ou a apontar um documento que falta indexar. Sem ela, a curadoria
 * viraria adivinhação sobre o que as pessoas perguntam.
 */
$semResposta = $pdo->query(
    'SELECT u.conteudo, COUNT(*) AS vezes, MAX(u.criado_em) AS ultima
     FROM mensagens u
     JOIN mensagens b ON b.conversa_id = u.conversa_id AND b.id = (
         SELECT MIN(id) FROM mensagens x WHERE x.conversa_id = u.conversa_id AND x.id > u.id AND x.autor_tipo = \'bot\'
     )
     WHERE u.autor_tipo = \'usuario\'
       AND (b.conteudo LIKE \'%não encontrei%\' OR b.conteudo LIKE \'%nao encontrei%\'
            OR b.conteudo LIKE \'%não tenho essa informação%\')
     GROUP BY lower(trim(u.conteudo))
     ORDER BY vezes DESC, ultima DESC
     LIMIT 15'
)->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Conversas</h1>
    <p class="page-sub">
        O que as pessoas realmente perguntaram. É a melhor fonte para decidir o que vira
        <a href="faq.php">FAQ curada</a> e qual documento falta indexar — melhor que adivinhar.
    </p>
</div>

<?php if ($semResposta): ?>
    <div class="card">
        <h2 class="card-title">Perguntas sem resposta nos documentos</h2>
        <p class="vazio" style="margin-bottom:0.8rem;">
            O agente admitiu não ter a informação. Cada linha é candidata a virar FAQ ou a indicar
            um documento faltando.
        </p>
        <table class="tabela">
            <thead><tr><th>Pergunta</th><th>Vezes</th><th>Última</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($semResposta as $s): ?>
                <tr>
                    <td><?= e(mb_substr((string) $s['conteudo'], 0, 110)) ?></td>
                    <td><?= (int) $s['vezes'] ?></td>
                    <td><small style="opacity:.6"><?= e(date('d/m H:i', strtotime((string) $s['ultima']))) ?></small></td>
                    <td class="acoes">
                        <a class="btn btn-secondary btn-sm" href="faq.php">Criar FAQ</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($conversa): ?>
    <div class="card">
        <div class="chat-topo">
            <h2 class="card-title" style="margin:0">
                Conversa #<?= (int) $conversa['id'] ?>
                <span class="tag"><?= e((string) ($conversa['canal_tipo'] ?? 'web')) ?></span>
                <span class="tag tag-<?= $conversa['modo'] === 'bot' ? 'neutro' : 'ok' ?>"><?= e((string) $conversa['modo']) ?></span>
            </h2>
            <small style="opacity:.7">
                <?= e((string) ($conversa['agente'] ?? '—')) ?> ·
                <?= e(date('d/m/Y H:i', strtotime((string) $conversa['criado_em']))) ?>
                <?php if ($conversa['ultima_msg_usuario_em']): ?>
                    <?php
                    // Janela de 24h da Meta: fora dela só template aprovado.
                    // Quando o atendimento humano existir, é aqui que ele
                    // descobre se ainda pode responder livremente.
                    $horas = (time() - strtotime((string) $conversa['ultima_msg_usuario_em'])) / 3600;
                    ?>
                    · <?= $horas > 24
                        ? '<span class="tag tag-erro">fora da janela de 24h</span>'
                        : 'última fala há ' . (int) $horas . 'h' ?>
                <?php endif; ?>
            </small>
        </div>

        <div class="chat" style="max-height:none">
            <?php foreach ($mensagens as $m): ?>
                <?php if ($m['autor_tipo'] === 'sistema'): ?>
                    <div class="bolha sistema" title="Falha técnica — não foi mostrada ao visitante"><span class="bolha-corpo"><?= e((string) $m['conteudo']) ?></span></div>
                    <?php continue; ?>
                <?php endif; ?>

                <div class="bolha <?= $m['autor_tipo'] === 'usuario' ? 'user' : 'bot' ?>"><span class="bolha-corpo"><?= e((string) $m['conteudo']) ?></span><?php if (!empty($fontes[(int) $m['id']])): ?>
                        <div class="bolha-fontes">
                            <?php foreach ($fontes[(int) $m['id']] as $f): ?>
                                <?= $f['tipo'] === 'faq' ? 'FAQ' : 'doc' ?>:
                                <?= e(mb_substr((string) $f['rotulo'], 0, 52)) ?><?php if ($f['secao']): ?> — <?= e((string) $f['secao']) ?><?php endif; ?>
                                (<?= number_format((float) $f['score'], 3, ',', '.') ?>)<br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?><span class="bolha-meta">
                        <?= e(date('d/m H:i:s', strtotime((string) $m['criado_em']))) ?>
                        <?php if ($m['autor_tipo'] === 'atendente'): ?> · atendente<?php endif; ?>
                        <?php if ($m['latencia_ms']): ?> · <?= (int) $m['latencia_ms'] ?> ms<?php endif; ?>
                        <?php if ($m['tokens_in']): ?> · <?= (int) $m['tokens_in'] ?>/<?= (int) $m['tokens_out'] ?> tokens<?php endif; ?>
                    </span></div>
            <?php endforeach; ?>
        </div>

        <div class="form-acoes">
            <a href="conversas.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="chat-topo">
        <h2 class="card-title" style="margin:0"><?= count($conversas) ?> conversa(s)</h2>
        <form method="get" class="chat-form" style="flex:0 1 320px">
            <input type="text" name="q" value="<?= e($filtro) ?>" placeholder="buscar no conteúdo…">
            <button type="submit" class="btn btn-secondary">Buscar</button>
        </form>
    </div>

    <?php if (!$conversas): ?>
        <p class="vazio">Nenhuma conversa ainda. Converse pelo <a href="playground.php">Playground</a> para gerar histórico.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>#</th>
                <th>Primeira pergunta</th>
                <th>Agente</th>
                <th>Mensagens</th>
                <th>Início</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($conversas as $c): ?>
                <tr>
                    <td><?= (int) $c['id'] ?></td>
                    <td>
                        <?= e(mb_substr((string) ($c['primeira'] ?? '—'), 0, 82)) ?>
                        <?php if ($c['falhas']): ?>
                            <br><span class="tag tag-erro"><?= (int) $c['falhas'] ?> falha(s) técnica(s)</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= e((string) ($c['agente'] ?? '—')) ?></small></td>
                    <td><?= (int) $c['msgs'] ?></td>
                    <td><small style="opacity:.6"><?= e(date('d/m H:i', strtotime((string) $c['criado_em']))) ?></small></td>
                    <td class="acoes"><a href="?ver=<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

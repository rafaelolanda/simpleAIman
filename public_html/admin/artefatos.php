<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Jobs\Queue;
use SimpleAIman\Rag\Ingestor;

$paginaAtual = 'artefatos.php';
$tituloPagina = 'Artefatos';

$ingestor = new Ingestor();
$aceitas = $ingestor->extensoesAceitas();
$baseFiltro = (int) ($_GET['base'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('artefatos.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    // -----------------------------------------------------------------
    if ($acao === 'excluir') {
        $stmt = $pdo->prepare('SELECT arquivo FROM artefatos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $arquivo = (string) $stmt->fetchColumn();

        // Os chunks, embeddings e o índice FTS saem por cascata e trigger.
        $pdo->prepare('DELETE FROM artefatos WHERE id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM jobs WHERE tipo = \'ingestao\' AND payload LIKE :p')
            ->execute(['p' => '%"artefato_id":' . $id . '%']);

        if ($arquivo !== '') {
            @unlink(caminho_uploads('artefatos') . '/' . $arquivo);
        }

        Auth::log('artefato_excluido', 'id=' . $id);
        flash_set('sucesso', 'Artefato excluído.');
        redirect('artefatos.php' . ($baseFiltro ? '?base=' . $baseFiltro : ''));
    }

    // -----------------------------------------------------------------
    if ($acao === 'reindexar') {
        $pdo->prepare('UPDATE artefatos SET status = \'pendente\', erro = NULL, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $id]);

        // Sem progresso salvo, o worker refaz a extração — que é o ponto de
        // reindexar: o chunk pode ter mudado de tamanho ou o modelo, de espaço.
        $pdo->prepare('DELETE FROM jobs WHERE tipo = \'ingestao\' AND payload LIKE :p')
            ->execute(['p' => '%"artefato_id":' . $id . '%']);

        Queue::enfileirar('ingestao', ['artefato_id' => $id], 1);
        Queue::cutucarWorker();

        Auth::log('artefato_reindexado', 'id=' . $id);
        flash_set('sucesso', 'Reindexação enfileirada.');
        redirect('artefatos.php' . ($baseFiltro ? '?base=' . $baseFiltro : ''));
    }

    // -----------------------------------------------------------------
    if ($acao === 'enviar') {
        $baseId = (int) ($_POST['base_id'] ?? 0);
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $arquivo = $_FILES['arquivo'] ?? null;

        $erro = match (true) {
            $baseId <= 0 => 'Escolha uma base.',
            !is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE => 'Escolha um arquivo.',
            // Tamanho excedido chega como código do PHP, e a mensagem padrão
            // não diz o limite — melhor dizer aqui.
            in_array($arquivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                => 'Arquivo maior que o limite de ' . UPLOAD_MAX_MB . ' MB.',
            $arquivo['error'] !== UPLOAD_ERR_OK => 'Falha no envio do arquivo (código ' . $arquivo['error'] . ').',
            $arquivo['size'] > UPLOAD_MAX_MB * 1024 * 1024
                => 'Arquivo de ' . formatar_bytes((int) $arquivo['size']) . ' excede o limite de ' . UPLOAD_MAX_MB . ' MB.',
            default => null,
        };

        // A extensão vem do nome ENVIADO, mas o nome final é gerado por nós:
        // usar o nome original permitiria caminho relativo, colisão e
        // sobrescrita de arquivo alheio.
        $extensao = $erro === null
            ? strtolower(pathinfo((string) $arquivo['name'], PATHINFO_EXTENSION))
            : '';

        if ($erro === null && !in_array($extensao, $aceitas, true)) {
            $erro = 'Tipo .' . $extensao . ' não suportado. Aceitos: ' . implode(', ', $aceitas) . '.';
        }

        if ($erro !== null) {
            flash_set('erro', $erro);
            redirect('artefatos.php' . ($baseFiltro ? '?base=' . $baseFiltro : ''));
        }

        $hash = hash_file('sha256', $arquivo['tmp_name']);

        $dup = $pdo->prepare('SELECT titulo FROM artefatos WHERE base_id = :b AND hash = :h');
        $dup->execute(['b' => $baseId, 'h' => $hash]);
        $jaExiste = $dup->fetchColumn();

        if ($jaExiste !== false) {
            flash_set('erro', 'Este arquivo já está nesta base como "' . $jaExiste . '". Reindexe-o em vez de subir de novo.');
            redirect('artefatos.php' . ($baseFiltro ? '?base=' . $baseFiltro : ''));
        }

        $destino = caminho_uploads('artefatos');

        if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
            flash_set('erro', 'Não foi possível criar a pasta de uploads.');
            redirect('artefatos.php');
        }

        $nomeArquivo = date('Ymd-His') . '-' . substr($hash, 0, 8) . '.' . $extensao;

        if (!move_uploaded_file($arquivo['tmp_name'], $destino . '/' . $nomeArquivo)) {
            flash_set('erro', 'Não foi possível gravar o arquivo enviado.');
            redirect('artefatos.php');
        }

        $agora = now();

        $pdo->prepare(
            'INSERT INTO artefatos (base_id, setor_id, tipo, titulo, arquivo, hash, tamanho, status, criado_em, editado_em)
             VALUES (:base, :setor, :tipo, :titulo, :arquivo, :hash, :tamanho, \'pendente\', :agora, :agora)'
        )->execute([
            'base' => $baseId,
            'setor' => ((int) ($_POST['setor_id'] ?? 0)) ?: null,
            'tipo' => $extensao,
            'titulo' => $titulo !== '' ? $titulo : pathinfo((string) $arquivo['name'], PATHINFO_FILENAME),
            'arquivo' => $nomeArquivo,
            'hash' => $hash,
            'tamanho' => (int) $arquivo['size'],
            'agora' => $agora,
        ]);

        $artefatoId = (int) $pdo->lastInsertId();

        // Enfileira e responde na hora. A ingestão roda em segundo plano —
        // extrair, chunkar e embeddar um PDF grande estoura qualquer
        // max_execution_time se feito dentro do request.
        Queue::enfileirar('ingestao', ['artefato_id' => $artefatoId]);
        Queue::cutucarWorker();

        Auth::log('artefato_enviado', $nomeArquivo);
        flash_set('sucesso', 'Arquivo recebido. A indexação começou e você acompanha o progresso aqui.');
        redirect('artefatos.php?base=' . $baseId);
    }
}

$bases = $pdo->query('SELECT id, nome FROM bases WHERE ativo = 1 ORDER BY nome')->fetchAll();
$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

$sql = 'SELECT a.*, b.nome AS base,
               (SELECT COUNT(*) FROM chunks c WHERE c.artefato_id = a.id) AS chunks,
               (SELECT COUNT(*) FROM embeddings e JOIN chunks c ON c.id = e.chunk_id WHERE c.artefato_id = a.id) AS vetores
        FROM artefatos a JOIN bases b ON b.id = a.base_id';

if ($baseFiltro > 0) {
    $sql .= ' WHERE a.base_id = ' . $baseFiltro;
}

$artefatos = $pdo->query($sql . ' ORDER BY a.id DESC')->fetchAll();
$fila = SimpleAIman\Jobs\Queue::resumo();

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Artefatos</h1>
    <p class="page-sub">
        Documentos que alimentam o RAG. O envio responde na hora e a indexação roda em segundo plano —
        um PDF grande não caberia no tempo de uma requisição.
    </p>
</div>

<?php if (!$bases): ?>
    <div class="card"><p class="alerta alerta-erro">Nenhuma base ativa. Crie uma em <a href="bases.php">Bases</a>.</p></div>
<?php else: ?>

<div class="card">
    <h2 class="card-title">Enviar documento</h2>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="enviar">

        <div class="form-grid">
            <label>
                Base
                <select name="base_id" required>
                    <?php foreach ($bases as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= $baseFiltro === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Setor responsável
                <select name="setor_id">
                    <option value="0">—</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Título
                <input type="text" name="titulo" placeholder="usa o nome do arquivo se vazio">
            </label>

            <label>
                Arquivo
                <input type="file" name="arquivo" required accept="<?= e('.' . implode(',.', $aceitas)) ?>">
                <small>Aceitos: <?= e(implode(', ', $aceitas)) ?> · até <?= UPLOAD_MAX_MB ?> MB</small>
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary">Enviar e indexar</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="chat-topo">
        <h2 class="card-title" style="margin:0">
            Documentos
            <?php if ($baseFiltro): ?>
                <a href="artefatos.php" class="tag tag-neutro" style="text-decoration:none">remover filtro</a>
            <?php endif; ?>
        </h2>
        <small style="opacity:.7">
            Fila: <?= (int) $fila['pendente'] ?> pendente · <?= (int) $fila['processando'] ?> em curso · <?= (int) $fila['erro'] ?> com erro
        </small>
    </div>

    <?php if (!$artefatos): ?>
        <p class="vazio">Nenhum artefato enviado.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Documento</th>
                <th>Base</th>
                <th>Situação</th>
                <th>Indexação</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($artefatos as $a): ?>
                <?php
                $chunks = (int) $a['chunks'];
                $vetores = (int) $a['vetores'];
                $pct = $chunks > 0 ? (int) round($vetores / $chunks * 100) : 0;
                $classe = match ($a['status']) {
                    'ok' => 'ok',
                    'erro' => 'erro',
                    default => 'neutro',
                };
                ?>
                <tr>
                    <td>
                        <strong><?= e($a['titulo']) ?></strong>
                        <br><small style="opacity:.6"><?= e($a['tipo']) ?> · <?= formatar_bytes((int) $a['tamanho']) ?> · <?= e(date('d/m/Y H:i', strtotime($a['criado_em']))) ?></small>
                    </td>
                    <td><?= e($a['base']) ?></td>
                    <td>
                        <span class="tag tag-<?= $classe ?>"><?= e($a['status']) ?></span>
                        <?php if ($a['erro']): ?>
                            <br><small style="color:#b91c1c"><?= e(mb_substr((string) $a['erro'], 0, 140)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($chunks === 0): ?>
                            <small style="opacity:.6">aguardando</small>
                        <?php else: ?>
                            <?= $vetores ?>/<?= $chunks ?> chunks
                            <div class="barra"><span style="width:<?= $pct ?>%"></span></div>
                        <?php endif; ?>
                    </td>
                    <td class="acoes">
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="reindexar">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Reindexar</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir este artefato e seus chunks?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Recarrega enquanto houver trabalho em andamento — o progresso vem
        // do worker, que roda fora deste request.
        $emAndamento = array_filter($artefatos, static fn (array $a): bool => in_array($a['status'], ['pendente', 'processando'], true));
        ?>
        <?php if ($emAndamento): ?>
            <p class="vazio" style="margin-top:0.75rem;">Indexação em andamento — esta página se atualiza sozinha.</p>
            <script>setTimeout(() => location.reload(), 5000);</script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>

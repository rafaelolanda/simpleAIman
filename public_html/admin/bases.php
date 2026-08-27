<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'bases.php';
$tituloPagina = 'Bases';

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('bases.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        $artefatos = (int) $pdo->query('SELECT COUNT(*) FROM artefatos WHERE base_id = ' . $id)->fetchColumn();

        if ($artefatos > 0) {
            flash_set('erro', "Esta base tem {$artefatos} artefato(s). Exclua-os antes.");
        } else {
            $pdo->prepare('DELETE FROM bases WHERE id = :id')->execute(['id' => $id]);
            Auth::log('base_excluida', 'id=' . $id);
            flash_set('sucesso', 'Base excluída.');
        }

        redirect('bases.php');
    }

    if ($acao === 'salvar') {
        $nome = trim(texto_utf8($_POST['nome'] ?? ''));

        if ($nome === '') {
            flash_set('erro', 'O nome é obrigatório.');
            redirect('bases.php');
        }

        $dados = [
            'nome' => $nome,
            'slug' => slugify(trim(texto_utf8($_POST['slug'] ?? '')) ?: $nome),
            'descricao' => trim(texto_utf8($_POST['descricao'] ?? '')),
            'setor_id' => ((int) ($_POST['setor_id'] ?? 0)) ?: null,
            'provedor_embedding_id' => ((int) ($_POST['provedor_embedding_id'] ?? 0)) ?: null,
            'modelo_embedding' => trim(texto_utf8($_POST['modelo_embedding'] ?? '')),
            'dimensoes' => (int) ($_POST['dimensoes'] ?? 768),
            'chunk_tamanho' => max(200, (int) ($_POST['chunk_tamanho'] ?? 800)),
            'chunk_sobreposicao' => max(0, (int) ($_POST['chunk_sobreposicao'] ?? 120)),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        // Sobreposição maior que o próprio chunk faria o texto repetir sem
        // avançar — o chunker travaria em laço se não houvesse avanço mínimo.
        if ($dados['chunk_sobreposicao'] >= $dados['chunk_tamanho']) {
            flash_set('erro', 'A sobreposição precisa ser menor que o tamanho do chunk.');
            redirect('bases.php');
        }

        $agora = now();

        if ($id > 0) {
            // Trocar o modelo de embedding invalida os vetores já gravados:
            // eles vivem num espaço vetorial diferente. Comparar vetores de
            // modelos distintos não dá erro — dá resultado ruim em silêncio.
            $atual = $pdo->prepare('SELECT modelo_embedding, dimensoes FROM bases WHERE id = :id');
            $atual->execute(['id' => $id]);
            $antes = $atual->fetch();

            $mudouModelo = $antes
                && ($antes['modelo_embedding'] !== $dados['modelo_embedding']
                    || (int) $antes['dimensoes'] !== $dados['dimensoes']);

            $dados['editado_em'] = $agora;
            $dados['id'] = $id;

            $pdo->prepare(
                'UPDATE bases SET nome=:nome, slug=:slug, descricao=:descricao, setor_id=:setor_id,
                        provedor_embedding_id=:provedor_embedding_id, modelo_embedding=:modelo_embedding,
                        dimensoes=:dimensoes, chunk_tamanho=:chunk_tamanho,
                        chunk_sobreposicao=:chunk_sobreposicao, ativo=:ativo, editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('base_editada', $nome);

            $vetores = (int) $pdo->query('SELECT COUNT(*) FROM embeddings WHERE base_id = ' . $id)->fetchColumn();

            flash_set(
                $mudouModelo && $vetores > 0 ? 'erro' : 'sucesso',
                $mudouModelo && $vetores > 0
                    ? "Base salva, mas o modelo de embedding mudou e há {$vetores} vetor(es) gravados com o modelo anterior. Reindexe os artefatos — até lá a busca fica inconsistente."
                    : 'Base atualizada.'
            );
        } else {
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;

            $pdo->prepare(
                'INSERT INTO bases (nome, slug, descricao, setor_id, provedor_embedding_id, modelo_embedding,
                        dimensoes, chunk_tamanho, chunk_sobreposicao, ativo, criado_em, editado_em)
                 VALUES (:nome, :slug, :descricao, :setor_id, :provedor_embedding_id, :modelo_embedding,
                        :dimensoes, :chunk_tamanho, :chunk_sobreposicao, :ativo, :criado_em, :editado_em)'
            )->execute($dados);

            Auth::log('base_criada', $nome);
            flash_set('sucesso', 'Base criada.');
        }

        redirect('bases.php');
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM bases WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$bases = $pdo->query(
    'SELECT b.*, p.nome AS provedor, s.nome AS setor,
            (SELECT COUNT(*) FROM artefatos a WHERE a.base_id = b.id) AS artefatos,
            (SELECT COUNT(*) FROM chunks c WHERE c.base_id = b.id) AS chunks,
            (SELECT COUNT(*) FROM embeddings e WHERE e.base_id = b.id) AS vetores
     FROM bases b
     LEFT JOIN provedores p ON p.id = b.provedor_embedding_id
     LEFT JOIN setores s ON s.id = b.setor_id
     ORDER BY b.nome'
)->fetchAll();

$provedores = $pdo->query('SELECT id, nome, modelo_embedding, dimensoes FROM provedores ORDER BY nome')->fetchAll();
$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Bases de conhecimento</h1>
    <p class="page-sub">
        Onde os artefatos são indexados. O modelo de embedding fica <strong>na base</strong>, não numa
        configuração global — trocá-lo invalida os vetores dela, e só dela.
    </p>
</div>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar base' : 'Nova base' ?></h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e($editando['nome'] ?? '') ?>" placeholder="ex.: Editais e regulamentos">
            </label>

            <label>
                Identificador
                <input type="text" name="slug" value="<?= e($editando['slug'] ?? '') ?>" placeholder="gerado do nome se vazio">
            </label>

            <label class="col-2">
                Descrição
                <input type="text" name="descricao" value="<?= e($editando['descricao'] ?? '') ?>" placeholder="o que esta base contém">
            </label>

            <label>
                Setor responsável
                <select name="setor_id">
                    <option value="0">—</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($editando['setor_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>
                    Quem transforma o texto em vetor. <strong>Trocar depois de indexar invalida
                    tudo o que já está nesta base</strong> — os vetores antigos e os novos deixam de
                    ser comparáveis, e a busca passa a devolver resultado ruim sem erro nenhum.
                    Mudou o modelo, reindexe a base.
                </small>
            </label>

            <label>
                Provedor de embedding
                <select name="provedor_embedding_id">
                    <option value="0">(provedor ativo)</option>
                    <?php foreach ($provedores as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($editando['provedor_embedding_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['nome']) ?> · <?= e((string) $p['modelo_embedding']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Modelo de embedding
                <input type="text" name="modelo_embedding" value="<?= e($editando['modelo_embedding'] ?? 'gemini-embedding-001') ?>">
                <small>Mudar depois de indexar exige reindexar tudo desta base.</small>
            </label>

            <label>
                Dimensões
                <input type="number" name="dimensoes" value="<?= (int) ($editando['dimensoes'] ?? 768) ?>" min="0" step="1">
                <small>768 responde em ~230 ms com 5 mil chunks; 1536 leva o dobro.</small>
            </label>

            <label>
                Tamanho do chunk
                <input type="number" name="chunk_tamanho" value="<?= (int) ($editando['chunk_tamanho'] ?? 800) ?>" min="200" step="50">
                <small>Em caracteres. Corta sempre na maior fronteira: parágrafo, frase, palavra.</small>
            </label>

            <label>
                Sobreposição
                <input type="number" name="chunk_sobreposicao" value="<?= (int) ($editando['chunk_sobreposicao'] ?? 120) ?>" min="0" step="10">
                <small>Repete o fim do chunk anterior. Evita perder a resposta que cai na emenda.</small>
            </label>
        </div>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="ativo" <?= ($editando['ativo'] ?? 1) ? 'checked' : '' ?>> Ativa</label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?><a href="bases.php" class="btn btn-secondary">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Bases cadastradas</h2>
    <?php if (!$bases): ?>
        <p class="vazio">Nenhuma base cadastrada.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Nome</th>
                <th>Embedding</th>
                <th>Chunk</th>
                <th>Conteúdo</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bases as $b): ?>
                <?php $pendentes = (int) $b['chunks'] - (int) $b['vetores']; ?>
                <tr>
                    <td>
                        <strong><?= e($b['nome']) ?></strong>
                        <?php if (!$b['ativo']): ?><span class="tag tag-neutro">inativa</span><?php endif; ?>
                        <br><small style="opacity:.6"><?= e((string) $b['descricao']) ?: e($b['slug']) ?></small>
                    </td>
                    <td>
                        <code><?= e((string) $b['modelo_embedding']) ?></code>
                        <br><small style="opacity:.6"><?= (int) $b['dimensoes'] ?> dim · <?= e((string) ($b['provedor'] ?? 'provedor ativo')) ?></small>
                    </td>
                    <td><small><?= (int) $b['chunk_tamanho'] ?> / <?= (int) $b['chunk_sobreposicao'] ?></small></td>
                    <td>
                        <?= (int) $b['artefatos'] ?> artefato(s) · <?= (int) $b['chunks'] ?> chunks
                        <?php if ($pendentes > 0): ?>
                            <br><span class="tag tag-erro"><?= $pendentes ?> sem vetor</span>
                        <?php endif; ?>
                    </td>
                    <td class="acoes">
                        <a href="artefatos.php?base=<?= (int) $b['id'] ?>" class="btn btn-secondary btn-sm">Artefatos</a>
                        <a href="?editar=<?= (int) $b['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta base?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

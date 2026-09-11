<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

use SimpleAIman\Rag\FaqIndexador;

$paginaAtual = 'faq.php';
$tituloPagina = 'FAQ';

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('faq.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        // O vetor sai por cascata (faq_embeddings.faq_id REFERENCES faq).
        $pdo->prepare('DELETE FROM faq WHERE id = :id')->execute(['id' => $id]);
        Auth::log('faq_excluida', 'id=' . $id);
        flash_set('sucesso', 'Pergunta excluída.');
        redirect('faq.php');
    }

    if ($acao === 'reindexar') {
        try {
            $n = (new FaqIndexador())->indexarPendentes(50);
            flash_set('sucesso', $n > 0 ? "{$n} pergunta(s) indexada(s)." : 'Nada pendente — tudo já está indexado.');
        } catch (Throwable $e) {
            \Log::erro('faq_admin_falhou', ['erro' => $e->getMessage()]);
            flash_set('erro', 'Não foi possível indexar agora. Confira o provedor em Provedores › Testar.');
        }

        redirect('faq.php');
    }

    if ($acao === 'nova_categoria') {
        $nome = trim(texto_utf8($_POST['categoria'] ?? ''));

        if ($nome !== '') {
            $pdo->prepare('INSERT INTO faq_categorias (nome, ordem, criado_em, editado_em) VALUES (:n, 0, :a, :a)')
                ->execute(['n' => $nome, 'a' => now()]);
            flash_set('sucesso', 'Categoria criada.');
        }

        redirect('faq.php');
    }

    if ($acao === 'salvar') {
        $pergunta = trim(texto_utf8($_POST['pergunta'] ?? ''));
        $resposta = trim(texto_utf8($_POST['resposta'] ?? ''));

        if ($pergunta === '' || $resposta === '') {
            flash_set('erro', 'Pergunta e resposta são obrigatórias.');
            redirect('faq.php');
        }

        $dados = [
            'pergunta' => $pergunta,
            'resposta' => $resposta,
            'categoria_id' => ((int) ($_POST['categoria_id'] ?? 0)) ?: null,
            'setor_id' => ((int) ($_POST['setor_id'] ?? 0)) ?: null,
            'tags' => trim(texto_utf8($_POST['tags'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'ordem' => (int) ($_POST['ordem'] ?? 0),
        ];

        $agora = now();

        if ($id > 0) {
            $dados['editado_em'] = $agora;
            $dados['id'] = $id;

            $pdo->prepare(
                'UPDATE faq SET pergunta=:pergunta, resposta=:resposta, categoria_id=:categoria_id,
                        setor_id=:setor_id, tags=:tags, ativo=:ativo, ordem=:ordem, editado_em=:editado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('faq_editada', mb_substr($pergunta, 0, 60));
        } else {
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;

            $pdo->prepare(
                'INSERT INTO faq (pergunta, resposta, categoria_id, setor_id, tags, ativo, ordem, criado_em, editado_em)
                 VALUES (:pergunta, :resposta, :categoria_id, :setor_id, :tags, :ativo, :ordem, :criado_em, :editado_em)'
            )->execute($dados);

            Auth::log('faq_criada', mb_substr($pergunta, 0, 60));
        }

        // Indexa na hora: é uma chamada de rede só, e o ganho é a pergunta
        // passar a valer imediatamente. Se falhar, fica pendente e o painel
        // avisa — perder o vetor não pode perder o texto curado.
        FaqIndexador::tentarIndexar();

        flash_set('sucesso', 'Pergunta salva.');
        redirect('faq.php');
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM faq WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$categorias = $pdo->query('SELECT id, nome FROM faq_categorias ORDER BY ordem, nome')->fetchAll();
$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

$perguntas = $pdo->query(
    'SELECT f.*, c.nome AS categoria, s.nome AS setor,
            (e.faq_id IS NOT NULL AND e.criado_em >= f.editado_em) AS indexada
     FROM faq f
     LEFT JOIN faq_categorias c ON c.id = f.categoria_id
     LEFT JOIN setores s ON s.id = f.setor_id
     LEFT JOIN faq_embeddings e ON e.faq_id = f.id
     ORDER BY f.ativo DESC, c.ordem, f.ordem, f.id DESC'
)->fetchAll();

$pendentes = FaqIndexador::pendentes();
$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>FAQ curada</h1>
    <p class="page-sub">
        Perguntas frequentes com resposta escrita por você. Quando o que o visitante digitar for
        parecido o bastante com uma delas, <strong>a resposta sai exatamente como está aqui</strong> —
        sem passar pelo modelo. É mais barato, mais rápido, e a palavra final é sua.
    </p>
    <?php /* A FAQ não tem escopo por agente, ao contrário das bases, que cada agente
             só consulta se estiverem marcadas na aba Conhecimento dele. Decisão de
             11/09/2026: a FAQ continua global, e o aviso é o que impede alguém de
             cadastrar aqui algo que só colaboradores deveriam ver. */ ?>
    <ul class="lista-alertas" style="margin-top:0.9rem;">
        <li class="alerta alerta-aviso">
            <strong>A FAQ vale para todos os agentes</strong> que estiverem com ela ligada — ela não tem
            escopo por agente, como os documentos das bases têm. Qualquer resposta cadastrada aqui pode ser
            entregue, palavra por palavra, a qualquer visitante de qualquer canal.
            <strong>Não cadastre nada que seja só para colaboradores.</strong>
        </li>
    </ul>
</div>

<?php if ($pendentes > 0): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <p class="alerta alerta-aviso">
            <?= $pendentes ?> pergunta(s) sem vetor atualizado — até serem indexadas, elas não são
            encontradas pela busca.
        </p>
        <form method="post" style="margin-top:0.7rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="reindexar">
            <button type="submit" class="btn btn-primary btn-sm">Indexar agora</button>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar pergunta' : 'Nova pergunta' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <label class="col-2">
                Pergunta
                <input type="text" name="pergunta" required value="<?= e((string) $v('pergunta')) ?>" placeholder="ex.: Como faço para trancar a matrícula?">
                <small>
                    Escreva <strong>como o visitante perguntaria</strong>, não como o regulamento diz.
                    É este texto que é comparado com o que ele digita.
                </small>
            </label>

            <label class="col-2">
                Resposta
                <textarea name="resposta" rows="6" required placeholder="A resposta sai exatamente assim, sem reescrita."><?= e((string) $v('resposta')) ?></textarea>
                <small>Texto final, palavra por palavra. Nada aqui passa pelo modelo.</small>
            </label>

            <label>
                Categoria
                <select name="categoria_id">
                    <option value="0">—</option>
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $v('categoria_id', 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Setor responsável
                <select name="setor_id">
                    <option value="0">—</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $v('setor_id', 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Se a pessoa pedir para falar com alguém logo depois, é para cá que ela vai.</small>
            </label>

            <label>
                Outras formas de perguntar
                <input type="text" name="tags" value="<?= e((string) $v('tags')) ?>" placeholder="boleto, segunda via, pagamento atrasado">
                <small>Ajuda a busca por termo exato.</small>
            </label>

            <label>
                Ordem
                <input type="number" name="ordem" value="<?= (int) $v('ordem', 0) ?>" step="1">
            </label>
        </div>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="ativo" <?= $v('ativo', 1) ? 'checked' : '' ?>> Ativa</label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?><a href="faq.php" class="btn btn-secondary">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Categorias</h2>
    <form method="post" class="chat-form">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="nova_categoria">
        <input type="text" name="categoria" placeholder="nome da categoria">
        <button type="submit" class="btn btn-secondary">Criar</button>
    </form>
    <?php if ($categorias): ?>
        <p class="vazio" style="margin-top:0.6rem;">
            <?= implode(' · ', array_map(static fn (array $c): string => e($c['nome']), $categorias)) ?>
        </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title"><?= count($perguntas) ?> pergunta(s)</h2>
    <?php if (!$perguntas): ?>
        <p class="vazio">
            Nenhuma pergunta cadastrada. Uma boa fonte para começar é
            <a href="conversas.php">Conversas</a>: o que as pessoas perguntaram de verdade.
        </p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Pergunta</th>
                <th>Categoria</th>
                <th>Setor</th>
                <th>Situação</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($perguntas as $f): ?>
                <tr>
                    <td>
                        <strong><?= e($f['pergunta']) ?></strong>
                        <br><small style="opacity:.6"><?= e(mb_substr((string) $f['resposta'], 0, 100)) ?><?= mb_strlen((string) $f['resposta']) > 100 ? '…' : '' ?></small>
                    </td>
                    <td><small><?= e((string) ($f['categoria'] ?? '—')) ?></small></td>
                    <td><small><?= e((string) ($f['setor'] ?? '—')) ?></small></td>
                    <td>
                        <?php if (!$f['ativo']): ?>
                            <span class="tag tag-neutro">inativa</span>
                        <?php elseif ($f['indexada']): ?>
                            <span class="tag tag-ok">indexada</span>
                        <?php else: ?>
                            <span class="tag tag-erro">sem vetor</span>
                        <?php endif; ?>
                    </td>
                    <td class="acoes">
                        <a href="?editar=<?= (int) $f['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta pergunta?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
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

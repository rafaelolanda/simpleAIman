<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'setores.php';
$tituloPagina = 'Setores';

$editando = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada. Tente novamente.');
        redirect('setores.php');
    }

    $acao = $_POST['acao'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'excluir') {
        $padrao = (int) $pdo->query('SELECT padrao FROM setores WHERE id = ' . $id)->fetchColumn();

        if ($padrao === 1) {
            flash_set('erro', 'Este é o setor padrão — é para onde vai o que não se encaixa em nenhum outro. Eleja outro como padrão antes de excluí-lo.');
        } else {
            $pdo->prepare('DELETE FROM setores WHERE id = :id')->execute(['id' => $id]);
            Auth::log('setor_excluido', 'id=' . $id);
            flash_set('sucesso', 'Setor excluído.');
        }

        redirect('setores.php');
    }

    if ($acao === 'revisado') {
        $pdo->prepare('UPDATE setores SET atualizado_em = :agora, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $id]);
        Auth::log('setor_revisado', 'id=' . $id);
        flash_set('sucesso', 'Contato marcado como conferido hoje.');
        redirect('setores.php');
    }

    if ($acao === 'salvar') {
        $nome = trim(texto_utf8($_POST['nome'] ?? ''));

        if ($nome === '') {
            flash_set('erro', 'O nome é obrigatório.');
            redirect('setores.php');
        }

        $dados = [
            'nome' => $nome,
            'slug' => slugify(trim(texto_utf8($_POST['slug'] ?? '')) ?: $nome),
            'descricao_llm' => trim(texto_utf8($_POST['descricao_llm'] ?? '')),
            'email' => trim(texto_utf8($_POST['email'] ?? '')),
            'telefone' => trim(texto_utf8($_POST['telefone'] ?? '')),
            'whatsapp' => preg_replace('/\D+/', '', (string) ($_POST['whatsapp'] ?? '')) ?: '',
            'ramal' => trim(texto_utf8($_POST['ramal'] ?? '')),
            'horario_atendimento' => trim(texto_utf8($_POST['horario_atendimento'] ?? '')),
            'local' => trim(texto_utf8($_POST['local'] ?? '')),
            'responsavel_nome' => trim(texto_utf8($_POST['responsavel_nome'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'ordem' => (int) ($_POST['ordem'] ?? 0),
        ];

        if ($dados['email'] !== '' && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            flash_set('erro', 'E-mail inválido. Contato errado é pior que contato nenhum — o agente entregaria esse endereço com toda a confiança.');
            redirect('setores.php');
        }

        $agora = now();

        if ($id > 0) {
            $dados['editado_em'] = $agora;
            // Qualquer edição conta como revisão: quem mexeu acabou de olhar.
            $dados['atualizado_em'] = $agora;
            $dados['id'] = $id;

            $pdo->prepare(
                'UPDATE setores SET nome=:nome, slug=:slug, descricao_llm=:descricao_llm, email=:email,
                        telefone=:telefone, whatsapp=:whatsapp, ramal=:ramal,
                        horario_atendimento=:horario_atendimento, local=:local,
                        responsavel_nome=:responsavel_nome, ativo=:ativo, ordem=:ordem,
                        editado_em=:editado_em, atualizado_em=:atualizado_em
                 WHERE id=:id'
            )->execute($dados);

            Auth::log('setor_editado', $nome);
        } else {
            $dados['criado_em'] = $agora;
            $dados['editado_em'] = $agora;
            $dados['atualizado_em'] = $agora;
            $dados['padrao'] = 0;

            $pdo->prepare(
                'INSERT INTO setores (nome, slug, descricao_llm, email, telefone, whatsapp, ramal,
                        horario_atendimento, local, responsavel_nome, ativo, ordem, padrao,
                        criado_em, editado_em, atualizado_em)
                 VALUES (:nome, :slug, :descricao_llm, :email, :telefone, :whatsapp, :ramal,
                        :horario_atendimento, :local, :responsavel_nome, :ativo, :ordem, :padrao,
                        :criado_em, :editado_em, :atualizado_em)'
            )->execute($dados);

            $id = (int) $pdo->lastInsertId();
            Auth::log('setor_criado', $nome);
        }

        // Setor padrão é único: eleger um rebaixa os demais. Sem isso o
        // encaminhamento teria dois destinos "de última instância".
        if (isset($_POST['padrao'])) {
            $pdo->exec('UPDATE setores SET padrao = 0');
            $pdo->prepare('UPDATE setores SET padrao = 1 WHERE id = :id')->execute(['id' => $id]);
        }

        flash_set('sucesso', 'Setor salvo.');
        redirect('setores.php');
    }
}

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM setores WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $editando = $stmt->fetch() ?: null;
}

$setores = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM faq f WHERE f.setor_id = s.id) AS faqs,
            (SELECT COUNT(*) FROM chamados c WHERE c.setor_id = s.id AND c.status = \'aberto\') AS chamados
     FROM setores s ORDER BY s.padrao DESC, s.ordem, s.nome'
)->fetchAll();

$v = static fn (string $campo, mixed $padrao = '') => $editando[$campo] ?? $padrao;
$limiteRevisao = date('Y-m-d', strtotime('-180 day'));

include __DIR__ . '/partials/head.php';
?>

<div class="page-header">
    <h1>Setores</h1>
    <p class="page-sub">
        Para onde o agente encaminha. É a mesma tabela que direciona a FAQ, os chamados e — quando o
        atendimento humano existir — qual atendente assume. <strong>Só canal institucional aqui:</strong>
        o agente conversa com público externo.
    </p>
</div>

<div class="card">
    <h2 class="card-title"><?= $editando ? 'Editar: ' . e((string) $editando['nome']) : 'Novo setor' ?></h2>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">

        <div class="form-grid">
            <label>
                Nome
                <input type="text" name="nome" required value="<?= e((string) $v('nome')) ?>" placeholder="ex.: Central do Candidato">
            </label>

            <label>
                Identificador
                <input type="text" name="slug" value="<?= e((string) $v('slug')) ?>" placeholder="gerado do nome se vazio">
            </label>

            <label class="col-2">
                O que este setor resolve
                <textarea name="descricao_llm" rows="3" placeholder="ex.: inscrições, processo seletivo, documentos de matrícula e dúvidas de quem ainda não é aluno."><?= e((string) $v('descricao_llm')) ?></textarea>
                <small>
                    <strong>É o texto que o agente lê para escolher o setor</strong> — escreva para ele, não para
                    o organograma. Descreva os assuntos que chegam, com as palavras que as pessoas usam.
                </small>
            </label>

            <label>
                Responsável
                <input type="text" name="responsavel_nome" value="<?= e((string) $v('responsavel_nome')) ?>" placeholder="nome de quem recebe os chamados">
            </label>

            <label>
                E-mail
                <input type="email" name="email" value="<?= e((string) $v('email')) ?>" placeholder="setor@instituicao.br">
                <small>Destino dos chamados abertos pelo agente.</small>
            </label>

            <label>
                Telefone
                <input type="text" name="telefone" value="<?= e((string) $v('telefone')) ?>" placeholder="(55) 3231-0000">
            </label>

            <label>
                Ramal
                <input type="text" name="ramal" value="<?= e((string) $v('ramal')) ?>" placeholder="1234">
            </label>

            <label>
                WhatsApp
                <input type="text" name="whatsapp" value="<?= e((string) $v('whatsapp')) ?>" placeholder="5555999991234">
                <small>Com código do país. Só dígitos.</small>
            </label>

            <label>
                Horário de atendimento
                <input type="text" name="horario_atendimento" value="<?= e((string) $v('horario_atendimento')) ?>" placeholder="segunda a sexta, das 8h às 17h">
                <small>Passar telefone às 22h sem dizer o horário só gera frustração.</small>
            </label>

            <label>
                Local
                <input type="text" name="local" value="<?= e((string) $v('local')) ?>" placeholder="Bloco A, térreo">
            </label>

            <label>
                Ordem
                <input type="number" name="ordem" value="<?= (int) $v('ordem', 0) ?>" step="1">
            </label>
        </div>

        <div class="form-checks">
            <label class="check"><input type="checkbox" name="ativo" <?= $v('ativo', 1) ? 'checked' : '' ?>> Ativo</label>
            <label class="check">
                <input type="checkbox" name="padrao" <?= $v('padrao', 0) ? 'checked' : '' ?>>
                Setor padrão — destino do que não se encaixa em nenhum outro
            </label>
        </div>

        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= $editando ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editando): ?><a href="setores.php" class="btn btn-secondary">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Cadastrados</h2>
    <?php if (!$setores): ?>
        <p class="vazio">Nenhum setor cadastrado.</p>
    <?php else: ?>
        <table class="tabela">
            <thead>
            <tr>
                <th>Setor</th>
                <th>Contato</th>
                <th>Revisão</th>
                <th>Uso</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($setores as $s): ?>
                <?php
                $temContato = ($s['email'] ?? '') !== '' || ($s['telefone'] ?? '') !== '' || ($s['whatsapp'] ?? '') !== '';
                $velho = ($s['atualizado_em'] ?? '') === '' || substr((string) $s['atualizado_em'], 0, 10) < $limiteRevisao;
                ?>
                <tr>
                    <td>
                        <strong><?= e($s['nome']) ?></strong>
                        <?php if ($s['padrao']): ?><span class="tag tag-ok">padrão</span><?php endif; ?>
                        <?php if (!$s['ativo']): ?><span class="tag tag-neutro">inativo</span><?php endif; ?>
                        <br><small style="opacity:.6"><?= e(mb_substr((string) $s['descricao_llm'], 0, 80)) ?></small>
                        <?php if (($s['descricao_llm'] ?? '') === ''): ?>
                            <br><span class="tag tag-erro">sem descrição — o agente não saberá quando usar</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$temContato): ?>
                            <span class="tag tag-erro">sem contato</span>
                        <?php else: ?>
                            <small>
                                <?php if ($s['email']): ?><?= e($s['email']) ?><br><?php endif; ?>
                                <?php if ($s['telefone']): ?><?= e($s['telefone']) ?><?= $s['ramal'] ? ' r.' . e($s['ramal']) : '' ?><br><?php endif; ?>
                                <?php if ($s['whatsapp']): ?>WhatsApp <?= e($s['whatsapp']) ?><br><?php endif; ?>
                                <?php if ($s['horario_atendimento']): ?><span style="opacity:.6"><?= e($s['horario_atendimento']) ?></span><?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($velho): ?>
                            <span class="tag tag-erro">conferir</span>
                            <br><small style="opacity:.6">contato errado é pior que contato nenhum</small>
                        <?php else: ?>
                            <small style="opacity:.6"><?= e(date('d/m/Y', strtotime((string) $s['atualizado_em']))) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?= (int) $s['faqs'] ?> FAQ · <?= (int) $s['chamados'] ?> chamado(s)</small></td>
                    <td class="acoes">
                        <?php if ($velho): ?>
                            <form method="post" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="revisado">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" title="Confirma que os contatos estão corretos hoje">Conferi</button>
                            </form>
                        <?php endif; ?>
                        <a href="?editar=<?= (int) $s['id'] ?>" class="btn btn-secondary btn-sm">Editar</a>
                        <?php if (!$s['padrao']): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Excluir este setor?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

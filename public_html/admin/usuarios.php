<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

// Página restrita: só o admin_master gerencia usuários. A checagem fica aqui e não
// só no menu — esconder o link não é controle de acesso, a URL continua alcançável.
if (!$ehAdminMaster) {
    http_response_code(403);
    $paginaAtual = '';
    $tituloPagina = 'Acesso negado';
    include __DIR__ . '/partials/head.php';
    echo '<div class="panel"><h2>Acesso negado</h2><p>Apenas o administrador principal pode gerenciar usuários.</p>'
        . '<a href="index.php" class="btn btn-secondary">Voltar ao painel</a></div>';
    include __DIR__ . '/partials/foot.php';
    exit;
}

$paginaAtual = 'usuarios.php';
$tituloPagina = 'Usuários';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada, tente novamente.');
        redirect('usuarios.php');
    }

    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($acao === 'excluir') {
            if ($id === Auth::userId()) {
                throw new RuntimeException('Você não pode excluir o seu próprio usuário.');
            }

            $stmt = $pdo->prepare('SELECT usuario, admin_master FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            if ((int) $alvo['admin_master'] === 1) {
                throw new RuntimeException('O administrador principal não pode ser excluído.');
            }

            $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute(['id' => $id]);
            Auth::log('excluir_usuario', $alvo['usuario']);
            flash_set('sucesso', 'Usuário removido.');
        } elseif ($acao === 'atendente') {
            // Marca quem recebe transferências e de qual setor. Fica aqui, e não
            // no perfil de cada um, porque "quem atende" é decisão de gestão. Já
            // a disponibilidade ("estou aqui agora") é do próprio atendente e
            // mora na tela de Atendimento.
            $stmt = $pdo->prepare('SELECT usuario FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();

            if (!$alvo) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            $atende = empty($_POST['atende']) ? 0 : 1;
            $setorId = (int) ($_POST['setor_id'] ?? 0) ?: null;

            // Tirar a marca de atendente zera a disponibilidade junto: senão a
            // pessoa ficaria "online" sem receber nada, e a fila contaria com
            // alguém que não existe.
            $pdo->prepare(
                'UPDATE admin_users
                    SET atende = :a,
                        setor_id = :s,
                        disponivel = CASE WHEN :a2 = 0 THEN 0 ELSE disponivel END,
                        editado_em = :agora
                  WHERE id = :id'
            )->execute([
                'a' => $atende,
                'a2' => $atende,
                's' => $setorId,
                'agora' => now(),
                'id' => $id,
            ]);

            Auth::log('usuario_atendente', $alvo['usuario'] . ' → ' . ($atende ? 'atende' : 'não atende'));
            flash_set('sucesso', 'Atendimento de ' . $alvo['usuario'] . ' atualizado.');
        } elseif ($acao === 'redefinir_senha') {
            $novaSenha = (string) ($_POST['nova_senha'] ?? '');

            if (strlen($novaSenha) < 8) {
                throw new RuntimeException('A nova senha precisa ter ao menos 8 caracteres.');
            }

            $stmt = $pdo->prepare('UPDATE admin_users SET senha_hash = :hash, editado_em = :agora WHERE id = :id');
            $stmt->execute(['hash' => password_hash($novaSenha, PASSWORD_DEFAULT), 'agora' => now(), 'id' => $id]);

            Auth::log('redefinir_senha_usuario', "id={$id}");
            flash_set('sucesso', 'Senha redefinida. Informe a nova senha ao usuário.');
        } else {
            $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
            $email = trim((string) ($_POST['email'] ?? '')) ?: null;
            $senha = (string) ($_POST['senha'] ?? '');

            if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) {
                throw new RuntimeException('Usuário deve ter de 3 a 30 caracteres: letras, números, ponto, hífen ou underline.');
            }

            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Informe um e-mail válido.');
            }

            if (strlen($senha) < 8) {
                throw new RuntimeException('A senha precisa ter ao menos 8 caracteres.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM admin_users WHERE usuario = :usuario');
            $stmt->execute(['usuario' => $usuario]);

            if ($stmt->fetchColumn()) {
                throw new RuntimeException('Já existe um usuário com esse nome.');
            }

            // admin_master não é concedido pelo formulário de propósito: o dono do
            // painel é único e definido na instalação
            $stmt = $pdo->prepare(
                'INSERT INTO admin_users (usuario, email, senha_hash, admin_master, criado_em, editado_em)
                 VALUES (:usuario, :email, :hash, 0, :agora, :agora)'
            );
            $stmt->execute([
                'usuario' => $usuario,
                'email' => $email,
                'hash' => password_hash($senha, PASSWORD_DEFAULT),
                'agora' => now(),
            ]);

            Auth::log('criar_usuario', $usuario);
            flash_set('sucesso', 'Usuário criado. Passe as credenciais para a pessoa e peça que troque a senha no primeiro acesso.');
        }
    } catch (RuntimeException $e) {
        flash_set('erro', $e->getMessage());
    }

    redirect('usuarios.php');
}

$usuarios = $pdo->query(
    'SELECT u.id, u.usuario, u.email, u.admin_master, u.criado_em, u.atende, u.disponivel, u.setor_id,
            s.nome AS setor
     FROM admin_users u
     LEFT JOIN setores s ON s.id = u.setor_id
     ORDER BY u.admin_master DESC, u.usuario ASC'
)->fetchAll();

$setores = $pdo->query('SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Usuários do painel</h1>
        <p>Contas com acesso ao painel administrativo. Só você, como administrador principal, vê e usa esta página.</p>
    </div>
</div>

<div class="panel">
    <h2>Novo usuário</h2>
    <p class="dica-painel">
        O novo usuário tem acesso a todo o painel, exceto a esta página de usuários.
    </p>
    <form method="post" action="usuarios.php">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label>Usuário (para login)</label>
                <input type="text" name="usuario" required minlength="3" maxlength="30" placeholder="ex.: maria.silva" autocomplete="off">
                <p class="dica-campo">Letras minúsculas, números, ponto, hífen ou underline.</p>
            </div>
            <div class="field">
                <label>E-mail</label>
                <input type="email" name="email" placeholder="usado para recuperar a senha" autocomplete="off">
            </div>
            <div class="field">
                <label>Senha provisória</label>
                <input type="text" name="senha" required minlength="8" autocomplete="new-password">
                <p class="dica-campo">Mínimo de 8 caracteres. Fica visível para você copiar e repassar.</p>
            </div>
        </div>
        <div class="actions-row">
            <button type="submit" class="btn">Criar usuário</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Usuários cadastrados</h2>
    <div class="table-wrap">
    <table class="cards-mobile">
        <thead><tr><th>Usuário</th><th>E-mail</th><th>Tipo</th><th>Atendimento</th><th>Criado em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td data-label="Usuário"><?= e($u['usuario']) ?><?= (int) $u['id'] === Auth::userId() ? ' <span class="badge on">você</span>' : '' ?></td>
                <td data-label="E-mail"><?= e($u['email'] ?? '—') ?></td>
                <td data-label="Tipo"><?= $u['admin_master'] ? 'Administrador principal' : 'Usuário' ?></td>
                <td data-label="Atendimento">
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">
                            <?php if ($u['atende']): ?>
                                Atende<?= $u['setor'] ? ' · ' . e((string) $u['setor']) : '' ?>
                                <?= $u['disponivel'] ? '<span class="badge on">online</span>' : '' ?>
                            <?php else: ?>
                                Não atende
                            <?php endif; ?>
                        </summary>
                        <form method="post" action="usuarios.php" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="atendente">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <label class="linha-check">
                                <input type="checkbox" name="atende" value="1" <?= $u['atende'] ? 'checked' : '' ?>>
                                recebe transferências
                            </label>
                            <select name="setor_id">
                                <option value="">Qualquer setor</option>
                                <?php foreach ($setores as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= (int) $u['setor_id'] === (int) $s['id'] ? 'selected' : '' ?>>
                                        <?= e($s['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Salvar</button>
                        </form>
                    </details>
                </td>
                <td data-label="Criado em"><?= e(date('d/m/Y', strtotime($u['criado_em']))) ?></td>
                <td data-label="">
                    <details class="acao-inline">
                        <summary class="btn btn-secondary btn-sm">Redefinir senha</summary>
                        <form method="post" action="usuarios.php" class="acao-inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="redefinir_senha">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <input type="text" name="nova_senha" required minlength="8" placeholder="nova senha" autocomplete="new-password">
                            <button type="submit" class="btn btn-sm">Salvar</button>
                        </form>
                    </details>
                    <?php if (!$u['admin_master'] && (int) $u['id'] !== Auth::userId()): ?>
                        <form method="post" action="usuarios.php" style="display:inline;" data-confirm="Remover o acesso de <?= e($u['usuario']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/partials/foot.php'; ?>

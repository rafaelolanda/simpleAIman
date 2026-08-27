<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';

$paginaAtual = 'perfil.php';
$tituloPagina = 'Meu perfil';

$stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id');
$stmt->execute(['id' => Auth::userId()]);
$usuarioAtual = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('erro', 'Sessão expirada, tente novamente.');
        redirect('perfil.php');
    }

    try {
        $novoUsuario = trim((string) ($_POST['usuario'] ?? ''));
        // Nome de EXIBICAO. E o unico dado desta tela que sai da casa: e ele
        // que o visitante ve em "Fulano entrou na conversa" e no prefixo de
        // cada mensagem no WhatsApp. Vazio faz o sistema dizer so "Atendente",
        // porque o usuario de login e credencial e nao pode aparecer la.
        $novoNome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 80);
        $novoEmail = trim((string) ($_POST['email'] ?? '')) ?: null;
        $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
        $novaSenha = (string) ($_POST['nova_senha'] ?? '');
        $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

        if ($novoUsuario === '') {
            throw new RuntimeException('O usuário não pode ficar em branco.');
        }

        if ($novoEmail !== null && !filter_var($novoEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }

        $trocandoSenha = $novaSenha !== '' || $confirmarSenha !== '';

        if ($trocandoSenha) {
            if ($senhaAtual === '' || !password_verify($senhaAtual, $usuarioAtual['senha_hash'])) {
                throw new RuntimeException('Senha atual incorreta.');
            }
            if (strlen($novaSenha) < 8) {
                throw new RuntimeException('A nova senha precisa ter pelo menos 8 caracteres.');
            }
            if ($novaSenha !== $confirmarSenha) {
                throw new RuntimeException('A confirmação não confere com a nova senha.');
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE usuario = :usuario AND id != :id');
        $stmt->execute(['usuario' => $novoUsuario, 'id' => Auth::userId()]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Já existe outro usuário com esse nome.');
        }

        if ($trocandoSenha) {
            $stmt = $pdo->prepare(
                'UPDATE admin_users SET usuario = :usuario, nome = :nome, email = :email, senha_hash = :hash, editado_em = :agora WHERE id = :id'
            );
            $stmt->execute([
                'usuario' => $novoUsuario,
                'nome' => $novoNome,
                'email' => $novoEmail,
                'hash' => password_hash($novaSenha, PASSWORD_DEFAULT),
                'agora' => now(),
                'id' => Auth::userId(),
            ]);
            Auth::log('trocar_senha', 'Senha alterada pelo próprio usuário');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE admin_users SET usuario = :usuario, nome = :nome, email = :email, editado_em = :agora WHERE id = :id'
            );
            $stmt->execute([
                'usuario' => $novoUsuario,
                'nome' => $novoNome,
                'email' => $novoEmail,
                'agora' => now(),
                'id' => Auth::userId(),
            ]);
        }

        $_SESSION['admin_username'] = $novoUsuario;
        Auth::log('editar_perfil', "usuario={$novoUsuario}");
        flash_set('sucesso', 'Perfil atualizado.');
    } catch (RuntimeException $e) {
        flash_set('erro', $e->getMessage());
    }

    redirect('perfil.php');
}

include __DIR__ . '/partials/head.php';
?>

<div class="admin-header">
    <div>
        <h1>Meu perfil</h1>
        <p>Dados de acesso ao painel administrativo.</p>
    </div>
</div>

<form method="post" action="perfil.php">
    <?= csrf_field() ?>

    <div class="panel">
        <h2>Conta</h2>
        <div class="form-grid">
            <div class="field">
                <label>Usuário</label>
                <input type="text" name="usuario" required value="<?= e($usuarioAtual['usuario']) ?>">
                <p class="dica-campo">Serve para entrar no painel. O visitante nunca vê.</p>
            </div>
            <div class="field">
                <label>Nome de exibição</label>
                <input type="text" name="nome" maxlength="80"
                       value="<?= e((string) ($usuarioAtual['nome'] ?? '')) ?>"
                       placeholder="ex.: Maria Souza">
                <p class="dica-campo">
                    <?php // Unico campo desta tela que sai da casa. Merece dizer onde aparece. ?>
                    É o que a pessoa atendida vê: <em>“Fulano entrou na conversa”</em> e o nome
                    antes de cada mensagem no WhatsApp.
                    <?php if (trim((string) ($usuarioAtual['nome'] ?? '')) === ''): ?>
                        <strong>Em branco, você aparece apenas como “Atendente”.</strong>
                    <?php endif; ?>
                </p>
            </div>
            <div class="field">
                <label>E-mail (usado pra recuperação de senha)</label>
                <input type="email" name="email" value="<?= e($usuarioAtual['email']) ?>" placeholder="seu@email.com">
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>Trocar senha</h2>
        <p style="color:var(--text-muted);font-size:0.85rem;margin-top:-0.5rem;">Deixe em branco se não quiser alterar a senha agora.</p>
        <div class="form-grid">
            <div class="field">
                <label>Senha atual</label>
                <div class="password-field">
                    <input type="password" name="senha_atual" id="senha_atual" autocomplete="current-password">
                    <button type="button" class="password-toggle" data-toggle-senha="senha_atual" aria-label="Mostrar senha"><?= svg_icon('eye') ?></button>
                </div>
            </div>
            <div class="field"></div>
            <div class="field">
                <label>Nova senha</label>
                <div class="password-field">
                    <input type="password" name="nova_senha" id="nova_senha" autocomplete="new-password" minlength="8">
                    <button type="button" class="password-toggle" data-toggle-senha="nova_senha" aria-label="Mostrar senha"><?= svg_icon('eye') ?></button>
                </div>
            </div>
            <div class="field">
                <label>Confirmar nova senha</label>
                <div class="password-field">
                    <input type="password" name="confirmar_senha" id="confirmar_senha" autocomplete="new-password" minlength="8">
                    <button type="button" class="password-toggle" data-toggle-senha="confirmar_senha" aria-label="Mostrar senha"><?= svg_icon('eye') ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="actions-row">
        <button type="submit" class="btn">Salvar alterações</button>
    </div>
</form>

<script>
document.querySelectorAll('[data-toggle-senha]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.toggleSenha);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    });
});
</script>

<?php include __DIR__ . '/partials/foot.php'; ?>

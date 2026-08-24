<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();

if (Auth::check()) {
    redirect('index.php');
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$erro = null;
$sucesso = null;

$userId = $token !== '' ? Auth::validarTokenReset($token) : null;

if ($token === '' || $userId === null) {
    $erro = 'Link inválido ou expirado. Peça um novo link de redefinição.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $novaSenha = (string) ($_POST['nova_senha'] ?? '');
        $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

        if (strlen($novaSenha) < 8) {
            $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
        } elseif ($novaSenha !== $confirmarSenha) {
            $erro = 'A confirmação não confere com a nova senha.';
        } else {
            Auth::atualizarSenha($userId, $novaSenha);
            Auth::limparTokenReset($userId);

            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_logs (admin_user_id, acao, detalhes, ip, criado_em) VALUES (:uid, :acao, :detalhes, :ip, :agora)'
            );
            $stmt->execute([
                'uid' => $userId,
                'acao' => 'redefinir_senha',
                'detalhes' => 'Senha redefinida via link de e-mail',
                'ip' => client_ip(),
                'agora' => now(),
            ]);

            $sucesso = 'Senha redefinida com sucesso.';
        }
    }
}
$tituloPagina = 'Redefinir senha';
include __DIR__ . '/partials/auth-open.php';
?>
        <h1>Redefinir senha</h1>
        <p class="sub">Escolha uma nova senha para acessar o painel.</p>

        <?php if ($erro): ?>
            <div class="alert error"><?= e($erro) ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert success"><?= e($sucesso) ?></div>
        <?php endif; ?>

        <?php if ($userId !== null && !$sucesso): ?>
        <form method="post" action="redefinir-senha" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="field">
                <label for="nova_senha">Nova senha</label>
                <input type="password" id="nova_senha" name="nova_senha" required minlength="8" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="confirmar_senha">Confirmar nova senha</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="btn" style="width:100%;">Redefinir senha</button>
        </form>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <p class="sub" style="margin-top:1.25rem;"><a href="login">Ir para o login →</a></p>
        <?php endif; ?>

        <?php if ($erro): ?>
            <p class="sub" style="margin-top:1.25rem;"><a href="esqueci-senha">Pedir um novo link</a></p>
        <?php endif; ?>
<?php include __DIR__ . '/partials/auth-close.php'; ?>
</body>
</html>

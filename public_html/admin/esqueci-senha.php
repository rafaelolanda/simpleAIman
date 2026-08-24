<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();

if (Auth::check()) {
    redirect('index.php');
}

$erro = null;
$sucesso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $identificador = 'reset|' . $usuario . '|' . client_ip();

        if ($usuario === '') {
            $erro = 'Informe o usuário.';
        } elseif (Auth::isBlocked($identificador)) {
            $erro = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.';
        } else {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id, usuario, email FROM admin_users WHERE usuario = :usuario');
            $stmt->execute(['usuario' => $usuario]);
            $user = $stmt->fetch();

            Auth::registrarTentativaFalha($identificador);

            if ($user && !empty($user['email'])) {
                $token = Auth::gerarTokenReset((int) $user['id']);
                $link = APP_URL . '/admin/redefinir-senha?token=' . $token;

                $corpo = '<p>Olá, ' . e($user['usuario']) . '.</p>'
                    . '<p>Recebemos um pedido de redefinição de senha do painel administrativo.</p>'
                    . '<p><a href="' . e($link) . '">Clique aqui para escolher uma nova senha</a> (o link expira em 60 minutos).</p>'
                    . '<p>Se você não pediu isso, pode ignorar este e-mail.</p>';

                Mailer::send($user['email'], $user['usuario'], 'Redefinição de senha', $corpo);
            }

            // mensagem sempre genérica — não revela se o usuário existe ou tem e-mail cadastrado
            $sucesso = 'Se o usuário existir e tiver um e-mail cadastrado, um link de redefinição foi enviado.';
        }
    }
}
$tituloPagina = 'Esqueci minha senha';
include __DIR__ . '/partials/auth-open.php';
?>
        <h1>Esqueci minha senha</h1>
        <p class="sub">Informe seu usuário para receber um link de redefinição por e-mail.</p>

        <?php if ($erro): ?>
            <div class="alert error"><?= e($erro) ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert success"><?= e($sucesso) ?></div>
        <?php endif; ?>

        <?php if (!$sucesso): ?>
        <form method="post" action="esqueci-senha" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field">
                <label for="usuario">Usuário</label>
                <input type="text" id="usuario" name="usuario" required autofocus>
            </div>
            <button type="submit" class="btn" style="width:100%;">Enviar link</button>
        </form>
        <?php endif; ?>

        <p class="sub" style="margin-top:1.25rem;"><a href="login">← Voltar para o login</a></p>
<?php include __DIR__ . '/partials/auth-close.php'; ?>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();

if (Auth::check()) {
    redirect('index.php');
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');

        if ($usuario === '' || $senha === '') {
            $erro = 'Informe usuário e senha.';
        } elseif (Auth::attempt($usuario, $senha)) {
            Auth::log('login', 'Login realizado com sucesso');
            redirect('index.php');
        } else {
            $erro = 'Usuário ou senha inválidos.';
        }
    }
}
$tituloPagina = 'Login';
include __DIR__ . '/partials/auth-open.php';
?>
        <h1>Painel administrativo</h1>
        <p class="sub">Acesse para gerenciar o site.</p>

        <?php if ($erro): ?>
            <div class="alert error"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field">
                <label for="usuario">Usuário</label>
                <input type="text" id="usuario" name="usuario" required autofocus value="<?= old('usuario') ?>">
            </div>
            <div class="field">
                <label for="senha">Senha</label>
                <div class="password-field">
                    <input type="password" id="senha" name="senha" required autocomplete="current-password">
                    <button type="button" class="password-toggle" id="toggle-senha" aria-label="Mostrar senha" aria-pressed="false">
                        <span class="icon-show"><?= svg_icon('eye') ?></span>
                        <span class="icon-hide" hidden><?= svg_icon('eye-off') ?></span>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn" style="width:100%;">Entrar</button>
        </form>
        <p class="sub" style="margin-top:1.1rem;text-align:center;"><a href="esqueci-senha">Esqueci minha senha</a></p>
<?php include __DIR__ . '/partials/auth-close.php'; ?>
<script>
(function () {
    var toggle = document.getElementById('toggle-senha');
    var senha = document.getElementById('senha');
    if (!toggle || !senha) return;

    toggle.addEventListener('click', function () {
        var mostrando = senha.type === 'text';
        senha.type = mostrando ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!mostrando));
        toggle.setAttribute('aria-label', mostrando ? 'Mostrar senha' : 'Ocultar senha');
        toggle.querySelector('.icon-show').hidden = !mostrando;
        toggle.querySelector('.icon-hide').hidden = mostrando;
    });
})();
</script>
</body>
</html>

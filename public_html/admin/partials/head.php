<?php
/**
 * @var string $tituloPagina
 * @var string $paginaAtual
 * @var array $config
 */
$corPrimaria = $config['cor_primaria'] ?? '#2563eb';
$corSecundaria = $config['cor_secundaria'] ?? '#0f172a';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($tituloPagina) ?> · <?= e($config['nome_instancia'] ?? 'Admin') ?></title>
    <link rel="stylesheet" href="../<?= asset_ver('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../<?= asset_ver('assets/css/simpleaiman.css') ?>">
    <style>
        :root { --accent: <?= e($corPrimaria) ?>; --accent-2: <?= e($corSecundaria) ?>; --on-accent: <?= cor_contraste($corPrimaria) ?>; }
    </style>
</head>
<body>
<div id="toasts"></div>
<?php
$flashSucesso = flash_get('sucesso');
$flashErro = flash_get('erro');
?>
<?php if ($flashSucesso): ?><span data-flash-success="<?= e($flashSucesso) ?>" hidden></span><?php endif; ?>
<?php if ($flashErro): ?><span data-flash-error="<?= e($flashErro) ?>" hidden></span><?php endif; ?>
<div class="admin-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="admin-main">
        <div class="admin-topbar">
            <button type="button" class="admin-menu-toggle" aria-label="Abrir menu">☰</button>
        </div>

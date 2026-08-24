<?php
/**
 * Shell "tela dividida" pras páginas de autenticação (login, esqueci senha, redefinir senha).
 * @var string $tituloPagina
 */
declare(strict_types=1);

$pdo = Database::connection();
$config = $pdo->query('SELECT * FROM config WHERE id = 1')->fetch() ?: [];

$corPrimaria = $config['cor_primaria'] ?? '#2563eb';
$corSecundaria = $config['cor_secundaria'] ?? '#0f172a';
$onPrimaria = cor_contraste($corPrimaria);
$nomeInstancia = $config['nome_instancia'] ?? 'simpleAIman';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($tituloPagina) ?> · Admin</title>
    <link rel="stylesheet" href="../<?= asset_ver('assets/css/admin.css') ?>">
    <style>
        :root { --accent: <?= e($corPrimaria) ?>; --accent-2: <?= e($corSecundaria) ?>; --on-accent: <?= $onPrimaria ?>; }
    </style>
</head>
<body>
<div class="login-split">
    <div class="login-brand">
        <div class="login-brand-shape login-brand-shape-1"></div>
        <div class="login-brand-shape login-brand-shape-2"></div>
        <div class="login-brand-content">
            <?php if (!empty($config['logo'])): ?>
                <img class="login-brand-logo" src="../assets/uploads/logo/<?= e($config['logo']) ?>" alt="<?= e($nomeInstancia) ?>">
            <?php else: ?>
                <span class="login-brand-icon"><?= svg_icon('bot', 44) ?></span>
            <?php endif; ?>
            <h2><?= e($nomeInstancia) ?></h2>
        </div>
    </div>
    <div class="login-panel">
        <div class="login-card">

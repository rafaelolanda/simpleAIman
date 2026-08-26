<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null) && Auth::check()) {
    Auth::log('logout', 'Logout realizado');
    // Sair do painel encerra a presença: sem isto a fila contaria com alguém que
// acabou de fechar a sessão, pelos dois minutos da janela.
if (Auth::userId() !== null) {
    \SimpleAIman\Atendimento\Fila::encerrarPresenca((int) Auth::userId());
}

Auth::logout();
}

redirect('login.php');

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

Auth::start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? null) && Auth::check()) {
    Auth::log('logout', 'Logout realizado');
    Auth::logout();
}

redirect('login.php');

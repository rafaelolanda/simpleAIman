<?php

declare(strict_types=1);

/**
 * Por que o login do painel está recusando?
 *
 *   php bin/diagnostico-login.php              # o retrato completo
 *   php bin/diagnostico-login.php --destravar  # apaga os bloqueios por tentativa
 *   php bin/diagnostico-login.php --testar=rafael
 *       pede a senha pelo teclado e diz se ela confere com o hash do banco
 *
 * Existe porque a tela de login responde "usuário ou senha inválidos" para
 * TRÊS casos diferentes: senha errada, usuário inexistente e bloqueio por
 * tentativas. Quem está de fora não consegue distinguir — e o segundo e o
 * terceiro não se resolvem trocando a senha.
 *
 * Também mostra QUAL arquivo de banco este processo abre. Se o site estiver
 * em outra pasta, com outro .env, a senha trocada por aqui não é a que ele lê.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$pdo = Database::connection();
$argumentos = array_slice($argv, 1);
$destravar = in_array('--destravar', $argumentos, true);
$testar = '';

foreach ($argumentos as $a) {
    if (preg_match('/^--testar=(.+)$/', $a, $m)) {
        $testar = trim($m[1]);
    }
}

echo "== Arquivo de banco ==\n";
$caminho = realpath(DB_PATH) ?: DB_PATH;
printf("  caminho:   %s\n", $caminho);
printf("  existe:    %s\n", is_file($caminho) ? 'sim' : 'NAO');

if (is_file($caminho)) {
    printf("  tamanho:   %s KB\n", number_format(filesize($caminho) / 1024, 1));
    printf("  alterado:  %s (%d min atrás)\n", date('Y-m-d H:i:s', filemtime($caminho)), (int) ((time() - filemtime($caminho)) / 60));
    printf("  gravavel:  %s\n", is_writable($caminho) ? 'sim' : 'NAO — o site nao consegue escrever');
    printf("  pasta:     %s\n", is_writable(dirname($caminho)) ? 'gravavel' : 'NAO gravavel — o WAL do SQLite precisa disso');
}

// Se o site estiver vivo, o banco tem mensagem recente. Banco parado no tempo
// enquanto o WhatsApp responde significa que o site usa OUTRO arquivo.
$ultima = $pdo->query('SELECT MAX(criado_em) FROM mensagens')->fetchColumn();
printf("  ult. msg:  %s\n", $ultima ?: '(nenhuma)');

echo "\n== Relógio ==\n";
printf("  PHP:       %s (%s)\n", date('Y-m-d H:i:s'), date_default_timezone_get());
printf("  now():     %s\n", now());
printf("  versao:    PHP %s\n", PHP_VERSION);

echo "\n== Usuários do painel ==\n";

foreach ($pdo->query('SELECT * FROM admin_users ORDER BY id') as $u) {
    $hash = (string) $u['senha_hash'];
    $info = password_get_info($hash);

    printf(
        "  #%d %s%s\n    hash:    %s (%d bytes)%s\n    editado: %s\n",
        $u['id'],
        $u['usuario'],
        !empty($u['admin_master']) ? ' — master' : '',
        $info['algoName'] !== 'unknown' ? $info['algoName'] : 'DESCONHECIDO — hash corrompido',
        strlen($hash),
        strlen($hash) < 50 ? '  <<< CURTO DEMAIS, hash truncado' : '',
        (string) ($u['editado_em'] ?? '—')
    );

    foreach (['ativo', 'papel', 'email'] as $campo) {
        if (array_key_exists($campo, $u)) {
            printf("    %-8s %s\n", $campo . ':', (string) ($u[$campo] ?? '—'));
        }
    }
}

echo "\n== Bloqueios por tentativa ==\n";
$linhas = $pdo->query('SELECT * FROM login_tentativas ORDER BY editado_em DESC')->fetchAll(PDO::FETCH_ASSOC);

if ($linhas === []) {
    echo "  nenhum — o bloqueio não é a causa.\n";
}

foreach ($linhas as $l) {
    $ate = (string) ($l['bloqueado_ate'] ?? '');
    $bloqueando = $ate !== '' && strtotime($ate) > time();

    printf(
        "  %-40s tentativas: %-3d ate: %-20s %s\n",
        (string) $l['identificador'],
        (int) $l['tentativas'],
        $ate !== '' ? $ate : '—',
        $bloqueando ? '<<< BLOQUEANDO AGORA' : ''
    );
}

if ($destravar) {
    $n = $pdo->exec('DELETE FROM login_tentativas');
    echo "\n  --destravar: {$n} registro(s) apagado(s). Tente entrar de novo.\n";
}

if ($testar !== '') {
    echo "\n== Conferir senha de '{$testar}' ==\n";
    $stmt = $pdo->prepare('SELECT senha_hash FROM admin_users WHERE usuario = :u');
    $stmt->execute(['u' => $testar]);
    $hash = $stmt->fetchColumn();

    if ($hash === false) {
        echo "  usuário não existe com esse nome EXATO (confira maiúsculas e espaços).\n";
    } else {
        echo '  digite a senha (ela nao fica no historico do shell): ';
        $senha = rtrim((string) fgets(STDIN), "\r\n");

        echo password_verify($senha, (string) $hash)
            ? "  CONFERE — a senha está certa; se a tela recusa, é bloqueio ou sessão.\n"
            : "  NAO CONFERE — o hash do banco é de outra senha.\n";
    }
}

echo "\n== Sessão (valores do CLI; o site pode ter outros) ==\n";
$destino = session_save_path() ?: sys_get_temp_dir();
printf("  save_path: %s\n", $destino);
printf("  gravavel:  %s\n", is_writable($destino) ? 'sim' : 'NAO — sem isto ninguem fica logado');
printf("  cookie:    %s (secure=%s)\n", SESSION_NAME, APP_ENV !== 'local' ? 'sim, exige HTTPS' : 'nao');

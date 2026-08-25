<?php

declare(strict_types=1);

/**
 * Redefine a senha de um usuário do painel, pela linha de comando.
 *
 *   php bin/redefinir-senha.php            # o admin_master
 *   php bin/redefinir-senha.php joao       # um usuário específico
 *
 * Existe porque o "esqueci minha senha" da tela depende de e-mail cadastrado —
 * e o primeiro admin nasce sem e-mail nenhum. Sem esta saída, perder a senha
 * inicial (impressa uma única vez pelo migrate) significa ficar de fora do
 * próprio painel, com o banco ali do lado.
 *
 * A senha nova é SORTEADA e impressa, em vez de recebida como argumento: senha
 * digitada na linha de comando fica no histórico do shell e no `ps` de quem
 * estiver na máquina.
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

$pdo = Database::connection();
$alvo = trim((string) ($argv[1] ?? ''));

if ($alvo !== '') {
    $stmt = $pdo->prepare('SELECT id, usuario, nome FROM admin_users WHERE usuario = :u');
    $stmt->execute(['u' => $alvo]);
} else {
    $stmt = $pdo->query('SELECT id, usuario, nome FROM admin_users WHERE admin_master = 1 ORDER BY id LIMIT 1');
}

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    echo $alvo !== ''
        ? "Usuário '{$alvo}' não encontrado.\n"
        : "Nenhum admin_master no banco. Rode: php database/migrate.php\n";

    echo "\nUsuários existentes:\n";

    foreach ($pdo->query('SELECT usuario, nome, admin_master FROM admin_users ORDER BY id') as $u) {
        echo '  ' . $u['usuario'] . ' (' . $u['nome'] . ')' . ($u['admin_master'] ? ' — master' : '') . "\n";
    }

    exit(1);
}

// 9 bytes = 12 caracteres em base64url. Sorteada com random_bytes, não com
// rand(): é credencial, não número de sorteio.
$senha = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');

$pdo->prepare(
    'UPDATE admin_users SET senha_hash = :hash, reset_token_hash = NULL, reset_expira = NULL,
            editado_em = :agora
     WHERE id = :id'
)->execute([
    'hash' => password_hash($senha, PASSWORD_DEFAULT),
    'agora' => now(),
    'id' => (int) $usuario['id'],
]);

// Qualquer token de recuperação pendente morre junto: deixar um vivo seria
// manter uma segunda porta aberta para uma senha que acabou de ser trocada.

echo "Senha redefinida.\n\n";
echo "  usuario: {$usuario['usuario']}\n";
echo "  senha:   {$senha}\n\n";
echo "Anote agora — ela não é recuperável depois, só redefinível.\n";
echo "Troque em Perfil assim que entrar, e cadastre um e-mail lá para que o\n";
echo "\"esqueci minha senha\" da tela funcione na próxima vez.\n";

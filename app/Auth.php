<?php

declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (APP_ENV !== 'local'),
            ]);
            session_start();
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login.php');
        }
    }

    public static function userId(): ?int
    {
        return $_SESSION['admin_user_id'] ?? null;
    }

    public static function username(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    /**
     * Papel de quem está logado, lido do banco.
     *
     * Não fica na sessão de propósito: um papel guardado no login continuaria
     * valendo depois de o administrador rebaixar a pessoa, até ela sair e
     * entrar de novo. Uma consulta por requisição é barata; permissão obsoleta
     * é o tipo de erro que ninguém percebe até dar errado.
     */
    public static function papelAtual(): ?string
    {
        $id = self::userId();

        if ($id === null) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT papel FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $papel = $stmt->fetchColumn();

        return $papel === false ? null : (string) $papel;
    }

    /**
     * Verifica bloqueio por tentativas de login (rate limiting).
     * Chave de identificação: usuario + IP combinados, pra não travar
     * um usuário legítimo por causa de outro IP tentando o mesmo login.
     */
    public static function isBlocked(string $identificador): bool
    {
        return self::bloqueioRestante($identificador) > 0;
    }

    /**
     * Segundos que faltam do bloqueio; 0 quando não há bloqueio.
     *
     * Bloqueio VENCIDO é apagado aqui, e não deixado para o próximo acerto de
     * senha. Sem isso o contador continuava em LOGIN_MAX_TENTATIVAS depois da
     * janela, e o erro de digitação seguinte bloqueava outros 15 minutos —
     * sem nunca deixar entrar. Prendeu o admin da instalação em 16/09/2026:
     * trocar a senha não adiantava, e de outro computador (outro IP) entrava.
     */
    public static function bloqueioRestante(string $identificador): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT bloqueado_ate FROM login_tentativas WHERE identificador = :id');
        $stmt->execute(['id' => $identificador]);
        $bloqueadoAte = $stmt->fetchColumn();

        if (!$bloqueadoAte) {
            return 0;
        }

        $falta = strtotime((string) $bloqueadoAte) - time();

        if ($falta > 0) {
            return $falta;
        }

        // Janela cumprida: o contador volta a zero e a pessoa tem as
        // LOGIN_MAX_TENTATIVAS de novo.
        self::limparTentativas($identificador);
        self::log('login_desbloqueado', 'Bloqueio venceu; contador zerado.');

        return 0;
    }

    /**
     * Nome do usuário como ele entra no log.
     *
     * Cortado em 40 caracteres: quem digita a senha no campo de usuário por
     * engano não deixa a senha inteira gravada em texto puro no log.
     */
    private static function rotuloDeUsuario(string $usuario): string
    {
        return 'usuário "' . mb_substr($usuario, 0, 40) . '"';
    }

    /**
     * Chave do rate limit: usuário + IP.
     *
     * Combinados para que um IP tentando senha alheia não tranque o dono da
     * conta — e é por isso que o mesmo login entra de outro computador
     * enquanto o primeiro está bloqueado.
     */
    public static function identificador(string $usuario): string
    {
        return $usuario . '|' . client_ip();
    }

    /** @return int quantas tentativas seguidas este identificador acumula */
    public static function registrarTentativaFalha(string $identificador): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT tentativas FROM login_tentativas WHERE identificador = :id');
        $stmt->execute(['id' => $identificador]);
        $tentativas = $stmt->fetchColumn();

        if ($tentativas === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO login_tentativas (identificador, tentativas, criado_em, editado_em) VALUES (:id, 1, :agora, :agora)'
            );
            $stmt->execute(['id' => $identificador, 'agora' => now()]);

            return 1;
        }

        $tentativas = (int) $tentativas + 1;
        $bloqueadoAte = null;

        if ($tentativas >= LOGIN_MAX_TENTATIVAS) {
            $bloqueadoAte = date('Y-m-d H:i:s', time() + (LOGIN_BLOQUEIO_MINUTOS * 60));
        }

        $stmt = $pdo->prepare(
            'UPDATE login_tentativas
             SET tentativas = :tentativas, bloqueado_ate = :bloqueado_ate, editado_em = :editado_em
             WHERE identificador = :id'
        );
        $stmt->execute([
            'tentativas' => $tentativas,
            'bloqueado_ate' => $bloqueadoAte,
            'editado_em' => now(),
            'id' => $identificador,
        ]);

        return $tentativas;
    }

    public static function limparTentativas(string $identificador): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM login_tentativas WHERE identificador = :id');
        $stmt->execute(['id' => $identificador]);
    }

    public static function attempt(string $usuario, string $senha): bool
    {
        $identificador = self::identificador($usuario);
        $falta = self::bloqueioRestante($identificador);

        // Recusa por bloqueio vai para o log do painel.
        //
        // Sem isto, o admin trancado do lado de fora não tinha onde ver o que
        // estava acontecendo: a tela dizia "usuário ou senha inválidos" e o
        // log não mencionava bloqueio nenhum. Aconteceu em 16/09/2026.
        if ($falta > 0) {
            self::log(
                'login_bloqueado',
                self::rotuloDeUsuario($usuario) . ' · recusado sem conferir a senha · faltam '
                    . (int) ceil($falta / 60) . ' min'
            );

            return false;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, usuario, senha_hash FROM admin_users WHERE usuario = :usuario');
        $stmt->execute(['usuario' => $usuario]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            $tentativas = self::registrarTentativaFalha($identificador);
            $bloqueou = $tentativas >= LOGIN_MAX_TENTATIVAS;

            self::log(
                $bloqueou ? 'login_bloqueio_iniciado' : 'login_falhou',
                self::rotuloDeUsuario($usuario) . ' · tentativa ' . $tentativas . ' de ' . LOGIN_MAX_TENTATIVAS
                    . ($bloqueou ? ' · bloqueado por ' . LOGIN_BLOQUEIO_MINUTOS . ' min' : '')
                    . ($user ? '' : ' · esse usuário não existe')
            );

            return false;
        }

        self::limparTentativas($identificador);

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $user['id'];
        $_SESSION['admin_username'] = $user['usuario'];

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    private const RESET_TOKEN_VALIDADE_MINUTOS = 60;

    /**
     * Gera um token de redefinição de senha (retorna o token em texto puro, pra ir no link
     * do e-mail) e grava só o hash dele no banco — o token puro nunca é persistido.
     */
    public static function gerarTokenReset(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', time() + (self::RESET_TOKEN_VALIDADE_MINUTOS * 60));

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE admin_users SET reset_token_hash = :hash, reset_expira = :expira, editado_em = :agora WHERE id = :id'
        );
        $stmt->execute([
            'hash' => hash('sha256', $token),
            'expira' => $expira,
            'agora' => now(),
            'id' => $userId,
        ]);

        return $token;
    }

    /**
     * Retorna o id do usuário se o token for válido e ainda não tiver expirado, senão null.
     * Como o token puro não fica no banco, precisamos comparar o hash contra os poucos
     * usuários que têm um token pendente (normalmente só 1, é um painel de admin único).
     */
    public static function validarTokenReset(string $token): ?int
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, reset_token_hash FROM admin_users WHERE reset_token_hash IS NOT NULL AND reset_expira > ' .
            $pdo->quote(now())
        );

        $hashRecebido = hash('sha256', $token);

        foreach ($stmt->fetchAll() as $row) {
            if (hash_equals((string) $row['reset_token_hash'], $hashRecebido)) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    public static function limparTokenReset(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE admin_users SET reset_token_hash = NULL, reset_expira = NULL, editado_em = :agora WHERE id = :id'
        );
        $stmt->execute(['agora' => now(), 'id' => $userId]);
    }

    public static function atualizarSenha(int $userId, string $novaSenha): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE admin_users SET senha_hash = :hash, editado_em = :agora WHERE id = :id');
        $stmt->execute([
            'hash' => password_hash($novaSenha, PASSWORD_DEFAULT),
            'agora' => now(),
            'id' => $userId,
        ]);
    }

    public static function log(string $acao, ?string $detalhes = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO admin_logs (admin_user_id, acao, detalhes, ip, criado_em) VALUES (:admin_user_id, :acao, :detalhes, :ip, :criado_em)'
        );
        $stmt->execute([
            'admin_user_id' => self::userId(),
            'acao' => $acao,
            'detalhes' => $detalhes,
            'ip' => client_ip(),
            'criado_em' => now(),
        ]);
    }
}

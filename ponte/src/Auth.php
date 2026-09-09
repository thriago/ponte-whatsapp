<?php
// src/Auth.php — tela de entrar. Usuário e senha; o usuário "admin" nasce
// na instalação, e quando a senha foi sorteada a primeira entrada obriga a
// trocar. Sem cadastro público e sem recuperação por e-mail: quem perdeu a
// senha usa `bash senha.sh --nova` na máquina onde instalou.

final class Auth
{
    public static function iniciarSessao(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function logado(): bool
    {
        self::iniciarSessao();
        return !empty($_SESSION['admin_id']);
    }

    public static function precisaTrocarSenha(): bool
    {
        self::iniciarSessao();
        return !empty($_SESSION['must_change_password']);
    }

    public static function tentarLogin(string $username, string $password): bool
    {
        $stmt = Db::get()->prepare('SELECT * FROM admin WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        self::iniciarSessao();
        session_regenerate_id(true);
        $_SESSION['admin_id']              = (int) $row['id'];
        $_SESSION['admin_username']        = $row['username'];
        $_SESSION['must_change_password']  = (int) $row['must_change_password'] === 1;
        return true;
    }

    public static function trocarSenha(string $novaSenha): void
    {
        self::iniciarSessao();
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT);
        $stmt = Db::get()->prepare('UPDATE admin SET password_hash = :h, must_change_password = 0 WHERE id = :id');
        $stmt->execute([':h' => $hash, ':id' => $_SESSION['admin_id']]);
        $_SESSION['must_change_password'] = false;
    }

    public static function sair(): void
    {
        self::iniciarSessao();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::iniciarSessao();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfValido(?string $token): bool
    {
        self::iniciarSessao();
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function flash(string $msg, string $tipo = 'info'): void
    {
        self::iniciarSessao();
        $_SESSION['flash'][] = ['msg' => $msg, 'tipo' => $tipo];
    }

    /** @return array<int,array{msg:string,tipo:string}> */
    public static function consumirFlash(): array
    {
        self::iniciarSessao();
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}

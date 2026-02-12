<?php
/**
 * Auth.php — Session-based authentication
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Auth
{
    private const SESSION_KEY = 'cybokron_user_id';

    public static function init(): void
    {
        ensureWebSessionStarted();
    }

    public static function login(string $username, string $password): bool
    {
        $user = Database::queryOne(
            'SELECT id, username, password_hash, role FROM users WHERE username = ? AND is_active = 1',
            [trim($username)]
        );

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION['cybokron_username'] = $user['username'];
        $_SESSION['cybokron_role'] = $user['role'];
        session_regenerate_id(true);

        return true;
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY], $_SESSION['cybokron_username'], $_SESSION['cybokron_role']);
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] > 0;
    }

    public static function id(): ?int
    {
        return isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return Database::queryOne('SELECT id, username, role FROM users WHERE id = ? AND is_active = 1', [self::id()]);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['cybokron_role'] ?? '') === 'admin';
    }
}

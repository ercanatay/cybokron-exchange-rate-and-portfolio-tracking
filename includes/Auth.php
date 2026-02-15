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

    public static function login(string $username, string $password, bool $remember = false): bool
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

        if ($remember) {
            $lifetime = 30 * 24 * 3600;
            $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            ini_set('session.gc_maxlifetime', (string) $lifetime);
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        }

        return true;
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY], $_SESSION['cybokron_username'], $_SESSION['cybokron_role']);
            session_destroy();

            // Expire session cookie to clear it from the browser
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires'  => time() - 3600,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly'  => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }
        }
    }

    public static function check(): bool
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY] <= 0) {
            return false;
        }
        // Revalidate against DB every 5 minutes
        $now = time();
        if ($now - ($_SESSION['cybokron_auth_checked_at'] ?? 0) > 300) {
            $user = Database::queryOne(
                'SELECT id, role FROM users WHERE id = ? AND is_active = 1',
                [(int) $_SESSION[self::SESSION_KEY]]
            );
            if (!$user) {
                self::logout();
                return false;
            }
            $_SESSION['cybokron_role'] = $user['role'];
            $_SESSION['cybokron_auth_checked_at'] = $now;
        }
        return true;
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

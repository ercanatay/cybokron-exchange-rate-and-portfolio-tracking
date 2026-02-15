<?php
/**
 * Auth.php — Session-based authentication with secure Remember Me
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Auth
{
    private const SESSION_KEY = 'cybokron_user_id';
    private const REMEMBER_COOKIE = 'cybokron_remember';
    private const REMEMBER_LIFETIME = 30 * 24 * 3600; // 30 days

    public static function init(): void
    {
        ensureWebSessionStarted();

        // If no active session, try remember-me cookie
        if (!isset($_SESSION[self::SESSION_KEY]) && PHP_SAPI !== 'cli') {
            self::loginFromRememberToken();
        }
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
            self::issueRememberToken((int) $user['id']);
        }

        return true;
    }

    public static function logout(): void
    {
        // Clear remember-me token from DB and cookie
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            self::clearRememberToken($_COOKIE[self::REMEMBER_COOKIE]);
            $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        }

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

    // ─── Remember Me Token System ───────────────────────────────────────────

    /**
     * Issue a secure remember-me token (selector:validator pattern).
     */
    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $hashedValidator = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_LIFETIME);

        // Clean up old tokens for this user (max 5 active tokens)
        Database::execute(
            'DELETE FROM remember_tokens WHERE user_id = ? AND (expires_at < NOW() OR id NOT IN (
                SELECT id FROM (SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT 4) AS keep
            ))',
            [$userId, $userId]
        );

        Database::insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'hashed_validator' => $hashedValidator,
            'expires_at' => $expiresAt,
        ]);

        $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + self::REMEMBER_LIFETIME,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Attempt login from remember-me cookie.
     */
    private static function loginFromRememberToken(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || substr_count($cookie, ':') !== 1) {
            return;
        }

        [$selector, $validator] = explode(':', $cookie, 2);
        if (strlen($selector) !== 32 || strlen($validator) !== 64) {
            return;
        }

        $token = Database::queryOne(
            'SELECT id, user_id, hashed_validator, expires_at FROM remember_tokens WHERE selector = ?',
            [$selector]
        );

        if (!$token) {
            return;
        }

        // Check expiry
        if (strtotime($token['expires_at']) < time()) {
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$token['id']]);
            return;
        }

        // Validate token (constant-time comparison)
        if (!hash_equals($token['hashed_validator'], hash('sha256', $validator))) {
            // Possible token theft — invalidate all tokens for this user
            Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$token['user_id']]);
            return;
        }

        // Token valid — log user in
        $user = Database::queryOne(
            'SELECT id, username, role FROM users WHERE id = ? AND is_active = 1',
            [(int) $token['user_id']]
        );

        if (!$user) {
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$token['id']]);
            return;
        }

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION['cybokron_username'] = $user['username'];
        $_SESSION['cybokron_role'] = $user['role'];
        session_regenerate_id(true);

        // Rotate token (issue new one, delete old)
        Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$token['id']]);
        self::issueRememberToken((int) $user['id']);
    }

    /**
     * Clear a remember-me token by cookie value.
     */
    private static function clearRememberToken(string $cookieValue): void
    {
        if ($cookieValue === '' || substr_count($cookieValue, ':') !== 1) {
            return;
        }

        [$selector] = explode(':', $cookieValue, 2);
        if (strlen($selector) === 32) {
            Database::execute('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        }
    }

    /**
     * Clean expired remember tokens (call from cron).
     */
    public static function cleanExpiredTokens(): void
    {
        Database::execute('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    }
}

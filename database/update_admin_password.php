<?php
/**
 * Update admin password from environment variable or file.
 *
 * Supports two modes:
 *   1. Plaintext password in .admin_password.tmp — hashed on server (recommended)
 *   2. Pre-hashed bcrypt in .admin_hash.tmp or ADMIN_HASH env var
 *
 * Usage: php database/update_admin_password.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

// Read password (plaintext) or hash from file/env
$hashFile = __DIR__ . '/../.admin_hash.tmp';
$passwordFile = __DIR__ . '/../.admin_password.tmp';

if (file_exists($passwordFile)) {
    // Plaintext password provided — hash it on the server
    $plaintext = trim(file_get_contents($passwordFile));
    if (!empty($plaintext)) {
        $hash = password_hash($plaintext, PASSWORD_BCRYPT);
        echo "  password hashed on server (bcrypt)\n";
    }
    @unlink($passwordFile);
} elseif (file_exists($hashFile)) {
    $hash = trim(file_get_contents($hashFile));
    @unlink($hashFile);
} else {
    $hash = getenv('ADMIN_HASH');
}
if (empty($hash)) {
    echo "ADMIN_HASH not set, skipping.\n";
    exit(0);
}

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    die("Config not found.\n");
}
require_once $configFile;

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Ensure admin user exists, then update password
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES ('admin', ?, 'admin', 1) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
$stmt->execute([$hash]);
$affected = $stmt->rowCount();
$action = $affected === 1 ? 'created' : ($affected === 2 ? 'updated' : 'unchanged');
echo "admin password {$action} ({$affected} row)\n";

<?php
/**
 * Update admin password from environment variable.
 *
 * Usage: ADMIN_HASH='$2y$...' php database/update_admin_password.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

// Read hash from file (preferred) or env variable
$hashFile = __DIR__ . '/../.admin_hash.tmp';
if (file_exists($hashFile)) {
    $hash = trim(file_get_contents($hashFile));
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
$action = $stmt->rowCount() === 1 ? 'created' : 'updated';
echo "admin password {$action} ({$stmt->rowCount()} row)\n";

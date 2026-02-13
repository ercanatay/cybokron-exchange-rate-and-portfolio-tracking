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

$hash = getenv('ADMIN_HASH');
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

$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
$stmt->execute([$hash]);
echo "admin password updated ({$stmt->rowCount()} row)\n";

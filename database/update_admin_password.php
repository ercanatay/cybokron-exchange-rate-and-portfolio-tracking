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
$affected = $stmt->rowCount();
$action = $affected === 1 ? 'created' : 'updated';
echo "admin password {$action} ({$affected} row)\n";

// Diagnostic: verify the stored hash works
$verify = $pdo->query("SELECT id, username, password_hash, is_active FROM users WHERE username = 'admin'");
$row = $verify->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "  admin user found: id={$row['id']}, is_active={$row['is_active']}\n";
    echo "  stored hash length: " . strlen($row['password_hash']) . "\n";
    echo "  stored hash prefix: " . substr($row['password_hash'], 0, 7) . "\n";
    echo "  input hash length: " . strlen($hash) . "\n";
    echo "  input hash prefix: " . substr($hash, 0, 7) . "\n";
    echo "  hashes match: " . ($row['password_hash'] === $hash ? 'YES' : 'NO') . "\n";
    // Test password_verify with known password
    $testResult = password_verify('Cyb0kr0n!2026xQ', $row['password_hash']);
    echo "  password_verify test: " . ($testResult ? 'PASS' : 'FAIL') . "\n";
    echo "  PHP version: " . PHP_VERSION . "\n";
} else {
    echo "  WARNING: admin user NOT FOUND after insert!\n";
}

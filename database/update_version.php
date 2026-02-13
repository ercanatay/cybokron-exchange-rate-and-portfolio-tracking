<?php
/**
 * Update app_version in settings table.
 *
 * Usage: php database/update_version.php 1.4.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

$version = $argv[1] ?? getenv('APP_VERSION') ?: '';
if (empty($version)) {
    echo "Usage: php database/update_version.php <version>\n";
    exit(0);
}

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config not found: {$configFile}\n");
    exit(1);
}
require_once $configFile;

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('app_version', ?) ON DUPLICATE KEY UPDATE value = ?");
    $stmt->execute([$version, $version]);
    echo "app_version updated to {$version}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

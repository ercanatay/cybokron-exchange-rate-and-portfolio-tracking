<?php
/**
 * Update app_version in settings table.
 *
 * Usage: APP_VERSION='1.4.0' php database/update_version.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

$version = getenv('APP_VERSION');
if (empty($version)) {
    echo "APP_VERSION not set, skipping.\n";
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

$stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('app_version', ?) ON DUPLICATE KEY UPDATE value = ?");
$stmt->execute([$version, $version]);
echo "app_version updated to {$version}\n";

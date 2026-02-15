<?php
/**
 * Database migration runner
 * Run: php database/migrate.php
 */

require_once __DIR__ . '/../includes/helpers.php';
cybokron_init();

if (PHP_SAPI !== 'cli') {
    die('Migration must run from CLI.');
}

$pdo = Database::getInstance();

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return $stmt && $stmt->rowCount() > 0;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return $stmt && $stmt->rowCount() > 0;
}

echo "Running migrations...\n";

// 1. Create users table
$sql = "CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') DEFAULT 'user',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB";
$pdo->exec($sql);
echo "  [OK] users table\n";

// 2. Create alerts table
$sql = "CREATE TABLE IF NOT EXISTS `alerts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `currency_code` VARCHAR(10) NOT NULL,
  `condition_type` ENUM('above', 'below', 'change_pct') NOT NULL,
  `threshold` DECIMAL(18,6) NOT NULL,
  `channel` ENUM('email', 'telegram', 'webhook') DEFAULT 'email',
  `channel_config` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_triggered_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_currency_active` (`currency_code`, `is_active`)
) ENGINE=InnoDB";
$pdo->exec($sql);
echo "  [OK] alerts table\n";

// 3. Portfolio: add user_id
if (!columnExists($pdo, 'portfolio', 'user_id')) {
    $pdo->exec("ALTER TABLE portfolio ADD COLUMN user_id INT UNSIGNED NULL AFTER id");
    echo "  [OK] portfolio.user_id added\n";
} else {
    echo "  [OK] portfolio.user_id exists\n";
}

// 4. Portfolio: add deleted_at
if (!columnExists($pdo, 'portfolio', 'deleted_at')) {
    $pdo->exec("ALTER TABLE portfolio ADD COLUMN deleted_at DATETIME NULL AFTER notes");
    echo "  [OK] portfolio.deleted_at added\n";
} else {
    echo "  [OK] portfolio.deleted_at exists\n";
}

// 5. Seed admin user
$stmt = $pdo->query("SELECT id FROM users WHERE username = 'admin'");
if (!$stmt || $stmt->rowCount() === 0) {
    $adminPass = getenv('CYBOKRON_ADMIN_PASSWORD') ?: bin2hex(random_bytes(16));
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (username, password_hash, role) VALUES ('admin', " . $pdo->quote($hash) . ", 'admin')");
    echo "  [OK] admin user seeded (password: {$adminPass})\n";
} else {
    echo "  [OK] admin user exists\n";
}

// 6. TCMB bank + currencies
$stmt = $pdo->query("SELECT id FROM banks WHERE slug = 'tcmb'");
if (!$stmt || $stmt->rowCount() === 0) {
    $pdo->exec("INSERT INTO banks (name, slug, url, scraper_class) VALUES ('TCMB', 'tcmb', 'https://www.tcmb.gov.tr/kurlar/today.xml', 'TCMB')");
    echo "  [OK] TCMB bank added\n";
} else {
    echo "  [OK] TCMB bank exists\n";
}

$tcmbCurrencies = [
    ['DKK', 'Danimarka Kronu', 'Danish Krone', 'kr', 'fiat', 4],
    ['NOK', 'Norveç Kronu', 'Norwegian Krone', 'kr', 'fiat', 4],
    ['SEK', 'İsveç Kronu', 'Swedish Krona', 'kr', 'fiat', 4],
    ['KWD', 'Kuveyt Dinarı', 'Kuwaiti Dinar', 'KD', 'fiat', 4],
    ['RON', 'Rumen Leyi', 'Romanian Leu', 'lei', 'fiat', 4],
    ['RUB', 'Rus Rublesi', 'Russian Rouble', '₽', 'fiat', 4],
    ['PKR', 'Pakistan Rupisi', 'Pakistani Rupee', '₨', 'fiat', 4],
    ['QAR', 'Katar Riyali', 'Qatari Rial', 'QR', 'fiat', 4],
    ['KRW', 'Güney Kore Wonu', 'South Korean Won', '₩', 'fiat', 4],
    ['AZN', 'Azerbaycan Manatı', 'Azerbaijani Manat', '₼', 'fiat', 4],
    ['KZT', 'Kazakistan Tengesi', 'Kazakhstan Tenge', '₸', 'fiat', 4],
    ['XDR', 'Özel Çekme Hakkı (SDR)', 'Special Drawing Right', 'XDR', 'fiat', 4],
];
foreach ($tcmbCurrencies as $c) {
    $stmt = $pdo->prepare("SELECT id FROM currencies WHERE code = ?");
    $stmt->execute([$c[0]]);
    if ($stmt->rowCount() === 0) {
        $pdo->prepare("INSERT INTO currencies (code, name_tr, name_en, symbol, type, decimal_places) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute($c);
        echo "  [OK] currency {$c[0]} added\n";
    }
}

echo "Migrations complete.\n";

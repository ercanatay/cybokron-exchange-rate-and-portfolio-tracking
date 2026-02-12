<?php
/**
 * helpers.php — Utility Functions
 * Cybokron Exchange Rate & Portfolio Tracking
 */

/**
 * Bootstrap the application: load config and includes.
 */
function cybokron_init(): void
{
    $configFile = __DIR__ . '/../config.php';
    if (!file_exists($configFile)) {
        die('Configuration file not found. Copy config.sample.php to config.php');
    }

    require_once $configFile;
    require_once __DIR__ . '/Database.php';
    require_once __DIR__ . '/Scraper.php';
    require_once __DIR__ . '/Portfolio.php';
    require_once __DIR__ . '/Updater.php';

    date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Istanbul');
}

/**
 * Load a bank scraper class by name.
 */
function loadBankScraper(string $className): Scraper
{
    $file = __DIR__ . '/../banks/' . $className . '.php';
    if (!file_exists($file)) {
        throw new RuntimeException("Bank scraper not found: {$className}");
    }

    require_once $file;

    if (!class_exists($className)) {
        throw new RuntimeException("Bank scraper class not found: {$className}");
    }

    $instance = new $className();

    if (!$instance instanceof Scraper) {
        throw new RuntimeException("{$className} must extend Scraper class.");
    }

    return $instance;
}

/**
 * Get latest rates from database.
 */
function getLatestRates(?string $bankSlug = null, ?string $currencyCode = null): array
{
    $sql = "
        SELECT
            r.buy_rate,
            r.sell_rate,
            r.change_percent,
            r.scraped_at,
            c.code AS currency_code,
            c.name_tr AS currency_name,
            c.name_en AS currency_name_en,
            c.symbol,
            c.type AS currency_type,
            b.name AS bank_name,
            b.slug AS bank_slug
        FROM rates r
        JOIN currencies c ON c.id = r.currency_id
        JOIN banks b ON b.id = r.bank_id
        WHERE b.is_active = 1 AND c.is_active = 1
    ";
    $params = [];

    if ($bankSlug) {
        $sql .= " AND b.slug = ?";
        $params[] = $bankSlug;
    }

    if ($currencyCode) {
        $sql .= " AND c.code = ?";
        $params[] = $currencyCode;
    }

    $sql .= " ORDER BY c.code ASC";

    return Database::query($sql, $params);
}

/**
 * Get rate history for a currency.
 */
function getRateHistory(string $currencyCode, int $days = 30, ?string $bankSlug = null): array
{
    $sql = "
        SELECT
            rh.buy_rate,
            rh.sell_rate,
            rh.change_percent,
            rh.scraped_at,
            b.name AS bank_name,
            b.slug AS bank_slug
        FROM rate_history rh
        JOIN currencies c ON c.id = rh.currency_id
        JOIN banks b ON b.id = rh.bank_id
        WHERE c.code = ?
          AND rh.scraped_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ";
    $params = [$currencyCode, $days];

    if ($bankSlug) {
        $sql .= " AND b.slug = ?";
        $params[] = $bankSlug;
    }

    $sql .= " ORDER BY rh.scraped_at ASC";

    return Database::query($sql, $params);
}

/**
 * Format a number as Turkish Lira.
 */
function formatTRY(float $amount, int $decimals = 2): string
{
    return number_format($amount, $decimals, ',', '.') . ' ₺';
}

/**
 * Format a rate value.
 */
function formatRate(float $rate, int $decimals = 4): string
{
    return number_format($rate, $decimals, ',', '.');
}

/**
 * Get CSS class for change percentage.
 */
function changeClass(float $change): string
{
    if ($change > 0) return 'text-success';
    if ($change < 0) return 'text-danger';
    return 'text-muted';
}

/**
 * Get arrow icon for change direction.
 */
function changeArrow(float $change): string
{
    if ($change > 0) return '▲';
    if ($change < 0) return '▼';
    return '–';
}

/**
 * Send JSON response.
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Simple logging.
 */
function cybokron_log(string $message, string $level = 'INFO'): void
{
    if (!defined('LOG_ENABLED') || !LOG_ENABLED) return;

    $logFile = defined('LOG_FILE') ? LOG_FILE : __DIR__ . '/../logs/cybokron.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

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
    initializeLocale();
}

/**
 * Get available locales from config.
 */
function getAvailableLocales(): array
{
    $locales = (defined('AVAILABLE_LOCALES') && is_array(AVAILABLE_LOCALES))
        ? AVAILABLE_LOCALES
        : ['tr', 'en'];

    $normalized = [];
    foreach ($locales as $locale) {
        $candidate = strtolower(trim((string) $locale));
        if (preg_match('/^[a-z]{2}$/', $candidate)) {
            $normalized[] = $candidate;
        }
    }

    $normalized = array_values(array_unique($normalized));

    return !empty($normalized) ? $normalized : ['tr', 'en'];
}

/**
 * Normalize locale against available locales.
 */
function normalizeLocale(?string $locale): string
{
    $available = getAvailableLocales();

    $default = defined('DEFAULT_LOCALE')
        ? strtolower(trim((string) DEFAULT_LOCALE))
        : 'tr';

    if (!in_array($default, $available, true)) {
        $default = $available[0];
    }

    if ($locale === null || $locale === '') {
        return $default;
    }

    $candidate = strtolower(trim($locale));

    return in_array($candidate, $available, true) ? $candidate : $default;
}

/**
 * Initialize locale from query/session/cookie.
 */
function initializeLocale(): void
{
    $locale = normalizeLocale(null);

    if (PHP_SAPI !== 'cli') {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (isset($_GET['lang'])) {
            $locale = normalizeLocale((string) $_GET['lang']);
        } elseif (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['cybokron_locale'])) {
            $locale = normalizeLocale((string) $_SESSION['cybokron_locale']);
        } elseif (!empty($_COOKIE['cybokron_locale'])) {
            $locale = normalizeLocale((string) $_COOKIE['cybokron_locale']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['cybokron_locale'] = $locale;
        }

        if (!headers_sent()) {
            $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie('cybokron_locale', $locale, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    $GLOBALS['cybokron_locale'] = $locale;
}

/**
 * Get current app locale.
 */
function getAppLocale(): string
{
    return isset($GLOBALS['cybokron_locale'])
        ? (string) $GLOBALS['cybokron_locale']
        : normalizeLocale(null);
}

/**
 * Get fallback locale.
 */
function getFallbackLocale(): string
{
    $fallback = defined('FALLBACK_LOCALE')
        ? strtolower(trim((string) FALLBACK_LOCALE))
        : 'en';

    return normalizeLocale($fallback);
}

/**
 * Load locale messages from file.
 */
function loadLocaleMessages(string $locale): array
{
    static $cache = [];

    $locale = normalizeLocale($locale);

    if (isset($cache[$locale])) {
        return $cache[$locale];
    }

    $file = __DIR__ . '/../locales/' . $locale . '.php';
    if (!file_exists($file)) {
        $cache[$locale] = [];
        return $cache[$locale];
    }

    $messages = require $file;
    $cache[$locale] = is_array($messages) ? $messages : [];

    return $cache[$locale];
}

/**
 * Translate message key for current locale with fallback.
 */
function t(string $key, array $replacements = []): string
{
    $locale = getAppLocale();
    $fallback = getFallbackLocale();

    $messages = loadLocaleMessages($locale);
    $fallbackMessages = ($fallback === $locale)
        ? $messages
        : loadLocaleMessages($fallback);

    $text = $messages[$key] ?? $fallbackMessages[$key] ?? $key;

    foreach ($replacements as $placeholder => $value) {
        $text = str_replace('{{' . $placeholder . '}}', (string) $value, $text);
    }

    return $text;
}

/**
 * Build a URL for switching language while preserving query params.
 */
function buildLocaleUrl(string $locale): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!$path) {
        $path = $_SERVER['PHP_SELF'] ?? 'index.php';
    }

    $params = $_GET;
    $params['lang'] = normalizeLocale($locale);

    return $path . '?' . http_build_query($params);
}

/**
 * Return the best currency name for the active locale.
 */
function localizedCurrencyName(array $row): string
{
    $locale = getAppLocale();

    $trKeys = ['currency_name_tr', 'name_tr', 'currency_name'];
    $enKeys = ['currency_name_en', 'name_en'];

    $primary = ($locale === 'en') ? $enKeys : $trKeys;
    $fallback = ($locale === 'en') ? $trKeys : $enKeys;

    foreach ($primary as $key) {
        if (!empty($row[$key])) {
            return (string) $row[$key];
        }
    }

    foreach ($fallback as $key) {
        if (!empty($row[$key])) {
            return (string) $row[$key];
        }
    }

    return '';
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
            c.name_tr AS currency_name_tr,
            c.name_en AS currency_name_en,
            c.name_tr AS currency_name,
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
 * Format number for the selected locale.
 */
function formatNumberLocalized(float $value, int $decimals = 2, ?string $locale = null): string
{
    $activeLocale = $locale ? normalizeLocale($locale) : getAppLocale();

    $decimalSeparator = $activeLocale === 'tr' ? ',' : '.';
    $thousandsSeparator = $activeLocale === 'tr' ? '.' : ',';

    return number_format($value, $decimals, $decimalSeparator, $thousandsSeparator);
}

/**
 * Format a number as Turkish Lira.
 */
function formatTRY(float $amount, int $decimals = 2): string
{
    $locale = getAppLocale();
    $formatted = formatNumberLocalized($amount, $decimals, $locale);

    return $locale === 'tr' ? $formatted . ' ₺' : '₺' . $formatted;
}

/**
 * Format a rate value.
 */
function formatRate(float $rate, int $decimals = 4): string
{
    return formatNumberLocalized($rate, $decimals);
}

/**
 * Format datetime in current locale style.
 */
function formatDateTime(string $datetime): string
{
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    return getAppLocale() === 'tr'
        ? date('d.m.Y H:i:s', $timestamp)
        : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Format date in current locale style.
 */
function formatDate(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    return getAppLocale() === 'tr'
        ? date('d.m.Y', $timestamp)
        : date('Y-m-d', $timestamp);
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

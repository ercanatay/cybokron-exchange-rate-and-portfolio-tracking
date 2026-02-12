<?php
/**
 * Cybokron Exchange Rate & Portfolio Tracking
 * Configuration File Template
 *
 * Copy this file to config.php and update values.
 * cp config.sample.php config.php
 */

// ─── Database ────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'cybokron');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ─── Application ─────────────────────────────────────────────────────────────
define('APP_NAME', 'Cybokron Exchange Rate & Portfolio Tracking');
define('APP_URL', 'http://localhost/cybokron');
define('APP_TIMEZONE', 'Europe/Istanbul');
define('APP_DEBUG', false);

// ─── GitHub Self-Update ──────────────────────────────────────────────────────
define('GITHUB_REPO', 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking');
define('GITHUB_BRANCH', 'main');
define('AUTO_UPDATE', true);

// ─── Scraping ────────────────────────────────────────────────────────────────
define('SCRAPE_TIMEOUT', 30);         // HTTP request timeout in seconds
define('SCRAPE_USER_AGENT', 'Cybokron/1.0');
define('SCRAPE_RETRY_COUNT', 3);      // Retry failed requests
define('SCRAPE_RETRY_DELAY', 5);      // Seconds between retries

// ─── Active Banks ────────────────────────────────────────────────────────────
// Add bank class names to activate scraping
$ACTIVE_BANKS = [
    'DunyaKatilim',
    // 'GarantiBBVA',
    // 'Ziraat',
];

// ─── Rate Update Schedule ────────────────────────────────────────────────────
define('UPDATE_INTERVAL_MINUTES', 15);
define('MARKET_OPEN_HOUR', 9);
define('MARKET_CLOSE_HOUR', 18);
define('MARKET_DAYS', [1, 2, 3, 4, 5]); // Mon-Fri

// ─── Currency Display ────────────────────────────────────────────────────────
// Default currencies to show on dashboard (empty = show all)
$DISPLAY_CURRENCIES = [
    'USD', 'EUR', 'GBP', 'XAU',
];

// ─── Logging ─────────────────────────────────────────────────────────────────
define('LOG_ENABLED', true);
define('LOG_FILE', __DIR__ . '/logs/cybokron.log');

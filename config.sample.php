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
define('ENABLE_SECURITY_HEADERS', true);
define('DEFAULT_LOCALE', 'tr');                  // Default language at installation time
define('FALLBACK_LOCALE', 'en');                 // Fallback if translation key is missing
define('AVAILABLE_LOCALES', ['tr', 'en']);       // Extend this list as you add files in /locales

// ─── GitHub Self-Update ──────────────────────────────────────────────────────
define('GITHUB_REPO', 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking');
define('GITHUB_BRANCH', 'main');
define('AUTO_UPDATE', true);
define('ENFORCE_CLI_CRON', true);                // Block web execution for cron scripts

// ─── Scraping ────────────────────────────────────────────────────────────────
define('SCRAPE_TIMEOUT', 30);         // HTTP request timeout in seconds
define('SCRAPE_USER_AGENT', 'Cybokron/1.0');
define('SCRAPE_RETRY_COUNT', 3);      // Retry failed requests
define('SCRAPE_RETRY_DELAY', 5);      // Seconds between retries
define('OPENROUTER_AI_REPAIR_ENABLED', true);   // AI fallback only triggers when parsed row count is too low
define('OPENROUTER_API_KEY', '');               // Set your API key from https://openrouter.ai
define('OPENROUTER_MODEL', 'z-ai/glm-5');       // Default model (can be changed later via scripts/set_openrouter_model.php)
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('OPENROUTER_MIN_EXPECTED_RATES', 8);     // Trigger AI fallback only below this row count
define('OPENROUTER_AI_COOLDOWN_SECONDS', 21600); // Skip repeated calls for the same table hash for 6 hours
define('OPENROUTER_AI_MAX_INPUT_CHARS', 12000); // Token/cost guard
define('OPENROUTER_AI_MAX_ROWS', 160);          // Token/cost guard
define('OPENROUTER_AI_MAX_TOKENS', 600);        // Token/cost guard
define('OPENROUTER_AI_TIMEOUT_SECONDS', 25);    // API timeout

// ─── API Security ────────────────────────────────────────────────────────────
define('API_ALLOW_CORS', false);                // Keep disabled unless cross-origin API access is required
define('API_ALLOWED_ORIGINS', []);              // Example: ['https://example.com']
define('API_REQUIRE_CSRF', true);               // Require CSRF token for state-changing API calls

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

// ─── Database ────────────────────────────────────────────────────────────────
define('DB_PERSISTENT', false);                 // Enable only after validating your DB/server setup

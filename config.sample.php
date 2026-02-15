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
define('DB_USER', 'cybokron_app');
define('DB_PASS', 'change_me_strong_password');
define('DB_CHARSET', 'utf8mb4');

// ─── Application ─────────────────────────────────────────────────────────────
define('APP_NAME', 'Cybokron Exchange Rate & Portfolio Tracking');
define('APP_URL', 'https://localhost/cybokron');
define('APP_TIMEZONE', 'Europe/Istanbul');
define('APP_DEBUG', false);
define('ENABLE_SECURITY_HEADERS', true);
define('CSP_POLICY', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:");
define('HSTS_MAX_AGE_SECONDS', 31536000);       // Applied only on HTTPS
define('DEFAULT_LOCALE', 'tr');                  // Default language at installation time
define('FALLBACK_LOCALE', 'en');                 // Fallback if translation key is missing
define('AVAILABLE_LOCALES', ['tr', 'en', 'ar', 'de', 'fr']);  // Extend as you add files in /locales

// ─── GitHub Self-Update ──────────────────────────────────────────────────────
define('GITHUB_REPO', 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking');
define('GITHUB_BRANCH', 'main');
define('AUTO_UPDATE', false);                    // Keep disabled unless signed-update setup is complete
define('ENFORCE_CLI_CRON', true);                // Block web execution for cron scripts
define('BACKUP_DIR', dirname(__DIR__) . '/cybokron-backups');
define('UPDATE_REQUIRE_SIGNATURE', true);        // Fail closed if signature/public key is missing
define('UPDATE_PACKAGE_ASSET_NAME', '');         // Optional: e.g. 'cybokron-update.zip'
define('UPDATE_SIGNATURE_ASSET_NAME', 'cybokron-update.zip.sig');
define('UPDATE_SIGNING_PUBLIC_KEY_PEM', "");     // PEM public key used for detached signature verification
define('UPDATE_ALLOWED_HOSTS', [
    'api.github.com',
    'github.com',
    'codeload.github.com',
    'objects.githubusercontent.com',
]);

// ─── Scraping ────────────────────────────────────────────────────────────────
define('SCRAPE_TIMEOUT', 30);         // HTTP request timeout in seconds
define('SCRAPE_USER_AGENT', 'Cybokron/1.0');
define('SCRAPE_RETRY_COUNT', 3);      // Retry failed requests
define('SCRAPE_RETRY_DELAY', 5);      // Seconds between retries
define('SCRAPE_ALLOWED_HOSTS', ['dunyakatilim.com.tr', 'www.dunyakatilim.com.tr', 'www.tcmb.gov.tr', 'tcmb.gov.tr', 'kur.doviz.com', 'doviz.com']);
define('OPENROUTER_AI_REPAIR_ENABLED', true);   // AI fallback only triggers when parsed row count is too low
define('OPENROUTER_API_KEY', '');               // Set your API key from https://openrouter.ai
define('OPENROUTER_MODEL', 'z-ai/glm-5');       // Default model (can be changed later via scripts/set_openrouter_model.php)
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('OPENROUTER_ALLOWED_HOSTS', ['openrouter.ai']);
define('OPENROUTER_MIN_EXPECTED_RATES', 8);     // Trigger AI fallback only below this row count
define('OPENROUTER_AI_COOLDOWN_SECONDS', 21600); // Skip repeated calls for the same table hash for 6 hours
define('OPENROUTER_AI_MAX_INPUT_CHARS', 12000); // Token/cost guard
define('OPENROUTER_AI_MAX_ROWS', 160);          // Token/cost guard
define('OPENROUTER_AI_MAX_TOKENS', 600);        // Token/cost guard
define('OPENROUTER_AI_TIMEOUT_SECONDS', 25);    // API timeout

// ─── Self-Healing Auto-Repair ────────────────────────────────────────────────
define('SELF_HEALING_ENABLED', true);              // Enable AI-powered self-healing when scraper breaks
define('SELF_HEALING_COOLDOWN_SECONDS', 3600);     // Minimum seconds between repair attempts per bank
define('SELF_HEALING_MAX_RETRIES', 2);             // Max repair retries per table change
define('GITHUB_API_TOKEN', '');                    // GitHub PAT (repo scope) - for auto-commit repair configs

// ─── API Security ────────────────────────────────────────────────────────────
define('API_ALLOW_CORS', false);                // Keep disabled unless cross-origin API access is required
define('API_ALLOWED_ORIGINS', []);              // Example: ['https://example.com']
define('API_REQUIRE_CSRF', true);               // Require CSRF token for state-changing API calls
define('API_MAX_BODY_BYTES', 32768);            // 32 KB max request body for write endpoints
define('API_WRITE_RATE_LIMIT', 30);             // Max write requests per window per IP/action
define('API_WRITE_RATE_WINDOW_SECONDS', 60);    // Fixed window duration for API_WRITE_RATE_LIMIT
define('API_READ_RATE_LIMIT', 120);             // Max read requests per window per IP (rates, history, etc.)
define('API_READ_RATE_WINDOW_SECONDS', 60);     // Fixed window duration for API_READ_RATE_LIMIT

// ─── Portfolio Authentication ────────────────────────────────────────────────
define('AUTH_REQUIRE_PORTFOLIO', true);         // Protect portfolio page and portfolio* API actions
define('LOGIN_RATE_LIMIT', 5);                  // Max login attempts per window (brute-force protection)
define('LOGIN_RATE_WINDOW_SECONDS', 300);       // 5 minutes
define('AUTH_BASIC_USER', 'admin');
define('AUTH_BASIC_PASSWORD_HASH', '');         // Generate via: php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"

// ─── Cloudflare Turnstile CAPTCHA ────────────────────────────────────────────
define('TURNSTILE_ENABLED', false);            // Enable in production after setting keys
define('TURNSTILE_SITE_KEY', '');              // Cloudflare Turnstile site key
define('TURNSTILE_SECRET_KEY', '');            // Cloudflare Turnstile secret key

// ─── Active Banks ────────────────────────────────────────────────────────────
// Add bank class names to activate scraping
$ACTIVE_BANKS = [
    'DunyaKatilim',
    'TCMB',
    'IsBank',
    // 'GarantiBBVA',
    // 'Ziraat',
];

// ─── Rate Update Schedule ────────────────────────────────────────────────────
define('UPDATE_INTERVAL_MINUTES', 15);
define('MARKET_OPEN_HOUR', 9);
define('MARKET_CLOSE_HOUR', 18);
define('MARKET_DAYS', [1, 2, 3, 4, 5]); // Mon-Fri

// ─── Rate History Retention ───────────────────────────────────────────────────
define('RATE_HISTORY_RETENTION_DAYS', 365);   // Delete rate_history older than N days (cron/cleanup_rate_history.php)

// ─── Webhook (on rate updates) ────────────────────────────────────────────────
define('RATE_UPDATE_WEBHOOK_URL', '');              // Single URL (Slack, Discord, Zapier, etc.)
define('RATE_UPDATE_WEBHOOK_URLS', []);             // Or array of URLs

// ─── Alert Notifications ─────────────────────────────────────────────────────
define('ALERT_EMAIL_FROM', 'noreply@localhost');   // From address for alert emails
define('ALERT_EMAIL_TO', '');                      // Default recipient (overridable per-alert via channel_config)
define('ALERT_COOLDOWN_MINUTES', 60);              // Minimum minutes between same alert triggers
define('ALERT_TELEGRAM_BOT_TOKEN', '');            // Optional: Telegram bot token for channel=telegram
define('ALERT_TELEGRAM_CHAT_ID', '');              // Optional: Telegram chat ID
define('ALERT_WEBHOOK_URL', '');                   // Optional: Webhook URL for channel=webhook

// ─── Currency Display ────────────────────────────────────────────────────────
// Default currencies to show on dashboard (empty = show all)
$DISPLAY_CURRENCIES = [
    'USD', 'EUR', 'GBP', 'XAU',
];

// ─── Logging ─────────────────────────────────────────────────────────────────
define('LOG_ENABLED', true);
define('LOG_FILE', dirname(__DIR__) . '/cybokron-logs/cybokron.log');

// ─── Database ────────────────────────────────────────────────────────────────
define('DB_PERSISTENT', false);                 // Enable only after validating your DB/server setup

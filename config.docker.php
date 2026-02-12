<?php
/**
 * Docker configuration — reads from environment variables
 */

$env = fn($key, $default) => getenv($key) ?: $default;

define('DB_HOST', $env('DB_HOST', 'db'));
define('DB_NAME', $env('DB_NAME', 'cybokron'));
define('DB_USER', $env('DB_USER', 'cybokron'));
define('DB_PASS', $env('DB_PASS', 'cybokron_secret'));
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Cybokron Exchange Rate & Portfolio Tracking');
define('APP_URL', $env('APP_URL', 'http://localhost:8080'));
define('APP_TIMEZONE', 'Europe/Istanbul');
define('APP_DEBUG', false);
define('ENABLE_SECURITY_HEADERS', true);
define('CSP_POLICY', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self'; img-src 'self' data:");
define('DEFAULT_LOCALE', 'tr');
define('FALLBACK_LOCALE', 'en');
define('AVAILABLE_LOCALES', ['tr', 'en', 'ar', 'de', 'fr']);

define('GITHUB_REPO', 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking');
define('GITHUB_BRANCH', 'main');
define('AUTO_UPDATE', false);
define('ENFORCE_CLI_CRON', true);
define('BACKUP_DIR', dirname(__DIR__) . '/cybokron-backups');
define('UPDATE_REQUIRE_SIGNATURE', true);
define('UPDATE_SIGNING_PUBLIC_KEY_PEM', "");
define('UPDATE_ALLOWED_HOSTS', ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com']);

define('SCRAPE_TIMEOUT', 30);
define('SCRAPE_USER_AGENT', 'Cybokron/1.0');
define('SCRAPE_RETRY_COUNT', 3);
define('SCRAPE_RETRY_DELAY', 5);
define('SCRAPE_ALLOWED_HOSTS', ['dunyakatilim.com.tr', 'www.dunyakatilim.com.tr', 'www.tcmb.gov.tr', 'tcmb.gov.tr']);
define('OPENROUTER_AI_REPAIR_ENABLED', false);
define('OPENROUTER_API_KEY', '');
define('OPENROUTER_MODEL', 'z-ai/glm-5');

define('API_ALLOW_CORS', false);
define('API_REQUIRE_CSRF', true);
define('API_MAX_BODY_BYTES', 32768);
define('API_WRITE_RATE_LIMIT', 30);
define('API_READ_RATE_LIMIT', 120);

define('AUTH_REQUIRE_PORTFOLIO', true);
define('AUTH_BASIC_USER', 'admin');
define('AUTH_BASIC_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
define('RATE_UPDATE_WEBHOOK_URL', '');
define('RATE_UPDATE_WEBHOOK_URLS', []);

$ACTIVE_BANKS = ['DunyaKatilim', 'TCMB'];

define('RATE_HISTORY_RETENTION_DAYS', 365);
define('LOG_ENABLED', true);
define('LOG_FILE', dirname(__DIR__) . '/cybokron-logs/cybokron.log');

$DISPLAY_CURRENCIES = ['USD', 'EUR', 'GBP', 'XAU'];

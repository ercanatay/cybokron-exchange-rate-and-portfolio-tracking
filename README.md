# Cybokron Exchange Rate & Portfolio Tracking

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-blue.svg)](https://php.net)
[![MySQL Version](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

Cybokron is an open-source PHP/MySQL application for tracking Turkish bank exchange rates and monitoring personal currency/metal portfolios.

## Project Model

- Standard open-source deployment model
- Self-hosted PHP + MySQL/MariaDB
- No platform lock-in
- Docker support (optional)

## Features

- **Multi-bank architecture** — Dünya Katılım, TCMB (Central Bank of Turkey), İş Bankası
- **Exchange rate scraping** with table-structure change detection
- **OpenRouter AI fallback** for automatic table-change recovery (cost-guarded)
- **Portfolio tracking** with profit/loss, soft delete, user-scoped RBAC, goal favorites, filtering, period-based progress & deadlines
- **Session-based authentication** (login, logout, admin/user roles)
- **Cloudflare Turnstile CAPTCHA** on login page (managed mode, auto-pass for most users)
- **Alert system** — email, Telegram, webhook notifications on rate thresholds
- **Rate history** with retention policy and cleanup cron
- **Chart.js dashboard** — rate trends, portfolio distribution pie chart
- **Currency converter** — bidirectional conversion, cross-rates
- **Bank selector + homepage controls** — default bank, homepage visibility, drag-drop order
- **Manual rate refresh** from dashboard and admin with CSRF protection
- **Widget layout controls** — show/hide + ordering persisted in settings
- **Currency icons** for major fiat currencies and precious metal badges
- **PWA support** — manifest, service worker, offline cache
- **Webhook dispatch** on rate updates (Zapier, IFTTT, Slack, Discord)
- **Admin dashboard** — bank/currency toggle, user list, system health
- **Observability panel** — scrape logs, success rates, latency
- **GitHub release-based self-update** with optional signed verification
- **Localization** — Turkish, English, Arabic, German, French

## Security Defaults

- CSRF protection for portfolio state-changing actions
- Cloudflare Turnstile CAPTCHA for login (configurable, disabled in development)
- Input validation for API and portfolio operations
- Security headers enabled by default (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`)
- Cron scripts can be restricted to CLI execution
- Optional CORS, disabled by default

## Performance Highlights

- Scraper page fetch caching prevents duplicate HTTP fetches during the same scrape cycle
- Currency code-to-ID lookup caching avoids N+1 queries in scrape persistence
- Transaction-wrapped rate writes reduce DB overhead and improve consistency
- Additional DB indexes for frequent history and portfolio lookups
- OpenRouter fallback uses cooldown + row/token limits to reduce API consumption

## Supported Banks

| Bank | URL | Status |
|------|-----|--------|
| Dünya Katılım | [gunluk-kurlar](https://dunyakatilim.com.tr/gunluk-kurlar) | Active |
| TCMB | [today.xml](https://www.tcmb.gov.tr/kurlar/today.xml) | Active |
| İş Bankası | [kur.doviz.com/isbankasi](https://kur.doviz.com/isbankasi) | Active |

## Requirements

- PHP 8.3 or 8.4
- MySQL 5.7+ or MariaDB 10.3+
- PHP extensions: `curl`, `dom`, `mbstring`, `json`, `pdo_mysql`, `zip`
- Cron access

## Installation

### 1. Clone

```bash
git clone https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking.git
cd cybokron-exchange-rate-and-portfolio-tracking
```

### 2. Create database

```bash
mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS cybokron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'cybokron_app'@'localhost' IDENTIFIED BY 'change_me_strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON cybokron.* TO 'cybokron_app'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql -u root -p cybokron < database/database.sql
```

### 3. Configure

```bash
cp config.sample.php config.php
nano config.php
```

Important defaults:

```php
define('DEFAULT_LOCALE', 'tr');
define('FALLBACK_LOCALE', 'en');
define('AVAILABLE_LOCALES', ['tr', 'en', 'ar', 'de', 'fr']);

define('ENABLE_SECURITY_HEADERS', true);
define('API_ALLOW_CORS', false);
define('API_REQUIRE_CSRF', true);
define('ENFORCE_CLI_CRON', true);
define('LOGIN_RATE_LIMIT', 5);           // brute-force protection
define('AUTO_UPDATE', false);
define('UPDATE_REQUIRE_SIGNATURE', true);
define('AUTH_REQUIRE_PORTFOLIO', true);
define('AUTH_BASIC_USER', 'admin');
define('AUTH_BASIC_PASSWORD_HASH', '');  // generate with password_hash(...)

define('TURNSTILE_ENABLED', false);      // Enable in production
define('TURNSTILE_SITE_KEY', '');        // Cloudflare Turnstile site key
define('TURNSTILE_SECRET_KEY', '');      // Cloudflare Turnstile secret key

define('RATE_UPDATE_WEBHOOK_URL', '');   // optional: Slack, Zapier, etc.
define('ALERT_EMAIL_FROM', 'noreply@localhost');
define('ALERT_COOLDOWN_MINUTES', 60);

define('OPENROUTER_AI_REPAIR_ENABLED', true);
define('OPENROUTER_MODEL', 'z-ai/glm-5');
define('OPENROUTER_API_KEY', '');        // set your key
```

Generate a password hash for `AUTH_BASIC_PASSWORD_HASH`:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

### 4. Run migrations (if upgrading)

```bash
php database/migrator.php
```

The migrator tracks applied migrations in a `schema_migrations` table. Place new `.sql` files in `database/migrations/` and run `migrator.php` to apply them. For fresh installs, `database/database.sql` already contains the full schema.

### 5. Configure cron

```cron
# Update exchange rates + check alerts every 15 minutes during market hours (Mon-Fri, 09:00-18:00)
*/15 9-18 * * 1-5 php /path/to/cron/update_rates.php >> /path/to/cybokron-logs/cron.log 2>&1 && php /path/to/cron/check_alerts.php >> /path/to/cybokron-logs/cron.log 2>&1

# Cleanup old rate history (weekly, Sunday 4am)
0 4 * * 0 php /path/to/cron/cleanup_rate_history.php >> /path/to/cybokron-logs/cron.log 2>&1
```

Optional (if self-update is configured with signed packages):

```cron
# Check for application updates daily at midnight
0 0 * * * php /path/to/cron/self_update.php >> /path/to/cybokron-logs/cron.log 2>&1
```

### 6. Open dashboard

`http://your-domain.com/cybokron/`

Default admin login: `admin` / `admin123` — change in production.

## OpenRouter AI Recovery

When a bank table changes and normal parser output is too small, Cybokron can auto-recover rows with OpenRouter.

- Default model: `z-ai/glm-5`
- Endpoint: `https://openrouter.ai/api/v1/chat/completions`
- Trigger condition: parsed row count below `OPENROUTER_MIN_EXPECTED_RATES`
- Cost controls:
1. Same table hash cooldown (`OPENROUTER_AI_COOLDOWN_SECONDS`)
2. Input size limit (`OPENROUTER_AI_MAX_INPUT_CHARS`, `OPENROUTER_AI_MAX_ROWS`)
3. Output token cap (`OPENROUTER_AI_MAX_TOKENS`)

Change model later (without code edits):

```bash
php scripts/set_openrouter_model.php z-ai/glm-5
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api.php?action=rates` | Latest rates |
| GET | `/api.php?action=rates&bank=dunya-katilim` | Rates by bank |
| GET | `/api.php?action=rates&currency=USD` | Rates by currency |
| GET | `/api.php?action=history&currency=USD&days=30` | Rate history |
| GET | `/api.php?action=portfolio` | Portfolio summary (Auth required) |
| POST | `/api.php?action=portfolio_add` | Add portfolio entry (Auth + CSRF) |
| POST/PUT/PATCH | `/api.php?action=portfolio_update` | Update portfolio entry (Auth + CSRF) |
| POST/DELETE | `/api.php?action=portfolio_delete&id=1` | Delete portfolio entry (Auth + CSRF) |
| GET | `/api.php?action=alerts` | List alerts (Auth required) |
| POST | `/api.php?action=alerts_add` | Create alert (Auth + CSRF) |
| POST/DELETE | `/api.php?action=alerts_delete&id=1` | Delete alert (Auth + CSRF) |
| GET | `/api.php?action=banks` | List banks |
| GET | `/api.php?action=currencies` | List currencies |
| GET | `/api.php?action=version` | App version |
| GET | `/api.php?action=ai_model` | OpenRouter model status |
| GET | `/api.php?action=goal_progress&goal_id=1&period=14d` | Goal progress with period filter (Auth required) |

API supports session login or Basic Auth. Rate limiting: 120 reads/min, 30 writes/min per IP.

## Docker

```bash
docker-compose up -d
```

App: `http://localhost:8080` — MySQL: port 3306. Uses `config.docker.php` with env vars.

## Localization

Translation files are in `locales/*.php`. Supported: Turkish (tr), English (en), Arabic (ar), German (de), French (fr).

To add a new language:

1. Create a file like `locales/de.php`
2. Copy keys from `locales/en.php`
3. Translate values
4. Add `de` to `AVAILABLE_LOCALES` in `config.php`

## CI/CD Flow (Control -> Test -> Deploy)

GitHub Actions workflows:

- `.github/workflows/quality-test-deploy.yml` — Main pipeline (PR + push to main)
- `.github/workflows/deploy.yml` — Production deployment (called by main pipeline or manual)
- `.github/workflows/rollback.yml` — Manual rollback to previous backup

Pipeline stages:

1. **Control**: PHP syntax check on 8.3 and 8.4
2. **Test**: Unit test suite on 8.3 and 8.4
3. **Migration Check**: SQL syntax validation for migration files
4. **Deploy** (main branch only): Config generation → backup → rsync → migrations → password update → version update → smoke test
5. **Auto Remediation**: Opens a GitHub issue if any stage fails on main

Rollback: Trigger `rollback.yml` manually via `gh workflow run rollback.yml` to restore from the latest file backup.

## Adding New Banks

To add a new bank source later:

1. Create a new scraper class in `banks/` extending `Scraper`
2. Implement `scrape()` and parse rules for the bank table (or override `run()` for XML sources like TCMB)
3. Add the class name to `$ACTIVE_BANKS` in `config.php`
4. Add the bank host to `SCRAPE_ALLOWED_HOSTS`
5. Insert bank metadata via a new migration SQL file in `database/migrations/`

## Changelog

### v1.7.3 (2026-02-14)

Kapalıçarşı scraper fix, Service Worker cache improvements, and admin cache management.

**Bug Fixes**
- Fix DovizComScraper targeting wrong table on multi-table pages (kur.doviz.com) — XPath now targets `table[@id="indexes"]` first, with fallback to `tbody//tr` and `//table//tr`
- Kapalıçarşı now correctly scrapes 19 currency rates from kur.doviz.com

**Service Worker**
- Change from cache-first to network-first strategy — no more shift+refresh needed to see updates
- Bump cache version to `cybokron-v3`
- Add `CLEAR_CACHE` message listener for admin-triggered cache clearing

**Admin Panel**
- Add "Clear Cache" button in System Health section — clears Service Worker and browser cache
- Cache clear button with visual feedback (loading → success → reset)

### v1.7.2 (2026-02-14)

Header UI/UX redesign, bank toggle fix, and portfolio rate update button.

**Header Redesign**
- Complete header rewrite with glass-morphism design and centered pill-group navigation
- Language switcher changed from inline pills to dropdown menu with animation
- Separate mobile menu overlay with nav links, language row, and action buttons
- Consistent header across all pages (index, portfolio, admin, observability, openrouter)
- Responsive: brand text hidden at 1100px, full mobile menu at 900px

**Bug Fixes**
- Fix bank/currency toggle not saving — `toggle_bank` and `toggle_currency` were missing from allowed POST actions list
- Add success messages for bank and currency toggle operations

**Portfolio**
- Add manual rate update button to portfolio page (admin only)

### v1.7.1 (2026-02-14)

Bug fixes for admin panel settings and version display on production.

**Admin Panel Fixes**
- Fix widget drag-drop not saving — `csrfToken` was undefined in widget management IIFE scope
- All admin settings (SEO toggle, default bank, chart defaults, retention, widget config) verified working

**Version Display**
- Add `getAppVersion()` helper with VERSION file + database fallback
- Replace all `file_get_contents('VERSION')` calls across 7 files (index, portfolio, admin, observability, openrouter, api, Updater)
- Eliminates PHP warnings on production where VERSION file is removed after deploy

**UI/UX**
- Redesign Groups and Tags sections with grid layouts, card styling, and hover-reveal actions
- Redesign Portfolio table with sticky headers, zebra striping, gradient accents, and card wrapper
- Responsive breakpoints for tablet (768px) and mobile (480px)

### v1.7.0 (2026-02-14)

Goal period filtering and deadline tracking for portfolio goals.

**Goal Period Filter**
- New period dropdown on goal cards: 7d, 14d, 1m, 3m, 6m, 9m, 1y, custom range
- AJAX-powered progress recalculation via `goal_progress` API endpoint
- Period filter respects goal sources (groups, tags, individual items)

**Goal Deadlines**
- Optional deadline field on goals with preset shortcuts (1m, 3m, 6m, 9m, 1y) or custom date
- Remaining time display on goal cards (":months months remaining" / "Expired")
- Deadline stored in `goal_deadline` column on `portfolio_goals` table

**Bug Fixes**
- Fix 401 auth error on goal period AJAX calls — `requirePortfolioAccessForApi()` now checks session auth before Basic Auth fallback
- Sync `database/database.sql` schema with actual DB (goal_deadline column, percent_date_mode enum, percent_period_months default)

**Localization**
- 20 new translation keys for period and deadline in all 5 languages (TR, EN, DE, AR, FR)

**CI/CD**
- Add `continue-on-error: true` to deploy cron verification step to prevent SSH rate-limit failures

### v1.6.1 (2026-02-14)

SEO controls, data retention settings, and localization completeness.

**Admin SEO Settings**
- noindex toggle in admin panel — adds `<meta name="robots" content="noindex">` to all pages when enabled
- Per-page SEO description meta tags for all public pages

**Data Retention**
- Configurable rate history retention period in admin panel (months/years)
- Settings persisted in DB, consumed by cleanup cron

**Localization**
- SEO description keys and data retention keys added across all 5 languages
- Complete German, Arabic, and French translations for admin config labels

### v1.5.5 (2026-02-14)

Security hardening based on consolidated audit from Codex, Claude Code, and Jules AI.

**Critical Fixes**
- Fix CSRF bypass on all goal mutations (add/edit/delete/favorite/source) — actions now gated by `$messageType === ''` check
- Fix IDOR on goal operations — user ownership scoping added to updateGoal, toggleGoalFavorite, deleteGoal, addGoalSource, removeGoalSource
- Add admin auth guard on index.php rate update action (previously any visitor could trigger exec())

**High Fixes**
- Fix destructive side effects in deleteGroup/deleteTag — ownership check now runs BEFORE unlinking items/pivot records
- Fix stored XSS in goal source builder — innerHTML replaced with createElement/textContent
- Hide exec() error output from users — details now logged server-side only

**Medium Fixes**
- Fix race condition in tag assignment — use Portfolio::add() return value instead of SELECT MAX(id)
- Add CSV formula injection protection — prefix dangerous cell values with single quote
- Update database.sql with all migrated columns (is_favorite, percent_date_mode, etc.)

### v1.5.4 (2026-02-13)

Security, performance, and accessibility improvements.

**Security (PR #15)**
- Fix IDOR vulnerability in alerts API — non-admin users can now only see/delete their own alerts
- User-scoped WHERE clauses added to alerts list and alerts_delete endpoints

**Performance (PR #16)**
- Optimize `computeGoalProgress()` from O(N*M) to O(N+M) using hash map pre-indexing
- Pre-index items by ID, group ID, and tag ID for O(1) lookups in goal source matching
- All inner item loops now iterate only matched items instead of scanning all items

**Accessibility (PR #17)**
- Add `aria-label` attributes to all icon-only buttons (delete, save, cancel, remove, favorite)
- Inject translated labels into JavaScript for dynamically created DOM elements
- Fix bug in PHP template where JS string concatenation was incorrectly used for aria-label
- New locale keys: `common.delete`, `common.remove`, `common.save`, `common.cancel` (TR, EN, DE, FR)

### v1.5.3 (2026-02-13)

Goal favorites and client-side filtering for the Goals tab.

**Portfolio Goals**
- Star/unstar goals to mark favorites — favorites always sort to the top of the list
- New `is_favorite` column on `portfolio_goals` with DB index for fast queries
- `toggleGoalFavorite()` backend method with CSRF-protected POST toggle

**Goal Filtering**
- New filter bar above the goals list with pill-style toggle buttons
- Filter by: Favorites, Source Type (Group / Tag), Currency
- Filters combine with AND logic and work entirely client-side (no page reload)
- Dynamic currency dropdown populated from goals' target currencies
- Clear button resets all active filters

**UI/UX**
- Favorite star button (★/☆) on each goal card header with hover scale animation
- Active filter buttons highlighted with primary color
- Responsive filter bar wrapping on mobile (≤768px)

**Database**
- Migration `004_add_goal_favorite.sql`: `is_favorite TINYINT(1)` column + composite index

**Localization**
- 8 new translation keys in Turkish and English (favorites, filter labels, toggle messages)

### v1.5.2 (2026-02-13)

Security hardening, accessibility improvements, and performance optimizations.

**Security**
- Added CSRF token validation to login form (`login.php`)
- Converted logout from GET to POST with CSRF protection (`logout.php`, `header.php`)
- Added explicit SSL verification (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST`) to webhook and alert curl calls (`WebhookDispatcher.php`, `AlertChecker.php`)

**UI/UX & Accessibility**
- Increased all touch targets to minimum 44px (WCAG 2.5.5): theme toggle, hamburger menu, swap button, language pills, `.btn-sm`, `.btn-xs`, admin `.btn-icon` and `.btn-action`
- Improved `--danger` color contrast from `#ef4444` to `#f87171` (~5.5:1 ratio on dark bg)
- Improved `--primary` color contrast from `#3b82f6` to `#60a5fa` (~6.3:1 ratio on dark bg)
- Increased language button font size from 0.68rem to 0.75rem

**Performance**
- Fixed N+1 query issue in OpenRouter panel: 3N separate SELECTs replaced with single `WHERE key IN(...)` batch query
- Added `defer` attribute to all script tags on index page for non-blocking loading
- Updated service worker precache list with missing assets (`chart.umd.min.js`, `converter.js`, `chart.js`, `bootstrap.js`, `currency-icons.css`), cache version bumped to `cybokron-v2`
- Lazy-loaded `Scraper.php`, `OpenRouterRateRepair.php`, `Updater.php` — removed from `cybokron_init()`, now loaded only in cron scripts that need them

**Localization**
- Added `nav.logout_action` translation key in Turkish and English

### v1.5.1 (2026-02-13)

Admin panel improvements: editable OpenRouter settings and redesigned system configuration UI.

**Admin OpenRouter Settings**
- API key and model editable from admin panel (password field with show/hide toggle)
- DB-backed API key resolution: database value takes priority over config.php constant
- Key source indicator shows whether API key comes from DB, config.php, or is not configured
- Default model `z-ai/glm-5` pre-filled, validated with regex before save

**System Configuration Redesign**
- Replaced flat key-value grid with themed card layout (6 sections: Security, Scraping, Market Hours, Notifications, API Limits, System)
- Human-readable localized labels instead of raw PHP constant names
- Color-coded status indicators with icons per section
- Responsive grid (3→2→1 columns)

**Localization**
- Added 40+ translation keys for config labels, day names, and OpenRouter settings in Turkish and English

### v1.5.0 (2026-02-13)

Release focused on OpenRouter AI management, model tuning, and admin observability.

**OpenRouter AI Management Panel**
- New `openrouter.php` admin page with connection testing, model management, per-bank AI repair statistics, and table change logs
- Live connection test with response time measurement against OpenRouter API
- Model switching from admin UI (DB-backed, no code edits needed)
- Per-bank AI repair cards showing last call time, cooldown status, and extracted rate counts

**AI Repair Tuning**
- Increased `OPENROUTER_AI_MAX_TOKENS` from 600 to 4000 for GLM-5 reasoning model compatibility
- Increased `OPENROUTER_AI_TIMEOUT_SECONDS` from 25 to 60 to accommodate reasoning token generation
- GLM-5 now successfully extracts 14/14 currency rates from Dünya Katılım

**Pipeline & Deployment**
- Added `OPENROUTER_API_KEY` to GitHub Actions deploy pipeline with secret injection
- Config template placeholder + sed replacement for production API key

**Navigation & UI**
- Added OpenRouter AI link in header navigation and admin footer
- New `assets/css/openrouter.css` with responsive card layout and status indicators

**Localization**
- Added 36 OpenRouter-related translation keys for Turkish and English

### v1.4.0 (2026-02-13)

Release focused on admin UX, homepage configurability, expanded bank coverage, and production deployment automation.

**Highlights**
- Added İş Bankası scraper (`banks/IsBank.php`) and integrated additional currency icon assets
- Added manual "Update Rates Now" action on both `admin.php` and `index.php`
- Added homepage visibility toggle and drag-drop custom ordering for rates (`show_on_homepage`, `display_order`)
- Added default bank and chart default settings managed in admin and consumed by homepage widgets
- Added widget layout persistence (visibility + order) through settings-backed configuration
- Added shared header include and broader UI refresh across rates, portfolio, login, and observability screens
- Added Cloudflare Turnstile CAPTCHA on login page (managed mode)
- Added automated CI/CD pipeline with backup, migration, and rollback support

**API / Portfolio**
- Extended portfolio update endpoint to accept `bank_slug` updates
- Expanded portfolio model capabilities around grouping metadata and edit flows

**Database**
- Added `rates.show_on_homepage` and `rates.display_order` columns with indexes
- Consolidated schema into single `database/database.sql` for fresh installs
- Migration system (`database/migrator.php`) with `schema_migrations` tracking table

### v1.3.1 (2026)

Patch release focused on localization completeness and stability fixes.

**Localization & UX**
- Localized API error/success payloads and endpoint descriptions through i18n keys
- Expanded translation coverage across Turkish, English, Arabic, German, and French
- Localized chart dataset labels, theme toggle accessibility labels, and CSV export headers
- Locale-aware number formatting improvements in portfolio analytics widgets

**Fixes**
- Hardened login redirect validation against unsafe redirect targets
- Improved portfolio form error mapping with field-level localized validation messages
- Fixed dashboard mini-portfolio summary metrics (`total_value`, `profit_percent`)

### v1.3.0 (2026)

Feature release focused on multi-bank coverage, portfolio depth, observability, and production hardening.

**Authentication & RBAC**
- Session-based login (`login.php`, `Auth.php`)
- User table, admin/user roles
- Portfolio `user_id` scoping (admin sees all, users see own)
- Login rate limiting (brute-force protection)

**Multi-bank**
- TCMB scraper (XML source: today.xml)
- 12 additional currencies (DKK, NOK, SEK, KWD, RON, RUB, etc.)

**Alerts**
- Alerts table, cron checker (`check_alerts.php`)
- Email, Telegram, webhook channels
- API: `alerts`, `alerts_add`, `alerts_delete`

**Portfolio**
- Edit form, soft delete (`deleted_at`)
- CSV export
- Analytics: distribution pie chart, annualized return

**Dashboard & UI**
- Chart.js rate history
- Currency converter widget
- Top movers, mini portfolio summary
- Dark/Light theme toggle

**PWA**
- `manifest.json`, service worker (`sw.js`)
- Offline cache, "Add to Home Screen"

**Admin & Observability**
- Admin dashboard (`admin.php`) — bank/currency toggle, user list
- Observability panel (`observability.php`) — scrape logs, success rates

**Integrations**
- Webhook dispatch on rate updates
- Signed update pipeline (`docs/SIGNED-UPDATE.md`, `generate_signature.php`)

**Other**
- Docker: Dockerfile, docker-compose
- Localization: Arabic, German, French
- Extended test coverage

**Security & Performance**
- Hardened API input validation and CSRF requirements for state-changing actions
- Scraper/page-fetch caching, currency lookup caching, and transaction-wrapped rate writes
- Accessibility and UX improvements across dashboard and portfolio screens

## Legal Disclaimer

- This software is provided for informational and personal tracking purposes only.
- **This is not investment advice, financial advice, trading advice, or tax advice.**
- Exchange rate data may be delayed, incomplete, or inaccurate depending on source availability.
- You are solely responsible for any financial decisions or actions taken based on this software.

## Open Source Governance

- License: [MIT](LICENSE)
- Code of Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)

## Author

[ercanatay](https://github.com/ercanatay)

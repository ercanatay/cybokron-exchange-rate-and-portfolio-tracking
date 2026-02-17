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

- **Multi-bank architecture** — 38 supported bank/exchange sources (Dünya Katılım, TCMB, İş Bankası, Kapalıçarşı, and more)
- **Exchange rate scraping** with table-structure change detection
- **OpenRouter AI fallback** for automatic table-change recovery (cost-guarded)
- **Autonomous self-healing** — detects broken scrapers, generates new configs via AI, validates & commits to GitHub
- **Live repair tracker** — real-time SSE streaming of repair pipeline steps with stepper UI
- **Portfolio tracking** with profit/loss, soft delete, user-scoped RBAC, goal favorites, filtering, period-based progress & deadlines
- **Currency totals per filter** — group/tag filter shows total native amounts (e.g. total XAU ounces, total XAG) with tag analytics strip
- **Deposit interest comparison** — each goal card shows what-if deposit return vs actual portfolio performance, with per-goal rate override
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
- **Observability panel** — scrape logs, success rates, latency, responsive card-based UI
- **GitHub release-based self-update** with optional signed verification
- **Localization** — Turkish, English, Arabic, German, French

## Security Defaults

- CSRF protection for portfolio state-changing actions
- Cloudflare Turnstile CAPTCHA for login (configurable, disabled in development)
- Input validation for API and portfolio operations
- Security headers enabled by default (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`)
- **Session revalidation** — active sessions are checked against the database every 5 minutes (deactivated/demoted users are revoked)
- **Strict ownership scoping** — portfolio, groups, tags, and goals are scoped to the authenticated user (no NULL-ownership leaks)
- **Basic Auth identity binding** — API Basic Auth credentials are bound to session identity for write operations
- Cron scripts can be restricted to CLI execution
- Optional CORS, disabled by default

## Performance Highlights

- Scraper page fetch caching prevents duplicate HTTP fetches during the same scrape cycle
- Currency code-to-ID lookup caching avoids N+1 queries in scrape persistence
- Transaction-wrapped rate writes reduce DB overhead and improve consistency
- Additional DB indexes for frequent history and portfolio lookups
- OpenRouter fallback uses cooldown + row/token limits to reduce API consumption

## Supported Banks

| Bank | Slug |
|------|------|
| Akbank | `akbank` |
| Albaraka Türk | `albaraka-turk` |
| Alternatif Bank | `alternatif-bank` |
| Altınkaynak | `altinkaynak` |
| Anadolubank | `anadolubank` |
| CEPTETEB | `cepteteb` |
| Denizbank | `denizbank` |
| DestekBank | `destekbank` |
| Dünya Katılım | `dunya-katilim` |
| Emlak Katılım | `emlak-katilim` |
| Enpara | `enpara` |
| Fibabanka | `fibabanka` |
| Garanti BBVA | `garanti-bbva` |
| Getirfinans | `getirfinans` |
| Hadi / TOMBank | `hadi` |
| Halkbank | `halkbank` |
| Harem | `harem` |
| Hayat Finans | `hayat-finans` |
| Hepsipay | `hepsipay` |
| HSBC | `hsbc` |
| ING Bank | `ing-bank` |
| İş Bankası | `is-bankasi` |
| Kapalıçarşı | `kapalicarsi` |
| Kuveyt Türk | `kuveyt-turk` |
| Misyon Bank | `misyon-bank` |
| Odacı | `odaci` |
| Odeabank | `odeabank` |
| Papara | `papara` |
| QNB Finansbank | `qnb-finansbank` |
| Şekerbank | `sekerbank` |
| TCMB | `tcmb` |
| Türkiye Finans | `turkiye-finans` |
| Vakıf Katılım | `vakif-katilim` |
| Vakıfbank | `vakifbank` |
| Venüs | `venus` |
| Yapıkredi | `yapikredi` |
| Ziraat Bankası | `ziraat-bankasi` |
| Ziraat Katılım | `ziraat-katilim` |

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

For detailed platform-specific instructions, see **[SETUP.md](SETUP.md)**.

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

## Documentation

| Document | Description |
|----------|-------------|
| [CHANGELOG.md](CHANGELOG.md) | Full version history with detailed release notes |
| [SETUP.md](SETUP.md) | Step-by-step installation guide for cPanel and VPS hosting |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | 100 common issues and solutions |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to contribute to the project |
| [SECURITY.md](SECURITY.md) | Security policy and vulnerability reporting |

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

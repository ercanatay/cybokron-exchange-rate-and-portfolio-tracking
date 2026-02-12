# Cybokron Exchange Rate & Portfolio Tracking

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-blue.svg)](https://php.net)
[![MySQL Version](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

Cybokron is an open-source PHP/MySQL application for tracking Turkish bank exchange rates and monitoring personal currency/metal portfolios.

## Project Model

- Standard open-source deployment model
- Self-hosted PHP + MySQL/MariaDB
- No platform lock-in

## Features

- Multi-bank architecture (initial bank: Dünya Katılım)
- Exchange rate scraping with table-structure change detection
- OpenRouter AI fallback for automatic table-change recovery (cost-guarded)
- Portfolio tracking with profit/loss calculation
- Historical rate storage
- GitHub release-based self-update
- Built-in localization (`tr` default, `en` fallback)

## Security Defaults

- CSRF protection for portfolio state-changing actions
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

## Supported Bank

| Bank | URL | Status |
|------|-----|--------|
| Dünya Katılım | [gunluk-kurlar](https://dunyakatilim.com.tr/gunluk-kurlar) | Active |

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
mysql -u root -p < database.sql
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
define('AVAILABLE_LOCALES', ['tr', 'en']);

define('ENABLE_SECURITY_HEADERS', true);
define('API_ALLOW_CORS', false);
define('API_REQUIRE_CSRF', true);
define('ENFORCE_CLI_CRON', true);

define('OPENROUTER_AI_REPAIR_ENABLED', true);
define('OPENROUTER_MODEL', 'z-ai/glm-5');
define('OPENROUTER_API_KEY', ''); // set your key
```

### 4. Configure cron

```cron
# Update exchange rates every 15 minutes during market hours (Mon-Fri, 09:00-18:00)
*/15 9-18 * * 1-5 php /path/to/cron/update_rates.php >> /var/log/cybokron.log 2>&1

# Check for application updates daily at midnight
0 0 * * * php /path/to/cron/self_update.php >> /var/log/cybokron-update.log 2>&1
```

### 5. Open dashboard

`http://your-domain.com/cybokron/`

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
| GET | `/api.php?action=portfolio` | Portfolio summary |
| POST | `/api.php?action=portfolio_add` | Add portfolio entry (CSRF required by default) |
| POST/DELETE | `/api.php?action=portfolio_delete&id=1` | Delete portfolio entry (CSRF required by default) |
| GET | `/api.php?action=banks` | List banks |
| GET | `/api.php?action=currencies` | List currencies |
| GET | `/api.php?action=version` | App version |
| GET | `/api.php?action=ai_model` | OpenRouter model status |

## Localization

Translation files are in `locales/*.php`.

To add a new language:

1. Create a file like `locales/de.php`
2. Copy keys from `locales/en.php`
3. Translate values
4. Add `de` to `AVAILABLE_LOCALES` in `config.php`

## CI/CD Flow (Control -> Test -> Deploy)

GitHub Actions workflow: `.github/workflows/quality-test-deploy.yml`

1. `Control`: syntax checks on PHP 8.3 and 8.4
2. `Test`: smoke tests on PHP 8.3 and 8.4
3. `Deploy`: runs only when control + test pass on `main` push (webhook-based, optional)
4. `Auto Remediation`: opens an issue automatically if pipeline fails on `main`

## Adding New Banks

To add a new bank source later:

1. Create a new scraper class in `banks/` extending `Scraper`
2. Implement `scrape()` and parse rules for the bank table
3. Add the class name to `$ACTIVE_BANKS` in `config.php`
4. Insert bank metadata into `banks` table (`name`, `slug`, `url`, `scraper_class`)

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

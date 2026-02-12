# Cybokron Exchange Rate & Portfolio Tracking

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![MySQL Version](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

Cybokron is an open-source PHP/MySQL application for tracking Turkish bank exchange rates and monitoring personal currency/metal portfolios.

## Project Model

- Standard open-source deployment model
- Self-hosted PHP + MySQL/MariaDB
- No platform lock-in

## Features

- Multi-bank architecture (initial bank: Dünya Katılım)
- Exchange rate scraping with table-structure change detection
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

## Supported Bank

| Bank | URL | Status |
|------|-----|--------|
| Dünya Katılım | [gunluk-kurlar](https://dunyakatilim.com.tr/gunluk-kurlar) | Active |

## Requirements

- PHP 8.0+
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

## Localization

Translation files are in `locales/*.php`.

To add a new language:

1. Create a file like `locales/de.php`
2. Copy keys from `locales/en.php`
3. Translate values
4. Add `de` to `AVAILABLE_LOCALES` in `config.php`

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

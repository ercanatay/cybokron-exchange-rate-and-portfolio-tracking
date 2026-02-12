# Cybokron Exchange Rate & Portfolio Tracking

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![MySQL Version](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)
[![Powered by Netlify](https://www.netlify.com/v3/img/components/netlify-color-bg.svg)](https://www.netlify.com/)

PHP/MySQL exchange rate tracker and portfolio manager. Scrapes live currency rates from Turkish banks, auto-updates when source tables change, and supports portfolio tracking. Self-updates via GitHub releases.

## Features

- 🏦 **Multi-Bank Support** — Scrape exchange rates from multiple Turkish banks (starting with Dünya Katılım)
- 📊 **Portfolio Tracking** — Add, manage, and track your currency portfolio with profit/loss calculations
- 🔄 **Auto-Update Rates** — Cron-based scraping with smart change detection
- 📈 **Historical Data** — Store and view rate history over time
- 🔁 **Self-Update** — Automatically pull new versions from GitHub releases
- 🏗️ **Schema Auto-Detect** — If the bank website table structure changes, the scraper adapts automatically
- 🌐 **Web Dashboard** — Clean, responsive UI to view rates and manage portfolios
- 🌍 **Multilingual UI** — Built-in Turkish (`tr`) and English (`en`) support, with Turkish as the default install language

## Supported Banks

| Bank | URL | Status |
|------|-----|--------|
| Dünya Katılım | [gunluk-kurlar](https://dunyakatilim.com.tr/gunluk-kurlar) | ✅ Active |

## Currencies Tracked

| Code | Currency | Type |
|------|----------|------|
| USD | US Dollar | Fiat |
| EUR | Euro | Fiat |
| GBP | British Pound | Fiat |
| CHF | Swiss Franc | Fiat |
| AUD | Australian Dollar | Fiat |
| CAD | Canadian Dollar | Fiat |
| CNY | Chinese Yuan | Fiat |
| JPY | Japanese Yen | Fiat |
| SAR | Saudi Riyal | Fiat |
| AED | UAE Dirham | Fiat |
| XAU | Gold | Precious Metal |
| XAG | Silver | Precious Metal |
| XPT | Platinum | Precious Metal |
| XPD | Palladium | Precious Metal |

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- PHP Extensions: `curl`, `dom`, `mbstring`, `json`, `pdo_mysql`
- Cron access (for auto-updates)

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking.git
cd cybokron-exchange-rate-and-portfolio-tracking
```

### 2. Create Database

```bash
mysql -u root -p < database.sql
```

### 3. Configure

```bash
cp config.sample.php config.php
nano config.php
```

Language defaults in `config.php`:

```php
define('DEFAULT_LOCALE', 'tr');
define('FALLBACK_LOCALE', 'en');
define('AVAILABLE_LOCALES', ['tr', 'en']);
```

### 4. Set Up Cron Jobs

```cron
# Update exchange rates every 15 minutes during market hours (Mon-Fri, 09:00-18:00)
*/15 9-18 * * 1-5 php /path/to/cron/update_rates.php >> /var/log/cybokron.log 2>&1

# Check for application updates daily at midnight
0 0 * * * php /path/to/cron/self_update.php >> /var/log/cybokron-update.log 2>&1
```

### 5. Access Dashboard

Open `http://your-domain.com/cybokron/` in your browser.

## Directory Structure

```
├── README.md
├── LICENSE
├── .gitignore
├── VERSION
├── config.sample.php
├── database.sql
├── index.php
├── portfolio.php
├── api.php
├── locales/
│   ├── tr.php
│   └── en.php
├── includes/
│   ├── Database.php
│   ├── Scraper.php
│   ├── Portfolio.php
│   ├── Updater.php
│   └── helpers.php
├── banks/
│   └── DunyaKatilim.php
├── cron/
│   ├── update_rates.php
│   └── self_update.php
└── assets/
    ├── css/style.css
    └── js/app.js
```

## Adding a New Bank

Create a new file in `banks/` extending the `Scraper` base class:

```php
<?php
// banks/SampleBank.php
require_once __DIR__ . '/../includes/Scraper.php';

class SampleBank extends Scraper
{
    protected string $bankName = 'Sample Bank';
    protected string $bankSlug = 'sample-bank';
    protected string $url = 'https://samplebank.com/exchange-rates';

    public function scrape(): array
    {
        $html = $this->fetchPage($this->url);
        return $this->parseRates($html);
    }
}
```

## Localization

The UI is localized with flat key/value dictionaries in `locales/*.php`.

- Default install locale is Turkish (`tr`)
- English (`en`) is included as a fallback
- Language can be switched from the top navigation

To add a new language:

1. Create a new file, for example `locales/de.php`
2. Copy keys from `locales/en.php` and translate values
3. Add the locale code in `AVAILABLE_LOCALES` in `config.php`

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api.php?action=rates` | Get latest rates |
| GET | `/api.php?action=rates&bank=dunya-katilim` | Rates for specific bank |
| GET | `/api.php?action=rates&currency=USD` | Rates for specific currency |
| GET | `/api.php?action=history&currency=USD&days=30` | 30-day rate history |
| GET | `/api.php?action=portfolio` | Portfolio summary |
| POST | `/api.php?action=portfolio_add` | Add portfolio entry |
| DELETE | `/api.php?action=portfolio_delete&id=1` | Delete portfolio entry |

## Self-Update

The app checks GitHub releases via `cron/self_update.php`. When a new version is found, it downloads the ZIP, extracts, and updates files automatically.

## Open Source Policy Notes

- License: MIT (OSI-approved)
- Code of Conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- Netlify link requirement: This site is powered by [Netlify](https://www.netlify.com/)
- Non-commercial scope: This repository is maintained as a non-commercial open-source project. It does not provide paid support or commercial hosting services.

## License

MIT License — see [LICENSE](LICENSE) file.

## Author

[ercanatay](https://github.com/ercanatay)

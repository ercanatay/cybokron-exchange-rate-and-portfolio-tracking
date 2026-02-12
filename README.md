# Cybokron Exchange Rate & Portfolio Tracking

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![MySQL Version](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)

PHP/MySQL exchange rate tracker and portfolio manager. Scrapes live currency rates from Turkish banks, auto-updates when source tables change, and supports portfolio tracking. Self-updates via GitHub releases.

## Features

- 🏦 **Multi-Bank Support** — Scrape exchange rates from multiple Turkish banks (starting with Dünya Katılım)
- 📊 **Portfolio Tracking** — Add, manage, and track your currency portfolio with profit/loss calculations
- 🔄 **Auto-Update Rates** — Cron-based scraping with smart change detection
- 📈 **Historical Data** — Store and view rate history over time
- 🔁 **Self-Update** — Automatically pull new versions from GitHub releases
- 🏗️ **Schema Auto-Detect** — If the bank website table structure changes, the scraper adapts automatically
- 🌐 **Web Dashboard** — Clean, responsive UI to view rates and manage portfolios

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

### 4. Set Up Cron Jobs

```cron
# Update exchange rates every 15 minutes during market hours (Mon-Fri, 09:00-18:00)
*/15 9-18 * * 1-5 php /path/to/cybokron/cron/update_rates.php >> /var/log/cybokron.log 2>&1

# Check for application updates daily at midnight
0 0 * * * php /path/to/cybokron/cron/self_update.php >> /var/log/cybokron-update.log 2>&1
```

### 5. Access Dashboard

Open `http://your-domain.com/cybokron/` in your browser.

## Directory Structure

```
cybokron/
├── README.md
├── LICENSE
├── .gitignore
├── VERSION
├── config.sample.php
├── database.sql
├── index.php
├── portfolio.php
├── api.php
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
    ├── css/
    │   └── style.css
    └── js/
        └── app.js
```

## Adding a New Bank

Create a new file in `banks/` directory extending the `Scraper` base class:

```php
<?php
// banks/YeniBank.php
require_once __DIR__ . '/../includes/Scraper.php';

class YeniBank extends Scraper
{
    protected string $bankName = 'Yeni Bank';
    protected string $bankSlug = 'yeni-bank';
    protected string $url = 'https://yenibank.com.tr/kurlar';

    public function scrape(): array
    {
        $html = $this->fetchPage($this->url);
        return $this->parseRates($html);
    }
}
```

Then register it in `config.php`:

```php
'banks' => [
    'dunya-katilim' => 'banks/DunyaKatilim.php',
    'yeni-bank'     => 'banks/YeniBank.php',
],
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api.php?action=rates` | Get latest rates |
| GET | `/api.php?action=rates&bank=dunya-katilim` | Rates for specific bank |
| GET | `/api.php?action=rates&currency=USD` | Rates for specific currency |
| GET | `/api.php?action=history&currency=USD&days=30` | Rate history |
| GET | `/api.php?action=portfolio` | Portfolio summary |
| POST | `/api.php?action=portfolio_add` | Add portfolio entry |
| DELETE | `/api.php?action=portfolio_delete&id=1` | Delete portfolio entry |

## Self-Update

Cybokron checks GitHub releases automatically via cron. Current version is stored in `VERSION` file.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-bank`)
3. Commit your changes
4. Push and open a Pull Request

## License

MIT License — see [LICENSE](LICENSE) file.

## Credits

- **Author:** [ercanatay](https://github.com/ercanatay)
- **Version:** 1.0.0

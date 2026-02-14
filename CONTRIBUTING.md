# Contributing to Cybokron

Thank you for your interest in contributing to Cybokron Exchange Rate & Portfolio Tracking. This guide explains how to get involved.

## Getting Started

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/cybokron-exchange-rate-and-portfolio-tracking.git
   cd cybokron-exchange-rate-and-portfolio-tracking
   ```
3. Set up the development environment (see [SETUP.md](SETUP.md))
4. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Environment

- PHP 8.3 or 8.4
- MySQL 5.7+ or MariaDB 10.3+
- Required PHP extensions: `curl`, `dom`, `mbstring`, `json`, `pdo_mysql`, `zip`

Copy the sample config and adjust for your local setup:

```bash
cp config.sample.php config.php
```

Import the database schema:

```bash
mysql -u root -p cybokron < database/database.sql
```

## Code Style

- Follow PSR-12 coding standards for PHP
- Use meaningful variable and function names
- Keep functions focused and short
- Add PHPDoc comments for public methods
- Localization: all user-facing strings must use the `t()` helper with keys in `locales/*.php`

## Adding a New Bank Scraper

1. Create a new class in `banks/` extending `Scraper`
2. Implement the `scrape()` method (or override `run()` for XML sources)
3. Add the class name to `$ACTIVE_BANKS` in `config.sample.php`
4. Add the bank host to `SCRAPE_ALLOWED_HOSTS`
5. Create a migration file in `database/migrations/` to insert bank metadata
6. Add localization keys for the bank name in all 5 locale files

## Adding Translations

Translation files are in `locales/*.php`. Supported languages: Turkish (tr), English (en), Arabic (ar), German (de), French (fr).

1. Add new keys to `locales/en.php` first (English is the reference)
2. Add the same keys to all other locale files
3. Use the `t('key.name')` helper in PHP templates

## Database Migrations

- Place new SQL files in `database/migrations/` with the naming convention `NNN_description.sql`
- The migrator (`database/migrator.php`) tracks applied migrations in a `schema_migrations` table
- Keep `database/database.sql` in sync with the full schema for fresh installs

## Testing

Run the test suite before submitting:

```bash
php tests/run.php
```

Run PHP syntax checks:

```bash
find . -name "*.php" -not -path "./vendor/*" | xargs -n1 php -l
```

## Submitting Changes

1. Ensure all tests pass
2. Ensure `php -l` reports no syntax errors on changed files
3. Write a clear commit message describing what and why
4. Push to your fork and open a Pull Request against `main`
5. Fill in the PR template with a summary and test plan

## Pull Request Guidelines

- Keep PRs focused on a single concern
- Include a clear description of the change
- Reference any related issues
- Add screenshots for UI changes
- Ensure CI passes (PHP syntax check + tests on 8.3 and 8.4)

## Reporting Issues

- Use [GitHub Issues](https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/issues) to report bugs
- Include: steps to reproduce, expected behavior, actual behavior, PHP version, browser (for UI issues)
- Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md) first for known solutions

## Security Vulnerabilities

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for details.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).

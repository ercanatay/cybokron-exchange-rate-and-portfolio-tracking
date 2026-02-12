# Cybokron v1.3.0

## Added
- Admin dashboard with bank/currency toggles, user management, and system health controls
- Observability panel for scrape logs, success rates, and latency metrics
- TCMB (`today.xml`) bank integration and extended currency coverage
- Session-based authentication with role-based access (`admin`, `user`)
- Alert engine with email, Telegram, and webhook channels
- Portfolio analytics (distribution pie + annualized return) and CSV export
- PWA capabilities (`manifest.json`, `sw.js`) with offline cache support
- Docker deployment assets (`Dockerfile`, `docker-compose.yml`, `config.docker.php`)

## Improved
- API surface expanded (`alerts*`, `banks`, `currencies`, `history`, `version`, `ai_model`)
- Security defaults strengthened (CSRF checks, safer headers, CLI-only cron option)
- Scraper and rate persistence performance improved via caching and transaction-based writes
- UI/UX and accessibility refinements on dashboard and portfolio flows
- Localization expanded: English, Turkish, Arabic, German, French

## Operational Notes
- New cron jobs: alert checks and historical rate cleanup
- Signed update workflow documented in `docs/SIGNED-UPDATE.md`
- Full support validated on PHP 8.3 and 8.4

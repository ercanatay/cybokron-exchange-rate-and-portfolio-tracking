# Changelog

All notable changes to this project will be documented in this file.

## [1.9.2] - 2026-02-14

Additional security hardening based on PR #24 codebase analysis report.

### Critical Fixes
- Enforce authentication on all API write operations regardless of AUTH_REQUIRE_PORTFOLIO setting
- Fix unauthenticated alerts access — require login, return empty array for anonymous users

### High Fixes
- Fix Local File Inclusion (LFI) risk in loadBankScraper — alphanumeric class name validation + realpath() path traversal check
- Fix CAGR calculation overflow — require minimum 30-day holding period to prevent extreme exponents
- Add email validation (filter_var) in AlertChecker before sending

## [1.9.1] - 2026-02-14

Security hardening based on consolidated audit across the PHP codebase.

### Critical Fixes
- Fix broken object-level authorization (IDOR) on tag/group assignment — ownership checks on assignTag, removeTag, bulkAssignGroup, and group_id in add/update
- Fix IDOR in goal_progress API — owner-scoped getGoal and getAllGoalSources methods
- Fix SSRF via alert webhook URLs — HTTPS-only enforcement + CURLOPT_PROTOCOLS on AlertChecker, WebhookDispatcher, and Turnstile
- Validate webhook URL on alert creation to prevent stored SSRF

### High Fixes
- Harden login redirect with strict allowlist regex (blocks backslash, absolute path, and crafted payloads)
- Fix XSS on CSRF token output in rate update form (htmlspecialchars)
- Add input validation on admin settings (normalizeBankSlug, normalizeCurrencyCode, API key format/length)

### Medium Fixes
- Fix non-admin alert scope — strict user_id scoping instead of including global rows
- Fix repair endpoints for generic scraper banks — pass full bank row to loadBankScraper
- Fix goal mutation false-positive success (>= 0 changed to > 0)
- Add SQL column whitelist for currencies name field

### Schema Integrity
- Add foreign keys on portfolio_tags.user_id, portfolio_goals.user_id, repair_configs.bank_id, repair_logs.bank_id
- Add INDEX on alerts.user_id for user-scoped queries
- Remove 4 redundant duplicate indexes (idx_username, idx_slug, idx_code, idx_bank_currency)
- New migration: 008_schema_integrity_fixes.sql

### Other
- Remove hardcoded ServBay PHP binary path — use PHP_BINARY
- Add periodic stale file cleanup to file-based rate limiter

## [1.9.0] - 2026-02-14

Live repair progress tracker with real-time SSE streaming and responsive observability redesign.

### Live Repair Tracker
- New SSE endpoint (`api_repair_stream.php`) streams self-healing pipeline steps in real-time
- Progress callback mechanism in `ScraperAutoRepair` emits step-by-step events (fetch_html, check_enabled, cooldown, generate_config, validate, save, commit, complete)
- Stepper/timeline UI with animated status icons (pending, spinner, success/error)
- `Scraper::prepareRepairContext()` public API for SSE endpoint to access HTML fetch + hash without Reflection
- Admin auth + CSRF + rate limit (5/min) security on SSE endpoint

### Observability UI/UX Redesign
- Complete responsive redesign of `observability.php` with card-based section layout
- Section icons (stats, healing, live, logs) with gradient backgrounds
- Sub-panels for self-healing area (active configs, repair logs, manual trigger)
- Mobile (640px and below): tables transform to card view using `data-label` attribute pattern
- Tablet (641-900px) and desktop (901px+) responsive breakpoints
- New `assets/css/observability.css` stylesheet (~400 lines)
- Manual trigger buttons with bank emoji icons and hover states

### Localization
- 17 new `repair.*` translation keys across all 5 languages (TR, EN, DE, FR, AR)

## [1.8.0] - 2026-02-14

Autonomous self-healing scraper system with AI-powered configuration recovery.

### Self-Healing Pipeline
- New `ScraperAutoRepair` class detects broken scraper configs and auto-recovers via OpenRouter AI
- Pipeline: detect failure, fetch HTML, generate new config via AI, validate, save, commit to GitHub
- Cooldown management prevents repeated API calls for the same table hash
- Detailed step logging with duration tracking for each pipeline phase
- GitHub auto-commit of repaired configs via Personal Access Token

### Scraper Improvements
- `DunyaKatilimScraper` returns empty array instead of throwing on parse failure (enables self-healing trigger)
- Table change detection with SHA-256 hash comparison

### Observability Integration
- Repair history logs displayed in observability panel
- Active repair configurations listed with bank/status/timestamp
- Manual repair trigger buttons per bank with CSRF protection

### CI/CD
- Deploy workflow updated with `CYBOKRON_GITHUB_PAT` secret for self-healing GitHub commits

## [1.7.4] - 2026-02-14

Fix cache persistence and dark mode header rendering.

### Cache
- Add `Cache-Control: no-cache, must-revalidate` header for HTML pages to prevent browser/proxy caching
- Service Worker registration with `updateViaCache: 'none'` and version query parameter to force update checks
- Bump SW cache to `cybokron-v4`

### Dark Mode
- Add `--surface-rgb` CSS variable for proper header glass-morphism transparency in dark mode

## [1.7.3] - 2026-02-14

Kapalicarsi scraper fix, Service Worker cache improvements, and admin cache management.

### Bug Fixes
- Fix DovizComScraper targeting wrong table on multi-table pages (kur.doviz.com)
- Kapalicarsi now correctly scrapes 19 currency rates from kur.doviz.com

### Service Worker
- Change from cache-first to network-first strategy
- Bump cache version to `cybokron-v3`
- Add `CLEAR_CACHE` message listener for admin-triggered cache clearing

### Admin Panel
- Add "Clear Cache" button in System Health section
- Cache clear button with visual feedback (loading, success, reset)

## [1.7.2] - 2026-02-14

Header UI/UX redesign, bank toggle fix, and portfolio rate update button.

### Header Redesign
- Complete header rewrite with glass-morphism design and centered pill-group navigation
- Language switcher changed from inline pills to dropdown menu with animation
- Separate mobile menu overlay with nav links, language row, and action buttons
- Consistent header across all pages
- Responsive: brand text hidden at 1100px, full mobile menu at 900px

### Bug Fixes
- Fix bank/currency toggle not saving
- Add success messages for bank and currency toggle operations

### Portfolio
- Add manual rate update button to portfolio page (admin only)

## [1.7.1] - 2026-02-14

Bug fixes for admin panel settings and version display on production.

### Admin Panel Fixes
- Fix widget drag-drop not saving
- All admin settings verified working

### Version Display
- Add `getAppVersion()` helper with VERSION file + database fallback
- Replace all `file_get_contents('VERSION')` calls across 7 files

### UI/UX
- Redesign Groups and Tags sections with grid layouts, card styling, and hover-reveal actions
- Redesign Portfolio table with sticky headers, zebra striping, gradient accents, and card wrapper
- Responsive breakpoints for tablet (768px) and mobile (480px)

## [1.7.0] - 2026-02-14

Goal period filtering and deadline tracking for portfolio goals.

### Goal Period Filter
- New period dropdown on goal cards: 7d, 14d, 1m, 3m, 6m, 9m, 1y, custom range
- AJAX-powered progress recalculation via `goal_progress` API endpoint
- Period filter respects goal sources (groups, tags, individual items)

### Goal Deadlines
- Optional deadline field on goals with preset shortcuts (1m, 3m, 6m, 9m, 1y) or custom date
- Remaining time display on goal cards
- Deadline stored in `goal_deadline` column on `portfolio_goals` table

### Bug Fixes
- Fix 401 auth error on goal period AJAX calls
- Sync `database/database.sql` schema with actual DB

### Localization
- 20 new translation keys for period and deadline in all 5 languages (TR, EN, DE, AR, FR)

## [1.6.1] - 2026-02-14

SEO controls, data retention settings, and localization completeness.

### Admin SEO Settings
- noindex toggle in admin panel
- Per-page SEO description meta tags for all public pages

### Data Retention
- Configurable rate history retention period in admin panel (months/years)
- Settings persisted in DB, consumed by cleanup cron

### Localization
- SEO description keys and data retention keys added across all 5 languages

## [1.5.5] - 2026-02-14

Security hardening based on consolidated audit from Codex, Claude Code, and Jules AI.

### Critical Fixes
- Fix CSRF bypass on all goal mutations
- Fix IDOR on goal operations
- Add admin auth guard on index.php rate update action

### High Fixes
- Fix destructive side effects in deleteGroup/deleteTag
- Fix stored XSS in goal source builder
- Hide error output from users

### Medium Fixes
- Fix race condition in tag assignment
- Add CSV formula injection protection
- Update database.sql with all migrated columns

## [1.5.4] - 2026-02-13

Security, performance, and accessibility improvements.

### Security
- Fix IDOR vulnerability in alerts API
- User-scoped WHERE clauses added to alerts endpoints

### Performance
- Optimize `computeGoalProgress()` from O(N*M) to O(N+M) using hash map pre-indexing

### Accessibility
- Add `aria-label` attributes to all icon-only buttons

## [1.5.3] - 2026-02-13

Goal favorites and client-side filtering for the Goals tab.

### Portfolio Goals
- Star/unstar goals to mark favorites
- New `is_favorite` column on `portfolio_goals` with DB index

### Goal Filtering
- New filter bar above the goals list with pill-style toggle buttons
- Filter by: Favorites, Source Type (Group / Tag), Currency

## [1.5.2] - 2026-02-13

Security hardening, accessibility improvements, and performance optimizations.

### Security
- Added CSRF token validation to login form
- Converted logout from GET to POST with CSRF protection
- Added explicit SSL verification to webhook and alert curl calls

### Performance
- Fixed N+1 query issue in OpenRouter panel
- Added `defer` attribute to all script tags
- Lazy-loaded heavy includes

## [1.5.1] - 2026-02-13

Admin panel improvements: editable OpenRouter settings and redesigned system configuration UI.

### Admin OpenRouter Settings
- API key and model editable from admin panel (password field with show/hide toggle)
- DB-backed API key resolution: database value takes priority over config.php constant
- Key source indicator shows whether API key comes from DB, config.php, or is not configured
- Default model `z-ai/glm-5` pre-filled, validated with regex before save

### System Configuration Redesign
- Replaced flat key-value grid with themed card layout (6 sections: Security, Scraping, Market Hours, Notifications, API Limits, System)
- Human-readable localized labels instead of raw PHP constant names
- Color-coded status indicators with icons per section
- Responsive grid (3→2→1 columns)

### Localization
- Added 40+ translation keys for config labels, day names, and OpenRouter settings in Turkish and English

## [1.5.0] - 2026-02-13

OpenRouter AI management, model tuning, and admin observability.

### OpenRouter AI Management Panel
- New `openrouter.php` admin page with connection testing, model management, per-bank AI repair statistics, and table change logs
- Live connection test with response time measurement against OpenRouter API
- Model switching from admin UI (DB-backed, no code edits needed)
- Per-bank AI repair cards showing last call time, cooldown status, and extracted rate counts

### AI Repair Tuning
- Increased `OPENROUTER_AI_MAX_TOKENS` from 600 to 4000 for GLM-5 reasoning model compatibility
- Increased `OPENROUTER_AI_TIMEOUT_SECONDS` from 25 to 60 to accommodate reasoning token generation
- GLM-5 now successfully extracts 14/14 currency rates from Dünya Katılım

### Pipeline & Deployment
- Added `OPENROUTER_API_KEY` to GitHub Actions deploy pipeline with secret injection
- Config template placeholder + sed replacement for production API key

### Navigation & UI
- Added OpenRouter AI link in header navigation and admin footer
- New `assets/css/openrouter.css` with responsive card layout and status indicators

### Localization
- Added 36 OpenRouter-related translation keys for Turkish and English

## [1.4.0] - 2026-02-13

Admin UX, homepage configurability, expanded bank coverage, and production deployment automation.

### New Features
- Added İş Bankası scraper (`banks/IsBank.php`) and integrated additional currency icon assets
- Added manual "Update Rates Now" action on both `admin.php` and `index.php`
- Added homepage visibility toggle and drag-drop custom ordering for rates (`show_on_homepage`, `display_order`)
- Added default bank and chart default settings managed in admin and consumed by homepage widgets
- Added widget layout persistence (visibility + order) through settings-backed configuration
- Added shared header include and broader UI refresh across rates, portfolio, login, and observability screens
- Added Cloudflare Turnstile CAPTCHA on login page (managed mode)
- Added automated CI/CD pipeline with backup, migration, and rollback support

### API / Portfolio
- Extended portfolio update endpoint to accept `bank_slug` updates
- Expanded portfolio model capabilities around grouping metadata and edit flows

### Database
- Added `rates.show_on_homepage` and `rates.display_order` columns with indexes
- Consolidated schema into single `database/database.sql` for fresh installs
- Migration system (`database/migrator.php`) with `schema_migrations` tracking table

## [1.3.1] - 2026

Localization completeness and stability fixes.

### Localization & UX
- Localized API error/success payloads and endpoint descriptions through i18n keys
- Expanded translation coverage across Turkish, English, Arabic, German, and French
- Localized chart dataset labels, theme toggle accessibility labels, and CSV export headers
- Locale-aware number formatting improvements in portfolio analytics widgets

### Fixes
- Hardened login redirect validation against unsafe redirect targets
- Improved portfolio form error mapping with field-level localized validation messages
- Fixed dashboard mini-portfolio summary metrics (`total_value`, `profit_percent`)

## [1.3.0] - 2026

Initial public release with multi-bank scraping, portfolio tracking, authentication, RBAC, alerts, PWA support, Docker, and webhook integrations.

### Authentication & RBAC
- Session-based login (`login.php`, `Auth.php`)
- User table, admin/user roles
- Portfolio `user_id` scoping (admin sees all, users see own)
- Login rate limiting (brute-force protection)

### Multi-bank
- TCMB scraper (XML source: today.xml)
- 12 additional currencies (DKK, NOK, SEK, KWD, RON, RUB, etc.)

### Alerts
- Alerts table, cron checker (`check_alerts.php`)
- Email, Telegram, webhook channels
- API: `alerts`, `alerts_add`, `alerts_delete`

### Portfolio
- Edit form, soft delete (`deleted_at`)
- CSV export
- Analytics: distribution pie chart, annualized return

### Dashboard & UI
- Chart.js rate history
- Currency converter widget
- Top movers, mini portfolio summary
- Dark/Light theme toggle

### PWA
- `manifest.json`, service worker (`sw.js`)
- Offline cache, "Add to Home Screen"

### Admin & Observability
- Admin dashboard (`admin.php`) — bank/currency toggle, user list
- Observability panel (`observability.php`) — scrape logs, success rates

### Integrations
- Webhook dispatch on rate updates
- Signed update pipeline (`docs/SIGNED-UPDATE.md`, `generate_signature.php`)

### Other
- Docker: Dockerfile, docker-compose
- Localization: Arabic, German, French
- Extended test coverage
- Hardened API input validation and CSRF requirements for state-changing actions
- Scraper/page-fetch caching, currency lookup caching, and transaction-wrapped rate writes

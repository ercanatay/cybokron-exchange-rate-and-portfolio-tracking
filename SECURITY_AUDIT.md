# PHP Codebase Security & Code Quality Audit Report

**Date:** 2026-02-14
**Scope:** Full codebase analysis — all PHP files, SQL schemas, configuration files, .htaccess

---

## CRITICAL (3)

### 1. Hardcoded Default Password in Docker Config
- **File:** `config.docker.php:50`
- **Problem:** `AUTH_BASIC_PASSWORD_HASH` is set to the well-known bcrypt hash of `'password'` (`$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` — the Laravel/bcrypt test vector for the string "password").
- **Risk:** Anyone who deploys via Docker without changing this has an admin account with password `password`. Since the app manages financial portfolio data, this is critical.
- **Fix:** Remove the hardcoded hash. Require users to set it via environment variable or fail-closed on first run.

### 2. Placeholder Admin Password Hash in Database Seed
- **File:** `database/database.sql:384`
- **Problem:** `INSERT IGNORE INTO users ... VALUES ('admin', '$2y$10$placeholder_change_after_install', 'admin', 1)`. This placeholder is not a valid bcrypt hash, meaning `password_verify()` will always return false — nobody can log in as admin after fresh install without running the separate `update_admin_password.php` script. If someone installs and skips that step, the admin account is orphaned.
- **Risk:** Broken onboarding flow; potential for social-engineered workarounds.
- **Fix:** Either seed with no user and force setup on first visit, or require the password to be set during install.

### 3. Scraper Class Dynamic Instantiation from Database Values
- **File:** `includes/helpers.php:714-738` (`loadBankScraper`)
- **Problem:** The `scraper_class` value from the `banks` database table is used directly as a PHP class name: `new $className()`. The `require_once` path is also constructed from it: `__DIR__ . '/../banks/' . $className . '.php'`. While there is an `instanceof Scraper` check and a `file_exists` check, if an admin (or SQL injection elsewhere) inserts a malicious `scraper_class` value pointing to an existing file (e.g., `../includes/Database`), it will be loaded and instantiated. The `instanceof` check would prevent exploitation in most cases, but the `require_once` with unsanitized path is a path traversal risk.
- **Risk:** If any SQL injection exists (or admin account is compromised), this becomes remote code execution.
- **Fix:** Validate `$className` with a strict allowlist regex (e.g., `/^[A-Za-z][A-Za-z0-9]{2,50}$/`) before using it in `require_once` or `new`.

---

## HIGH (7)

### 4. Open Redirect on Login Success
- **File:** `login.php:34-38`
- **Problem:** After successful login, the redirect target is taken from `$_GET['redirect']`. The validation blocks `//`, `://`, and `..`, but does not block paths like `\\/evil.com` on some web servers, or `javascript:` URIs in some edge cases, or data URIs. It also accepts any relative path without restricting to known pages.
- **Fix:** Validate the redirect against a whitelist of known application pages, or at minimum prepend `/` and strip any scheme/host.

### 5. Admin API Key Stored in Database in Plaintext
- **File:** `admin.php:158-166`
- **Problem:** The OpenRouter API key is stored plaintext in the `settings` table. Anyone with database read access (backup leak, SQL injection) gets the API key.
- **Risk:** Financial exposure — OpenRouter API keys can incur charges.
- **Fix:** Encrypt the API key at rest using `openssl_encrypt()` with a key derived from a server-side secret, or use a secrets manager.

### 6. Webhook SSRF — No Host Allowlist for Webhook URLs
- **File:** `includes/WebhookDispatcher.php:47-63` and `includes/AlertChecker.php:184-212`
- **Problem:** Webhook URLs from config (`RATE_UPDATE_WEBHOOK_URLS`) and alert `channel_config` are fetched via `curl_init($url)` with no host validation. `filter_var(FILTER_VALIDATE_URL)` accepts URLs targeting internal services (e.g., `http://169.254.169.254/...` for cloud metadata, `http://localhost:3306/`). The alert webhook URL comes from user-submitted JSON in `channel_config`.
- **Risk:** Server-Side Request Forgery (SSRF) — an attacker who creates an alert with `channel=webhook` and a crafted internal URL can probe/access internal network services.
- **Fix:** Add an allowlist check for webhook hosts or at minimum deny RFC1918/link-local/loopback addresses. Restrict protocols to HTTPS-only.

### 7. CSRF Token Leaked via SSE GET Parameter
- **File:** `api_repair_stream.php:25`
- **Problem:** The SSE endpoint passes `csrf_token` as a GET query parameter (`$_GET['csrf_token']`). GET parameters are logged in server access logs, browser history, Referer headers, and proxy logs. This leaks the CSRF token.
- **Risk:** CSRF token exposure enables cross-site request forgery attacks.
- **Fix:** Use a separate, short-lived token for SSE requests rather than the main CSRF token, or transmit it via a custom header using `fetch()` instead of native `EventSource`.

### 8. Temporary Password File Not Deleted After Use
- **File:** `database/update_admin_password.php:21-27`
- **Problem:** After reading the plaintext password from `.admin_password.tmp`, the script hashes it but never deletes the file. The plaintext password file persists on disk.
- **Risk:** Plaintext credentials remain on the filesystem indefinitely.
- **Fix:** Add `unlink($passwordFile)` and `unlink($hashFile)` after successfully reading and hashing.

### 9. `mail()` Header Injection
- **File:** `includes/AlertChecker.php:130-146`
- **Problem:** The `$to` variable comes from `$config['email']`, which is user-submitted JSON from the `channel_config` field. This value is passed directly to PHP's `mail()` function. If it contains newlines or commas, it can inject additional headers (Bcc, CC, Subject overrides).
- **Risk:** Email header injection — spam relay, information disclosure.
- **Fix:** Validate `$to` is a single email address using `filter_var($to, FILTER_VALIDATE_EMAIL)` and reject if it contains `\r` or `\n`.

### 10. No Rate Limiting on Admin Password Update Script
- **File:** `database/update_admin_password.php`
- **Problem:** While it checks `PHP_SAPI === 'cli'`, the `.htaccess` file denies web access to `/database/`. However, if `.htaccess` is bypassed (nginx, misconfigured Apache, LiteSpeed), this script is directly accessible and could be used to overwrite the admin password by placing a crafted `.admin_password.tmp` file.
- **Risk:** Privilege escalation if server misconfiguration exposes CLI-only scripts.
- **Fix:** Add an explicit secondary guard (e.g., check for a shared secret env variable) inside the script itself.

---

## MEDIUM (11)

### 11. `'unsafe-inline'` in CSP for Scripts
- **File:** `includes/helpers.php:94`
- **Problem:** The default CSP allows `script-src 'self' 'unsafe-inline'`. This significantly weakens XSS protection because any injected inline script will execute.
- **Fix:** Remove `'unsafe-inline'` and use nonce-based CSP for inline scripts, or refactor all inline scripts to external files.

### 12. CSRF Token Not Rotated After Validation
- **File:** `includes/helpers.php:670-695`
- **Problem:** The CSRF token persists for the entire session. Once generated, the same token is reused for all forms. If an attacker obtains it (e.g., from the SSE GET param leak in issue #7), they can forge requests for the rest of the session.
- **Fix:** Rotate the CSRF token after each successful form submission, or use per-form tokens.

### 13. Race Condition in File-Based Rate Limiter
- **File:** `includes/helpers.php:385-435`
- **Problem:** The rate limiter uses `flock()` on temp files, which is advisory on many systems and doesn't work correctly on NFS. On concurrent requests, the JSON file could be corrupted. Also, rate limit files are never cleaned up, leading to inode exhaustion over time.
- **Fix:** Use database-based rate limiting, or add periodic cleanup of stale rate limit files (e.g., in cron).

### 14. No Foreign Key Constraint on `alerts.user_id`
- **File:** `database/database.sql:117`
- **Problem:** The `alerts` table has `user_id int unsigned DEFAULT NULL` but no `FOREIGN KEY` constraint referencing `users(id)`. Orphaned alert records can accumulate when users are deleted.
- **Fix:** Add `CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`.

### 15. `alerts.currency_code` Not Foreign-Keyed to `currencies.code`
- **File:** `database/database.sql:118`
- **Problem:** `currency_code varchar(10)` stores a string rather than referencing `currencies.id`. Alerts can reference non-existent currency codes with no referential integrity enforcement.
- **Fix:** Either add a FK to `currencies(code)` or change to `currency_id` referencing `currencies(id)`.

### 16. `repair_configs.bank_id` Missing Foreign Key
- **File:** `database/database.sql:260`
- **Problem:** While `idx_bank_active` index exists, there is no `FOREIGN KEY (bank_id) REFERENCES banks(id)` constraint. This allows orphaned repair configs.
- **Fix:** Add the FK constraint.

### 17. Duplicate Index on `users.username`
- **File:** `database/database.sql:19-20`
- **Problem:** `UNIQUE KEY username (username)` and `KEY idx_username (username)` — the unique key already provides an index. The second index is redundant and wastes storage/write performance.
- **Fix:** Remove `KEY idx_username`.

### 18. Duplicate Index on `banks.slug`
- **File:** `database/database.sql:38-39`
- **Problem:** `UNIQUE KEY slug (slug)` and `KEY idx_slug (slug)` are redundant.
- **Fix:** Remove `KEY idx_slug`.

### 19. Duplicate Composite Index on `rates`
- **File:** `database/database.sql:75-78`
- **Problem:** `UNIQUE KEY uk_bank_currency (bank_id, currency_id)` and `KEY idx_bank_currency (bank_id, currency_id)` are identical. Redundant index.
- **Fix:** Remove `KEY idx_bank_currency`.

### 20. Redundant Index on `currencies.code`
- **File:** `database/database.sql:56-57`
- **Problem:** `UNIQUE KEY code (code)` and `KEY idx_code (code)` — redundant.
- **Fix:** Remove `KEY idx_code`.

### 21. Hardcoded macOS PHP Binary Path
- **File:** `includes/helpers.php:1169`
- **Problem:** `$phpBinary = '/Applications/ServBay/bin/php'` — this macOS development path is hardcoded into the production exec fallback. On any non-macOS server, this file won't exist, and the code falls through to `PHP_BINARY`, but it's dead code in production and suggests development artifacts leaked into the codebase.
- **Fix:** Remove the ServBay-specific path. Use `PHP_BINARY` directly.

---

## LOW (8)

### 22. `@` Error Suppression on DOM Parsing
- **File:** `includes/Scraper.php:210` and `includes/OpenRouterRateRepair.php:218`
- **Problem:** `@$dom->loadHTML(...)` suppresses HTML parse warnings. While common for malformed HTML, it also hides legitimate errors and makes debugging harder.
- **Fix:** Use `libxml_use_internal_errors(true)` instead of `@` to capture and log warnings.

### 23. Sequential `if` Instead of `elseif` for POST Actions
- **File:** `admin.php:28-182`
- **Problem:** Each `if ($_POST['action'] === '...')` block is independent (not `elseif`). If a POST somehow matches multiple actions, only the last matching block's message survives. Architecturally fragile.
- **Fix:** Use `elseif` chains or a `switch` statement for the action handler.

### 24. `rate_history` Table Unbounded Growth
- **File:** `database/database.sql:87-102`
- **Problem:** Every scrape inserts into `rate_history` (no dedup). With 3 banks, 16 currencies, and 15-minute intervals, that's ~140K rows/month. The cleanup cron exists but must be manually configured.
- **Fix:** Document cleanup cron setup prominently, or add a `LIMIT` trigger in the scraper itself.

### 25. Missing `X-Download-Options` on API Responses
- **File:** `api.php` (entire file)
- **Problem:** No `Content-Disposition: attachment` or `X-Download-Options: noopen` header for defense-in-depth against browser rendering of JSON as HTML in older browsers.
- **Fix:** Add `header('X-Download-Options: noopen')` for API responses.

### 26. Session Fixation Window Before Regeneration
- **File:** `includes/Auth.php:27-30`
- **Problem:** `session_regenerate_id(true)` is called after setting session variables. Ideally, the session ID should be regenerated before writing sensitive data.
- **Fix:** Call `session_regenerate_id(true)` before writing to `$_SESSION`.

### 27. Locale Cookie Missing `domain` Attribute
- **File:** `includes/helpers.php:541-548`
- **Problem:** The `cybokron_locale` cookie is set without an explicit `domain` attribute. In multi-subdomain deployments this could cause issues.
- **Fix:** Consider making this configurable.

### 28. `SET FOREIGN_KEY_CHECKS = 0` Without Guards
- **File:** `database/database.sql:6`
- **Problem:** Disabling foreign key checks during schema creation is standard, but the file could be accidentally re-run on an existing database, silently violating constraints.
- **Fix:** Add a comment warning or guard logic.

### 29. CSV Export Missing `csvSafe()` on `currency_code`
- **File:** `portfolio_export.php:72`
- **Problem:** The `currency_code` field is output directly without the `csvSafe()` function that protects other fields. Currency codes are validated upstream, so this is low risk, but inconsistent.
- **Fix:** Apply `csvSafe()` to all fields for consistency.

---

## Summary

| Severity | Count | Key Themes |
|----------|-------|------------|
| **Critical** | 3 | Default passwords, dynamic class instantiation |
| **High** | 7 | Open redirect, SSRF, plaintext secrets, email injection |
| **Medium** | 11 | Weak CSP, missing FK constraints, duplicate indexes, CSRF lifecycle |
| **Low** | 8 | Error suppression, session timing, code quality |
| **Total** | **29** | |

## Positives Worth Noting

The codebase demonstrates good security awareness in many areas:
- Prepared statements used consistently (no raw SQL injection found)
- CSRF protection on all state-changing forms
- Host allowlists for scraping, GitHub, and OpenRouter outbound requests
- `htmlspecialchars()` used consistently in templates (no XSS found)
- Session hardening (strict mode, httponly, samesite)
- CSP and security headers applied by default
- Zip-slip protection in the updater
- Log injection prevention (`cybokron_log` sanitizes newlines)
- SQL identifier validation in `Database::assertIdentifier()`
- Rate limiting on login and API endpoints
- Signature verification for self-update packages

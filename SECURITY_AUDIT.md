# PHP Codebase Security & Quality Audit

**Date:** 2026-02-15
**Scope:** Full codebase analysis — security, bugs, performance, code quality, database schema

## Overall Assessment

This is a well-architected PHP application with strong security fundamentals. The codebase demonstrates defense-in-depth with prepared statements, CSRF protection, input validation, output escaping, and host allowlisting. No critical vulnerabilities were found — the issues below range from High to Low severity.

---

## HIGH Severity Issues

### 1. Default Admin Credentials / Temp Password Files Not Cleaned
- **Files:** `database/database.sql:384-386`, `database/update_admin_password.php:21-27`
- **Problem:** The seed SQL inserts a default admin user with a placeholder bcrypt hash. The `update_admin_password.php` script reads plaintext passwords from `.admin_password.tmp` but never deletes the temp file after processing.
- **Fix:** Add `@unlink($passwordFile)` and `@unlink($hashFile)` after successful password update.

### 2. OpenRouter API Key Stored as Plaintext in Database
- **Files:** `admin.php:180-188`, `includes/OpenRouterRateRepair.php:166-174`
- **Problem:** The OpenRouter API key is stored unencrypted in the `settings` table. Database exposure reveals the key.
- **Fix:** Encrypt the API key before storage using `sodium_crypto_secretbox()` or similar.

### 3. `update_admin_password.php` Accepts Unvalidated Hash from Environment
- **File:** `database/update_admin_password.php:31`
- **Problem:** The script accepts a hash from `getenv('ADMIN_HASH')` and inserts it directly. If an attacker controls environment variables, they can set their own bcrypt hash.
- **Fix:** Validate that the hash starts with `$2y$` or `$2b$` before accepting.

---

## MEDIUM Severity Issues

### 4. CSRF Token Never Rotated After Use
- **File:** `includes/helpers.php:707-716`
- **Problem:** The CSRF token is generated once per session and reused for all requests.
- **Fix:** Regenerate the CSRF token after each successful form POST.

### 5. Filesystem-Based Rate Limiting
- **File:** `includes/helpers.php:409-472`
- **Problem:** Rate limiting via JSON files in `/tmp` doesn't scale and is subject to race conditions in shared hosting.
- **Fix:** Use database-backed or Redis-backed rate limiting.

### 6. Session-Based Repair Rate Limit Bypassable
- **File:** `api_repair_stream.php:43-57`
- **Problem:** Clearing cookies gives a fresh rate limit counter. Expensive AI calls can be amplified.
- **Fix:** Add IP-based rate limiting using `enforceIpRateLimit()`.

### 7. `alerts.user_id` Nullable with No FK Constraint
- **File:** `database/database.sql:113`
- **Problem:** No foreign key on `alerts.user_id` allows orphaned rows.
- **Fix:** Add `CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`.

### 8. `Database::update()` Accepts Raw SQL in `$where`
- **File:** `includes/Database.php:138`
- **Problem:** The `$where` parameter is raw SQL — safe today but a future injection risk.
- **Fix:** Document the security contract or restrict to parameterized conditions.

### 9. Webhook SSRF — No Private IP Filtering
- **Files:** `includes/AlertChecker.php:200-205`, `includes/WebhookDispatcher.php:67-72`
- **Problem:** HTTPS-only validation doesn't prevent SSRF to internal IPs (e.g., `https://169.254.169.254/`).
- **Fix:** Validate resolved IPs are not in private ranges before making requests.

### 10. Two Divergent Database Schema Files
- **Files:** `database.sql` (root) vs `database/database.sql`
- **Problem:** Two schema files can diverge, causing deployment errors.
- **Fix:** Remove the root-level `database.sql` or make it a redirect.

---

## LOW Severity Issues

### 11. `@loadHTML()` Suppresses Parsing Errors
- **Files:** `includes/Scraper.php:210`, `includes/OpenRouterRateRepair.php:218`
- **Fix:** Use `libxml_use_internal_errors(true)` instead.

### 12. Updater Creates Empty Backup Directory (No Actual Backup)
- **File:** `includes/Updater.php:172-178`
- **Fix:** Copy current app into `$backupDir` before overwriting.

### 13. `icon.php` Missing Security Headers
- **File:** `icon.php:1-24`
- **Fix:** Add `header('X-Content-Type-Options: nosniff');`.

### 14. Logout Doesn't Expire Session Cookie
- **File:** `includes/Auth.php:48-54`
- **Fix:** Explicitly expire the session cookie with `setcookie()`.

### 15. `rate_history` Unbounded Growth
- **File:** `database/database.sql:83-98`
- **Fix:** Consider partitioning or reducing default retention.

### 16. Sample Config Uses HTTP by Default
- **File:** `config.sample.php:19`
- **Fix:** Add comment: `// IMPORTANT: Use https:// in production`.

### 17. Polymorphic `source_id` With No FK Enforcement
- **File:** `database/database.sql:232`
- **Fix:** Implement application-level cascade deletes.

### 18. `t()` Replacements Not HTML-Escaped
- **File:** `includes/helpers.php:641-658`
- **Fix:** Escape replacements by default or document that callers must escape.

---

## Code Quality Issues

### 19. Inconsistent Settings Upsert Pattern
- **File:** `admin.php` (multiple lines)
- **Problem:** Uses raw SQL instead of `Database::upsert()`.
- **Fix:** Replace with `Database::upsert('settings', ...)`.

### 20. `Portfolio.php` God Class (60KB)
- **File:** `includes/Portfolio.php`
- **Problem:** Single file handles CRUD, groups, tags, goals, progress computation.
- **Fix:** Extract into focused classes.

---

## Summary

| # | Issue | Severity |
|---|-------|----------|
| 1 | Temp password files not cleaned | HIGH |
| 2 | API key plaintext in DB | HIGH |
| 3 | Unvalidated env hash | HIGH |
| 4 | CSRF token not rotated | MEDIUM |
| 5 | Filesystem rate limiting | MEDIUM |
| 6 | Repair rate limit bypassable | MEDIUM |
| 7 | alerts.user_id no FK | MEDIUM |
| 8 | Raw SQL in $where | MEDIUM |
| 9 | Webhook SSRF no IP filter | MEDIUM |
| 10 | Divergent schema files | MEDIUM |
| 11-18 | Various low-severity issues | LOW |
| 19-20 | Code quality issues | LOW |

**Positive observations:** PDO with `EMULATE_PREPARES=false`, proper bcrypt password handling, `session_regenerate_id()` on login, `htmlspecialchars()` on all output, comprehensive CSP/HSTS headers, HTTPS enforcement on outbound calls, host allowlisting, zip-slip protection, CSV formula injection protection, and CLI-only cron enforcement.

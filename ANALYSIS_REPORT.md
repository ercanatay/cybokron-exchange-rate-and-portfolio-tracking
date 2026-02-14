# Codebase Analysis Report

This document outlines security vulnerabilities, logic errors, and code quality issues identified in the codebase.

## 1. Security (Critical): Unauthenticated Information Disclosure & IDOR
**File:** `api.php`
**Line:** 310-314 (Alerts endpoint), 330 (Add Alert), 376 (Delete Alert)
**Description:**
The `alerts` endpoint allows unauthenticated users (if `AUTH_REQUIRE_PORTFOLIO` is false or default configuration is misunderstood) to list ALL alerts in the system. The `WHERE` clause becomes `1=1` when `Auth::check()` is false. This exposes metadata about all users' alerts.
Additionally, unauthenticated users can create alerts (which are then "global" or unassigned) and delete ANY alert by ID (IDOR) because the deletion logic only checks ownership *if* authenticated.
**Fix:**
Enforce authentication for `alerts` endpoints regardless of portfolio visibility settings. Return empty list or 403 if not authenticated. Ensure `user_id` is always checked for deletion.

## 2. Security (High): Server-Side Request Forgery (SSRF) via Webhook
**File:** `includes/AlertChecker.php`
**Line:** 196 (in `sendWebhookAlert`)
**Description:**
The `sendWebhookAlert` function uses `curl_init` and `curl_exec` on a URL provided by the user in the `channel_config` JSON blob. There is no validation or filtering of this URL. An attacker (authenticated or unauthenticated per above) can create an alert with a webhook pointing to internal services (e.g., AWS metadata, localhost ports) to perform SSRF attacks.
**Fix:**
Validate the URL scheme (http/https only) and ensure the target IP address is not private/internal (loopback, 10.x, 192.168.x, etc.) before executing the request.

## 3. Security (High): Unauthenticated Portfolio Exposure (Configuration Risk)
**File:** `api.php`, `includes/Portfolio.php`
**Line:** Various
**Description:**
If `AUTH_REQUIRE_PORTFOLIO` is set to `false`, the API exposes all portfolio items (`Portfolio::getAll`) and allows modification (`Portfolio::add`, `update`, `delete`) without authentication. While this may be intended for single-user local setups, it poses a high risk if exposed to the internet.
**Fix:**
Ensure write operations (`add`, `update`, `delete`) always require authentication, even if read operations are public.

## 4. Security (Low): Potential LFI in Bank Scraper Loading
**File:** `includes/helpers.php`
**Line:** 714 (`loadBankScraper`)
**Description:**
The `loadBankScraper` function uses the class name to construct a file path for `require_once`. While the class name comes from the database (which reduces risk), if an attacker can compromise the database (SQLi) or if an admin interface allows editing `scraper_class` without validation, this could lead to Local File Inclusion (LFI).
**Fix:**
Validate `$className` against a whitelist of allowed characters (alphanumeric only) or check that the resolved path is within the expected directory using `realpath()`.

## 5. Logic (Medium): CAGR Calculation Accuracy
**File:** `includes/Portfolio.php`
**Line:** 1241 (`computeGoalProgress`)
**Description:**
The CAGR calculation uses `pow($ratio, 1 / $years)`. If `$years` is very small (e.g., less than 1 day), `1 / $years` becomes very large, potentially causing overflow or misleading percentage calculations.
**Fix:**
Add a minimum threshold for `$years` (e.g., 1 month or 0.1 years) or handle the case where `$days` is small more gracefully.

## 6. Logic (Low): Missing Email Validation
**File:** `includes/AlertChecker.php`
**Line:** 105 (`sendEmailAlert`)
**Description:**
The email address in `channel_config` is used in `mail()` without strict validation. While header injection is less likely in modern PHP, it allows sending emails to arbitrary addresses if the user can create an alert.
**Fix:**
Use `filter_var($email, FILTER_VALIDATE_EMAIL)` before sending.

# Troubleshooting Guide

This document covers 100 common issues encountered when installing, configuring, and running the Cybokron Exchange Rate & Portfolio Tracking application. Each entry includes the symptom, root cause, and a step-by-step solution.

---

## Installation & Setup

### 1. PHP version too old

**Symptom:** The application shows a blank white page or a parse error mentioning unexpected syntax.

**Cause:** The server is running PHP 8.2 or earlier. This project requires PHP 8.3 or newer because it uses typed class constants, `json_validate()`, and other 8.3+ features.

**Solution:**
1. Check the current PHP version:
   ```bash
   php -v
   ```
2. If the version is below 8.3, upgrade PHP. On Ubuntu/Debian:
   ```bash
   sudo add-apt-repository ppa:ondrej/php
   sudo apt update
   sudo apt install php8.3 php8.3-cli php8.3-fpm
   ```
3. On ServBay, switch the PHP version from the ServBay control panel under **Settings > PHP > Version** and select 8.3 or 8.4.
4. Restart the web server and verify:
   ```bash
   php -v
   ```

---

### 2. Missing PHP curl extension

**Symptom:** Fatal error: `Call to undefined function curl_init()` when running a scraper or cron job.

**Cause:** The `curl` PHP extension is not installed or not enabled.

**Solution:**
1. Check if curl is loaded:
   ```bash
   php -m | grep curl
   ```
2. If missing, install it:
   ```bash
   sudo apt install php8.3-curl
   sudo systemctl restart php8.3-fpm
   ```
3. On ServBay, enable the extension in the ServBay PHP settings panel. Restart the PHP service afterward.

---

### 3. Missing PHP dom extension

**Symptom:** Fatal error: `Class 'DOMDocument' not found` when scraping bank rates.

**Cause:** The `dom` extension (provided by `php-xml`) is not installed.

**Solution:**
1. Install the XML package which includes DOM:
   ```bash
   sudo apt install php8.3-xml
   sudo systemctl restart php8.3-fpm
   ```
2. Verify:
   ```bash
   php -m | grep dom
   ```

---

### 4. Missing PHP mbstring extension

**Symptom:** Errors related to `mb_detect_encoding()` or `mb_convert_encoding()` when processing scraped HTML that contains Turkish characters.

**Cause:** The `mbstring` extension is not installed.

**Solution:**
```bash
sudo apt install php8.3-mbstring
sudo systemctl restart php8.3-fpm
```

---

### 5. Missing PHP pdo_mysql extension

**Symptom:** Fatal error: `could not find driver` when the application tries to connect to the database.

**Cause:** The `pdo_mysql` extension is not installed or enabled.

**Solution:**
```bash
sudo apt install php8.3-mysql
sudo systemctl restart php8.3-fpm
php -m | grep pdo_mysql
```

---

### 6. config.php not found

**Symptom:** The application displays an error like `Failed to open required 'config.php'` or shows a blank page.

**Cause:** The configuration file has not been created from the sample template.

**Solution:**
1. Copy the sample config:
   ```bash
   cp config.sample.php config.php
   ```
2. Open `config.php` in an editor and fill in the database credentials, site URL, and other required values:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'cybokron_exchange');
   define('DB_USER', 'root');
   define('DB_PASS', 'your_password');
   ```
3. Reload the application in your browser.

---

### 7. Database connection refused

**Symptom:** `SQLSTATE[HY000] [2002] Connection refused` when loading any page.

**Cause:** MySQL or MariaDB is not running, or the host/port in `config.php` is wrong.

**Solution:**
1. Check if MySQL is running:
   ```bash
   sudo systemctl status mysql
   ```
2. If not running, start it:
   ```bash
   sudo systemctl start mysql
   ```
3. Verify the host and port in `config.php`. If you use `localhost` and it fails, try `127.0.0.1` instead (this forces TCP instead of a Unix socket):
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', 3306);
   ```
4. Ensure the database user has the proper grants:
   ```sql
   GRANT ALL PRIVILEGES ON cybokron_exchange.* TO 'your_user'@'127.0.0.1' IDENTIFIED BY 'your_password';
   FLUSH PRIVILEGES;
   ```

---

### 8. Database schema import fails

**Symptom:** Errors when running `mysql < database/database.sql`, such as `ERROR 1064 (42000): You have an error in your SQL syntax`.

**Cause:** The MySQL version is too old or the import command is missing the database name.

**Solution:**
1. Make sure MySQL 5.7+ or MariaDB 10.3+ is installed:
   ```bash
   mysql --version
   ```
2. Create the database first, then import:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS cybokron_exchange CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p cybokron_exchange < database/database.sql
   ```
3. If you still get syntax errors, check that the SQL file was not corrupted during download.

---

### 9. File permissions prevent writing logs

**Symptom:** Warning: `file_put_contents(logs/app.log): failed to open stream: Permission denied`.

**Cause:** The web server user (e.g., `www-data`, `_www`, or the ServBay user) does not have write permission to the logs directory.

**Solution:**
```bash
mkdir -p logs
chmod 775 logs
chown www-data:www-data logs
```
On ServBay (macOS), the web server typically runs as your own user, so permissions are usually fine. If not:
```bash
chmod 775 logs
```

---

### 10. .htaccess not being processed

**Symptom:** Pretty URLs return 404, or the `api.php` router does not receive requests correctly.

**Cause:** Apache `mod_rewrite` is not enabled, or `AllowOverride` is set to `None`.

**Solution:**
1. Enable mod_rewrite:
   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```
2. In the Apache virtual host configuration, set:
   ```apache
   <Directory /var/www/cybokron-exchange>
       AllowOverride All
   </Directory>
   ```
3. Restart Apache. On ServBay, mod_rewrite is enabled by default; check the site configuration in the ServBay panel.

---

### 11. Timezone-related errors in rate timestamps

**Symptom:** Rates show timestamps that are offset by several hours, or PHP throws a warning about the default timezone not being set.

**Cause:** The PHP timezone is not configured or does not match the expected `Europe/Istanbul` timezone.

**Solution:**
1. Set the timezone in `config.php`:
   ```php
   date_default_timezone_set('Europe/Istanbul');
   ```
2. Alternatively, set it in `php.ini`:
   ```ini
   date.timezone = Europe/Istanbul
   ```
3. Restart PHP-FPM and verify:
   ```bash
   php -r "echo date_default_timezone_get();"
   ```

---

### 12. Composer not found or required

**Symptom:** User tries to run `composer install` but there is no `composer.json` file.

**Cause:** This project does not use Composer. All dependencies are self-contained. Running `composer install` is unnecessary.

**Solution:**
No action needed. This project has no Composer dependencies. If you see a dependency error, it refers to a missing PHP extension, not a Composer package. Check that all required PHP extensions are installed:
```bash
php -m | grep -E "curl|dom|mbstring|json|pdo_mysql|zip"
```

---

### 13. Missing PHP zip extension

**Symptom:** CSV export or backup functionality throws `Class 'ZipArchive' not found`.

**Cause:** The `zip` PHP extension is not installed.

**Solution:**
```bash
sudo apt install php8.3-zip
sudo systemctl restart php8.3-fpm
```

---

### 14. Site loads but CSS and JS assets are missing

**Symptom:** The page loads with no styling. The browser console shows 404 errors for files under `assets/`.

**Cause:** The document root is misconfigured, or the `assets/` directory was not included in the deployment.

**Solution:**
1. Verify that the web server document root points to the project root directory, not a subdirectory.
2. Check that the `assets/` directory exists and contains `css/` and `js/` subdirectories:
   ```bash
   ls -la assets/
   ```
3. If the directory is missing, re-clone or re-deploy the project.
4. On ServBay, confirm the site root in **ServBay > Sites** matches the project path.

---

### 15. PHP json extension missing

**Symptom:** Fatal error: `Call to undefined function json_encode()` on any page.

**Cause:** On PHP 8.0+, `json` is compiled into PHP by default. This error typically means the PHP installation is corrupt or a very unusual build was used.

**Solution:**
1. Check if json is available:
   ```bash
   php -m | grep json
   ```
2. If not, reinstall PHP:
   ```bash
   sudo apt install --reinstall php8.3
   ```
3. On most systems, this extension cannot be disabled. If you see this error, your PHP installation needs to be repaired.

---

## Database

### 16. Migration fails with "table already exists"

**Symptom:** Running `php database/migrator.php` produces `SQLSTATE[42S01]: Base table or view already exists`.

**Cause:** The migration was partially applied, or the schema was imported manually before running migrations.

**Solution:**
1. Check the `migrations` table to see which migrations have been recorded:
   ```sql
   SELECT * FROM migrations ORDER BY id;
   ```
2. If the table was created by a direct SQL import, manually insert the migration record:
   ```sql
   INSERT INTO migrations (migration, applied_at) VALUES ('001_create_rates_table', NOW());
   ```
3. Run the migrator again:
   ```bash
   php database/migrator.php
   ```

---

### 17. Foreign key constraint fails on insert

**Symptom:** `SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails`.

**Cause:** You are trying to insert a record that references a non-existent parent row (e.g., adding a portfolio item for a currency that does not exist in the rates table).

**Solution:**
1. Identify the foreign key from the error message.
2. Insert the parent record first. For example, make sure the currency exists:
   ```sql
   SELECT * FROM currencies WHERE code = 'USD';
   ```
3. If missing, run the rate scraper to populate currencies:
   ```bash
   php cron/update_rates.php
   ```
4. Then retry the insert operation.

---

### 18. Duplicate key error on rate insert

**Symptom:** `Duplicate entry 'XXX-USD-2026-02-14' for key 'unique_rate'` when running the rate updater.

**Cause:** The cron job ran more than once in the same scrape window, or the unique constraint on (bank, currency, date) prevented a duplicate.

**Solution:**
1. This is expected behavior -- the application prevents duplicate rates for the same bank, currency, and date.
2. If you need to update an existing rate, the scraper should use `INSERT ... ON DUPLICATE KEY UPDATE`. Check that the scraper class includes this logic.
3. If rates seem stale, check the `updated_at` column:
   ```sql
   SELECT bank, currency, rate_date, updated_at FROM rates WHERE rate_date = CURDATE() ORDER BY updated_at DESC LIMIT 10;
   ```

---

### 19. Character set mismatch causes garbled Turkish characters

**Symptom:** Turkish characters like "ş", "ç", "ğ", "ı" appear as `Ã§`, `ÅŸ`, or question marks in the database.

**Cause:** The database or connection is not using `utf8mb4`.

**Solution:**
1. Ensure the database uses utf8mb4:
   ```sql
   ALTER DATABASE cybokron_exchange CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Verify the connection charset in `config.php`:
   ```php
   define('DB_CHARSET', 'utf8mb4');
   ```
3. If using PDO, the DSN should include:
   ```
   charset=utf8mb4
   ```
4. Convert existing tables:
   ```sql
   ALTER TABLE rates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

---

### 20. Database connection timeout under load

**Symptom:** `SQLSTATE[HY000] [2002] Connection timed out` during peak usage or when multiple cron jobs run simultaneously.

**Cause:** MySQL `max_connections` is reached or the `wait_timeout` is too low.

**Solution:**
1. Check current connections:
   ```sql
   SHOW STATUS LIKE 'Threads_connected';
   SHOW VARIABLES LIKE 'max_connections';
   ```
2. Increase `max_connections` in `my.cnf`:
   ```ini
   [mysqld]
   max_connections = 200
   wait_timeout = 300
   ```
3. Restart MySQL:
   ```bash
   sudo systemctl restart mysql
   ```

---

### 21. Max connections exceeded

**Symptom:** `SQLSTATE[HY000] [1040] Too many connections`.

**Cause:** The application or cron jobs are opening connections without closing them, or the pool is exhausted.

**Solution:**
1. Immediately check what is consuming connections:
   ```sql
   SHOW PROCESSLIST;
   ```
2. Kill idle or sleeping connections:
   ```sql
   SELECT CONCAT('KILL ', id, ';') FROM information_schema.processlist WHERE command = 'Sleep' AND time > 300;
   ```
3. Ensure the application closes PDO connections by setting the variable to null at the end of scripts:
   ```php
   $pdo = null;
   ```
4. Increase `max_connections` if legitimately needed (see issue 20).

---

### 22. Slow queries on rate history

**Symptom:** The rate history chart or portfolio calculations take more than 5 seconds to load.

**Cause:** Missing indexes on the `rates` table, particularly on `(bank, currency, rate_date)`.

**Solution:**
1. Check existing indexes:
   ```sql
   SHOW INDEX FROM rates;
   ```
2. Add composite indexes if missing:
   ```sql
   ALTER TABLE rates ADD INDEX idx_rates_bank_currency_date (bank, currency, rate_date);
   ALTER TABLE rates ADD INDEX idx_rates_currency_date (currency, rate_date);
   ```
3. Run `ANALYZE TABLE rates;` to update statistics.
4. For very large tables, consider running `php cron/cleanup_rate_history.php` to prune old entries.

---

### 23. Schema out of sync after update

**Symptom:** Columns referenced in the code do not exist in the database, causing `SQLSTATE[42S22]: Column not found` errors.

**Cause:** A new version was deployed but migrations were not run.

**Solution:**
1. Run the migrator:
   ```bash
   php database/migrator.php
   ```
2. If the migrator itself fails, check which migrations are pending:
   ```bash
   ls database/migrations/
   ```
3. Compare against the `migrations` table in the database.
4. Apply missing migrations manually if needed by executing the SQL files in order.

---

### 24. Database backup and restore issues

**Symptom:** A restored backup shows old data, or the restore fails with syntax errors.

**Cause:** The backup was taken with incompatible options or from a different MySQL/MariaDB version.

**Solution:**
1. Create a proper backup:
   ```bash
   mysqldump -u root -p --single-transaction --routines --triggers cybokron_exchange > backup_$(date +%Y%m%d).sql
   ```
2. Restore:
   ```bash
   mysql -u root -p cybokron_exchange < backup_20260214.sql
   ```
3. If restoring between MySQL and MariaDB, avoid using `--set-gtid-purged=ON`. Use:
   ```bash
   mysqldump -u root -p --set-gtid-purged=OFF cybokron_exchange > backup.sql
   ```

---

### 25. MySQL vs MariaDB behavioral differences

**Symptom:** A query works on MySQL 8.0 but fails on MariaDB 10.3 (or vice versa), particularly with JSON functions or window functions.

**Cause:** MariaDB and MySQL have diverged in JSON handling and certain SQL features.

**Solution:**
1. If using `JSON_TABLE` or `JSON_ARRAYAGG`, note that MariaDB 10.3 has limited JSON support. Upgrade to MariaDB 10.6+ if possible.
2. Check the current server:
   ```sql
   SELECT VERSION();
   ```
3. The application is tested against MySQL 5.7+/8.0+ and MariaDB 10.3+. If you encounter a specific function incompatibility, check `database/migrator.php` for any version-specific SQL and apply the correct variant for your server.

---

## Scraping & Rate Updates

### 26. Rates not updating

**Symptom:** The dashboard shows stale rates; the "last updated" timestamp is hours or days old.

**Cause:** The cron job for rate updates is not configured or not running.

**Solution:**
1. Verify the cron job is scheduled:
   ```bash
   crontab -l | grep update_rates
   ```
2. If missing, add it:
   ```bash
   crontab -e
   ```
   Add the following line (runs every 15 minutes during market hours):
   ```
   */15 8-18 * * 1-5 /usr/bin/php /path/to/cron/update_rates.php >> /path/to/logs/cron.log 2>&1
   ```
3. Test manually:
   ```bash
   php cron/update_rates.php
   ```
4. Check `logs/` for errors.

---

### 27. Cron job runs but produces no output

**Symptom:** The cron log file is empty or contains no rate data.

**Cause:** PHP CLI is using a different `php.ini` than the web server, or the working directory is wrong.

**Solution:**
1. Ensure the cron job uses an absolute path for PHP and the script:
   ```
   */15 * * * * /usr/local/bin/php /Applications/ServBay/www/servbay/cybokron-exchange-rate-and-portfolio-tracking/cron/update_rates.php >> /tmp/cron_rates.log 2>&1
   ```
2. Check which `php.ini` the CLI uses:
   ```bash
   php --ini
   ```
3. Make sure the required extensions are enabled in the CLI php.ini.

---

### 28. SSL certificate verification fails during scrape

**Symptom:** cURL error: `SSL certificate problem: unable to get local issuer certificate`.

**Cause:** The CA bundle is not configured for PHP's cURL.

**Solution:**
1. Download a current CA bundle:
   ```bash
   curl -o /etc/ssl/certs/cacert.pem https://curl.se/ca/cacert.pem
   ```
2. Set the path in `php.ini`:
   ```ini
   curl.cainfo = /etc/ssl/certs/cacert.pem
   openssl.cafile = /etc/ssl/certs/cacert.pem
   ```
3. Restart PHP-FPM. Do NOT disable SSL verification (`CURLOPT_SSL_VERIFYPEER = false`) in production.

---

### 29. Bank website changed its HTML structure

**Symptom:** Rates return as zero or null for a specific bank, while other banks work fine. Logs show parsing errors.

**Cause:** The bank redesigned its website, changing CSS selectors or DOM structure that the scraper relies on.

**Solution:**
1. Check the scraper log for the specific bank:
   ```bash
   php cron/update_rates.php 2>&1 | grep "BankName"
   ```
2. Open the bank URL in a browser and inspect the new HTML structure.
3. Update the corresponding scraper class in `banks/`:
   ```bash
   ls banks/
   ```
4. Modify the CSS selectors or XPath in the bank's scraper class to match the new structure.
5. If OpenRouter AI self-healing is enabled, the `ScraperAutoRepair` class may attempt an automatic fix. Check `repairs/` for any generated patches.

---

### 30. Scraper timeout on slow bank websites

**Symptom:** `cURL error 28: Operation timed out after 30000 milliseconds`.

**Cause:** The bank's server is slow or the default timeout is too short.

**Solution:**
1. Increase the timeout in `config.php`:
   ```php
   define('SCRAPER_TIMEOUT', 60); // seconds
   ```
2. If only one bank is slow, consider increasing the timeout only for that specific scraper class by overriding the `getTimeout()` method in the corresponding `banks/` class.
3. Verify the bank URL is still reachable:
   ```bash
   curl -o /dev/null -s -w "%{http_code} %{time_total}s\n" https://www.examplebank.com.tr
   ```

---

### 31. Allowed hosts mismatch blocks scraping

**Symptom:** The scraper logs show `Host not in allowed list` or similar permission errors.

**Cause:** The bank URL's hostname is not in the configured allowed hosts list.

**Solution:**
1. Open `config.php` and check the `ALLOWED_SCRAPE_HOSTS` setting.
2. Add the missing host:
   ```php
   define('ALLOWED_SCRAPE_HOSTS', [
       'www.tcmb.gov.tr',
       'www.isbank.com.tr',
       'www.garanti.com.tr',
       // add the missing host here
   ]);
   ```
3. Run the scraper again:
   ```bash
   php cron/update_rates.php
   ```

---

### 32. Scraped rates are empty or zero

**Symptom:** The scraper runs without errors but all rates are stored as `0.00` or `NULL`.

**Cause:** The scraper is fetching the page successfully but the parsing logic is extracting empty values, likely due to JavaScript-rendered content that is not in the raw HTML.

**Solution:**
1. Check if the bank's rates are loaded via JavaScript (AJAX). If so, the scraper needs to target the bank's API endpoint rather than the HTML page.
2. Inspect the raw HTML returned by the scraper:
   ```bash
   php -r "echo file_get_contents('https://www.examplebank.com.tr/exchange-rates');" | head -100
   ```
3. If the rates are not in the raw HTML, look for an XHR/API call in the browser's Network tab and update the scraper URL accordingly.
4. Update the bank's scraper class in `banks/` to target the correct data source.

---

### 33. Duplicate rates inserted for the same period

**Symptom:** Charts show double data points, or the rates table has multiple entries per bank per currency per day.

**Cause:** The unique constraint is missing from the `rates` table, or the scraper is not using `INSERT ... ON DUPLICATE KEY UPDATE`.

**Solution:**
1. Check if the unique constraint exists:
   ```sql
   SHOW CREATE TABLE rates;
   ```
2. If missing, add it:
   ```sql
   ALTER TABLE rates ADD UNIQUE KEY unique_rate (bank, currency, rate_date);
   ```
3. Remove duplicates first:
   ```sql
   DELETE r1 FROM rates r1
   INNER JOIN rates r2
   WHERE r1.id > r2.id
     AND r1.bank = r2.bank
     AND r1.currency = r2.currency
     AND r1.rate_date = r2.rate_date;
   ```
4. Then add the unique key.

---

### 34. Scraper class not found for a bank

**Symptom:** `Class 'Banks\ExampleBank' not found` when the scraper runs.

**Cause:** The bank scraper file is missing from the `banks/` directory, or the class name does not match the file name.

**Solution:**
1. List available scraper classes:
   ```bash
   ls banks/
   ```
2. Ensure the file name matches the class name (e.g., `banks/Garanti.php` contains `class Garanti extends Scraper`).
3. If the file is missing, create a new scraper class by copying an existing one as a template:
   ```bash
   cp banks/IsBank.php banks/ExampleBank.php
   ```
4. Update the class name, URL, and CSS selectors in the new file.

---

### 35. DOM parsing error with malformed HTML

**Symptom:** PHP warnings: `DOMDocument::loadHTML(): Tag ... invalid` or `Unexpected end tag`.

**Cause:** The bank's HTML is not well-formed, and DOMDocument is strict by default.

**Solution:**
1. Suppress HTML parsing warnings by using the `LIBXML_NOERROR` flag in the scraper class:
   ```php
   libxml_use_internal_errors(true);
   $dom = new DOMDocument();
   $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
   libxml_clear_errors();
   ```
2. This should already be implemented in the base `Scraper` class. If a specific bank scraper overrides `loadHTML`, make sure it includes these flags.

---

### 36. Rate history not recording over time

**Symptom:** The rate history chart shows only today's data; historical data is missing.

**Cause:** The `cron/cleanup_rate_history.php` script is configured with too short a retention period, or rates are only being updated (not inserted) for existing dates.

**Solution:**
1. Check the cleanup retention setting in `config.php`:
   ```php
   define('RATE_HISTORY_DAYS', 365); // keep 1 year of history
   ```
2. Verify that the cleanup cron is not running too aggressively:
   ```bash
   crontab -l | grep cleanup_rate_history
   ```
3. A reasonable schedule is once daily:
   ```
   0 3 * * * /usr/bin/php /path/to/cron/cleanup_rate_history.php
   ```
4. Verify historical data exists:
   ```sql
   SELECT rate_date, COUNT(*) FROM rates GROUP BY rate_date ORDER BY rate_date DESC LIMIT 30;
   ```

---

### 37. TCMB XML parse error

**Symptom:** `SimpleXMLElement::__construct(): Entity: line X: parser error` when fetching rates from the Central Bank of Turkey.

**Cause:** The TCMB XML feed returned an HTML error page (e.g., a 403 or maintenance page) instead of valid XML.

**Solution:**
1. Test the TCMB URL directly:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" "https://www.tcmb.gov.tr/kurlar/today.xml"
   ```
2. If you get a 403, TCMB may be blocking your IP or requiring specific headers. Add a User-Agent header in the scraper:
   ```php
   curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CybokronBot/1.0)');
   ```
3. Verify the response is actually XML before parsing:
   ```php
   if (strpos($response, '<?xml') === false) {
       throw new \RuntimeException('TCMB did not return valid XML');
   }
   ```

---

### 38. Market hours configuration prevents scraping

**Symptom:** The cron job runs but logs indicate "Outside market hours, skipping."

**Cause:** The market hours in `config.php` do not match the current server time or timezone.

**Solution:**
1. Check the configured market hours in `config.php`:
   ```php
   define('MARKET_OPEN_HOUR', 8);
   define('MARKET_CLOSE_HOUR', 18);
   define('MARKET_DAYS', [1, 2, 3, 4, 5]); // Mon-Fri
   ```
2. Verify the server timezone (see issue 11).
3. For testing outside market hours, temporarily override or run the scraper with a force flag if available:
   ```bash
   php cron/update_rates.php --force
   ```

---

### 39. cURL error 6: Could not resolve host

**Symptom:** `cURL error 6: Could not resolve host: www.examplebank.com.tr`.

**Cause:** DNS resolution failed on the server, often due to restrictive DNS settings or network issues.

**Solution:**
1. Test DNS resolution:
   ```bash
   nslookup www.examplebank.com.tr
   ```
2. If it fails, check `/etc/resolv.conf` and add a public DNS:
   ```
   nameserver 8.8.8.8
   nameserver 1.1.1.1
   ```
3. If behind a proxy, configure cURL proxy settings in `config.php`:
   ```php
   define('CURL_PROXY', 'http://proxy.example.com:8080');
   ```

---

### 40. Scrape log shows repeated failures for all banks

**Symptom:** Every bank scraper fails with connection errors or timeouts simultaneously.

**Cause:** The server has no internet access, a firewall is blocking outbound HTTPS, or the server's IP has been rate-limited by multiple banks.

**Solution:**
1. Test outbound connectivity:
   ```bash
   curl -s https://www.google.com -o /dev/null -w "%{http_code}"
   ```
2. If blocked, check firewall rules:
   ```bash
   sudo iptables -L OUTPUT -n
   ```
3. Ensure port 443 (HTTPS) is open for outbound traffic.
4. If rate-limited, reduce scraping frequency in the cron schedule (e.g., every 30 minutes instead of every 15).

---

## Portfolio

### 41. Cannot add items to portfolio

**Symptom:** Clicking "Add to Portfolio" does nothing, or the form submission returns a 403 error.

**Cause:** The CSRF token is missing or expired.

**Solution:**
1. Check the browser's developer console for errors.
2. If you see a CSRF error, the session may have expired. Log out and log back in.
3. Ensure the CSRF token hidden field is present in the form:
   ```html
   <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
   ```
4. If the issue persists, check that `session_start()` is called before any output in `portfolio.php`.

---

### 42. CSRF token mismatch on portfolio operations

**Symptom:** "CSRF token validation failed" error on every form submission.

**Cause:** The session was regenerated between page load and form submission, or two tabs are sharing a session and one regenerated the token.

**Solution:**
1. Make sure CSRF tokens use a per-session (not per-request) approach to avoid issues with multiple tabs.
2. Check that `config.php` or the auth include does not call `session_regenerate_id(true)` on every page load -- only on login.
3. If you are behind a reverse proxy, ensure session cookies are passed correctly. Check the cookie domain and path settings.

---

### 43. Group operations failing silently

**Symptom:** Creating, editing, or deleting portfolio groups has no effect. No error is shown.

**Cause:** JavaScript errors are preventing the AJAX call from completing, or the API endpoint is returning an error that is not being displayed.

**Solution:**
1. Open the browser developer console (F12) and check for JavaScript errors.
2. Check the Network tab for the AJAX request to `api.php?action=...` and inspect the response.
3. If the response is `{"error":"CSRF token mismatch"}`, refresh the page to get a new token.
4. Ensure the `action` parameter is correctly set for group operations (e.g., `action=create_group`, `action=delete_group`).

---

### 44. CSV export is empty or contains only headers

**Symptom:** Downloading the portfolio CSV produces a file with column headers but no data rows.

**Cause:** The export query filters out all results, typically because no portfolio items exist for the current user or the date filter is too restrictive.

**Solution:**
1. Check that you have portfolio items:
   ```sql
   SELECT COUNT(*) FROM portfolio_items WHERE user_id = YOUR_USER_ID;
   ```
2. Verify the export URL does not include overly restrictive query parameters.
3. Open `portfolio_export.php` and check the query logic for any filter conditions that might exclude all rows.
4. Test the export without any filters applied.

---

### 45. Profit/loss calculation shows incorrect values

**Symptom:** The portfolio shows a profit when there should be a loss, or the percentage is clearly wrong.

**Cause:** The buy rate and current rate are compared incorrectly, or the currency direction (buy vs. sell) is mismatched.

**Solution:**
1. Check the buy rate stored in the portfolio item:
   ```sql
   SELECT currency, amount, buy_rate, buy_date FROM portfolio_items WHERE id = ITEM_ID;
   ```
2. Compare with the current rate displayed on the dashboard for the same bank and currency.
3. Ensure the portfolio item uses the same rate type (buying vs. selling) as the current display.
4. If the buy rate was entered manually, verify it matches the actual rate on the buy date.

---

### 46. Goal progress not updating

**Symptom:** Portfolio goals show 0% progress even though the target should be partially met.

**Cause:** The goal calculation relies on current rates that have not been fetched yet, or the goal currency does not match the portfolio item currency.

**Solution:**
1. Run the rate updater to ensure current rates are available:
   ```bash
   php cron/update_rates.php
   ```
2. Check the goal configuration:
   ```sql
   SELECT * FROM portfolio_goals WHERE user_id = YOUR_USER_ID;
   ```
3. Ensure the `target_currency` and `target_value` fields are set correctly.
4. Reload the portfolio page to trigger a recalculation.

---

### 47. Period filter not working on portfolio

**Symptom:** Selecting "Last 30 days" or "Last 90 days" filter shows all items regardless of date.

**Cause:** The `buy_date` column contains null values or dates in an unexpected format.

**Solution:**
1. Check the date format stored:
   ```sql
   SELECT id, buy_date FROM portfolio_items WHERE user_id = YOUR_USER_ID LIMIT 10;
   ```
2. The expected format is `YYYY-MM-DD`. If dates are stored differently, run a fix:
   ```sql
   UPDATE portfolio_items SET buy_date = STR_TO_DATE(buy_date, '%d/%m/%Y') WHERE buy_date LIKE '__/__/____';
   ```
3. Ensure items without a `buy_date` are handled gracefully in the filter logic.

---

### 48. buy_date format validation error

**Symptom:** Adding a portfolio item fails with "Invalid date format."

**Cause:** The date input is in a locale-specific format (e.g., `14/02/2026`) instead of the expected `YYYY-MM-DD` format.

**Solution:**
1. Use the HTML5 date input, which submits dates in `YYYY-MM-DD` format:
   ```html
   <input type="date" name="buy_date">
   ```
2. If using a custom date picker, ensure the submitted format is `YYYY-MM-DD`.
3. In the backend, validate and convert:
   ```php
   $buyDate = DateTime::createFromFormat('Y-m-d', $_POST['buy_date']);
   if (!$buyDate) {
       throw new InvalidArgumentException('Invalid date format. Use YYYY-MM-DD.');
   }
   ```

---

### 49. Deleted portfolio items still showing

**Symptom:** After deleting a portfolio item, it still appears in the list until the page is manually refreshed.

**Cause:** The JavaScript front-end is not removing the DOM element after a successful delete, or a service worker is serving a cached response.

**Solution:**
1. Check the browser console for errors after clicking delete.
2. Hard-refresh the page: `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (macOS).
3. If the item reappears after a hard refresh, verify the delete actually reached the database:
   ```sql
   SELECT * FROM portfolio_items WHERE id = ITEM_ID;
   ```
4. If the row still exists, the delete request failed. Check the CSRF token and user session.

---

### 50. Portfolio page loads blank

**Symptom:** The portfolio page shows a white screen or only the navigation bar.

**Cause:** A PHP fatal error occurred, but `display_errors` is off. Alternatively, a JavaScript error prevents rendering.

**Solution:**
1. Enable error display temporarily in `config.php`:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
2. Reload the page and check the error.
3. Check the PHP error log:
   ```bash
   tail -50 /var/log/php-fpm/error.log
   ```
4. Common causes: a missing database table (run migrations) or a missing include file.

---

### 51. Bulk operations failing on portfolio

**Symptom:** Selecting multiple portfolio items and performing a bulk action (delete, tag, move group) does nothing or returns an error.

**Cause:** The bulk action POST request exceeds PHP's `max_input_vars` limit when many items are selected.

**Solution:**
1. Check the current limit:
   ```bash
   php -i | grep max_input_vars
   ```
2. Increase it in `php.ini`:
   ```ini
   max_input_vars = 5000
   ```
3. Restart PHP-FPM:
   ```bash
   sudo systemctl restart php8.3-fpm
   ```
4. Alternatively, reduce the number of items per bulk operation.

---

### 52. CAGR shows 0% for portfolio items

**Symptom:** The Compound Annual Growth Rate is displayed as `0.00%` even for items held for more than a year with price changes.

**Cause:** The `buy_date` is the same as today (or very close), causing the time period to be near zero, which makes the CAGR formula return 0.

**Solution:**
1. Verify the `buy_date`:
   ```sql
   SELECT id, buy_date, DATEDIFF(CURDATE(), buy_date) AS days_held FROM portfolio_items WHERE user_id = YOUR_USER_ID;
   ```
2. CAGR requires at least one full day. If `days_held` is 0, the calculation cannot produce a meaningful result.
3. For items purchased today, the application should display "N/A" instead of 0%. Check the CAGR calculation in the portfolio code and ensure it handles the zero-day edge case:
   ```php
   if ($daysHeld < 1) {
       return null; // or 'N/A'
   }
   ```

---

## Authentication & Security

### 53. Login page shows blank

**Symptom:** Navigating to `login.php` displays a white page with no form.

**Cause:** A PHP error is occurring before any HTML output, typically a missing config file or session error.

**Solution:**
1. Check the PHP error log:
   ```bash
   tail -20 /var/log/php-fpm/error.log
   ```
2. Common causes:
   - `config.php` not found (see issue 6).
   - `session_start()` fails because the session save path is not writable.
3. Verify the session save path is writable:
   ```bash
   php -r "echo session_save_path();"
   ls -la $(php -r "echo session_save_path();")
   ```

---

### 54. Cloudflare Turnstile verification fails

**Symptom:** After solving the Turnstile CAPTCHA, the form submission returns "CAPTCHA verification failed."

**Cause:** The Turnstile secret key in `config.php` is incorrect, or the server cannot reach the Turnstile verification endpoint.

**Solution:**
1. Verify the Turnstile keys in `config.php`:
   ```php
   define('TURNSTILE_SITE_KEY', 'your_site_key');
   define('TURNSTILE_SECRET_KEY', 'your_secret_key');
   ```
2. Ensure the site key matches the domain configured in the Cloudflare dashboard.
3. Test connectivity to the verification endpoint:
   ```bash
   curl -s https://challenges.cloudflare.com/turnstile/v0/siteverify -d "secret=YOUR_SECRET&response=test"
   ```
4. If Turnstile is optional and not needed, disable it:
   ```php
   define('TURNSTILE_ENABLED', false);
   ```

---

### 55. Session expires too quickly

**Symptom:** Users are logged out after a few minutes of inactivity.

**Cause:** The PHP session garbage collection lifetime is too short, or the session cookie lifetime is misconfigured.

**Solution:**
1. Increase session lifetime in `php.ini`:
   ```ini
   session.gc_maxlifetime = 7200
   session.cookie_lifetime = 0
   ```
2. Or set it in `config.php` before `session_start()`:
   ```php
   ini_set('session.gc_maxlifetime', 7200); // 2 hours
   session_set_cookie_params(0); // until browser closes
   ```
3. If behind a load balancer, ensure session affinity (sticky sessions) is configured, or switch to database-backed sessions.

---

### 56. CSRF errors on every form submission

**Symptom:** Every POST request returns a CSRF validation error, even immediately after page load.

**Cause:** The session is not persisting between the page load (GET) and form submission (POST). This often happens when cookies are blocked or the session cookie domain is wrong.

**Solution:**
1. Open the browser developer tools and check that a session cookie (e.g., `PHPSESSID`) is being set and sent.
2. Check `config.php` for cookie settings:
   ```php
   session_set_cookie_params([
       'secure' => true,    // set to false if not using HTTPS locally
       'httponly' => true,
       'samesite' => 'Lax',
   ]);
   ```
3. If developing locally without HTTPS, set `secure` to `false`.
4. If using a custom domain, make sure the `domain` parameter is correct or omitted (defaults to current host).

---

### 57. Logout not clearing the session

**Symptom:** After clicking "Logout," the user can still access protected pages by navigating back.

**Cause:** `logout.php` is not properly destroying the session, or the browser is serving a cached page.

**Solution:**
1. Verify that `logout.php` includes:
   ```php
   session_start();
   session_unset();
   session_destroy();
   setcookie(session_name(), '', time() - 3600, '/');
   header('Location: login.php');
   exit;
   ```
2. Add cache-control headers to protected pages:
   ```php
   header('Cache-Control: no-store, no-cache, must-revalidate');
   header('Pragma: no-cache');
   ```

---

### 58. Basic Auth not accepted by API

**Symptom:** API requests with `Authorization: Basic ...` header return 401 Unauthorized.

**Cause:** The web server strips the `Authorization` header before PHP sees it. This is common with Apache + CGI/FastCGI.

**Solution:**
1. Add this to `.htaccess`:
   ```apache
   RewriteEngine On
   RewriteCond %{HTTP:Authorization} ^(.+)$
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
2. Or use `CGIPassAuth`:
   ```apache
   CGIPassAuth On
   ```
3. In PHP, also check for the `REDIRECT_HTTP_AUTHORIZATION` server variable:
   ```php
   $authHeader = $_SERVER['HTTP_AUTHORIZATION']
       ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
       ?? '';
   ```

---

### 59. Rate limiting too aggressive

**Symptom:** Legitimate users receive "429 Too Many Requests" after only a few actions.

**Cause:** The file-based rate limiter in `/tmp/cybokron_rate_limit/` has thresholds that are too low.

**Solution:**
1. Check the rate limit configuration in `config.php`:
   ```php
   define('RATE_LIMIT_MAX_REQUESTS', 60);
   define('RATE_LIMIT_WINDOW', 60); // seconds
   ```
2. Increase the limits as needed:
   ```php
   define('RATE_LIMIT_MAX_REQUESTS', 120);
   define('RATE_LIMIT_WINDOW', 60);
   ```
3. To clear existing rate limit data:
   ```bash
   rm -rf /tmp/cybokron_rate_limit/*
   ```

---

### 60. Password hash not working after PHP upgrade

**Symptom:** Existing users cannot log in after upgrading PHP. New accounts work fine.

**Cause:** Extremely old password hashes (e.g., MD5 or SHA1) are not compatible with `password_verify()`.

**Solution:**
1. Check how passwords are stored:
   ```sql
   SELECT id, username, LEFT(password, 10) FROM users LIMIT 5;
   ```
2. If hashes start with `$2y$`, they are bcrypt and should work.
3. If they are plain MD5 (32 hex characters), you need to reset passwords. Temporarily add a migration path:
   ```php
   // In the login handler, after verifying the old MD5 hash:
   if (md5($password) === $storedHash) {
       $newHash = password_hash($password, PASSWORD_DEFAULT);
       // UPDATE users SET password = $newHash WHERE id = $userId
   }
   ```
4. For new installations, always use `password_hash()` and `password_verify()`.

---

### 61. Admin panel access denied

**Symptom:** A logged-in user navigates to `admin.php` and gets a 403 or is redirected to the login page.

**Cause:** The user account does not have the admin role.

**Solution:**
1. Check the user's role:
   ```sql
   SELECT id, username, role FROM users WHERE username = 'your_username';
   ```
2. Grant admin access:
   ```sql
   UPDATE users SET role = 'admin' WHERE username = 'your_username';
   ```
3. Log out and log back in for the session to pick up the new role.

---

### 62. Security headers breaking embedded content

**Symptom:** External images, fonts, or iframes fail to load. The browser console shows Content-Security-Policy violations.

**Cause:** Strict CSP or X-Frame-Options headers are blocking external resources.

**Solution:**
1. Check the response headers in the browser's Network tab.
2. If you need to embed the app in an iframe, modify the X-Frame-Options header. In `.htaccess`:
   ```apache
   Header set X-Frame-Options "SAMEORIGIN"
   ```
3. For CSP issues, adjust the Content-Security-Policy header in `config.php` or the web server config to whitelist necessary domains:
   ```
   Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; img-src 'self' data:;
   ```

---

### 63. CORS errors when calling API from another domain

**Symptom:** JavaScript `fetch()` calls from a different origin fail with `Access-Control-Allow-Origin` errors.

**Cause:** The API does not include CORS headers for the requesting origin.

**Solution:**
1. Add CORS headers at the top of `api.php`:
   ```php
   header('Access-Control-Allow-Origin: https://your-frontend-domain.com');
   header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
   header('Access-Control-Allow-Headers: Content-Type, Authorization');
   header('Access-Control-Allow-Credentials: true');
   ```
2. Handle preflight (OPTIONS) requests:
   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
       http_response_code(204);
       exit;
   }
   ```
3. Replace the origin with `*` only for public, read-only endpoints. Never use `*` with credentials.

---

### 64. Redirect loop on login page

**Symptom:** The browser shows "This page redirected you too many times" on the login page.

**Cause:** The authentication check in `login.php` redirects unauthenticated users back to `login.php`, creating an infinite loop.

**Solution:**
1. Ensure `login.php` does not include the authentication redirect guard that protects other pages. The login page should be accessible without authentication.
2. Check for a global include that redirects:
   ```php
   // This should NOT be in login.php:
   if (!isset($_SESSION['user_id'])) {
       header('Location: login.php');
       exit;
   }
   ```
3. Clear your browser cookies and try again. The redirect loop may have cached redirect responses.

---

### 65. Brute force lockout with no way to recover

**Symptom:** After multiple failed login attempts, the user is locked out and cannot try again even with the correct password.

**Cause:** The rate limiter or brute force protection has blocked the IP/account, and there is no self-service unlock.

**Solution:**
1. Clear the file-based rate limit data:
   ```bash
   rm -rf /tmp/cybokron_rate_limit/*
   ```
2. If account-level lockout is implemented in the database:
   ```sql
   UPDATE users SET login_attempts = 0, locked_until = NULL WHERE username = 'locked_user';
   ```
3. Consider adding a lockout duration (e.g., 15 minutes) rather than permanent lockout. Check and adjust in `config.php`:
   ```php
   define('MAX_LOGIN_ATTEMPTS', 10);
   define('LOCKOUT_DURATION', 900); // 15 minutes in seconds
   ```

---

## API

### 66. API returns 401 Unauthorized

**Symptom:** All API requests return HTTP 401 with `{"error":"Unauthorized"}`.

**Cause:** The request is missing authentication. The API requires either a valid session cookie or Basic Auth credentials.

**Solution:**
1. For session-based auth, ensure the browser sends the session cookie (use `credentials: 'include'` in fetch).
2. For Basic Auth:
   ```bash
   curl -u username:password https://your-domain.com/api.php?action=rates
   ```
3. Verify the user exists and the password is correct.
4. Check that `.htaccess` passes the Authorization header (see issue 58).

---

### 67. API returns 403 Forbidden

**Symptom:** The API returns HTTP 403 even though the user is authenticated.

**Cause:** The user does not have permission for the requested action, or CSRF validation is failing on a POST endpoint.

**Solution:**
1. Check if the action requires admin privileges:
   ```sql
   SELECT role FROM users WHERE id = YOUR_USER_ID;
   ```
2. For POST/PUT/DELETE requests, include the CSRF token:
   ```bash
   curl -X POST -b "PHPSESSID=xxx" -d "csrf_token=TOKEN&action=delete_item&id=123" https://your-domain.com/api.php
   ```
3. For programmatic access, use Basic Auth instead of session auth to bypass CSRF requirements (if the application supports this flow).

---

### 68. API returns 429 Too Many Requests

**Symptom:** Rapid API calls return HTTP 429 with a `Retry-After` header.

**Cause:** The file-based rate limiter has been triggered.

**Solution:**
1. Wait for the `Retry-After` period indicated in the response header.
2. Reduce the frequency of your API calls.
3. If the limit is too strict for legitimate use, adjust in `config.php`:
   ```php
   define('API_RATE_LIMIT_MAX', 100);
   define('API_RATE_LIMIT_WINDOW', 60);
   ```
4. For server-to-server integrations, consider whitelisting the IP in the rate limiter.

---

### 69. CORS preflight request fails

**Symptom:** The browser sends an OPTIONS request that returns 405 Method Not Allowed or a 500 error, blocking the actual request.

**Cause:** The application does not handle OPTIONS requests, which browsers send as CORS preflight.

**Solution:**
1. Add an OPTIONS handler at the top of `api.php` (before any auth checks):
   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
       header('Access-Control-Allow-Origin: https://your-frontend.com');
       header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
       header('Access-Control-Allow-Headers: Content-Type, Authorization');
       header('Access-Control-Max-Age: 86400');
       http_response_code(204);
       exit;
   }
   ```
2. Ensure this runs before session checks or CSRF validation.

---

### 70. JSON parse error in API response

**Symptom:** The API returns invalid JSON, and `JSON.parse()` throws a syntax error. The response body starts with an HTML error or a PHP warning.

**Cause:** PHP is outputting warnings or notices before the JSON response.

**Solution:**
1. Ensure `api.php` sets the content type early:
   ```php
   header('Content-Type: application/json; charset=utf-8');
   ```
2. Suppress output before JSON by using output buffering:
   ```php
   ob_start();
   // ... processing ...
   ob_end_clean();
   echo json_encode($response);
   ```
3. Fix the underlying PHP warning rather than suppressing it. Check error logs:
   ```bash
   tail -20 /var/log/php-fpm/error.log
   ```

---

### 71. Action parameter missing in API request

**Symptom:** API returns `{"error":"Missing action parameter"}` or `{"error":"Unknown action"}`.

**Cause:** The `action` query parameter was not included in the request URL.

**Solution:**
1. Include the action in the URL:
   ```
   GET /api.php?action=rates
   GET /api.php?action=portfolio
   POST /api.php?action=add_item
   ```
2. For POST requests, `action` can also be in the request body, but the URL query string is preferred.
3. Check the list of valid actions in the `api.php` source code or documentation.

---

### 72. Rate limit hit during automated data collection

**Symptom:** A script that polls the API every few seconds starts getting 429 responses.

**Cause:** The polling interval is shorter than what the rate limiter allows.

**Solution:**
1. Reduce polling frequency. A reasonable interval for rate data is every 5-10 minutes.
2. Use the `Retry-After` header to dynamically adjust polling:
   ```javascript
   const retryAfter = response.headers.get('Retry-After');
   if (retryAfter) {
       await new Promise(resolve => setTimeout(resolve, retryAfter * 1000));
   }
   ```
3. Consider implementing server-sent events (SSE) instead of polling, if the API supports it via `api_repair_stream.php`.

---

### 73. API returns empty data array

**Symptom:** The API returns `{"data":[]}` when data should exist.

**Cause:** The query filters exclude all results, or the authenticated user has no data.

**Solution:**
1. Verify data exists in the database:
   ```sql
   SELECT COUNT(*) FROM rates WHERE rate_date = CURDATE();
   ```
2. Check the query parameters. For example, requesting rates for a nonexistent bank:
   ```
   GET /api.php?action=rates&bank=nonexistent
   ```
3. Ensure the authenticated user owns the data being queried (for portfolio endpoints).
4. Check the API response for pagination metadata; the data may be on a different page.

---

### 74. Portfolio API returns wrong user's data

**Symptom:** The portfolio API returns items belonging to a different user.

**Cause:** The session or Basic Auth is resolving to the wrong user ID, or the API query is missing a `user_id` filter.

**Solution:**
1. This is a security issue. Verify the session user:
   ```php
   error_log('Authenticated user ID: ' . $_SESSION['user_id']);
   ```
2. Ensure all portfolio queries filter by user:
   ```sql
   SELECT * FROM portfolio_items WHERE user_id = :user_id;
   ```
3. Never trust user-supplied user IDs in API requests. Always use the authenticated session's user ID.

---

### 75. Webhook not firing from API

**Symptom:** Alert webhooks configured via the API are not being sent when conditions are met.

**Cause:** The webhook URL is unreachable from the server, or the alert check cron is not running.

**Solution:**
1. Verify the cron job is running:
   ```bash
   crontab -l | grep check_alerts
   ```
2. Test the webhook URL manually:
   ```bash
   curl -X POST -H "Content-Type: application/json" -d '{"test":true}' https://your-webhook-url.com/endpoint
   ```
3. Check the alerts table for pending alerts:
   ```sql
   SELECT * FROM alerts WHERE status = 'pending' ORDER BY created_at DESC LIMIT 10;
   ```
4. Review the alert channel configuration:
   ```sql
   SELECT * FROM alert_channels WHERE type = 'webhook' AND user_id = YOUR_USER_ID;
   ```

---

## Alerts & Notifications

### 76. Email alerts not sending

**Symptom:** Alert conditions are triggered but no email is received.

**Cause:** SMTP is not configured, or the mail function is disabled on the server.

**Solution:**
1. Check SMTP settings in `config.php`:
   ```php
   define('SMTP_HOST', 'smtp.gmail.com');
   define('SMTP_PORT', 587);
   define('SMTP_USER', 'your-email@gmail.com');
   define('SMTP_PASS', 'your-app-password');
   define('SMTP_FROM', 'your-email@gmail.com');
   define('SMTP_ENCRYPTION', 'tls');
   ```
2. Test the email function:
   ```bash
   php -r "var_dump(mail('test@example.com', 'Test', 'Test body'));"
   ```
3. If using Gmail, you must use an App Password (not your regular password) and enable "Less secure app access" or use OAuth.
4. Check the cron alert checker is running (see issue 75).

---

### 77. Telegram bot not responding to alerts

**Symptom:** Telegram alerts are configured but messages are not delivered to the chat.

**Cause:** The Telegram bot token or chat ID is incorrect, or the bot has not been started by the user.

**Solution:**
1. Verify the bot token and chat ID in `config.php`:
   ```php
   define('TELEGRAM_BOT_TOKEN', 'your-bot-token');
   define('TELEGRAM_CHAT_ID', 'your-chat-id');
   ```
2. Test sending a message:
   ```bash
   curl -s "https://api.telegram.org/botYOUR_BOT_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text=Test+alert"
   ```
3. If you get `{"ok":false,"error_code":403}`, the user needs to start a conversation with the bot first by sending `/start` to the bot in Telegram.
4. To get your chat ID, send a message to the bot and then:
   ```bash
   curl -s "https://api.telegram.org/botYOUR_BOT_TOKEN/getUpdates" | python3 -m json.tool
   ```

---

### 78. Webhook delivery fails with timeout

**Symptom:** Alert log shows webhook delivery attempted but failed with a connection timeout.

**Cause:** The webhook endpoint is slow or unreachable from the server.

**Solution:**
1. Test the endpoint:
   ```bash
   curl -o /dev/null -s -w "%{http_code} %{time_total}s" -X POST https://your-webhook.com/endpoint
   ```
2. Ensure the webhook timeout is reasonable in `config.php`:
   ```php
   define('WEBHOOK_TIMEOUT', 10); // seconds
   ```
3. If the endpoint is behind a firewall, whitelist the server's IP.
4. Consider using an async delivery mechanism so webhook timeouts do not block other alerts.

---

### 79. Alert cooldown period too long

**Symptom:** An alert fires once but then does not fire again for hours, even though the condition continues to be met.

**Cause:** The alert cooldown prevents re-firing within the configured window.

**Solution:**
1. Check the cooldown setting in `config.php`:
   ```php
   define('ALERT_COOLDOWN', 3600); // seconds (1 hour)
   ```
2. Reduce it if needed:
   ```php
   define('ALERT_COOLDOWN', 900); // 15 minutes
   ```
3. Alternatively, individual alerts may have their own cooldown. Check:
   ```sql
   SELECT id, cooldown_seconds FROM alerts WHERE user_id = YOUR_USER_ID;
   ```

---

### 80. Duplicate alerts being sent

**Symptom:** The same alert message is sent multiple times within a short period.

**Cause:** The cron job `check_alerts.php` is running more frequently than the cooldown window, and the "last fired" timestamp is not being updated.

**Solution:**
1. Verify the cron schedule is not too aggressive:
   ```bash
   crontab -l | grep check_alerts
   ```
   A reasonable schedule is every 5 minutes:
   ```
   */5 * * * * /usr/bin/php /path/to/cron/check_alerts.php
   ```
2. Check that the alert's `last_fired_at` is being updated:
   ```sql
   SELECT id, last_fired_at FROM alerts WHERE id = ALERT_ID;
   ```
3. If `last_fired_at` is NULL or not updating, there may be a database write error. Check file permissions and database grants.

---

### 81. Alert conditions never trigger

**Symptom:** An alert is configured (e.g., "USD > 40 TRY") but it never fires even when the condition is clearly met.

**Cause:** The alert condition compares against a different rate type (buy vs. sell) or a different bank than expected.

**Solution:**
1. Check the alert configuration:
   ```sql
   SELECT * FROM alerts WHERE id = ALERT_ID;
   ```
2. Verify the current rate the alert is compared against:
   ```sql
   SELECT bank, currency, buying_rate, selling_rate FROM rates WHERE currency = 'USD' AND rate_date = CURDATE();
   ```
3. Make sure the alert specifies the correct `bank`, `currency`, and `rate_type` (buying or selling).
4. Run the alert checker manually and check the output:
   ```bash
   php cron/check_alerts.php 2>&1
   ```

---

### 82. Alert emails going to spam

**Symptom:** Alert emails are delivered but land in the spam/junk folder.

**Cause:** The sending domain lacks proper email authentication (SPF, DKIM, DMARC), or the email content triggers spam filters.

**Solution:**
1. Add SPF and DKIM records to your domain's DNS.
2. Use a reputable SMTP provider (e.g., Mailgun, SendGrid, Amazon SES) rather than sending from the server directly.
3. Ensure the `From` address matches the sending domain.
4. Avoid spam trigger words in the subject line. Use a clear subject like "Cybokron Alert: USD Rate Update" rather than "URGENT!!!".
5. Check the SMTP provider's deliverability dashboard for bounces and spam reports.

---

### 83. SMTP not configured error

**Symptom:** Alert log shows "SMTP not configured" or "Mail transport not available."

**Cause:** The SMTP constants are not defined in `config.php`.

**Solution:**
1. Open `config.php` and add or uncomment the SMTP configuration block:
   ```php
   define('SMTP_HOST', 'smtp.your-provider.com');
   define('SMTP_PORT', 587);
   define('SMTP_USER', 'your-username');
   define('SMTP_PASS', 'your-password');
   define('SMTP_FROM', 'alerts@your-domain.com');
   define('SMTP_FROM_NAME', 'Cybokron Alerts');
   define('SMTP_ENCRYPTION', 'tls');
   ```
2. If you do not want to use email alerts, you can disable the email channel and use only Telegram or webhooks.

---

## OpenRouter AI & Self-Healing

### 84. OpenRouter API key invalid

**Symptom:** Self-healing repair attempts fail with `401 Unauthorized` from the OpenRouter API.

**Cause:** The API key in `config.php` is missing, incorrect, or expired.

**Solution:**
1. Verify the key in `config.php`:
   ```php
   define('OPENROUTER_API_KEY', 'sk-or-v1-xxxxxxxxxxxx');
   ```
2. Test the key:
   ```bash
   curl -s -H "Authorization: Bearer sk-or-v1-xxxxxxxxxxxx" https://openrouter.ai/api/v1/models | head -20
   ```
3. If the key is expired, generate a new one at [openrouter.ai](https://openrouter.ai/) and update `config.php`.
4. Ensure you have sufficient credits on your OpenRouter account.

---

### 85. OpenRouter model not responding

**Symptom:** The self-healing request times out or returns an empty response from the AI model.

**Cause:** The specified model is overloaded, deprecated, or temporarily unavailable.

**Solution:**
1. Check which model is configured in `config.php`:
   ```php
   define('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet');
   ```
2. Try a different model:
   ```php
   define('OPENROUTER_MODEL', 'openai/gpt-4o');
   ```
3. Increase the timeout:
   ```php
   define('OPENROUTER_TIMEOUT', 120); // seconds
   ```
4. Check OpenRouter's status page for outages.

---

### 86. Repair generates wrong scraper configuration

**Symptom:** The AI-generated repair for a broken scraper uses incorrect CSS selectors or targets the wrong HTML elements.

**Cause:** The AI model hallucinated selectors based on its training data rather than the actual current page HTML.

**Solution:**
1. Check the generated repair in the `repairs/` directory:
   ```bash
   ls -lt repairs/
   ```
2. Review the latest repair file and compare the selectors against the actual bank page HTML.
3. Manually correct the selectors in the bank's scraper class file under `banks/`.
4. If the auto-repair consistently fails for a specific bank, disable self-healing for that bank and maintain it manually.

---

### 87. Repair cooldown preventing repeated fix attempts

**Symptom:** After a failed repair, the system refuses to attempt another repair, showing "Repair cooldown active."

**Cause:** The `ScraperAutoRepair` class enforces a cooldown period between repair attempts to prevent runaway API costs.

**Solution:**
1. Check the cooldown setting in `config.php`:
   ```php
   define('REPAIR_COOLDOWN', 3600); // 1 hour
   ```
2. To retry immediately, reduce the cooldown temporarily:
   ```php
   define('REPAIR_COOLDOWN', 60); // 1 minute
   ```
3. Alternatively, clear the cooldown state. Check where the cooldown timestamp is stored (typically in the database or a file) and reset it:
   ```bash
   rm -f /tmp/cybokron_repair_cooldown_*
   ```

---

### 88. GitHub commit fails during auto-repair

**Symptom:** The self-healing pipeline repairs the scraper locally but fails to commit the fix to GitHub.

**Cause:** Git credentials are not configured on the server, or the repository is not set up for push access.

**Solution:**
1. Verify Git is configured:
   ```bash
   git config user.name
   git config user.email
   ```
2. Check remote access:
   ```bash
   git remote -v
   ```
3. If using HTTPS, configure a personal access token:
   ```bash
   git remote set-url origin https://YOUR_TOKEN@github.com/your-org/your-repo.git
   ```
4. If using SSH, ensure the SSH key is added to the agent:
   ```bash
   ssh-add -l
   ssh -T git@github.com
   ```

---

### 89. Self-healing enters a repair loop

**Symptom:** The auto-repair keeps modifying the scraper, each attempt breaking it in a different way, consuming API credits rapidly.

**Cause:** The repair logic does not validate the fix before committing, or the bank page structure is too dynamic for reliable scraping.

**Solution:**
1. Check the repair log for repeated attempts:
   ```bash
   ls -la repairs/
   ```
2. Disable auto-repair for the problematic bank in `config.php`:
   ```php
   define('AUTO_REPAIR_DISABLED_BANKS', ['ProblematicBank']);
   ```
3. Set a maximum repair attempt limit:
   ```php
   define('MAX_REPAIR_ATTEMPTS', 3);
   ```
4. Manually fix the scraper and increase the cooldown to prevent the auto-repair from overwriting your fix.

---

### 90. SSE stream disconnects during repair

**Symptom:** The browser shows the repair progress via Server-Sent Events, but the connection drops mid-repair.

**Cause:** The web server or a reverse proxy is closing long-lived connections due to timeout settings.

**Solution:**
1. In `api_repair_stream.php`, ensure proper SSE headers are set:
   ```php
   header('Content-Type: text/event-stream');
   header('Cache-Control: no-cache');
   header('X-Accel-Buffering: no'); // for Nginx
   ```
2. In Nginx, increase proxy timeouts:
   ```nginx
   proxy_read_timeout 300;
   proxy_send_timeout 300;
   proxy_buffering off;
   ```
3. In Apache, ensure `mod_proxy` is not buffering:
   ```apache
   SetEnv proxy-nokeepalive 1
   ```
4. Increase PHP's `max_execution_time` for the SSE script:
   ```php
   set_time_limit(300);
   ```

---

## UI & Frontend

### 91. Dark mode colors are wrong or unreadable

**Symptom:** Switching to dark mode makes text invisible (e.g., dark text on a dark background).

**Cause:** Some CSS variables or component styles do not have dark mode overrides.

**Solution:**
1. Inspect the problematic element in the browser dev tools and check which CSS variable it uses.
2. Open the stylesheet (usually `assets/css/style.css` or similar) and look for the `[data-theme="dark"]` or `@media (prefers-color-scheme: dark)` section.
3. Add the missing variable override. For example:
   ```css
   [data-theme="dark"] {
       --text-primary: #e0e0e0;
       --bg-primary: #1a1a2e;
       --card-bg: #16213e;
   }
   ```
4. If using a CSS framework, check that the dark mode class is toggled on the `<html>` or `<body>` element.

---

### 92. Chart not rendering on the rate history page

**Symptom:** The chart area is blank. The browser console shows `Chart is not defined` or `Canvas is already in use`.

**Cause:** The Chart.js library failed to load, or the canvas element is being initialized twice.

**Solution:**
1. Check the browser console for 404 errors on the Chart.js script.
2. Verify the Chart.js file exists:
   ```bash
   ls assets/js/chart*.js
   ```
3. If the error is "Canvas is already in use," destroy the previous chart instance before creating a new one:
   ```javascript
   if (window.rateChart) {
       window.rateChart.destroy();
   }
   window.rateChart = new Chart(ctx, config);
   ```
4. If Chart.js is loaded from a CDN, ensure the CDN is not blocked by a content security policy or ad blocker.

---

### 93. Service worker caching old version of the app

**Symptom:** After updating the application, the browser still shows the old UI and old JavaScript.

**Cause:** The PWA service worker (`sw.js`) has cached the old assets and is serving them from cache.

**Solution:**
1. Update the cache version in `sw.js`:
   ```javascript
   const CACHE_NAME = 'cybokron-v2'; // increment the version
   ```
2. Instruct users to hard-refresh: `Ctrl+Shift+R` (or `Cmd+Shift+R` on macOS).
3. Users can also clear the service worker from the browser:
   - Chrome: `chrome://serviceworker-internals/`
   - Firefox: `about:serviceworkers`
4. In the service worker, implement a cache-busting strategy by deleting old caches in the `activate` event:
   ```javascript
   self.addEventListener('activate', event => {
       event.waitUntil(
           caches.keys().then(keys =>
               Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
           )
       );
   });
   ```

---

### 94. Mobile layout broken or elements overlapping

**Symptom:** On mobile devices, the sidebar overlaps the main content or buttons are cut off.

**Cause:** CSS media queries are missing or the viewport meta tag is not set.

**Solution:**
1. Ensure the viewport meta tag is in the HTML `<head>`:
   ```html
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   ```
2. Check CSS media queries for mobile breakpoints:
   ```css
   @media (max-width: 768px) {
       .sidebar { display: none; }
       .main-content { margin-left: 0; }
   }
   ```
3. Test using the browser's responsive design mode (F12 > Toggle Device Toolbar).
4. If the sidebar uses a hamburger menu on mobile, ensure the JavaScript toggle function is working.

---

### 95. Language not switching

**Symptom:** Clicking a language switcher (e.g., English, Turkish) does not change the displayed language.

**Cause:** The locale cookie or session variable is not being set, or the locale files are missing.

**Solution:**
1. Check that locale files exist:
   ```bash
   ls locales/
   ```
   You should see files like `tr.php`, `en.php`, `ar.php`, `de.php`, `fr.php`.
2. Verify the language switcher sets the correct session or cookie value:
   ```php
   $_SESSION['locale'] = 'en'; // or 'tr', 'ar', etc.
   ```
3. Check that the application reads the locale before loading strings:
   ```php
   $locale = $_SESSION['locale'] ?? 'en';
   $strings = require "locales/{$locale}.php";
   ```
4. Clear the session and cookies, then try switching again.

---

## Docker

### 96. Docker container will not start

**Symptom:** `docker-compose up` fails, or the container exits immediately with a non-zero code.

**Cause:** Missing environment variables, port conflicts, or the Dockerfile has a build error.

**Solution:**
1. Check the logs:
   ```bash
   docker-compose logs --tail=50
   ```
2. Verify the `.env` file or `docker-compose.yml` environment section has all required variables:
   ```yaml
   environment:
     DB_HOST: db
     DB_NAME: cybokron_exchange
     DB_USER: root
     DB_PASS: rootpassword
   ```
3. Ensure `config.docker.php` is being used inside the container instead of `config.php`:
   ```bash
   docker-compose exec app ls -la config*.php
   ```
4. If the container crashes on startup, try building fresh:
   ```bash
   docker-compose down -v
   docker-compose build --no-cache
   docker-compose up -d
   ```

---

### 97. Database data not persisting after container restart

**Symptom:** After running `docker-compose down` and `docker-compose up`, the database is empty.

**Cause:** The MySQL data directory is not mounted as a Docker volume.

**Solution:**
1. Check `docker-compose.yml` for a volume definition:
   ```yaml
   services:
     db:
       image: mysql:8.0
       volumes:
         - db_data:/var/lib/mysql
   volumes:
     db_data:
   ```
2. If the `volumes` section is missing, add it as shown above.
3. Use `docker-compose down` (without `-v`) to preserve volumes. The `-v` flag deletes volumes:
   ```bash
   docker-compose down    # keeps volumes
   docker-compose down -v # DELETES volumes and all data
   ```

---

### 98. Docker port conflict

**Symptom:** `docker-compose up` fails with `Bind for 0.0.0.0:8080: address already in use`.

**Cause:** Another process on the host is using port 8080 (or whatever port is configured).

**Solution:**
1. Find what is using the port:
   ```bash
   lsof -i :8080
   ```
2. Either stop the conflicting process or change the port mapping in `docker-compose.yml`:
   ```yaml
   services:
     app:
       ports:
         - "8888:80"  # map host port 8888 to container port 80
   ```
3. Restart:
   ```bash
   docker-compose down
   docker-compose up -d
   ```

---

## CI/CD & Deployment

### 99. GitHub Actions deploy workflow fails

**Symptom:** The `quality-test-deploy.yml` or `deploy.yml` workflow fails with an error during the deploy step.

**Cause:** Common causes include expired SSH keys, incorrect server credentials stored in GitHub Secrets, or the target server being unreachable.

**Solution:**
1. Check the GitHub Actions log for the specific error.
2. Verify GitHub Secrets are set correctly in the repository settings under **Settings > Secrets and variables > Actions**:
   - `DEPLOY_HOST` -- the server hostname or IP
   - `DEPLOY_USER` -- the SSH username
   - `DEPLOY_KEY` -- the private SSH key
   - `DEPLOY_PATH` -- the target directory on the server
3. Test SSH access manually:
   ```bash
   ssh -i /path/to/key deploy_user@your-server "echo ok"
   ```
4. If the SSH key has a passphrase, remove it or use `ssh-agent` in the workflow.
5. Ensure the deploy path exists and is writable by the deploy user:
   ```bash
   ssh deploy_user@your-server "ls -la /var/www/cybokron-exchange"
   ```
6. If the workflow uses `rsync`, ensure rsync is installed on both the runner and the server.

---

### 100. Rollback workflow not working

**Symptom:** Running the `rollback.yml` workflow does not revert the application to the previous version.

**Cause:** The rollback mechanism cannot find a previous release, or the symlink structure is broken.

**Solution:**
1. Check the releases directory on the server:
   ```bash
   ssh deploy_user@your-server "ls -la /var/www/cybokron-exchange/releases/"
   ```
2. Verify the `current` symlink points to the active release:
   ```bash
   ssh deploy_user@your-server "readlink -f /var/www/cybokron-exchange/current"
   ```
3. Manually rollback by updating the symlink:
   ```bash
   ssh deploy_user@your-server "ln -sfn /var/www/cybokron-exchange/releases/PREVIOUS_RELEASE /var/www/cybokron-exchange/current"
   ```
4. Restart PHP-FPM to clear opcache:
   ```bash
   ssh deploy_user@your-server "sudo systemctl restart php8.3-fpm"
   ```
5. If using database migrations, note that rollback does not automatically revert schema changes. You may need to restore a database backup taken before the failed deploy (see issue 24).

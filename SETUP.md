# Cybokron Exchange Rate & Portfolio Tracking -- Setup Guide

Comprehensive installation instructions for deploying the application on
**cPanel shared hosting** or a **VPS / dedicated server**.

GitHub repository: <https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking>

---

## Table of Contents

- [Prerequisites](#prerequisites)
- [Option A: cPanel Shared Hosting](#option-a-cpanel-shared-hosting)
  - [A1. Upload Files](#a1-upload-files)
  - [A2. Create MySQL Database](#a2-create-mysql-database)
  - [A3. Import Schema](#a3-import-schema)
  - [A4. Configure Application](#a4-configure-application)
  - [A5. Set File Permissions](#a5-set-file-permissions)
  - [A6. Configure Cron Jobs](#a6-configure-cron-jobs)
  - [A7. SSL / HTTPS](#a7-ssl--https)
  - [A8. Cloudflare Turnstile CAPTCHA (Optional)](#a8-cloudflare-turnstile-captcha-optional)
  - [A9. Email Configuration for Alerts](#a9-email-configuration-for-alerts)
  - [A10. Verify Installation](#a10-verify-installation)
- [Option B: VPS / Dedicated Server](#option-b-vps--dedicated-server)
  - [B1. Server Preparation](#b1-server-preparation)
  - [B2. Install PHP 8.3+ with Required Extensions](#b2-install-php-83-with-required-extensions)
  - [B3. Install MySQL / MariaDB](#b3-install-mysql--mariadb)
  - [B4. Install Nginx (or Apache)](#b4-install-nginx-or-apache)
  - [B5. Clone Repository](#b5-clone-repository)
  - [B6. Create Database and Import Schema](#b6-create-database-and-import-schema)
  - [B7. Configure Application](#b7-configure-application)
  - [B8. Nginx Virtual Host Configuration](#b8-nginx-virtual-host-configuration)
  - [B9. Apache Virtual Host Configuration (Alternative)](#b9-apache-virtual-host-configuration-alternative)
  - [B10. Set File Permissions and Ownership](#b10-set-file-permissions-and-ownership)
  - [B11. Configure Cron Jobs](#b11-configure-cron-jobs)
  - [B12. SSL with Let's Encrypt](#b12-ssl-with-lets-encrypt)
  - [B13. Firewall Configuration](#b13-firewall-configuration)
  - [B14. Verify Installation](#b14-verify-installation)
- [Docker Deployment](#docker-deployment)
- [Post-Installation](#post-installation)
  - [Change Default Admin Password](#change-default-admin-password)
  - [Enable Cloudflare Turnstile](#enable-cloudflare-turnstile)
  - [Configure OpenRouter AI](#configure-openrouter-ai)
  - [Configure Alerts (Email, Telegram, Webhook)](#configure-alerts-email-telegram-webhook)
  - [Configure Self-Healing](#configure-self-healing)
  - [Enable GitHub Self-Update](#enable-github-self-update)
- [Upgrading](#upgrading)
  - [Manual Upgrade](#manual-upgrade)
  - [Docker Upgrade](#docker-upgrade)

---

## Prerequisites

| Requirement | Minimum Version |
|---|---|
| PHP | 8.3 or 8.4 |
| MySQL | 5.7+ |
| MariaDB (alternative) | 10.3+ |
| PHP extensions | `curl`, `dom`, `mbstring`, `json`, `pdo_mysql`, `zip` |
| Git | Any recent version (or manual ZIP upload) |
| Cron access | Required for rate updates and alerts |

Make sure you have access to a terminal (SSH or cPanel Terminal) and the
credentials for your MySQL server before continuing.

---

## Option A: cPanel Shared Hosting

### A1. Upload Files

You have two methods to get the project files onto your hosting account.

#### Method 1 -- Git via cPanel Terminal

Most modern cPanel hosts provide Terminal access (sometimes labeled
"Terminal" or "SSH Access" in the cPanel dashboard).

```bash
# Open cPanel > Terminal
cd ~/public_html

# Clone the repository
git clone https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking.git

# If you want the app at a subdirectory like /exchange:
# git clone https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking.git exchange
```

If your host does not allow Git, proceed to Method 2.

#### Method 2 -- ZIP Upload via File Manager

1. Download the latest release ZIP from GitHub:
   <https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/archive/refs/heads/main.zip>

2. In cPanel, open **File Manager**.

3. Navigate to `public_html` (or your desired subdirectory).

4. Click **Upload** and select the downloaded ZIP file.

5. Once uploaded, right-click the ZIP file and select **Extract**.

6. Rename the extracted folder from
   `cybokron-exchange-rate-and-portfolio-tracking-main` to your preferred
   directory name (for example, `exchange` or `cybokron`).

7. Delete the ZIP file from the server to save disk space.

---

### A2. Create MySQL Database

1. In cPanel, navigate to **MySQL Databases** (under the "Databases" section).

2. Under **Create New Database**, enter a database name (for example,
   `cybokron`) and click **Create Database**.
   > cPanel will prefix it with your username, for example `youruser_cybokron`.

3. Under **MySQL Users > Add New User**, create a new user and set a
   strong password. Note these credentials -- you will need them for
   `config.php`.

4. Under **Add User to Database**, select the user and database you just
   created, then click **Add**.

5. On the privileges screen, check **ALL PRIVILEGES** and click
   **Make Changes**.

---

### A3. Import Schema

#### Via cPanel Terminal

```bash
cd ~/public_html/cybokron-exchange-rate-and-portfolio-tracking

# Import the full schema (replace values with your actual credentials)
mysql -u youruser_cybokron -p youruser_cybokron < database/database.sql
```

Enter the password when prompted.

#### Via phpMyAdmin

1. In cPanel, open **phpMyAdmin**.
2. Select your database in the left sidebar.
3. Click the **Import** tab.
4. Click **Choose File** and select `database/database.sql` from your
   local copy of the project.
5. Click **Go** to execute the import.

#### Upgrading an Existing Database

If you are upgrading from a previous version, run the migrator instead of
re-importing the full schema:

```bash
cd ~/public_html/cybokron-exchange-rate-and-portfolio-tracking
php database/migrator.php
```

You can check migration status before running:

```bash
php database/migrator.php --status
php database/migrator.php --dry-run
```

---

### A4. Configure Application

Copy the sample configuration file and edit it:

```bash
cd ~/public_html/cybokron-exchange-rate-and-portfolio-tracking
cp config.sample.php config.php
```

Open `config.php` in the cPanel File Manager editor (or via your preferred
text editor) and update each section as described below.

#### Database Settings

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'youruser_cybokron');       // Your cPanel-prefixed DB name
define('DB_USER', 'youruser_cybokron');       // Your cPanel-prefixed DB user
define('DB_PASS', 'your_strong_password');    // The password you set in A2
define('DB_CHARSET', 'utf8mb4');
```

On most shared hosts, `DB_HOST` remains `localhost`. Some hosts use
`127.0.0.1` or a remote hostname -- check your cPanel MySQL information
page if connections fail.

#### Application Settings

```php
define('APP_URL', 'https://yourdomain.com/cybokron');  // Full URL, no trailing slash
define('APP_TIMEZONE', 'Europe/Istanbul');              // Your timezone
define('APP_DEBUG', false);                             // Keep false in production
define('ENABLE_SECURITY_HEADERS', true);                // Recommended: true
define('CSP_POLICY', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:");
```

Set `APP_URL` to the exact URL visitors will use to access the app.
Include `https://` if you have SSL configured (see A7).

#### Locale Settings

```php
define('DEFAULT_LOCALE', 'en');                              // Default display language
define('FALLBACK_LOCALE', 'en');                             // Fallback if a key is missing
define('AVAILABLE_LOCALES', ['tr', 'en', 'ar', 'de', 'fr']);  // Languages you want available
```

#### Authentication

```php
define('AUTH_REQUIRE_PORTFOLIO', true);          // Protect portfolio pages with login
define('AUTH_BASIC_USER', 'admin');              // Admin username
define('AUTH_BASIC_PASSWORD_HASH', '');          // Set after installation -- see Post-Installation
define('LOGIN_RATE_LIMIT', 5);                  // Max failed login attempts per window
define('LOGIN_RATE_WINDOW_SECONDS', 300);       // Rate limit window (5 minutes)
```

Generate your password hash:

```bash
php -r "echo password_hash('YourSecurePassword', PASSWORD_DEFAULT), PHP_EOL;"
```

Copy the output and paste it as the value of `AUTH_BASIC_PASSWORD_HASH`.

#### Cloudflare Turnstile CAPTCHA (Optional)

```php
define('TURNSTILE_ENABLED', false);        // Set true after obtaining keys
define('TURNSTILE_SITE_KEY', '');          // From Cloudflare dashboard
define('TURNSTILE_SECRET_KEY', '');        // From Cloudflare dashboard
```

See [A8. Cloudflare Turnstile CAPTCHA](#a8-cloudflare-turnstile-captcha-optional)
for setup instructions.

#### Active Banks

```php
$ACTIVE_BANKS = [
    'DunyaKatilim',
    'TCMB',
    'IsBank',
    // 'GarantiBBVA',
    // 'Ziraat',
];
```

Uncomment additional banks as needed. Each entry corresponds to a scraper
class in the `banks/` directory.

#### OpenRouter AI Repair (Optional)

```php
define('OPENROUTER_AI_REPAIR_ENABLED', false);   // Enable AI fallback for broken scrapers
define('OPENROUTER_API_KEY', '');                 // Your API key from https://openrouter.ai
define('OPENROUTER_MODEL', 'z-ai/glm-5');        // Model to use
```

#### Self-Healing and GitHub Integration (Optional)

```php
define('SELF_HEALING_ENABLED', false);       // AI-powered auto-repair when scrapers break
define('SELF_HEALING_COOLDOWN_SECONDS', 3600);
define('GITHUB_API_TOKEN', '');              // GitHub PAT with repo scope (for auto-commit)
```

#### API Security

```php
define('API_ALLOW_CORS', false);       // Enable only if cross-origin API access is needed
define('API_REQUIRE_CSRF', true);      // Keep true for form-based state changes
```

#### Alert Notifications

```php
define('RATE_UPDATE_WEBHOOK_URL', '');             // Slack, Discord, or Zapier webhook URL
define('ALERT_EMAIL_FROM', 'noreply@yourdomain.com');
define('ALERT_TELEGRAM_BOT_TOKEN', '');            // Telegram bot token (from @BotFather)
define('ALERT_TELEGRAM_CHAT_ID', '');              // Target Telegram chat/channel ID
```

#### Cron Security

```php
define('ENFORCE_CLI_CRON', true);   // Block web-based cron execution (recommended)
```

When set to `true`, cron scripts will only run from the command line. This
prevents anyone from triggering cron jobs by visiting their URL in a browser.

#### Logging

```php
define('LOG_ENABLED', true);
define('LOG_FILE', dirname(__DIR__) . '/cybokron-logs/cybokron.log');
```

Make sure the log directory exists and is writable:

```bash
mkdir -p ~/cybokron-logs
chmod 755 ~/cybokron-logs
```

> **Important:** The default `LOG_FILE` path places logs one directory
> above the application root, which keeps them outside of `public_html`.
> Adjust the path if your directory structure differs.

---

### A5. Set File Permissions

```bash
cd ~/public_html/cybokron-exchange-rate-and-portfolio-tracking

# Directories: 755
find . -type d -exec chmod 755 {} \;

# Files: 644
find . -type f -exec chmod 644 {} \;

# config.php contains credentials -- restrict access
chmod 600 config.php

# Log directory (outside web root)
mkdir -p ~/cybokron-logs
chmod 755 ~/cybokron-logs
```

> **Note:** On shared hosting, file ownership is typically handled by the
> server. If you encounter permission errors, contact your hosting provider.

---

### A6. Configure Cron Jobs

In cPanel, navigate to **Cron Jobs** (under the "Advanced" section).

Set the email address for cron output notifications (optional but useful
for debugging).

Add the following cron jobs. Replace `/home/youruser/public_html/cybokron-exchange-rate-and-portfolio-tracking`
with your actual installation path.

#### Update Exchange Rates -- Every 15 Minutes (Mon-Fri, 09:00-18:00)

- **Schedule:** Common Settings > select "Every 15 Minutes", then manually
  adjust the fields to:
  - Minute: `*/15`
  - Hour: `9-18`
  - Day: `*`
  - Month: `*`
  - Weekday: `1-5`

- **Command:**
  ```
  /usr/local/bin/php /home/youruser/public_html/cybokron-exchange-rate-and-portfolio-tracking/cron/update_rates.php >> /home/youruser/cybokron-logs/cron.log 2>&1
  ```

Full crontab expression:
```
*/15 9-18 * * 1-5
```

#### Check Alerts -- Every 15 Minutes (After Rate Updates)

- **Schedule:** Same as above but offset by 2 minutes:
  - Minute: `2-59/15`
  - Hour: `9-18`
  - Day: `*`
  - Month: `*`
  - Weekday: `1-5`

- **Command:**
  ```
  /usr/local/bin/php /home/youruser/public_html/cybokron-exchange-rate-and-portfolio-tracking/cron/check_alerts.php >> /home/youruser/cybokron-logs/cron.log 2>&1
  ```

Full crontab expression:
```
2-59/15 9-18 * * 1-5
```

#### Cleanup Old Rate History -- Weekly (Sunday at 03:00)

- **Schedule:**
  - Minute: `0`
  - Hour: `3`
  - Day: `*`
  - Month: `*`
  - Weekday: `0`

- **Command:**
  ```
  /usr/local/bin/php /home/youruser/public_html/cybokron-exchange-rate-and-portfolio-tracking/cron/cleanup_rate_history.php >> /home/youruser/cybokron-logs/cron.log 2>&1
  ```

Full crontab expression:
```
0 3 * * 0
```

#### Self-Update Check -- Daily at Midnight (Optional)

- **Schedule:**
  - Minute: `0`
  - Hour: `0`
  - Day: `*`
  - Month: `*`
  - Weekday: `*`

- **Command:**
  ```
  /usr/local/bin/php /home/youruser/public_html/cybokron-exchange-rate-and-portfolio-tracking/cron/self_update.php >> /home/youruser/cybokron-logs/cron.log 2>&1
  ```

Full crontab expression:
```
0 0 * * *
```

> **Tip:** On some shared hosts, the PHP binary path may differ. Common
> paths include `/usr/bin/php`, `/usr/local/bin/php`, or
> `/opt/cpanel/ea-php83/root/usr/bin/php`. Run `which php` in the cPanel
> Terminal to find yours. Use the path corresponding to PHP 8.3+.

---

### A7. SSL / HTTPS

#### Free SSL via cPanel (AutoSSL / Let's Encrypt)

1. In cPanel, navigate to **SSL/TLS Status** or **SSL/TLS**.
2. If AutoSSL is available, click **Run AutoSSL** to provision a free
   certificate for your domain.
3. Most cPanel hosts automatically renew AutoSSL certificates.

#### Free SSL via Cloudflare

1. Sign up at [cloudflare.com](https://www.cloudflare.com) and add your
   domain.
2. Update your domain's nameservers to Cloudflare's (provided during
   setup).
3. In the Cloudflare dashboard, go to **SSL/TLS** and set the mode to
   **Full (strict)** if your server has its own certificate, or **Full** if
   using a self-signed certificate.
4. Enable **Always Use HTTPS** under **SSL/TLS > Edge Certificates**.

After enabling SSL, update `APP_URL` in `config.php` to use `https://`.

---

### A8. Cloudflare Turnstile CAPTCHA (Optional)

Cloudflare Turnstile adds bot protection to the login page without
annoying CAPTCHAs.

1. Log in to [dash.cloudflare.com](https://dash.cloudflare.com).
2. Go to **Turnstile** in the left sidebar.
3. Click **Add Site**.
4. Enter your domain and select the widget type (Managed is recommended).
5. Copy the **Site Key** and **Secret Key**.
6. Update `config.php`:

```php
define('TURNSTILE_ENABLED', true);
define('TURNSTILE_SITE_KEY', '0x4AAAAAAA...');
define('TURNSTILE_SECRET_KEY', '0x4AAAAAAA...');
```

---

### A9. Email Configuration for Alerts

The application uses PHP's built-in `mail()` function for sending alert
emails. On most shared hosts, this works out of the box.

1. Set the sender address in `config.php`:

```php
define('ALERT_EMAIL_FROM', 'noreply@yourdomain.com');
define('ALERT_EMAIL_TO', 'you@example.com');
```

2. Ensure the `ALERT_EMAIL_FROM` address uses your actual domain so that
   emails are not rejected by spam filters.

3. If your host requires authenticated SMTP (many do for better
   deliverability), you may need to configure a mail account in cPanel
   under **Email Accounts** and use it as the `ALERT_EMAIL_FROM` address.

4. For improved deliverability, set up SPF and DKIM records in your
   domain's DNS. cPanel provides these under **Email Deliverability**.

---

### A10. Verify Installation

1. Open your browser and navigate to your `APP_URL` (for example,
   `https://yourdomain.com/cybokron`).

2. You should see the exchange rate dashboard. If rates are not yet
   populated, they will appear after the first cron run.

3. Manually trigger a rate update to verify everything works:

```bash
cd ~/public_html/cybokron-exchange-rate-and-portfolio-tracking
php cron/update_rates.php
```

4. Refresh the browser -- rates should now appear.

5. Navigate to the portfolio page and verify the login prompt appears (if
   `AUTH_REQUIRE_PORTFOLIO` is `true`).

6. Check the log file for any errors:

```bash
cat ~/cybokron-logs/cybokron.log
```

If you see database connection errors, double-check your `DB_HOST`,
`DB_NAME`, `DB_USER`, and `DB_PASS` values in `config.php`.

---

## Option B: VPS / Dedicated Server

These instructions target **Ubuntu 22.04 / 24.04** and **Debian 12**.
CentOS / RHEL / AlmaLinux equivalents are noted where commands differ.

### B1. Server Preparation

```bash
# Update package lists and upgrade existing packages
sudo apt update && sudo apt upgrade -y

# Install essential tools
sudo apt install -y curl wget git unzip software-properties-common
```

**CentOS / RHEL / AlmaLinux:**

```bash
sudo dnf update -y
sudo dnf install -y curl wget git unzip epel-release
```

---

### B2. Install PHP 8.3+ with Required Extensions

#### Ubuntu / Debian

```bash
# Add the Ondrej PHP repository (provides PHP 8.3 and 8.4)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP 8.3 and required extensions
sudo apt install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-curl \
    php8.3-dom \
    php8.3-mbstring \
    php8.3-mysql \
    php8.3-zip \
    php8.3-xml

# Verify installation
php -v
php -m | grep -E 'curl|dom|mbstring|json|pdo_mysql|zip'
```

> **Note:** The `json` extension is built into PHP 8.x and does not need a
> separate package.

To install PHP 8.4 instead, replace `php8.3` with `php8.4` in all
commands above.

#### CentOS / RHEL / AlmaLinux

```bash
# Add the Remi repository
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E %rhel).rpm
sudo dnf module enable php:remi-8.3 -y

# Install PHP and extensions
sudo dnf install -y \
    php-fpm \
    php-cli \
    php-curl \
    php-dom \
    php-mbstring \
    php-mysqlnd \
    php-zip \
    php-xml

php -v
```

---

### B3. Install MySQL / MariaDB

Choose **one** of the following.

#### MySQL 8.x

```bash
# Ubuntu / Debian
sudo apt install -y mysql-server

# Start and enable
sudo systemctl start mysql
sudo systemctl enable mysql

# Secure the installation
sudo mysql_secure_installation
```

**CentOS / RHEL:**

```bash
sudo dnf install -y mysql-server
sudo systemctl start mysqld
sudo systemctl enable mysqld
sudo mysql_secure_installation
```

#### MariaDB 10.6+ (Alternative)

```bash
# Ubuntu / Debian
sudo apt install -y mariadb-server

sudo systemctl start mariadb
sudo systemctl enable mariadb
sudo mysql_secure_installation
```

**CentOS / RHEL:**

```bash
sudo dnf install -y mariadb-server
sudo systemctl start mariadb
sudo systemctl enable mariadb
sudo mysql_secure_installation
```

---

### B4. Install Nginx (or Apache)

#### Nginx (Recommended)

```bash
# Ubuntu / Debian
sudo apt install -y nginx

sudo systemctl start nginx
sudo systemctl enable nginx
```

**CentOS / RHEL:**

```bash
sudo dnf install -y nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

#### Apache (Alternative)

```bash
# Ubuntu / Debian
sudo apt install -y apache2 libapache2-mod-php8.3

# Enable required modules
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

**CentOS / RHEL:**

```bash
sudo dnf install -y httpd php-fpm
sudo systemctl start httpd
sudo systemctl enable httpd
```

---

### B5. Clone Repository

```bash
# Create the web directory
sudo mkdir -p /var/www/cybokron
cd /var/www

# Clone the repository
sudo git clone https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking.git cybokron

# Set ownership
sudo chown -R www-data:www-data /var/www/cybokron
```

**CentOS / RHEL** -- replace `www-data` with `nginx` (for Nginx) or
`apache` (for Apache):

```bash
sudo chown -R nginx:nginx /var/www/cybokron
```

---

### B6. Create Database and Import Schema

```bash
# Log in to MySQL as root
sudo mysql -u root -p
```

Run the following SQL commands:

```sql
CREATE DATABASE cybokron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'cybokron_app'@'localhost' IDENTIFIED BY 'your_strong_password_here';

GRANT ALL PRIVILEGES ON cybokron.* TO 'cybokron_app'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

Import the schema:

```bash
sudo mysql -u cybokron_app -p cybokron < /var/www/cybokron/database/database.sql
```

#### Upgrading an Existing Database

If upgrading from a previous version:

```bash
cd /var/www/cybokron

# Check migration status first
php database/migrator.php --status

# Preview what will run
php database/migrator.php --dry-run

# Apply pending migrations
php database/migrator.php
```

Migration files are located in `database/migrations/` and follow the naming
convention `NNN_description.sql`. The migrator tracks applied migrations in
a `schema_migrations` table with checksum verification.

---

### B7. Configure Application

```bash
cd /var/www/cybokron

# Copy the sample config
sudo cp config.sample.php config.php

# Edit with your preferred editor
sudo nano config.php
```

Update all values as described in [A4. Configure Application](#a4-configure-application).
Key values to set:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'cybokron');
define('DB_USER', 'cybokron_app');
define('DB_PASS', 'your_strong_password_here');
define('DB_CHARSET', 'utf8mb4');

// Application
define('APP_URL', 'https://exchange.yourdomain.com');  // No trailing slash
define('APP_TIMEZONE', 'Europe/Istanbul');
define('APP_DEBUG', false);
define('ENABLE_SECURITY_HEADERS', true);

// Locale
define('DEFAULT_LOCALE', 'en');
define('FALLBACK_LOCALE', 'en');
define('AVAILABLE_LOCALES', ['tr', 'en', 'ar', 'de', 'fr']);

// Authentication
define('AUTH_REQUIRE_PORTFOLIO', true);
define('AUTH_BASIC_USER', 'admin');
define('AUTH_BASIC_PASSWORD_HASH', '');  // Generate below
define('LOGIN_RATE_LIMIT', 5);
define('LOGIN_RATE_WINDOW_SECONDS', 300);

// Cron security
define('ENFORCE_CLI_CRON', true);

// Logging
define('LOG_ENABLED', true);
define('LOG_FILE', '/var/log/cybokron/cybokron.log');
```

Generate the admin password hash:

```bash
php -r "echo password_hash('YourSecurePassword', PASSWORD_DEFAULT), PHP_EOL;"
```

Create the log directory:

```bash
sudo mkdir -p /var/log/cybokron
sudo chown www-data:www-data /var/log/cybokron
```

Restrict config file permissions:

```bash
sudo chmod 640 /var/www/cybokron/config.php
sudo chown www-data:www-data /var/www/cybokron/config.php
```

---

### B8. Nginx Virtual Host Configuration

Create a new virtual host configuration file:

```bash
sudo nano /etc/nginx/sites-available/cybokron
```

Paste the following configuration:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name exchange.yourdomain.com;

    root /var/www/cybokron;
    index index.php index.html;

    charset utf-8;

    # Security: deny access to sensitive files
    location ~ /\.(git|env|admin_password|admin_hash) {
        deny all;
        return 404;
    }

    location ~ /(config\.php|config\.sample\.php|config\.docker\.php|VERSION|CHANGELOG|LICENSE|README|SETUP) {
        deny all;
        return 404;
    }

    location ~ ^/(database|cron|includes|tests|repairs|scripts|node_modules)/ {
        deny all;
        return 404;
    }

    location ~ /(docker-compose\.yml|Dockerfile|package\.json|package-lock\.json|playwright\.config\.js) {
        deny all;
        return 404;
    }

    # Static assets caching
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP processing via PHP-FPM
    location ~ \.php$ {
        # Prevent execution of PHP in upload directories
        location ~ ^/(database|cron|includes|tests|repairs|scripts)/ {
            deny all;
            return 404;
        }

        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Timeouts for long-running scraping requests
        fastcgi_read_timeout 120;
    }

    # Deny access to .htaccess (if present)
    location ~ /\.ht {
        deny all;
    }

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 256;

    # Request size limit
    client_max_body_size 10M;

    # Logging
    access_log /var/log/nginx/cybokron_access.log;
    error_log  /var/log/nginx/cybokron_error.log;
}
```

Enable the site and test the configuration:

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/cybokron /etc/nginx/sites-enabled/

# Remove default site if not needed
# sudo rm /etc/nginx/sites-enabled/default

# Test configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

**CentOS / RHEL:** Place the config file in `/etc/nginx/conf.d/cybokron.conf`
instead of using `sites-available`/`sites-enabled`. Adjust the PHP-FPM
socket path to `/run/php-fpm/www.sock`.

---

### B9. Apache Virtual Host Configuration (Alternative)

If you chose Apache in B4:

```bash
sudo nano /etc/apache2/sites-available/cybokron.conf
```

Paste the following:

```apache
<VirtualHost *:80>
    ServerName exchange.yourdomain.com
    DocumentRoot /var/www/cybokron

    <Directory /var/www/cybokron>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny access to sensitive directories
    <DirectoryMatch "^/var/www/cybokron/(database|cron|includes|tests|repairs|scripts|node_modules)">
        Require all denied
    </DirectoryMatch>

    # Deny access to sensitive files
    <FilesMatch "\.(sample\.php|docker\.php|yml|json|lock|md|sql)$">
        Require all denied
    </FilesMatch>

    <FilesMatch "^(config\.php|\.env|\.git|Dockerfile|VERSION)">
        Require all denied
    </FilesMatch>

    # PHP-FPM proxy (if using php-fpm instead of mod_php)
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Compression
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml
    </IfModule>

    # Caching for static assets
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 30 days"
        ExpiresByType application/javascript "access plus 30 days"
        ExpiresByType image/png "access plus 30 days"
        ExpiresByType image/svg+xml "access plus 30 days"
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/cybokron_error.log
    CustomLog ${APACHE_LOG_DIR}/cybokron_access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite cybokron.conf
sudo a2enmod rewrite headers expires deflate proxy_fcgi
sudo systemctl reload apache2
```

**CentOS / RHEL:** Place the config in `/etc/httpd/conf.d/cybokron.conf`
and restart with `sudo systemctl restart httpd`.

---

### B10. Set File Permissions and Ownership

```bash
cd /var/www/cybokron

# Set ownership (www-data for Ubuntu/Debian, nginx or apache for RHEL)
sudo chown -R www-data:www-data /var/www/cybokron

# Directories: 755
sudo find /var/www/cybokron -type d -exec chmod 755 {} \;

# Files: 644
sudo find /var/www/cybokron -type f -exec chmod 644 {} \;

# config.php: restricted (readable by web server only)
sudo chmod 640 /var/www/cybokron/config.php

# Log directory
sudo mkdir -p /var/log/cybokron
sudo chown www-data:www-data /var/log/cybokron
sudo chmod 755 /var/log/cybokron

# Backup directory (used by self-update)
sudo mkdir -p /var/www/cybokron-backups
sudo chown www-data:www-data /var/www/cybokron-backups
sudo chmod 755 /var/www/cybokron-backups
```

---

### B11. Configure Cron Jobs

Open the www-data user's crontab:

```bash
sudo crontab -u www-data -e
```

Add the following entries:

```cron
# ─── Cybokron Exchange Rate & Portfolio Tracking ─────────────────────────────

# Update exchange rates every 15 minutes during market hours (Mon-Fri 09:00-18:00)
*/15 9-18 * * 1-5  /usr/bin/php /var/www/cybokron/cron/update_rates.php >> /var/log/cybokron/cron.log 2>&1

# Check and send alerts 2 minutes after each rate update
2-59/15 9-18 * * 1-5  /usr/bin/php /var/www/cybokron/cron/check_alerts.php >> /var/log/cybokron/cron.log 2>&1

# Clean up old rate history weekly (Sunday 03:00)
0 3 * * 0  /usr/bin/php /var/www/cybokron/cron/cleanup_rate_history.php >> /var/log/cybokron/cron.log 2>&1

# Self-update check daily at midnight (optional -- requires AUTO_UPDATE=true in config)
0 0 * * *  /usr/bin/php /var/www/cybokron/cron/self_update.php >> /var/log/cybokron/cron.log 2>&1
```

Verify the PHP path on your system:

```bash
which php
# or specifically:
which php8.3
```

Set up log rotation for cron logs:

```bash
sudo nano /etc/logrotate.d/cybokron
```

```
/var/log/cybokron/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
```

---

### B12. SSL with Let's Encrypt

Install Certbot and obtain a free SSL certificate:

#### Nginx

```bash
# Ubuntu / Debian
sudo apt install -y certbot python3-certbot-nginx

# Obtain and install certificate
sudo certbot --nginx -d exchange.yourdomain.com

# Verify auto-renewal
sudo certbot renew --dry-run
```

#### Apache

```bash
sudo apt install -y certbot python3-certbot-apache

sudo certbot --apache -d exchange.yourdomain.com

sudo certbot renew --dry-run
```

**CentOS / RHEL:**

```bash
sudo dnf install -y certbot python3-certbot-nginx   # or python3-certbot-apache
sudo certbot --nginx -d exchange.yourdomain.com
sudo certbot renew --dry-run
```

After obtaining the certificate, Certbot will automatically update your
Nginx or Apache configuration to listen on port 443 and redirect HTTP to
HTTPS.

Update `APP_URL` in `config.php` to use `https://`:

```php
define('APP_URL', 'https://exchange.yourdomain.com');
```

---

### B13. Firewall Configuration

#### UFW (Ubuntu / Debian)

```bash
# Allow SSH (do this first to avoid locking yourself out)
sudo ufw allow OpenSSH

# Allow HTTP and HTTPS
sudo ufw allow 'Nginx Full'
# or for Apache:
# sudo ufw allow 'Apache Full'

# Enable the firewall
sudo ufw enable

# Verify rules
sudo ufw status verbose
```

#### firewalld (CentOS / RHEL)

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
sudo firewall-cmd --list-all
```

> **Important:** Do NOT expose MySQL port 3306 to the public internet.
> Database connections should only be from `localhost`.

---

### B14. Verify Installation

1. Open your browser and navigate to `https://exchange.yourdomain.com`.

2. You should see the exchange rate dashboard.

3. Manually trigger a rate update:

```bash
sudo -u www-data php /var/www/cybokron/cron/update_rates.php
```

4. Refresh the browser to see populated rates.

5. Check for errors:

```bash
# Application log
cat /var/log/cybokron/cybokron.log

# Nginx error log
sudo tail -50 /var/log/nginx/cybokron_error.log

# PHP-FPM log
sudo tail -50 /var/log/php8.3-fpm.log
```

6. Verify PHP extensions are loaded:

```bash
php -m | grep -E 'curl|dom|mbstring|json|pdo_mysql|zip'
```

Expected output:

```
curl
dom
json
mbstring
pdo_mysql
zip
```

7. Verify cron is registered:

```bash
sudo crontab -u www-data -l
```

---

## Docker Deployment

A `docker-compose.yml` is included for containerized deployment.

### Quick Start

```bash
cd /path/to/cybokron-exchange-rate-and-portfolio-tracking

# Build and start containers
docker-compose up -d

# The app will be available at http://localhost:8080
```

The Docker setup includes:

- **app** container: PHP 8.3 + Apache serving the application on port 8080
- **db** container: MySQL 8.4 with automatic schema import

### Environment Variables

The Docker configuration (`config.docker.php`) reads settings from
environment variables. You can override them in `docker-compose.yml`:

```yaml
services:
  app:
    environment:
      - DB_HOST=db
      - DB_NAME=cybokron
      - DB_USER=cybokron
      - DB_PASS=your_secure_password
      - APP_URL=https://exchange.yourdomain.com
```

### Docker Cron

Cron jobs are not automatically configured inside the container. You have
two options:

1. **Host-based cron:** Run cron commands from the host using
   `docker exec`:

```cron
*/15 9-18 * * 1-5  docker exec cybokron-app php /var/www/html/cron/update_rates.php >> /var/log/cybokron/cron.log 2>&1
2-59/15 9-18 * * 1-5  docker exec cybokron-app php /var/www/html/cron/check_alerts.php >> /var/log/cybokron/cron.log 2>&1
0 3 * * 0  docker exec cybokron-app php /var/www/html/cron/cleanup_rate_history.php >> /var/log/cybokron/cron.log 2>&1
```

2. **In-container cron:** Install cron inside the container by extending
   the Dockerfile.

### Persistent Data

- MySQL data is stored in the `cybokron_mysql` Docker volume.
- Application logs are mapped to `./cybokron-logs` on the host.

---

## Post-Installation

### Change Default Admin Password

The application uses bcrypt-hashed passwords. There are several ways to
set or change the admin password.

#### Method 1 -- Generate Hash and Update config.php

```bash
php -r "echo password_hash('YourNewSecurePassword', PASSWORD_DEFAULT), PHP_EOL;"
```

Copy the output (it starts with `$2y$`) and set it in `config.php`:

```php
define('AUTH_BASIC_PASSWORD_HASH', '$2y$10$...');
```

#### Method 2 -- Use the Password Update Script

```bash
cd /var/www/cybokron

# Create a temporary password file
echo 'YourNewSecurePassword' > .admin_password.tmp

# Run the update script
php database/update_admin_password.php

# The script hashes the password and stores it in the database.
# The temporary file should be deleted afterward.
rm .admin_password.tmp
```

#### Method 3 -- Pre-Hashed via File

```bash
# Generate the hash and write it to a file
php -r "echo password_hash('YourNewSecurePassword', PASSWORD_BCRYPT);" > .admin_hash.tmp

# Run the update script
php database/update_admin_password.php

# Clean up
rm .admin_hash.tmp
```

---

### Enable Cloudflare Turnstile

1. Go to [dash.cloudflare.com](https://dash.cloudflare.com) and navigate
   to **Turnstile**.
2. Click **Add Site** and configure for your domain.
3. Copy the Site Key and Secret Key.
4. Update `config.php`:

```php
define('TURNSTILE_ENABLED', true);
define('TURNSTILE_SITE_KEY', '0x4AAAAAAA...');
define('TURNSTILE_SECRET_KEY', '0x4AAAAAAA...');
```

Turnstile will appear on the login page, protecting against automated
brute-force attacks.

---

### Configure OpenRouter AI

OpenRouter provides AI-powered fallback when bank scrapers fail to parse
exchange rate tables (for example, after a bank website redesign).

1. Sign up at [openrouter.ai](https://openrouter.ai) and obtain an API
   key.
2. Update `config.php`:

```php
define('OPENROUTER_AI_REPAIR_ENABLED', true);
define('OPENROUTER_API_KEY', 'sk-or-v1-...');
define('OPENROUTER_MODEL', 'z-ai/glm-5');
```

The AI repair triggers only when the number of parsed rates falls below
`OPENROUTER_MIN_EXPECTED_RATES` (default: 8). Additional safeguards are
in place:

- `OPENROUTER_AI_COOLDOWN_SECONDS` (default: 21600 / 6 hours) prevents
  repeated calls for the same table hash.
- `OPENROUTER_AI_MAX_INPUT_CHARS` (default: 12000) and
  `OPENROUTER_AI_MAX_TOKENS` (default: 600) control cost.

---

### Configure Alerts (Email, Telegram, Webhook)

#### Email Alerts

```php
define('ALERT_EMAIL_FROM', 'noreply@yourdomain.com');
define('ALERT_EMAIL_TO', 'you@example.com');
define('ALERT_COOLDOWN_MINUTES', 60);
```

#### Telegram Alerts

1. Create a bot via [@BotFather](https://t.me/BotFather) on Telegram.
2. Get your chat ID by messaging your bot and checking
   `https://api.telegram.org/bot<TOKEN>/getUpdates`.
3. Update `config.php`:

```php
define('ALERT_TELEGRAM_BOT_TOKEN', '123456789:ABCDEF...');
define('ALERT_TELEGRAM_CHAT_ID', '-1001234567890');
```

#### Webhook Alerts (Slack, Discord, Zapier)

```php
// Single webhook URL
define('RATE_UPDATE_WEBHOOK_URL', 'https://hooks.slack.com/services/T00/B00/XXXX');

// Or multiple URLs
define('RATE_UPDATE_WEBHOOK_URLS', [
    'https://hooks.slack.com/services/T00/B00/XXXX',
    'https://discord.com/api/webhooks/1234/abcdef',
]);
```

A general-purpose alert webhook can also be configured:

```php
define('ALERT_WEBHOOK_URL', 'https://hooks.example.com/cybokron-alerts');
```

---

### Configure Self-Healing

Self-healing uses AI to automatically repair scraper configurations when
bank websites change their HTML structure.

```php
define('SELF_HEALING_ENABLED', true);
define('SELF_HEALING_COOLDOWN_SECONDS', 3600);   // 1 hour between repair attempts per bank
define('SELF_HEALING_MAX_RETRIES', 2);            // Max retry attempts per table change
```

Self-healing requires OpenRouter AI to be configured (see above).

If you want repair configurations to be automatically committed to your
GitHub repository, also set:

```php
define('GITHUB_API_TOKEN', 'ghp_...');   // GitHub PAT with repo scope
```

---

### Enable GitHub Self-Update

The self-update system allows the application to check for and apply
updates from GitHub automatically.

```php
define('AUTO_UPDATE', true);
define('GITHUB_REPO', 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking');
define('GITHUB_BRANCH', 'main');
define('BACKUP_DIR', '/var/www/cybokron-backups');
```

For signed updates (recommended for production):

```php
define('UPDATE_REQUIRE_SIGNATURE', true);
define('UPDATE_SIGNING_PUBLIC_KEY_PEM', "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----");
```

The self-update cron (`cron/self_update.php`) runs daily at midnight by
default and will:

1. Check for a newer version on GitHub.
2. Back up the current installation.
3. Download and verify the update package.
4. Apply the update.
5. Run any pending database migrations.

> **Warning:** Thoroughly test self-update in a staging environment before
> enabling it in production. Keep `UPDATE_REQUIRE_SIGNATURE` set to `true`
> to ensure only authenticated updates are applied.

---

## Upgrading

### Manual Upgrade

#### Using Git

```bash
cd /var/www/cybokron

# Stash any local changes (config.php should already be in .gitignore)
git stash

# Pull the latest changes
git pull origin main

# Re-apply local changes if needed
git stash pop

# Run database migrations
php database/migrator.php

# Check migration status
php database/migrator.php --status

# Clear any caches and verify
php -r "opcache_reset();" 2>/dev/null
```

#### Using ZIP Download

1. Download the latest release from GitHub.
2. Back up your current `config.php`:
   ```bash
   cp /var/www/cybokron/config.php /var/www/cybokron/config.php.backup
   ```
3. Extract the new files over the existing installation.
4. Restore your config:
   ```bash
   cp /var/www/cybokron/config.php.backup /var/www/cybokron/config.php
   ```
5. Run migrations:
   ```bash
   php /var/www/cybokron/database/migrator.php
   ```
6. Verify file permissions (see B10).

#### Migration Commands Reference

```bash
# Show status of all migrations
php database/migrator.php --status

# Preview which migrations will run (without executing)
php database/migrator.php --dry-run

# Run all pending migrations
php database/migrator.php

# Mark migrations as applied without executing (advanced -- use with caution)
php database/migrator.php --mark-applied
```

---

### Docker Upgrade

```bash
cd /path/to/cybokron-exchange-rate-and-portfolio-tracking

# Pull the latest changes
git pull origin main

# Rebuild the container
docker-compose build --no-cache

# Restart with the new image
docker-compose up -d

# Run migrations inside the container
docker exec cybokron-app php /var/www/html/database/migrator.php

# Verify
docker exec cybokron-app php /var/www/html/database/migrator.php --status
```

> **Tip:** The MySQL data volume (`cybokron_mysql`) persists across container
> rebuilds, so your data is safe during upgrades.

---

## Troubleshooting

### Common Issues

| Problem | Solution |
|---|---|
| "Connection refused" on database | Verify `DB_HOST` is correct. On shared hosting, try `localhost` or `127.0.0.1`. Check that MySQL is running: `sudo systemctl status mysql`. |
| "Access denied" for database user | Double-check `DB_USER` and `DB_PASS`. On cPanel, ensure the user is added to the database with full privileges. |
| Blank page / 500 error | Set `APP_DEBUG` to `true` temporarily and check the PHP error log (`/var/log/php8.3-fpm.log` or Apache error log). |
| Cron jobs not running | Verify the PHP binary path (`which php`). Check cron logs (`/var/log/syslog` or `cron.log`). Ensure `ENFORCE_CLI_CRON` is `true`. |
| "Permission denied" on log file | Ensure the log directory exists and is writable by the web server user (`www-data`). |
| Rates not updating | Run `php cron/update_rates.php` manually and check the output. Verify `$ACTIVE_BANKS` is configured. |
| SSL certificate errors | Run `sudo certbot renew` to check certificate status. Ensure your domain DNS points to the server. |
| PHP extensions missing | Run `php -m` to list loaded modules. Install missing ones via `apt install php8.3-<extension>` and restart PHP-FPM. |

### Checking Logs

```bash
# Application log
tail -100 /var/log/cybokron/cybokron.log

# Cron log
tail -100 /var/log/cybokron/cron.log

# Nginx error log
sudo tail -100 /var/log/nginx/cybokron_error.log

# PHP-FPM log
sudo tail -100 /var/log/php8.3-fpm.log

# MySQL error log
sudo tail -100 /var/log/mysql/error.log
```

### Getting Help

- Open an issue on GitHub: <https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/issues>
- Check existing issues and discussions for solutions to common problems.

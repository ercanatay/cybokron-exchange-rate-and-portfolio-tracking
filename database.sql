-- =============================================
-- Cybokron Exchange Rate & Portfolio Tracking
-- Database Schema v1.3.1
-- =============================================

CREATE DATABASE IF NOT EXISTS `cybokron`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `cybokron`;

-- ─── Banks ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `banks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `url` VARCHAR(500) NOT NULL,
  `scraper_class` VARCHAR(100) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_scraped_at` DATETIME NULL,
  `table_hash` VARCHAR(64) NULL COMMENT 'Hash of source table structure for change detection',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_slug` (`slug`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB;

-- ─── Currencies ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `currencies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `name_tr` VARCHAR(100) NOT NULL,
  `name_en` VARCHAR(100) NOT NULL,
  `symbol` VARCHAR(10) NULL,
  `type` ENUM('fiat', 'precious_metal', 'crypto') DEFAULT 'fiat',
  `decimal_places` TINYINT UNSIGNED DEFAULT 4,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_code` (`code`),
  INDEX `idx_type` (`type`)
) ENGINE=InnoDB;

-- ─── Exchange Rates (Latest) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_id` INT UNSIGNED NOT NULL,
  `currency_id` INT UNSIGNED NOT NULL,
  `buy_rate` DECIMAL(18,6) NOT NULL,
  `sell_rate` DECIMAL(18,6) NOT NULL,
  `change_percent` DECIMAL(8,4) NULL,
  `show_on_homepage` TINYINT(1) DEFAULT 1 COMMENT 'Show this rate on homepage',
  `display_order` INT UNSIGNED DEFAULT 0 COMMENT 'Custom display order (0 = default order)',
  `scraped_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bank_currency` (`bank_id`, `currency_id`),
  FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE,
  INDEX `idx_scraped` (`scraped_at`),
  INDEX `idx_bank_currency` (`bank_id`, `currency_id`),
  INDEX `idx_homepage` (`show_on_homepage`),
  INDEX `idx_display_order` (`display_order`)
) ENGINE=InnoDB;

-- ─── Exchange Rate History ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rate_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_id` INT UNSIGNED NOT NULL,
  `currency_id` INT UNSIGNED NOT NULL,
  `buy_rate` DECIMAL(18,6) NOT NULL,
  `sell_rate` DECIMAL(18,6) NOT NULL,
  `change_percent` DECIMAL(8,4) NULL,
  `scraped_at` DATETIME NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE,
  INDEX `idx_history_lookup` (`bank_id`, `currency_id`, `scraped_at`),
  INDEX `idx_currency_scraped` (`currency_id`, `scraped_at`),
  INDEX `idx_scraped_at` (`scraped_at`)
) ENGINE=InnoDB;

-- ─── Users ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') DEFAULT 'user',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB;

-- ─── Portfolio ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `portfolio` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `currency_id` INT UNSIGNED NOT NULL,
  `bank_id` INT UNSIGNED NULL,
  `amount` DECIMAL(18,6) NOT NULL,
  `buy_rate` DECIMAL(18,6) NOT NULL COMMENT 'Rate at which the currency was purchased',
  `buy_date` DATE NOT NULL,
  `notes` VARCHAR(500) NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE SET NULL,
  INDEX `idx_bank_currency` (`bank_id`, `currency_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_currency` (`currency_id`),
  INDEX `idx_buy_date` (`buy_date`)
) ENGINE=InnoDB;

-- ─── Scrape Logs ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scrape_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_id` INT UNSIGNED NOT NULL,
  `status` ENUM('success', 'error', 'warning') NOT NULL,
  `message` TEXT NULL,
  `rates_count` INT UNSIGNED DEFAULT 0,
  `duration_ms` INT UNSIGNED NULL,
  `table_changed` TINYINT(1) DEFAULT 0 COMMENT 'Was the source HTML table structure different?',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE,
  INDEX `idx_bank_status` (`bank_id`, `status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB;

-- ─── Alerts ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `alerts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NULL,
  `currency_code` VARCHAR(10) NOT NULL,
  `condition_type` ENUM('above', 'below', 'change_pct') NOT NULL,
  `threshold` DECIMAL(18,6) NOT NULL,
  `channel` ENUM('email', 'telegram', 'webhook') DEFAULT 'email',
  `channel_config` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_triggered_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_currency_active` (`currency_code`, `is_active`)
) ENGINE=InnoDB;

-- ─── App Settings ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================
-- Seed Data
-- =============================================

-- Insert banks
INSERT INTO `banks` (`name`, `slug`, `url`, `scraper_class`) VALUES
('Dünya Katılım', 'dunya-katilim', 'https://dunyakatilim.com.tr/gunluk-kurlar', 'DunyaKatilim'),
('TCMB', 'tcmb', 'https://www.tcmb.gov.tr/kurlar/today.xml', 'TCMB');

-- Insert Currencies
INSERT INTO `currencies` (`code`, `name_tr`, `name_en`, `symbol`, `type`, `decimal_places`) VALUES
('USD', 'Amerikan Doları', 'US Dollar', '$', 'fiat', 4),
('EUR', 'Euro', 'Euro', '€', 'fiat', 4),
('GBP', 'İngiliz Sterlini', 'British Pound', '£', 'fiat', 4),
('CHF', 'İsviçre Frangı', 'Swiss Franc', 'CHF', 'fiat', 4),
('AUD', 'Avustralya Doları', 'Australian Dollar', 'A$', 'fiat', 4),
('CAD', 'Kanada Doları', 'Canadian Dollar', 'C$', 'fiat', 4),
('CNY', 'Çin Yuanı', 'Chinese Yuan', '¥', 'fiat', 4),
('JPY', 'Japon Yeni', 'Japanese Yen', '¥', 'fiat', 4),
('SAR', 'Suudi Riyali', 'Saudi Riyal', 'SAR', 'fiat', 4),
('AED', 'BAE Dirhemi', 'UAE Dirham', 'AED', 'fiat', 4),
('XAU', 'Altın', 'Gold', 'XAU', 'precious_metal', 4),
('XAG', 'Gümüş', 'Silver', 'XAG', 'precious_metal', 4),
('XPT', 'Platin', 'Platinum', 'XPT', 'precious_metal', 4),
('XPD', 'Paladyum', 'Palladium', 'XPD', 'precious_metal', 4),
('DKK', 'Danimarka Kronu', 'Danish Krone', 'kr', 'fiat', 4),
('NOK', 'Norveç Kronu', 'Norwegian Krone', 'kr', 'fiat', 4),
('SEK', 'İsveç Kronu', 'Swedish Krona', 'kr', 'fiat', 4),
('KWD', 'Kuveyt Dinarı', 'Kuwaiti Dinar', 'KD', 'fiat', 4),
('RON', 'Rumen Leyi', 'Romanian Leu', 'lei', 'fiat', 4),
('RUB', 'Rus Rublesi', 'Russian Rouble', '₽', 'fiat', 4),
('PKR', 'Pakistan Rupisi', 'Pakistani Rupee', '₨', 'fiat', 4),
('QAR', 'Katar Riyali', 'Qatari Rial', 'QR', 'fiat', 4),
('KRW', 'Güney Kore Wonu', 'South Korean Won', '₩', 'fiat', 4),
('AZN', 'Azerbaycan Manatı', 'Azerbaijani Manat', '₼', 'fiat', 4),
('KZT', 'Kazakistan Tengesi', 'Kazakhstan Tenge', '₸', 'fiat', 4),
('XDR', 'Özel Çekme Hakkı (SDR)', 'Special Drawing Right', 'XDR', 'fiat', 4);

-- Insert default admin user (password: admin123 - change in production)
INSERT INTO `users` (`username`, `password_hash`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- Insert default settings
INSERT INTO `settings` (`key`, `value`) VALUES
('app_version', '1.3.1'),
('last_update_check', NULL),
('last_rate_update', NULL),
('openrouter_model', 'z-ai/glm-5');

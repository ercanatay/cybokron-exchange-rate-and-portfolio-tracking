-- =============================================
-- Cybokron Exchange Rate & Portfolio Tracking
-- Database Schema v1.0.0
-- =============================================

CREATE DATABASE IF NOT EXISTS `cybokron` 
    DEFAULT CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE `cybokron`;

-- -------------------------------------------
-- Banks table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `banks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `url` VARCHAR(500) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_scraped_at` DATETIME NULL,
    `table_hash` VARCHAR(64) NULL COMMENT 'Hash of table structure for change detection',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Currencies table
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `currencies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(10) NOT NULL UNIQUE,
    `name_tr` VARCHAR(100) NOT NULL,
    `name_en` VARCHAR(100) NOT NULL,
    `type` ENUM('fiat', 'precious_metal') NOT NULL DEFAULT 'fiat',
    `symbol` VARCHAR(10) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_code` (`code`),
    INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Exchange rates (latest snapshot)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `rates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id` INT UNSIGNED NOT NULL,
    `currency_id` INT UNSIGNED NOT NULL,
    `buy_rate` DECIMAL(18,6) NOT NULL,
    `sell_rate` DECIMAL(18,6) NOT NULL,
    `change_percent` DECIMAL(8,4) NULL,
    `fetched_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bank_currency` (`bank_id`, `currency_id`),
    INDEX `idx_fetched` (`fetched_at`),
    FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Rate history (for charts & trends)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id` INT UNSIGNED NOT NULL,
    `currency_id` INT UNSIGNED NOT NULL,
    `buy_rate` DECIMAL(18,6) NOT NULL,
    `sell_rate` DECIMAL(18,6) NOT NULL,
    `change_percent` DECIMAL(8,4) NULL,
    `fetched_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_currency_date` (`bank_id`, `currency_id`, `fetched_at`),
    INDEX `idx_fetched` (`fetched_at`),
    FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Portfolio entries
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `currency_id` INT UNSIGNED NOT NULL,
    `bank_id` INT UNSIGNED NULL,
    `amount` DECIMAL(18,6) NOT NULL,
    `buy_rate` DECIMAL(18,6) NOT NULL COMMENT 'Rate at purchase time (TRY)',
    `buy_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_currency` (`currency_id`),
    FOREIGN KEY (`currency_id`) REFERENCES `currencies`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- Scraper logs
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `scrape_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id` INT UNSIGNED NOT NULL,
    `status` ENUM('success', 'error', 'structure_changed') NOT NULL,
    `message` TEXT NULL,
    `rates_count` INT UNSIGNED NULL,
    `duration_ms` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_status` (`bank_id`, `status`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`bank_id`) REFERENCES `banks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------
-- App settings (key-value store)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Seed Data
-- =============================================

-- Insert Dünya Katılım bank
INSERT INTO `banks` (`slug`, `name`, `url`) VALUES
('dunya-katilim', 'Dünya Katılım Bankası', 'https://dunyakatilim.com.tr/gunluk-kurlar');

-- Insert currencies
INSERT INTO `currencies` (`code`, `name_tr`, `name_en`, `type`) VALUES
('USD', 'Amerikan Doları', 'US Dollar', 'fiat'),
('EUR', 'Euro', 'Euro', 'fiat'),
('GBP', 'İngiliz Sterlini', 'British Pound', 'fiat'),
('CHF', 'İsviçre Frangı', 'Swiss Franc', 'fiat'),
('AUD', 'Avustralya Doları', 'Australian Dollar', 'fiat'),
('CAD', 'Kanada Doları', 'Canadian Dollar', 'fiat'),
('CNY', 'Çin Yuanı', 'Chinese Yuan', 'fiat'),
('JPY', 'Japon Yeni', 'Japanese Yen', 'fiat'),
('SAR', 'Suudi Riyali', 'Saudi Riyal', 'fiat'),
('AED', 'BAE Dirhemi', 'UAE Dirham', 'fiat'),
('XAU', 'Altın', 'Gold', 'precious_metal'),
('XAG', 'Gümüş', 'Silver', 'precious_metal'),
('XPT', 'Platin', 'Platinum', 'precious_metal'),
('XPD', 'Paladyum', 'Palladium', 'precious_metal');

-- Insert default settings
INSERT INTO `settings` (`key`, `value`) VALUES
('app_version', '1.0.0'),
('last_update_check', NULL);

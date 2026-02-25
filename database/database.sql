-- Cybokron Exchange Rate & Portfolio Tracking
-- Complete database schema (fresh install)
-- Run: mysql -u root -p your_database < database/database.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Users ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `users` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `username` varchar(64) NOT NULL,
    `password_hash` varchar(255) NOT NULL,
    `role` enum('admin','user') DEFAULT 'user',
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Banks ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `banks` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `url` varchar(500) NOT NULL,
    `scraper_class` varchar(100) NOT NULL,
    `is_active` tinyint(1) DEFAULT 1,
    `last_scraped_at` datetime DEFAULT NULL,
    `table_hash` varchar(64) DEFAULT NULL COMMENT 'Hash of source table structure for change detection',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Currencies ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `currencies` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `code` varchar(10) NOT NULL,
    `name_tr` varchar(100) NOT NULL,
    `name_en` varchar(100) NOT NULL,
    `symbol` varchar(10) DEFAULT NULL,
    `type` enum('fiat','precious_metal','crypto') DEFAULT 'fiat',
    `decimal_places` tinyint unsigned DEFAULT 4,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `code` (`code`),
    KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Rates (current) ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `rates` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `bank_id` int unsigned NOT NULL,
    `currency_id` int unsigned NOT NULL,
    `buy_rate` decimal(18,6) NOT NULL,
    `sell_rate` decimal(18,6) NOT NULL,
    `change_percent` decimal(8,4) DEFAULT NULL,
    `show_on_homepage` tinyint(1) DEFAULT 1 COMMENT 'Show this rate on homepage',
    `display_order` int unsigned DEFAULT 0 COMMENT 'Custom display order (0 = default order)',
    `scraped_at` datetime NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bank_currency` (`bank_id`,`currency_id`),
    KEY `currency_id` (`currency_id`),
    KEY `idx_scraped` (`scraped_at`),
    KEY `idx_homepage` (`show_on_homepage`),
    KEY `idx_display_order` (`display_order`),
    CONSTRAINT `rates_ibfk_1` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `rates_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Rate History ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `rate_history` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `bank_id` int unsigned NOT NULL,
    `currency_id` int unsigned NOT NULL,
    `buy_rate` decimal(18,6) NOT NULL,
    `sell_rate` decimal(18,6) NOT NULL,
    `change_percent` decimal(8,4) DEFAULT NULL,
    `scraped_at` datetime NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_lookup` (`bank_id`,`currency_id`,`scraped_at`),
    KEY `idx_currency_scraped` (`currency_id`,`scraped_at`),
    KEY `idx_scraped_at` (`scraped_at`),
    CONSTRAINT `rate_history_ibfk_1` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `rate_history_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `settings` (
    `key` varchar(100) NOT NULL,
    `value` text,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Alerts ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alerts` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned DEFAULT NULL,
    `currency_code` varchar(10) NOT NULL,
    `condition_type` enum('above','below','change_pct') NOT NULL,
    `threshold` decimal(18,6) NOT NULL,
    `channel` enum('email','telegram','webhook') DEFAULT 'email',
    `channel_config` text,
    `is_active` tinyint(1) DEFAULT 1,
    `last_triggered_at` datetime DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_currency_active` (`currency_code`,`is_active`),
    KEY `idx_alerts_user` (`user_id`),
    CONSTRAINT `fk_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio Groups ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio_groups` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL,
    `name` varchar(100) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `color` varchar(7) NOT NULL DEFAULT '#3b82f6',
    `icon` varchar(10) DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_slug_user` (`slug`,`user_id`),
    KEY `idx_group_user` (`user_id`),
    CONSTRAINT `fk_group_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL,
    `currency_id` int unsigned NOT NULL,
    `bank_id` int unsigned DEFAULT NULL,
    `group_id` int unsigned DEFAULT NULL,
    `amount` decimal(18,6) NOT NULL,
    `buy_rate` decimal(18,6) NOT NULL COMMENT 'Rate at which the currency was purchased',
    `buy_date` date NOT NULL,
    `notes` varchar(500) DEFAULT NULL,
    `deleted_at` datetime DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_currency` (`bank_id`,`currency_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_portfolio_user_deleted` (`user_id`, `deleted_at`),
    KEY `idx_currency` (`currency_id`),
    KEY `idx_buy_date` (`buy_date`),
    KEY `idx_portfolio_group` (`group_id`),
    CONSTRAINT `fk_portfolio_group` FOREIGN KEY (`group_id`) REFERENCES `portfolio_groups` (`id`) ON DELETE SET NULL,
    CONSTRAINT `portfolio_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `portfolio_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `portfolio_ibfk_3` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio Tags ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio_tags` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL,
    `name` varchar(50) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `color` varchar(7) NOT NULL DEFAULT '#8b5cf6',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio_tags_user` (`user_id`),
    KEY `idx_portfolio_tags_slug` (`slug`),
    CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio Tag Items ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio_tag_items` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `portfolio_id` int unsigned NOT NULL,
    `tag_id` int unsigned NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_portfolio_tag_unique` (`portfolio_id`,`tag_id`),
    KEY `idx_portfolio_tag_items_tag` (`tag_id`),
    CONSTRAINT `fk_pti_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolio` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pti_tag` FOREIGN KEY (`tag_id`) REFERENCES `portfolio_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio Goals ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio_goals` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL,
    `name` varchar(100) NOT NULL,
    `target_value` decimal(18,6) NOT NULL COMMENT 'Target value in TRY',
    `target_type` varchar(20) NOT NULL DEFAULT 'value',
    `target_currency` varchar(10) DEFAULT NULL,
    `bank_slug` varchar(50) DEFAULT NULL COMMENT 'Filter items by bank',
    `is_favorite` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT 'Whether this goal is marked as favorite',
    `percent_date_mode` enum('all','range','since_first','weighted') DEFAULT NULL COMMENT 'Date mode for percent goals',
    `percent_date_start` date DEFAULT NULL COMMENT 'Start date for range mode',
    `percent_date_end` date DEFAULT NULL COMMENT 'End date for range mode',
    `percent_period_months` int unsigned DEFAULT 12 COMMENT 'Period in months for since_first mode',
    `goal_deadline` date DEFAULT NULL COMMENT 'Target deadline for the goal',
    `deposit_rate` decimal(5,2) DEFAULT NULL COMMENT 'Per-goal deposit interest rate override (NULL = use admin default)',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio_goals_user` (`user_id`),
    KEY `idx_portfolio_goals_favorite` (`user_id`, `is_favorite`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Portfolio Goal Sources ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `portfolio_goal_sources` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `goal_id` int unsigned NOT NULL,
    `source_type` enum('group','tag','item') NOT NULL,
    `source_id` int unsigned NOT NULL COMMENT 'group_id, tag_id, or portfolio item id',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_goal_source_unique` (`goal_id`,`source_type`,`source_id`),
    CONSTRAINT `fk_goal_source_goal` FOREIGN KEY (`goal_id`) REFERENCES `portfolio_goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Scrape Logs ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `scrape_logs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `bank_id` int unsigned NOT NULL,
    `status` enum('success','error','warning') NOT NULL,
    `message` text,
    `rates_count` int unsigned DEFAULT 0,
    `duration_ms` int unsigned DEFAULT NULL,
    `table_changed` tinyint(1) DEFAULT 0 COMMENT 'Was the source HTML table structure different?',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_status` (`bank_id`,`status`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `scrape_logs_ibfk_1` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Remember Me Tokens ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL,
    `selector` varchar(64) NOT NULL,
    `hashed_validator` varchar(128) NOT NULL,
    `expires_at` datetime NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_selector` (`selector`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Repair Configs (self-healing) ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `repair_configs` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `bank_id` int unsigned NOT NULL,
    `xpath_rows` varchar(500) NOT NULL,
    `columns` json NOT NULL COMMENT 'Column index/selector mapping',
    `currency_map` json NOT NULL COMMENT 'Local name to ISO code mapping',
    `number_format` varchar(20) NOT NULL DEFAULT 'turkish',
    `skip_header_rows` tinyint unsigned NOT NULL DEFAULT 0,
    `table_hash` varchar(64) NOT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `github_issue_url` varchar(500) DEFAULT NULL,
    `github_commit_sha` varchar(64) DEFAULT NULL,
    `deactivated_at` datetime DEFAULT NULL,
    `deactivation_reason` varchar(500) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_active` (`bank_id`,`is_active`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_repair_configs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Repair Logs (self-healing) ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `repair_logs` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `bank_id` int unsigned NOT NULL,
    `step` varchar(50) NOT NULL COMMENT 'Pipeline step name',
    `status` varchar(20) NOT NULL COMMENT 'success, error, skipped',
    `message` text DEFAULT NULL,
    `duration_ms` int unsigned DEFAULT NULL,
    `metadata` json DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_created` (`bank_id`,`created_at`),
    KEY `idx_step_status` (`step`,`status`),
    CONSTRAINT `fk_repair_logs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Schema Migrations (used by migrator.php) ───────────────────────────────

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `filename` varchar(255) NOT NULL,
    `checksum` varchar(32) NOT NULL,
    `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `execution_time_ms` int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Leverage Rules ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `leverage_rules` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `source_type` enum('group','tag','currency') NOT NULL,
    `source_id` int unsigned DEFAULT NULL,
    `currency_code` varchar(10) NOT NULL,
    `buy_threshold` decimal(8,2) NOT NULL DEFAULT -15.00,
    `sell_threshold` decimal(8,2) NOT NULL DEFAULT 30.00,
    `reference_price` decimal(18,6) NOT NULL,
    `ai_enabled` tinyint(1) NOT NULL DEFAULT 1,
    `strategy_context` text DEFAULT NULL,
    `status` enum('active','paused','completed') NOT NULL DEFAULT 'active',
    `last_checked_at` datetime DEFAULT NULL,
    `last_triggered_at` datetime DEFAULT NULL,
    `last_trigger_direction` enum('buy','sell') DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_currency` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Leverage History ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `leverage_history` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `rule_id` int unsigned NOT NULL,
    `event_type` enum('buy_signal','sell_signal','ai_analysis','price_update','status_change') NOT NULL,
    `price_at_event` decimal(18,6) DEFAULT NULL,
    `reference_price_at_event` decimal(18,6) DEFAULT NULL,
    `change_percent` decimal(8,2) DEFAULT NULL,
    `ai_response` text DEFAULT NULL,
    `ai_recommendation` enum('strong_buy','buy','hold','sell','strong_sell') DEFAULT NULL,
    `notification_sent` tinyint(1) NOT NULL DEFAULT 0,
    `notification_channel` varchar(20) DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rule` (`rule_id`),
    KEY `idx_event` (`event_type`),
    CONSTRAINT `fk_leverage_history_rule` FOREIGN KEY (`rule_id`) REFERENCES `leverage_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════════════
-- SEED DATA
-- ═══════════════════════════════════════════════════════════════════════════════

-- Banks (Dünya Katılım, TCMB, İş Bankası active; all others inactive by default)
INSERT IGNORE INTO `banks` (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
('Dünya Katılım', 'dunya-katilim', 'https://dunyakatilim.com.tr/gunluk-kurlar', 'DunyaKatilim', 1),
('TCMB', 'tcmb', 'https://www.tcmb.gov.tr/kurlar/today.xml', 'TCMB', 1),
('İş Bankası', 'is-bankasi', 'https://kur.doviz.com/isbankasi', 'DovizComScraper', 1),
('Akbank', 'akbank', 'https://kur.doviz.com/akbank', 'DovizComScraper', 0),
('Albaraka Türk', 'albaraka-turk', 'https://kur.doviz.com/albaraka-turk', 'DovizComScraper', 0),
('Alternatif Bank', 'alternatif-bank', 'https://kur.doviz.com/alternatif-bank', 'DovizComScraper', 0),
('Altınkaynak', 'altinkaynak', 'https://kur.doviz.com/altinkaynak', 'DovizComScraper', 0),
('Anadolubank', 'anadolubank', 'https://kur.doviz.com/anadolubank', 'DovizComScraper', 0),
('CEPTETEB', 'cepteteb', 'https://kur.doviz.com/cepteteb', 'DovizComScraper', 0),
('Denizbank', 'denizbank', 'https://kur.doviz.com/denizbank', 'DovizComScraper', 0),
('DestekBank', 'destekbank', 'https://kur.doviz.com/destekbank', 'DovizComScraper', 0),
('Emlak Katılım', 'emlak-katilim', 'https://kur.doviz.com/emlak-katilim', 'DovizComScraper', 0),
('Enpara', 'enpara', 'https://kur.doviz.com/enpara', 'DovizComScraper', 0),
('Fibabanka', 'fibabanka', 'https://kur.doviz.com/fibabanka', 'DovizComScraper', 0),
('Garanti BBVA', 'garanti-bbva', 'https://kur.doviz.com/garanti-bbva', 'DovizComScraper', 0),
('Getirfinans', 'getirfinans', 'https://kur.doviz.com/getirfinans', 'DovizComScraper', 0),
('Hadi / TOMBank', 'hadi', 'https://kur.doviz.com/hadi', 'DovizComScraper', 0),
('Halkbank', 'halkbank', 'https://kur.doviz.com/halkbank', 'DovizComScraper', 0),
('Harem', 'harem', 'https://kur.doviz.com/harem', 'DovizComScraper', 0),
('Hayat Finans', 'hayat-finans', 'https://kur.doviz.com/hayat-finans', 'DovizComScraper', 0),
('Hepsipay', 'hepsipay', 'https://kur.doviz.com/hepsipay', 'DovizComScraper', 0),
('HSBC', 'hsbc', 'https://kur.doviz.com/hsbc', 'DovizComScraper', 0),
('ING Bank', 'ing-bank', 'https://kur.doviz.com/ing-bank', 'DovizComScraper', 0),
('Kapalıçarşı', 'kapalicarsi', 'https://kur.doviz.com/kapalicarsi', 'DovizComScraper', 0),
('Kuveyt Türk', 'kuveyt-turk', 'https://kur.doviz.com/kuveyt-turk', 'DovizComScraper', 0),
('Misyon Bank', 'misyon-bank', 'https://kur.doviz.com/misyon-bank', 'DovizComScraper', 0),
('Odacı', 'odaci', 'https://kur.doviz.com/odaci', 'DovizComScraper', 0),
('Odeabank', 'odeabank', 'https://kur.doviz.com/odeabank', 'DovizComScraper', 0),
('Papara', 'papara', 'https://kur.doviz.com/papara', 'DovizComScraper', 0),
('QNB Finansbank', 'qnb-finansbank', 'https://kur.doviz.com/qnb-finansbank', 'DovizComScraper', 0),
('Şekerbank', 'sekerbank', 'https://kur.doviz.com/sekerbank', 'DovizComScraper', 0),
('Türkiye Finans', 'turkiye-finans', 'https://kur.doviz.com/turkiye-finans', 'DovizComScraper', 0),
('Vakıf Katılım', 'vakif-katilim', 'https://kur.doviz.com/vakif-katilim', 'DovizComScraper', 0),
('Vakıfbank', 'vakifbank', 'https://kur.doviz.com/vakifbank', 'DovizComScraper', 0),
('Venüs', 'venus', 'https://kur.doviz.com/venus', 'DovizComScraper', 0),
('Yapıkredi', 'yapikredi', 'https://kur.doviz.com/yapikredi', 'DovizComScraper', 0),
('Ziraat Bankası', 'ziraat-bankasi', 'https://kur.doviz.com/ziraat-bankasi', 'DovizComScraper', 0),
('Ziraat Katılım', 'ziraat-katilim', 'https://kur.doviz.com/ziraat-katilim', 'DovizComScraper', 0);

-- Currencies
INSERT IGNORE INTO `currencies` (`code`, `name_tr`, `name_en`, `symbol`, `type`, `decimal_places`) VALUES
('USD', 'Amerikan Doları', 'US Dollar', '$', 'fiat', 4),
('EUR', 'Euro', 'Euro', '€', 'fiat', 4),
('GBP', 'İngiliz Sterlini', 'British Pound', '£', 'fiat', 4),
('CHF', 'İsviçre Frangı', 'Swiss Franc', 'CHF', 'fiat', 4),
('JPY', 'Japon Yeni', 'Japanese Yen', '¥', 'fiat', 4),
('CAD', 'Kanada Doları', 'Canadian Dollar', 'C$', 'fiat', 4),
('AUD', 'Avustralya Doları', 'Australian Dollar', 'A$', 'fiat', 4),
('CNY', 'Çin Yuanı', 'Chinese Yuan', '¥', 'fiat', 4),
('SAR', 'Suudi Riyali', 'Saudi Riyal', 'SAR', 'fiat', 4),
('AED', 'BAE Dirhemi', 'UAE Dirham', 'AED', 'fiat', 4),
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
('XDR', 'Özel Çekme Hakkı (SDR)', 'Special Drawing Right', 'XDR', 'fiat', 4),
('XAU', 'Altın', 'Gold', 'XAU', 'precious_metal', 4),
('XAG', 'Gümüş', 'Silver', 'XAG', 'precious_metal', 4),
('XPT', 'Platin', 'Platinum', 'XPT', 'precious_metal', 4),
('XPD', 'Paladyum', 'Palladium', 'XPD', 'precious_metal', 4);

-- Leverage & SendGrid settings
INSERT INTO `settings` (`key`, `value`) VALUES
    ('leverage_enabled', '1'),
    ('leverage_ai_model', 'google/gemini-3.1-pro-preview'),
    ('leverage_ai_enabled', '1'),
    ('leverage_check_interval_minutes', '15'),
    ('leverage_cooldown_minutes', '60'),
    ('sendgrid_enabled', '1'),
    ('sendgrid_api_key', ''),
    ('sendgrid_from_email', 'noreply@example.com'),
    ('sendgrid_from_name', 'Cybokron Leverage'),
    ('leverage_notify_emails', '["admin@example.com"]')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Admin user is created by database/update_admin_password.php with a proper bcrypt hash.
-- Do not seed a placeholder password here — migrate.php handles first-run setup.

-- Migration 013: Leverage (Kaldırac) system tables + settings
-- Cybokron Exchange Rate & Portfolio Tracking

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

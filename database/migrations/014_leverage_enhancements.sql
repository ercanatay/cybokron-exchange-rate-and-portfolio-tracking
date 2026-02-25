-- Migration 014: Leverage enhancements — trailing stop, webhooks, backtesting, notifications
-- Cybokron Exchange Rate & Portfolio Tracking

-- ─── New table: leverage_webhooks ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS leverage_webhooks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    platform ENUM('generic','discord','slack') NOT NULL DEFAULT 'generic',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sent_at DATETIME NULL,
    last_status_code INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── New table: leverage_backtests ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS leverage_backtests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    data_source VARCHAR(50) NOT NULL DEFAULT 'rate_history',
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    total_signals INT NOT NULL DEFAULT 0,
    buy_signals INT NOT NULL DEFAULT 0,
    sell_signals INT NOT NULL DEFAULT 0,
    total_return_pct DECIMAL(8,2) NULL,
    max_drawdown_pct DECIMAL(8,2) NULL,
    win_rate_pct DECIMAL(8,2) NULL,
    result_data JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule (rule_id),
    CONSTRAINT fk_leverage_backtests_rule FOREIGN KEY (rule_id) REFERENCES leverage_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ALTER: leverage_rules — add trailing stop + weak threshold columns ────

ALTER TABLE leverage_rules
    ADD COLUMN trailing_stop_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER sell_threshold,
    ADD COLUMN trailing_stop_type ENUM('auto','threshold') NOT NULL DEFAULT 'auto' AFTER trailing_stop_enabled,
    ADD COLUMN trailing_stop_pct DECIMAL(8,2) NULL AFTER trailing_stop_type,
    ADD COLUMN peak_price DECIMAL(18,6) NULL AFTER trailing_stop_pct,
    ADD COLUMN buy_threshold_weak DECIMAL(8,2) NULL AFTER peak_price,
    ADD COLUMN sell_threshold_weak DECIMAL(8,2) NULL AFTER buy_threshold_weak;

-- ─── ALTER: leverage_history — expand notification_channel and event_type ──

ALTER TABLE leverage_history
    MODIFY COLUMN notification_channel VARCHAR(100) DEFAULT NULL;

ALTER TABLE leverage_history
    MODIFY COLUMN event_type ENUM('buy_signal','sell_signal','weak_buy_signal','weak_sell_signal','trailing_stop_signal','ai_analysis','price_update','status_change') NOT NULL;

-- ─── Settings seed ─────────────────────────────────────────────────────────

INSERT INTO settings (`key`, `value`) VALUES
    ('telegram_enabled', '0'),
    ('telegram_bot_token', ''),
    ('telegram_chat_id', ''),
    ('webhook_enabled', '0'),
    ('backtesting_enabled', '1'),
    ('backtesting_default_source', 'rate_history'),
    ('backtesting_metals_dev_api_key', ''),
    ('backtesting_exchangerate_host_api_key', ''),
    ('leverage_weekly_report_enabled', '0'),
    ('leverage_weekly_report_day', 'monday')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Self-Healing Repair Configs & Logs
-- Migration 007: Add repair_configs and repair_logs tables

CREATE TABLE IF NOT EXISTS `repair_configs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id` INT UNSIGNED NOT NULL,
    `xpath_rows` VARCHAR(500) NOT NULL,
    `columns` JSON NOT NULL COMMENT 'Column index/selector mapping',
    `currency_map` JSON NOT NULL COMMENT 'Local name to ISO code mapping',
    `number_format` VARCHAR(20) NOT NULL DEFAULT 'turkish',
    `skip_header_rows` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `table_hash` VARCHAR(64) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `github_issue_url` VARCHAR(500) DEFAULT NULL,
    `github_commit_sha` VARCHAR(64) DEFAULT NULL,
    `deactivated_at` DATETIME DEFAULT NULL,
    `deactivation_reason` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_active` (`bank_id`, `is_active`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `bank_id` INT UNSIGNED NOT NULL,
    `step` VARCHAR(50) NOT NULL COMMENT 'Pipeline step name',
    `status` VARCHAR(20) NOT NULL COMMENT 'success, error, skipped',
    `message` TEXT DEFAULT NULL,
    `duration_ms` INT UNSIGNED DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bank_created` (`bank_id`, `created_at`),
    INDEX `idx_step_status` (`step`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

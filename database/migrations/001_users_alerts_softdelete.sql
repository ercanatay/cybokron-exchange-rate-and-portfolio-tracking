-- Migration: users, alerts, portfolio user_id & soft delete
-- Run: php database/migrate.php (preferred) or mysql < this file

USE `cybokron`;

-- Users table (RBAC-lite)
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

-- Alerts table (user_id nullable for anonymous alerts)
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

-- Portfolio: add user_id, deleted_at (run migrate.php to add columns safely)

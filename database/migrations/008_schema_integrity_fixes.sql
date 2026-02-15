-- 008: Schema integrity fixes (idempotent)
-- Remove redundant indexes, add missing FKs and indexes

-- Drop redundant indexes only if they exist
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_username');
SET @sql = IF(@idx_exists > 0, 'DROP INDEX `idx_username` ON `users`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banks' AND INDEX_NAME = 'idx_slug');
SET @sql = IF(@idx_exists > 0, 'DROP INDEX `idx_slug` ON `banks`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'currencies' AND INDEX_NAME = 'idx_code');
SET @sql = IF(@idx_exists > 0, 'DROP INDEX `idx_code` ON `currencies`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rates' AND INDEX_NAME = 'idx_bank_currency');
SET @sql = IF(@idx_exists > 0, 'DROP INDEX `idx_bank_currency` ON `rates`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add missing user_id index on alerts (if not exists)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND INDEX_NAME = 'idx_alerts_user');
SET @sql = IF(@idx_exists = 0, 'CREATE INDEX `idx_alerts_user` ON `alerts` (`user_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK on portfolio_tags.user_id (if not exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_tags' AND CONSTRAINT_NAME = 'fk_tags_user');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `portfolio_tags` ADD CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK on portfolio_goals.user_id (if not exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_goals' AND CONSTRAINT_NAME = 'fk_goals_user');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `portfolio_goals` ADD CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK on repair_configs.bank_id (if not exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_configs' AND CONSTRAINT_NAME = 'fk_repair_configs_bank');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `repair_configs` ADD CONSTRAINT `fk_repair_configs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK on repair_logs.bank_id (if not exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_logs' AND CONSTRAINT_NAME = 'fk_repair_logs_bank');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE `repair_logs` ADD CONSTRAINT `fk_repair_logs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

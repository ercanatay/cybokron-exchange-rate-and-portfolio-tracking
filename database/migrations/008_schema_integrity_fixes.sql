-- 008: Schema integrity fixes
-- Remove redundant indexes, add missing FKs and indexes

-- Drop redundant indexes (already covered by UNIQUE keys)
DROP INDEX `idx_username` ON `users`;
DROP INDEX `idx_slug` ON `banks`;
DROP INDEX `idx_code` ON `currencies`;
DROP INDEX `idx_bank_currency` ON `rates`;

-- Add missing user_id index on alerts
CREATE INDEX `idx_alerts_user` ON `alerts` (`user_id`);

-- Add FK on portfolio_tags.user_id
ALTER TABLE `portfolio_tags`
    ADD CONSTRAINT `fk_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Add FK on portfolio_goals.user_id
ALTER TABLE `portfolio_goals`
    ADD CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Add FK on repair_configs.bank_id
ALTER TABLE `repair_configs`
    ADD CONSTRAINT `fk_repair_configs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE;

-- Add FK on repair_logs.bank_id
ALTER TABLE `repair_logs`
    ADD CONSTRAINT `fk_repair_logs_bank` FOREIGN KEY (`bank_id`) REFERENCES `banks` (`id`) ON DELETE CASCADE;

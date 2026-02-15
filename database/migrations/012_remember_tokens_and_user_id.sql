-- Migration 012: Remember Me tokens table + portfolio user_id NOT NULL
-- Cybokron Exchange Rate & Portfolio Tracking

-- ─── Remember Tokens ────────────────────────────────────────────────────────

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

-- ─── Portfolio user_id NOT NULL ─────────────────────────────────────────────
-- Assign orphaned rows to admin user before making column NOT NULL

SET @admin_id = (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1);

-- Only proceed if admin user exists
SET @do_migrate = IF(@admin_id IS NOT NULL, 1, 0);

-- Update NULL user_id rows in portfolio
SET @sql1 = IF(@do_migrate, CONCAT('UPDATE portfolio SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Update NULL user_id rows in portfolio_groups
SET @sql2 = IF(@do_migrate, CONCAT('UPDATE portfolio_groups SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Update NULL user_id rows in portfolio_tags
SET @sql3 = IF(@do_migrate, CONCAT('UPDATE portfolio_tags SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Update NULL user_id rows in portfolio_goals
SET @sql4 = IF(@do_migrate, CONCAT('UPDATE portfolio_goals SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- Now make user_id NOT NULL on portfolio (idempotent: check if already NOT NULL)
SET @is_nullable = (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portfolio'
      AND COLUMN_NAME = 'user_id'
);
SET @sql5 = IF(@is_nullable = 'YES' AND @do_migrate,
    'ALTER TABLE portfolio MODIFY COLUMN user_id int unsigned NOT NULL',
    'SELECT 1');
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- Update FK to CASCADE instead of SET NULL (since user_id is now NOT NULL)
SET @has_old_fk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portfolio'
      AND CONSTRAINT_NAME = 'portfolio_ibfk_1'
);
SET @sql6 = IF(@has_old_fk > 0 AND @do_migrate,
    'ALTER TABLE portfolio DROP FOREIGN KEY portfolio_ibfk_1, ADD CONSTRAINT portfolio_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

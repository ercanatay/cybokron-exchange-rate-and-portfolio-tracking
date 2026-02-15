-- Migration 012: Remember Me tokens table + all portfolio user_id NOT NULL
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

-- ─── All portfolio tables: user_id NOT NULL ─────────────────────────────────
-- Assign orphaned NULL rows to admin user before making columns NOT NULL

SET @admin_id = (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1);
SET @do_migrate = IF(@admin_id IS NOT NULL, 1, 0);

-- ── portfolio ───────────────────────────────────────────────────────────────

SET @sql1 = IF(@do_migrate, CONCAT('UPDATE portfolio SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @is_nullable_p = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio' AND COLUMN_NAME = 'user_id');
SET @sql_p = IF(@is_nullable_p = 'YES' AND @do_migrate, 'ALTER TABLE portfolio MODIFY COLUMN user_id int unsigned NOT NULL', 'SELECT 1');
PREPARE stmt_p FROM @sql_p;
EXECUTE stmt_p;
DEALLOCATE PREPARE stmt_p;

-- Update FK to CASCADE
SET @has_fk_p = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio' AND CONSTRAINT_NAME = 'portfolio_ibfk_1');
SET @sql_fk_p = IF(@has_fk_p > 0 AND @do_migrate, 'ALTER TABLE portfolio DROP FOREIGN KEY portfolio_ibfk_1, ADD CONSTRAINT portfolio_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt_fk_p FROM @sql_fk_p;
EXECUTE stmt_fk_p;
DEALLOCATE PREPARE stmt_fk_p;

-- ── portfolio_groups ────────────────────────────────────────────────────────

SET @sql2 = IF(@do_migrate, CONCAT('UPDATE portfolio_groups SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @is_nullable_g = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_groups' AND COLUMN_NAME = 'user_id');
SET @sql_g = IF(@is_nullable_g = 'YES' AND @do_migrate, 'ALTER TABLE portfolio_groups MODIFY COLUMN user_id int unsigned NOT NULL', 'SELECT 1');
PREPARE stmt_g FROM @sql_g;
EXECUTE stmt_g;
DEALLOCATE PREPARE stmt_g;

-- ── portfolio_tags ──────────────────────────────────────────────────────────

SET @sql3 = IF(@do_migrate, CONCAT('UPDATE portfolio_tags SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @is_nullable_t = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_tags' AND COLUMN_NAME = 'user_id');
SET @sql_t = IF(@is_nullable_t = 'YES' AND @do_migrate, 'ALTER TABLE portfolio_tags MODIFY COLUMN user_id int unsigned NOT NULL', 'SELECT 1');
PREPARE stmt_t FROM @sql_t;
EXECUTE stmt_t;
DEALLOCATE PREPARE stmt_t;

-- ── portfolio_goals ─────────────────────────────────────────────────────────

SET @sql4 = IF(@do_migrate, CONCAT('UPDATE portfolio_goals SET user_id = ', @admin_id, ' WHERE user_id IS NULL'), 'SELECT 1');
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

SET @is_nullable_gl = (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio_goals' AND COLUMN_NAME = 'user_id');
SET @sql_gl = IF(@is_nullable_gl = 'YES' AND @do_migrate, 'ALTER TABLE portfolio_goals MODIFY COLUMN user_id int unsigned NOT NULL', 'SELECT 1');
PREPARE stmt_gl FROM @sql_gl;
EXECUTE stmt_gl;
DEALLOCATE PREPARE stmt_gl;

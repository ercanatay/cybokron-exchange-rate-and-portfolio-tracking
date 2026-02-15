-- 010: Security hardening
-- FK constraint for alerts.user_id and composite index for portfolio

-- alerts.user_id FK (skip if already exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'alerts' AND CONSTRAINT_NAME = 'fk_alerts_user');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE alerts ADD CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Composite index on portfolio (user_id, deleted_at) for ownership queries
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio' AND INDEX_NAME = 'idx_portfolio_user_deleted');
SET @sql2 = IF(@idx_exists = 0,
    'CREATE INDEX idx_portfolio_user_deleted ON portfolio (user_id, deleted_at)',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

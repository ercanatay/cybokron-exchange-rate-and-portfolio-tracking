-- Portfolio Groups table and group_id column on portfolio
-- Adds group/folder support for organizing portfolio items

CREATE TABLE IF NOT EXISTS `portfolio_groups` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned DEFAULT NULL,
    `name` varchar(100) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `color` varchar(7) NOT NULL DEFAULT '#3b82f6',
    `icon` varchar(10) DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_slug_user` (`slug`, `user_id`),
    KEY `idx_group_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add group_id column to portfolio if not exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portfolio' AND COLUMN_NAME = 'group_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `portfolio` ADD COLUMN `group_id` int unsigned DEFAULT NULL AFTER `notes`, ADD KEY `idx_portfolio_group` (`group_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

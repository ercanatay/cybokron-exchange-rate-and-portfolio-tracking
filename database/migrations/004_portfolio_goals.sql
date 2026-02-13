-- Portfolio Goals
-- Target-based tracking with progress bars
-- Goals can reference groups, tags, or individual portfolio items
-- Deduplication: items are counted once even if matched by multiple sources

CREATE TABLE IF NOT EXISTS `portfolio_goals` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned DEFAULT NULL,
    `name` varchar(100) NOT NULL,
    `target_value` decimal(18,2) NOT NULL COMMENT 'Target value in TRY',
    `target_type` enum('value','cost') NOT NULL DEFAULT 'value' COMMENT 'Track current value or total cost',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio_goals_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goal sources: which groups, tags, or individual items belong to a goal
CREATE TABLE IF NOT EXISTS `portfolio_goal_sources` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `goal_id` int unsigned NOT NULL,
    `source_type` enum('group','tag','item') NOT NULL,
    `source_id` int unsigned NOT NULL COMMENT 'group_id, tag_id, or portfolio item id',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_goal_source_unique` (`goal_id`, `source_type`, `source_id`),
    CONSTRAINT `fk_goal_source_goal` FOREIGN KEY (`goal_id`) REFERENCES `portfolio_goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

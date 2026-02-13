-- Portfolio Tags & Tag-Items (many-to-many)
-- Adds tag/label support for portfolio items

CREATE TABLE IF NOT EXISTS `portfolio_tags` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned DEFAULT NULL,
    `name` varchar(50) NOT NULL,
    `slug` varchar(100) NOT NULL,
    `color` varchar(7) NOT NULL DEFAULT '#8b5cf6',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio_tags_user` (`user_id`),
    KEY `idx_portfolio_tags_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portfolio_tag_items` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `portfolio_id` int unsigned NOT NULL,
    `tag_id` int unsigned NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_portfolio_tag_unique` (`portfolio_id`, `tag_id`),
    KEY `idx_portfolio_tag_items_tag` (`tag_id`),
    CONSTRAINT `fk_pti_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolio` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pti_tag` FOREIGN KEY (`tag_id`) REFERENCES `portfolio_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

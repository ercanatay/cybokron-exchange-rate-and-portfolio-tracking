-- =============================================
-- Migration: Add Homepage Visibility Control
-- Date: 2026-02-13
-- Description: Add show_on_homepage column to rates table
-- =============================================

USE `cyb_exchange`;

-- Add show_on_homepage column to rates table
ALTER TABLE `rates`
ADD COLUMN `show_on_homepage` TINYINT(1) DEFAULT 1 COMMENT 'Show this rate on homepage' AFTER `change_percent`;

-- Add index for homepage filtering
ALTER TABLE `rates`
ADD INDEX `idx_homepage` (`show_on_homepage`);

-- Set default: Show first 10 rates on homepage (USD, EUR, GBP from each bank)
UPDATE `rates` r
JOIN `currencies` c ON c.id = r.currency_id
SET r.show_on_homepage = CASE
    WHEN c.code IN ('USD', 'EUR', 'GBP', 'CHF', 'XAU') THEN 1
    ELSE 0
END;

-- =============================================

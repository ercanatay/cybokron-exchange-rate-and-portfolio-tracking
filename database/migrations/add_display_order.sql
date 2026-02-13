-- Migration: Add display_order column to rates table
-- Date: 2026-02-13
-- Description: Adds display_order column for custom sorting of rates on homepage

ALTER TABLE `rates` 
ADD COLUMN `display_order` INT UNSIGNED DEFAULT 0 COMMENT 'Custom display order (0 = default order)' AFTER `show_on_homepage`,
ADD INDEX `idx_display_order` (`display_order`);

-- Set initial display_order based on current order (bank_id, currency_code)
UPDATE `rates` r
JOIN (
    SELECT 
        r2.id,
        @row_number:=@row_number + 1 AS new_order
    FROM `rates` r2
    JOIN `currencies` c ON c.id = r2.currency_id
    JOIN `banks` b ON b.id = r2.bank_id
    CROSS JOIN (SELECT @row_number := 0) AS init
    ORDER BY b.name, c.code
) AS ordered ON ordered.id = r.id
SET r.display_order = ordered.new_order;

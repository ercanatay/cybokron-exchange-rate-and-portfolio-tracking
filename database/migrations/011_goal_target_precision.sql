-- 011: Align goal target_value precision with other decimal columns (18,6)
ALTER TABLE `portfolio_goals` MODIFY COLUMN `target_value` DECIMAL(18,6) NOT NULL COMMENT 'Target value in TRY';

-- Enhance portfolio_goals: add 'amount' target type and target_currency
-- Allows goals like "accumulate 10 XAU" or "save 5000 USD"

ALTER TABLE `portfolio_goals`
  MODIFY COLUMN `target_type` varchar(20) NOT NULL DEFAULT 'value' COMMENT 'value=TRY value, cost=TRY cost, amount=currency amount',
  ADD COLUMN `target_currency` varchar(10) DEFAULT NULL COMMENT 'Required when target_type=amount, e.g. XAU, USD' AFTER `target_type`;

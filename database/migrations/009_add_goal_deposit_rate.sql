ALTER TABLE portfolio_goals ADD COLUMN deposit_rate DECIMAL(5,2) DEFAULT NULL COMMENT 'Per-goal deposit interest rate override (NULL = use admin default)' AFTER goal_deadline;

-- Add goal_deadline column for goal target dates
ALTER TABLE portfolio_goals
  ADD COLUMN goal_deadline DATE DEFAULT NULL AFTER percent_period_months;

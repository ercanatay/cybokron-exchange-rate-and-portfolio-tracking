-- Add percent goal support: date mode, date range, and period fields.
ALTER TABLE portfolio_goals
  ADD COLUMN percent_date_mode ENUM('all','range','since_first','weighted') DEFAULT NULL
    COMMENT 'Date mode for percent goals' AFTER bank_slug,
  ADD COLUMN percent_date_start DATE DEFAULT NULL
    COMMENT 'Start date for range mode' AFTER percent_date_mode,
  ADD COLUMN percent_date_end DATE DEFAULT NULL
    COMMENT 'End date for range mode' AFTER percent_date_start,
  ADD COLUMN percent_period_months INT UNSIGNED DEFAULT 12
    COMMENT 'Period in months for since_first mode' AFTER percent_date_end;

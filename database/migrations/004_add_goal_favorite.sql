ALTER TABLE portfolio_goals
  ADD COLUMN is_favorite TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Whether this goal is marked as favorite'
    AFTER bank_slug;

CREATE INDEX idx_portfolio_goals_favorite ON portfolio_goals (user_id, is_favorite);

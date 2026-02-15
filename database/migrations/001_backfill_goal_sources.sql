-- Backfill portfolio_goal_sources for goals that have no sources.
-- Links all source-less goals to the first portfolio group (dynamic lookup).
INSERT INTO portfolio_goal_sources (goal_id, source_type, source_id)
SELECT g.id, 'group', pg.id
FROM portfolio_goals g
LEFT JOIN portfolio_goal_sources gs ON gs.goal_id = g.id
CROSS JOIN (SELECT id FROM portfolio_groups ORDER BY id LIMIT 1) pg
WHERE gs.id IS NULL;

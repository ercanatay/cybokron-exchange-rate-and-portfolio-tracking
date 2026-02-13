-- Backfill portfolio_goal_sources for goals that have no sources.
-- Links all source-less goals to group_id=1 (Altın) which is the primary portfolio group.
INSERT INTO portfolio_goal_sources (goal_id, source_type, source_id)
SELECT g.id, 'group', 1
FROM portfolio_goals g
LEFT JOIN portfolio_goal_sources gs ON gs.goal_id = g.id
WHERE gs.id IS NULL;

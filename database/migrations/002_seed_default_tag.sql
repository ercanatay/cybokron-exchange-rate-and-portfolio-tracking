-- Seed default "Altın" tag if no tags exist yet.
-- Uses dynamic user_id lookup instead of hardcoded ID.
INSERT INTO portfolio_tags (user_id, name, slug, color)
SELECT u.id, 'Altın', 'altin', '#8b5cf6'
FROM (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1) u
WHERE NOT EXISTS (SELECT 1 FROM portfolio_tags WHERE slug = 'altin');

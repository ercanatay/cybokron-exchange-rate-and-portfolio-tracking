-- Seed default "Altın" tag if no tags exist yet.
INSERT INTO portfolio_tags (user_id, name, slug, color)
SELECT 1, 'Altın', 'altin', '#8b5cf6'
FROM dual
WHERE NOT EXISTS (SELECT 1 FROM portfolio_tags WHERE slug = 'altin');

-- Seed portfolio data from local ServBay database
-- This migration inserts portfolio groups, items, and goals

-- Portfolio Groups
INSERT IGNORE INTO portfolio_groups (id, user_id, name, slug, color, icon, created_at) VALUES
(1, 1, 'Altın', 'altin', '#f2cb07', '🧈', '2026-02-13 11:07:05'),
(2, 1, 'Gümüş', 'gumus', '#a6a6a6', '⚪', '2026-02-13 11:26:36'),
(3, 1, 'Platin', 'platin', '#a6a6a6', '🔘', '2026-02-13 11:27:55');

-- Portfolio Items (using subqueries to resolve currency_id and bank_id)
INSERT IGNORE INTO portfolio (id, user_id, currency_id, bank_id, amount, buy_rate, buy_date, notes, group_id, created_at) VALUES
(1, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 10.000000, 6402.000000, '2026-01-14', 'Altın Mehir', 1, NOW()),
(2, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 2.390000, 6407.000000, '2026-01-14', 'Yüzük', 1, NOW()),
(4, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 5.880000, 6809.000000, '2026-02-03', 'Standart Birikim Altın', 1, NOW()),
(5, 1, (SELECT id FROM currencies WHERE code='XPT'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.590000, 3143.800000, '2026-02-03', 'Standart Birikim', 3, NOW()),
(6, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 1.900000, 6867.590000, '2026-02-05', 'Standart Birikim', 1, NOW()),
(7, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 17.690000, 7021.000000, '2026-02-10', 'Standart Birikim', 1, NOW()),
(8, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 2.940000, 7025.290000, '2026-02-10', 'Standart Birikim', 1, NOW()),
(9, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='is-bankasi'), 10.580000, 7061.000000, '2026-02-11', 'Standart Birikim', 1, NOW()),
(10, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 3.510000, 7068.000000, '2026-02-12', 'Standart Birikim', 1, NOW()),
(11, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.230000, 6462.300000, '2026-01-19', 'iPhone', 1, NOW()),
(13, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 1.040000, 6725.890000, '2026-01-21', 'iPhone', 1, NOW()),
(14, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='tcmb'), 0.230000, 6462.300000, '2026-02-19', 'iPhone', 1, NOW()),
(16, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.040000, 6467.000000, '2026-02-02', 'iPhone', 1, NOW()),
(17, 1, (SELECT id FROM currencies WHERE code='XAU'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 2.390000, 6431.770000, '2026-02-02', 'iPhone', 1, NOW()),
(18, 1, (SELECT id FROM currencies WHERE code='XAG'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 66.410000, 120.420000, '2026-02-03', 'Standart Birikim', 2, NOW()),
(19, 1, (SELECT id FROM currencies WHERE code='XAG'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.800000, 114.510000, '2026-02-10', 'Standart Birikim', 2, NOW()),
(20, 1, (SELECT id FROM currencies WHERE code='XAG'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.250000, 114.510000, '2026-02-10', 'Standart Birikim', 2, NOW()),
(21, 1, (SELECT id FROM currencies WHERE code='XAG'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.030000, 114.800000, '2026-02-10', 'Standart Birikim', 2, NOW()),
(22, 1, (SELECT id FROM currencies WHERE code='XAG'), (SELECT id FROM banks WHERE slug='dunya-katilim'), 0.140000, 116.390000, '2026-02-12', 'Standart Birikim', 2, NOW());

-- Portfolio Goals
INSERT IGNORE INTO portfolio_goals (id, user_id, name, target_value, target_type, target_currency, bank_slug, created_at) VALUES
(1, 1, 'Altın Hedefim', 500000.00, 'value', NULL, NULL, '2026-02-13 13:09:49'),
(2, 1, 'XAU Biriktirme Hedefi', 60.00, 'amount', 'XAU', NULL, '2026-02-13 13:53:04'),
(3, 1, 'İş Bankası XAU Hedefi', 50.00, 'amount', 'XAU', 'is-bankasi', '2026-02-13 14:24:11'),
(4, 1, 'Altınlarım 3000 USD Etsin', 3000.00, 'currency_value', 'USD', NULL, '2026-02-13 14:52:43');

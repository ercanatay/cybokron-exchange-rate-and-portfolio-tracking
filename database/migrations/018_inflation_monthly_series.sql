-- 018_inflation_monthly_series.sql
-- Enflasyon korumali hedef artik tek bir yillik oranla degil, aylik gerceklesen
-- enflasyon serisiyle hesaplanir: her kalem alis gununden bugune, gectigi her ayin
-- aylik oraniyla gun bazinda bilesik buyutulur. ENAG ve TUIK ayri seriler olarak tutulur.
-- Yillik oran ayari (inflation_annual_rate) seri bossa yedek olarak kalir.

CREATE TABLE IF NOT EXISTS `inflation_monthly` (
    `source` enum('enag','tuik') NOT NULL,
    `period` char(7) NOT NULL COMMENT 'YYYY-MM',
    `rate_percent` decimal(7,2) NOT NULL COMMENT 'Aylik degisim yuzdesi, orn. 2.10',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`source`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aciklanan aylik veriler, Aralik 2025 - Eylul 2026.
-- ENAG: E-TUFE aylik degisim. TUIK: TUFE aylik degisim.
-- INSERT IGNORE: admin panelinden sonradan duzeltilen degerleri ezmez.
INSERT IGNORE INTO `inflation_monthly` (`source`, `period`, `rate_percent`) VALUES
    ('enag', '2025-12', 2.11),
    ('enag', '2026-01', 6.32),
    ('enag', '2026-02', 4.01),
    ('enag', '2026-03', 4.10),
    ('enag', '2026-04', 5.07),
    ('enag', '2026-05', 2.16),
    ('enag', '2026-06', 1.94),
    ('enag', '2026-07', 3.07),
    ('enag', '2026-08', 2.24),
    ('enag', '2026-09', 2.10),
    ('tuik', '2025-12', 0.89),
    ('tuik', '2026-01', 4.84),
    ('tuik', '2026-02', 2.96),
    ('tuik', '2026-03', 1.94),
    ('tuik', '2026-04', 4.18),
    ('tuik', '2026-05', 1.71),
    ('tuik', '2026-06', 0.99),
    ('tuik', '2026-07', 1.78),
    ('tuik', '2026-08', 1.84),
    ('tuik', '2026-09', 1.84);

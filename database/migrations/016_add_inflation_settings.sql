-- 016_add_inflation_settings.sql
-- Enflasyon korumali hedef ozelligi: ENAG yillik enflasyon orani ayarlari.
-- settings key-value tablosuna eklenir. INSERT IGNORE: key PRIMARY KEY, tekrar calismada atlar.
-- Varsayilan deger son bilinen ENAG yillik orani; kaynak manual, kilit acik (otomatik scrape ezmez).
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('inflation_annual_rate', '53.13'),
    ('inflation_source', 'manual'),
    ('inflation_locked', '1');

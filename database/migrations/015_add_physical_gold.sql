-- 015_add_physical_gold.sql
-- Fiziki altın takibi: Harem altin.doviz.com kaynağından gram altın ve 22 ayar bilezik.
-- XAU yani ons saf altın ile takip yanıltıcıydı; fiziki ürünler gram bazlı TL fiyatla takip edilir.

-- 1. currencies.type ENUM tipine 'physical_gold' değeri ekle, mevcut değerler korunur.
ALTER TABLE currencies
    MODIFY COLUMN `type` enum('fiat','precious_metal','crypto','physical_gold') DEFAULT 'fiat';

-- 2. Yeni para birimleri, gram bazlı fiziki altın. INSERT IGNORE: code UNIQUE, tekrar çalışmada atlar.
INSERT IGNORE INTO currencies (`code`, `name_tr`, `name_en`, `symbol`, `type`, `decimal_places`, `is_active`) VALUES
    ('GRAMALTIN', 'Gram Altın Fiziki', 'Physical Gram Gold', 'gr', 'physical_gold', 4, 1),
    ('BILEZIK22', '22 Ayar Bilezik',   '22K Gold Bracelet', 'gr', 'physical_gold', 4, 1);

-- 3. Harem fiziki altın kaynağı. Tek sayfa hem gram altın hem 22 ayar bilezik fiyatını içerir.
--    INSERT IGNORE: slug UNIQUE, tekrar çalışmada atlar.
INSERT IGNORE INTO banks (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
    ('Harem Fiziki Altın', 'harem-fiziki', 'https://altin.doviz.com/harem/gram-altin', 'HaremAltinScraper', 1);

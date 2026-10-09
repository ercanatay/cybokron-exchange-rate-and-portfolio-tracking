-- 017_albaraka_direct_scraper.sql
-- Albaraka Türk: kur.doviz.com aracısı yerine bankanın kendi sayfasından doğrudan çekim.
-- Satır 005'te DovizComScraper ile pasif olarak eklenmişti; slug UNIQUE olduğundan
-- INSERT IGNORE etkisiz kalır, bu yüzden mevcut satır güncellenir ve aktifleştirilir.
-- table_hash sıfırlanır: eski hash doviz.com tablosuna aitti, ilk çekimde sahte "tablo değişti" uyarısı vermesin.

UPDATE banks
SET `url` = 'https://www.albaraka.com.tr/tr/doviz-kurlari',
    `scraper_class` = 'AlbarakaTurk',
    `is_active` = 1,
    `table_hash` = NULL
WHERE `slug` = 'albaraka-turk';

-- Eski kurulumlarda 005 hiç uygulanmadıysa satırı oluştur.
INSERT IGNORE INTO banks (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
    ('Albaraka Türk', 'albaraka-turk', 'https://www.albaraka.com.tr/tr/doviz-kurlari', 'AlbarakaTurk', 1);

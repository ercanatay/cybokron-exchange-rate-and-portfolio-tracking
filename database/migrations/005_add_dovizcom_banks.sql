-- Migration: Add kur.doviz.com banks and update IsBank scraper_class
-- All new banks default to is_active = 0 (admin enables manually)

-- Update existing İş Bankası to use the generic scraper
UPDATE banks SET scraper_class = 'DovizComScraper' WHERE slug = 'is-bankasi';

-- Insert new banks (all inactive by default)
INSERT IGNORE INTO `banks` (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
('Akbank', 'akbank', 'https://kur.doviz.com/akbank', 'DovizComScraper', 0),
('Albaraka Türk', 'albaraka-turk', 'https://kur.doviz.com/albaraka-turk', 'DovizComScraper', 0),
('Alternatif Bank', 'alternatif-bank', 'https://kur.doviz.com/alternatif-bank', 'DovizComScraper', 0),
('Altınkaynak', 'altinkaynak', 'https://kur.doviz.com/altinkaynak', 'DovizComScraper', 0),
('Anadolubank', 'anadolubank', 'https://kur.doviz.com/anadolubank', 'DovizComScraper', 0),
('CEPTETEB', 'cepteteb', 'https://kur.doviz.com/cepteteb', 'DovizComScraper', 0),
('Denizbank', 'denizbank', 'https://kur.doviz.com/denizbank', 'DovizComScraper', 0),
('DestekBank', 'destekbank', 'https://kur.doviz.com/destekbank', 'DovizComScraper', 0),
('Emlak Katılım', 'emlak-katilim', 'https://kur.doviz.com/emlak-katilim', 'DovizComScraper', 0),
('Enpara', 'enpara', 'https://kur.doviz.com/enpara', 'DovizComScraper', 0),
('Fibabanka', 'fibabanka', 'https://kur.doviz.com/fibabanka', 'DovizComScraper', 0),
('Garanti BBVA', 'garanti-bbva', 'https://kur.doviz.com/garanti-bbva', 'DovizComScraper', 0),
('Getirfinans', 'getirfinans', 'https://kur.doviz.com/getirfinans', 'DovizComScraper', 0),
('Hadi / TOMBank', 'hadi', 'https://kur.doviz.com/hadi', 'DovizComScraper', 0),
('Halkbank', 'halkbank', 'https://kur.doviz.com/halkbank', 'DovizComScraper', 0),
('Harem', 'harem', 'https://kur.doviz.com/harem', 'DovizComScraper', 0),
('Hayat Finans', 'hayat-finans', 'https://kur.doviz.com/hayat-finans', 'DovizComScraper', 0),
('Hepsipay', 'hepsipay', 'https://kur.doviz.com/hepsipay', 'DovizComScraper', 0),
('HSBC', 'hsbc', 'https://kur.doviz.com/hsbc', 'DovizComScraper', 0),
('ING Bank', 'ing-bank', 'https://kur.doviz.com/ing-bank', 'DovizComScraper', 0),
('Kapalıçarşı', 'kapalicarsi', 'https://kur.doviz.com/kapalicarsi', 'DovizComScraper', 0),
('Kuveyt Türk', 'kuveyt-turk', 'https://kur.doviz.com/kuveyt-turk', 'DovizComScraper', 0),
('Misyon Bank', 'misyon-bank', 'https://kur.doviz.com/misyon-bank', 'DovizComScraper', 0),
('Odacı', 'odaci', 'https://kur.doviz.com/odaci', 'DovizComScraper', 0),
('Odeabank', 'odeabank', 'https://kur.doviz.com/odeabank', 'DovizComScraper', 0),
('Papara', 'papara', 'https://kur.doviz.com/papara', 'DovizComScraper', 0),
('QNB Finansbank', 'qnb-finansbank', 'https://kur.doviz.com/qnb-finansbank', 'DovizComScraper', 0),
('Şekerbank', 'sekerbank', 'https://kur.doviz.com/sekerbank', 'DovizComScraper', 0),
('Türkiye Finans', 'turkiye-finans', 'https://kur.doviz.com/turkiye-finans', 'DovizComScraper', 0),
('Vakıf Katılım', 'vakif-katilim', 'https://kur.doviz.com/vakif-katilim', 'DovizComScraper', 0),
('Vakıfbank', 'vakifbank', 'https://kur.doviz.com/vakifbank', 'DovizComScraper', 0),
('Venüs', 'venus', 'https://kur.doviz.com/venus', 'DovizComScraper', 0),
('Yapıkredi', 'yapikredi', 'https://kur.doviz.com/yapikredi', 'DovizComScraper', 0),
('Ziraat Bankası', 'ziraat-bankasi', 'https://kur.doviz.com/ziraat-bankasi', 'DovizComScraper', 0),
('Ziraat Katılım', 'ziraat-katilim', 'https://kur.doviz.com/ziraat-katilim', 'DovizComScraper', 0);

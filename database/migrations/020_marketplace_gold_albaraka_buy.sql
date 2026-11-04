-- 020_marketplace_gold_albaraka_buy.sql
-- idefix SATIŞ + Albaraka ALIŞ: 019 ile eklenen idefix kaynağının ikinci ALIŞ seçeneği.
-- SATIŞ yine Ahlatcı idefix ürün fiyatı; ALIŞ = Albaraka gram altın alışı x Albaraka altın günü
-- dönüşüm katsayısı (bilezik 45.49/50.02, gram altın 0.99; MarketplaceGoldScraper).
-- Harem ALIŞ seçeneği (idefix-ahlatci) aynen kalır; portföy satırı banka alanıyla birini seçer.
-- url = ilk ürün sayfası (bilezik); scraper diğer ürünü kendisi çeker.
-- INSERT IGNORE: slug UNIQUE, tekrar çalışmada atlar.

INSERT IGNORE INTO banks (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
    ('İdefix SATIŞ + Albaraka ALIŞ', 'idefix-ahlatci-albaraka', 'https://www.idefix.com/10-gr-22-ayar-3-mm-kibrit-copu-p-6555382?vendorId=5397', 'MarketplaceGoldScraper', 1);

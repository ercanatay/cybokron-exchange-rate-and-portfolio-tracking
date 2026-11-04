-- 019_marketplace_gold_sources.sql
-- Pazaryeri fiziki altın kaynakları: Ahlatcı mağazasının idefix ve n11 ürün sayfaları.
-- Çift kaynaklı: SATIŞ = mağaza ürün fiyatı ÷ gram, ALIŞ = Harem alış fiyatı (MarketplaceGoldScraper).
-- Portföy satırı mağazayı banka alanıyla seçer; sonradan düzenleme formundan değiştirilebilir.
--
-- n11 pasif eklenir: n11 sunucunun IP'sine Cloudflare 403 döndürüyor, bu yüzden aktif olsa da
-- hiç kur yazamaz. Erişim açılırsa is_active = 1 yapmak yeterli.
-- url = ilk ürün sayfası (bilezik); scraper diğer ürünü kendisi çeker.
-- INSERT IGNORE: slug UNIQUE, tekrar çalışmada atlar.

INSERT IGNORE INTO banks (`name`, `slug`, `url`, `scraper_class`, `is_active`) VALUES
    ('İdefix Ahlatcı Fiziki Altın', 'idefix-ahlatci', 'https://www.idefix.com/10-gr-22-ayar-3-mm-kibrit-copu-p-6555382?vendorId=5397', 'MarketplaceGoldScraper', 1),
    ('n11 Ahlatcı Fiziki Altın',    'n11-ahlatci',    'https://www.n11.com/urun/ahlatci-10-gr-22-ayar-3-mm-kibrit-copu-bilezik-19291978?magaza=ahlatcikuyumculuk', 'MarketplaceGoldScraper', 0);

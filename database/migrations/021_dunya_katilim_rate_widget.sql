-- 021_dunya_katilim_rate_widget.sql
-- Dünya Katılım: /gunluk-kurlar sayfası 2026-10-21 civarında kur tablosunu HTML içinde taşımayı bıraktı;
-- tablo artık sayfanın JavaScript ile yüklediği widget adresinden geliyor. Tablo yapısı aynı.
-- table_hash sıfırlanır: eski hash eski sayfaya aitti, ilk çekimde sahte "tablo değişti" uyarısı vermesin.

UPDATE banks
SET `url` = 'https://dunyakatilim.com.tr/RemoteWidget/currencies-subpage?pageId=2921&parentPageId=2921',
    `table_hash` = NULL
WHERE `slug` = 'dunya-katilim';

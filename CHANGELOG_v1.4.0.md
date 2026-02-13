# Changelog v1.4.0 - 13 Şubat 2026

## 🎉 Yeni Özellikler

### 1. Manuel Kur Güncelleme Butonu
- ✅ **Admin Paneli**: Sistem sağlığı bölümüne "Kurları Şimdi Güncelle" butonu eklendi
- ✅ **Anasayfa**: Banka seçici bölümüne manuel güncelleme butonu eklendi
- ✅ Buton tıklandığında `cron/update_rates.php` script'i çalıştırılır
- ✅ Başarı/hata mesajları gösterilir

### 2. İş Bankası Entegrasyonu
- ✅ Yeni banka kaynağı: İş Bankası (doviz.com'dan scraping)
- ✅ 12 döviz kuru çekiliyor: USD, EUR, GBP, CHF, CAD, AUD, DKK, SEK, NOK, JPY, KWD, SAR
- ✅ Scraper sınıfı: `banks/IsBank.php`
- ✅ Ortalama çekim süresi: ~419ms
- ✅ Toplam sistem: 3 banka, 47 kur

### 3. Anasayfa Görünürlük Yönetimi
- ✅ `rates` tablosuna `show_on_homepage` kolonu eklendi
- ✅ Admin panelinde "Anasayfa Kurları" bölümü
- ✅ Her kur için görünür/gizli toggle butonu
- ✅ Varsayılan: Tüm kurlar görünür
- ✅ `index.php` sadece görünür kurları gösterir

### 4. Banka Dropdown Seçici
- ✅ Anasayfaya banka dropdown eklendi
- ✅ "Tüm Bankalar" veya tek banka seçimi
- ✅ URL parametresi: `?bank=slug`
- ✅ Gradient mor-mavi arka plan tasarımı

### 5. Döviz Simgeleri
- ✅ 16 ülke bayrağı SVG (USD, EUR, GBP, vb.)
- ✅ Kıymetli madenler için CSS gradient:
  - XAU (Altın): Altın sarısı gradient
  - XAG (Gümüş): Gümüş grisi gradient
  - XPT (Platin): Platin beyazı gradient
  - XPD (Paladyum): Paladyum grisi gradient
- ✅ 32px yuvarlak simgeler
- ✅ `assets/css/currency-icons.css`

### 6. Varsayılan Banka Ayarı
- ✅ Admin panelinde "Varsayılan Banka Ayarı" bölümü
- ✅ Anasayfa için varsayılan banka seçimi
- ✅ `settings` tablosunda `default_bank` kaydı
- ✅ URL parametresi her zaman öncelikli

### 7. 🆕 Kur Sıralama (Drag & Drop)
- ✅ Admin panelinde kurların sırasını sürükle-bırak ile değiştirme
- ✅ `rates` tablosuna `display_order` kolonu eklendi
- ✅ Native JavaScript drag & drop API kullanımı
- ✅ "Sıralamayı Kaydet" butonu ile kaydetme
- ✅ Anasayfa ve tüm listelerde özel sıralama uygulanır
- ✅ Görsel ipucu: ⋮⋮ drag handle ikonu

### 8. 🆕 Kur Grafiği Varsayılan Ayarları
- ✅ Admin panelinde "Kur Grafiği Varsayılan Ayarları" bölümü
- ✅ Varsayılan döviz seçimi (örn: USD, EUR, XAU)
- ✅ Varsayılan dönem seçimi (7, 30, 90, 180, 365 gün)
- ✅ `settings` tablosunda `chart_default_currency` ve `chart_default_days`
- ✅ Anasayfa grafiği bu ayarları kullanır
- ✅ Kullanıcı dropdown'dan değiştirebilir

## 📊 Veritabanı Değişiklikleri

### Yeni Kolonlar
```sql
-- rates tablosu
ALTER TABLE `rates` 
ADD COLUMN `show_on_homepage` TINYINT(1) DEFAULT 1 COMMENT 'Show this rate on homepage',
ADD COLUMN `display_order` INT UNSIGNED DEFAULT 0 COMMENT 'Custom display order (0 = default order)',
ADD INDEX `idx_homepage` (`show_on_homepage`),
ADD INDEX `idx_display_order` (`display_order`);
```

### Yeni Settings
```sql
INSERT INTO `settings` (`key`, value) VALUES
('default_bank', 'all'),
('chart_default_currency', 'USD'),
('chart_default_days', '30');
```

### Yeni Banka
```sql
INSERT INTO `banks` (`name`, `slug`, `url`, `scraper_class`) VALUES
('İş Bankası', 'is-bankasi', 'https://kur.doviz.com/isbankasi', 'IsBank');
```

## 🔧 Teknik Detaylar

### Dosya Değişiklikleri
- `admin.php`: Drag & drop UI, chart defaults, rate ordering
- `index.php`: Chart defaults kullanımı, manual update button
- `includes/helpers.php`: `getLatestRates()` ORDER BY güncellendi
- `banks/IsBank.php`: Yeni scraper sınıfı
- `config.php`: İş Bankası aktif bankalar listesine eklendi
- `assets/css/currency-icons.css`: Döviz simgeleri stilleri
- `locales/tr.php`: 11 yeni çeviri anahtarı
- `locales/en.php`: 11 yeni çeviri anahtarı
- `database.sql`: Schema güncellendi
- `database/migrations/add_display_order.sql`: Migration dosyası

### Yeni Çeviri Anahtarları
```
admin.chart_defaults
admin.chart_defaults_desc
admin.chart_currency
admin.chart_days
admin.chart_defaults_updated
admin.drag_drop_hint
admin.drag_drop_desc
admin.save_order
admin.rate_order_updated
```

## 🎨 UI/UX İyileştirmeleri

1. **Gradient Tasarım**: Banka seçici mor-mavi gradient arka plan
2. **Drag Handle**: ⋮⋮ ikonu ile sürüklenebilir satırlar
3. **Visual Feedback**: Sürükleme sırasında opacity değişimi
4. **Save Button**: Değişiklik yapıldığında otomatik görünür
5. **Info Box**: Mavi bilgi kutusu ile kullanım talimatları
6. **Responsive**: Tüm yeni özellikler mobil uyumlu

## 📈 Performans

- İş Bankası scraping: ~419ms
- Toplam 47 kur güncelleme: ~2-3 saniye
- Drag & drop: Native API (framework yok)
- Chart defaults: Database'den tek sorgu

## 🔒 Güvenlik

- CSRF token koruması tüm POST işlemlerinde
- Admin yetkisi kontrolü
- SQL injection koruması (prepared statements)
- XSS koruması (htmlspecialchars)

## 🐛 Düzeltilen Hatalar

- Admin.php parse error düzeltildi (duplicate code temizlendi)
- Display order migration SQL syntax hatası düzeltildi
- Chart defaults validation eklendi

## 📝 Notlar

- Tüm kurlar varsayılan olarak anasayfada görünür
- Display order başlangıçta banka adı ve döviz koduna göre sıralanır
- Chart defaults mevcut dövizler arasından seçilmelidir
- Drag & drop sadece admin panelinde aktif

## 🚀 Kullanım

### Manuel Kur Güncelleme
1. Admin paneli veya anasayfadaki "🔄 Kurları Şimdi Güncelle" butonuna tıklayın
2. Sistem tüm bankaların kurlarını çeker
3. Başarı/hata mesajı gösterilir

### Kur Sıralama
1. Admin paneline gidin
2. "Anasayfa Kurları" bölümüne inin
3. Satırları sürükleyip bırakarak sıralayın
4. "💾 Sıralamayı Kaydet" butonuna tıklayın

### Grafik Ayarları
1. Admin paneline gidin
2. "Kur Grafiği Varsayılan Ayarları" bölümünü bulun
3. Varsayılan döviz ve dönemi seçin
4. "Kaydet" butonuna tıklayın
5. Anasayfadaki grafik bu ayarları kullanır

## 🔄 Migration

Mevcut kurumdan yükseltme için:

```bash
cd cybokron-exchange-rate-and-portfolio-tracking
mysql -h 127.0.0.1 -P 3306 -u root -p***REDACTED*** cyb_exchange < database/migrations/add_display_order.sql
```

## 📦 Versiyon

- **Önceki**: v1.3.1
- **Şimdiki**: v1.4.0
- **Tarih**: 13 Şubat 2026

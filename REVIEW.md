# Cybokron Exchange Rate & Portfolio Tracking — Full Stack Developer Review

## 1. Genel Proje Değerlendirmesi

### Proje Özeti
Cybokron, Türk bankalarından döviz kurlarını otomatik çekerek gösteren ve kişisel portföy takibi yapan self-hosted bir PHP/MySQL uygulaması. Şu an sadece **Dünya Katılım Bankası** desteklenmektedir ve 14 para birimi/kıymetli maden takip edilmektedir.

### Mimari Diyagram
```
┌──────────────────────────────────────────────────────────────┐
│                     KULLANICI ARAYÜZÜ                        │
│  index.php (Kurlar)  │  portfolio.php (Portföy)  │  api.php │
└──────────┬───────────┴──────────────┬─────────────┴──────────┘
           │                          │
    ┌──────▼──────────┐     ┌────────▼──────────┐
    │  Vanilla JS     │     │  PHP Backend       │
    │  (150 satır)    │     │  helpers/Database/  │
    │  Auto-refresh   │     │  Portfolio/Scraper  │
    └─────────────────┘     └────────────────────┘
                                     │
        ┌────────────┬───────────────┼────────────────┐
        │            │               │                │
    ┌───▼────┐  ┌───▼────┐   ┌─────▼──────┐  ┌─────▼────────┐
    │Scraper │  │Portfolio│   │ Database   │  │ OpenRouter   │
    │(Banks) │  │ Class   │   │  (PDO)     │  │ AI Fallback  │
    └────────┘  └────────┘   └────────────┘  └──────────────┘
                                  │
                         ┌───────▼──────────┐
                         │  MySQL / MariaDB  │
                         │  7 Tablo          │
                         └──────────────────┘
```

---

## 2. Güçlü Yönler (Artılar)

### 2.1 Güvenlik Odaklı Tasarım
- **CSRF koruması**: Session-based token'lar ile state-değiştiren her endpoint korunmuş (`getCsrfToken()`, `verifyCsrfToken()`)
- **Input validasyonu**: Regex whitelist (`^[A-Z0-9]{3,10}$`, `^[a-z0-9-]{1,100}$`), decimal range kontrolleri
- **SQL Injection koruması**: PDO prepared statements, `assertIdentifier()` ile tablo/kolon isim kontrolü
- **Security Headers**: X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy
- **Session Hardening**: `use_strict_mode`, `use_only_cookies`, `cookie_httponly`, `cookie_samesite=Lax`
- **Log Injection önleme**: Kontrol karakterleri ve satır sonları sanitize ediliyor
- **ZIP path traversal koruması**: `..`, mutlak path, drive letter kontrolü

### 2.2 Temiz Kod Mimarisi
- **Separation of Concerns**: Scraper, Database, Portfolio, OpenRouterRateRepair sınıfları ayrı dosyalarda
- **Abstract Scraper Pattern**: Yeni banka eklemek için sadece `Scraper` sınıfını extend etmek yeterli
- **Database Wrapper**: Statement caching, transaction desteği, upsert metodu
- **i18n sistemi**: Türkçe/İngilizce çeviri, fallback mekanizması, locale-aware formatlama

### 2.3 Akıllı Scraping Mekanizması
- **Tablo yapısı değişikliği algılama**: SHA256 hash ile thead karşılaştırma
- **AI Fallback**: OpenRouter üzerinden scraper başarısız olduğunda AI ile kur çekme
- **Retry mekanizması**: cURL isteklerinde konfigüre edilebilir retry/delay
- **Request-level cache**: Aynı URL'ye tekrar istek göndermeme

### 2.4 Performans Optimizasyonları
- **PDO Statement Cache**: Aynı SQL sorgularını tekrar prepare etmeme
- **JS DOM Node Cache**: `rateRowCache` ile her refresh'te selector sorgusu yapmama
- **Version-based API**: Değişiklik yoksa boş response dönme (304-benzeri)
- **Intl.NumberFormat cache**: Formatter instance'larını tekrar oluşturmama

### 2.5 CI/CD Pipeline
- PHP 8.3/8.4 matrix strategy ile syntax check + test
- Deploy webhook desteği
- Başarısız build'lerde otomatik GitHub issue oluşturma

---

## 3. Zayıf Yönler ve İyileştirme Alanları

### 3.1 Kritik Eksiklikler

| Seviye | Eksiklik | Açıklama |
|--------|----------|----------|
| **Kritik** | Authentication yok | Portföy verisi URL erişimi olan herkese açık. Multi-user deployment imkansız. |
| **Kritik** | Rate limiting yok | API endpoint'leri sınırsız çağrılabilir, scraping abuse riski |
| **Yüksek** | Self-update imza doğrulaması yok | GitHub compromise durumunda zararlı kod uygulanabilir |
| **Yüksek** | Test coverage çok düşük | Sadece 3 fonksiyon test ediliyor (normalizeCurrencyCode, normalizeBankSlug, extractRatesFromModelText) |
| **Orta** | Rate history temizliği yok | `rate_history` tablosu süresiz büyüyecek, partition/retention policy gerekli |
| **Orta** | Frontend framework yok | Vanilla JS ile karmaşık özellikler eklemek zorlaşır |
| **Düşük** | Light mode yok | Sadece dark mode mevcut |

### 3.2 Teknik Borçlar

1. **`helpers.php` 653 satır**: Fonksiyonlar tek dosyada toplanmış, Router/Controller/Service ayrımı yok
2. **Static method pattern**: `Database`, `Portfolio` sınıfları tamamen static — test edilebilirlik düşük, dependency injection yok
3. **`$GLOBALS` kullanımı**: Locale bilgisi `$GLOBALS['cybokron_locale']` ile taşınıyor
4. **`@$dom->loadHTML()` kullanımı**: Error suppression ile DOM hataları yutulabilir
5. **`mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')`**: PHP 8.2+'da deprecated olabilir
6. **Tek banka scraper**: Sadece Dünya Katılım var, çoklu banka yapısı hazır ama test edilmemiş

---

## 4. Önerilen Ek Özellikler

### 4.1 Yüksek Öncelikli (MVP+)

#### 1. Kullanıcı Kimlik Doğrulama Sistemi
```
Neden: Portföy verisi kişisel finansal veri. Şu an URL bilen herkes erişebilir.
Kapsam:
  - Session-based login/logout
  - Bcrypt ile şifre hash
  - "Beni hatırla" özelliği
  - Brute-force koruması (login rate limiting)
  - Admin/user rolleri
Etki: Güvenlik + Multi-user desteği
```

#### 2. Çoklu Banka Desteği
```
Neden: Sadece Dünya Katılım var. Türkiye'de 50+ banka mevcut.
Kapsam:
  - TCMB (Merkez Bankası) scraper - referans kurlar
  - Ziraat Bankası, Garanti BBVA, İş Bankası scraperları
  - Banka karşılaştırma görünümü (aynı dövizi farklı bankalar)
  - "En iyi alış/satış" sıralaması
Etki: Kullanıcı değeri çok yüksek
```

#### 3. Kur Grafiği (Rate History Chart)
```
Neden: rate_history tablosu var ama görselleştirilmiyor. API endpoint hazır.
Kapsam:
  - Chart.js veya Lightweight Charts entegrasyonu
  - Günlük/haftalık/aylık/yıllık trend grafikleri
  - Buy/sell spread grafiği
  - Teknik analiz göstergeleri (SMA, EMA)
  - Portföy performans grafiği (zaman serisi)
Etki: Dashboard'un ana çekim noktası olur
```

#### 4. Kur Alarm/Bildirim Sistemi
```
Neden: Kullanıcılar belirli kur seviyelerinde haberdar olmak ister.
Kapsam:
  - "USD 40 TL'nin altına düşünce bildir" tipi alarm
  - E-posta bildirimi (PHPMailer/Symfony Mailer)
  - Telegram bot entegrasyonu
  - Webhook desteği (Slack, Discord, custom)
  - Yüzdesel değişim alarmları (günlük %2+ değişim)
Veritabanı:
  alerts(id, user_id, currency_id, condition, threshold, channel, is_active)
```

### 4.2 Orta Öncelikli (Değer Katan)

#### 5. Portföy Düzenleme (Edit) Desteği
```
Neden: Portfolio::update() metodu var ama UI'dan kullanılmıyor.
Kapsam:
  - Inline edit veya modal form
  - Miktar, alış kuru, tarih, not düzenleme
  - Değişiklik geçmişi (audit log)
```

#### 6. Döviz Çevirici (Currency Converter)
```
Neden: Mevcut kur verileri ile anında çeviri doğal bir eklenti.
Kapsam:
  - Anlık kur üzerinden çift yönlü çeviri
  - Alış/satış fiyatı seçimi
  - Popüler çiftler (USD/TRY, EUR/TRY, EUR/USD)
  - Çapraz kur hesaplama
```

#### 7. CSV/Excel Dışa Aktarım
```
Neden: Portföy verisini Excel'e aktarma yaygın ihtiyaç.
Kapsam:
  - Portföy listesini CSV olarak indirme
  - Kur geçmişini CSV/Excel formatında export
  - Tarih aralığı seçimi
  - PhpSpreadsheet ile .xlsx desteği
```

#### 8. Dashboard Widget Sistemi
```
Neden: Ana sayfa sadece tablo gösteriyor, kişiselleştirme yok.
Kapsam:
  - Favori kurlar widget'ı
  - Mini portföy özeti
  - Hızlı bakış kartları (günün en çok değişen kurları)
  - Son güncelleme zaman damgası
  - Piyasa açık/kapalı göstergesi
```

#### 9. PWA (Progressive Web App) Desteği
```
Neden: Mobil kullanıcılar için native benzeri deneyim.
Kapsam:
  - Service Worker ile offline cache
  - manifest.json ile "Ana Ekrana Ekle"
  - Push notification desteği (kur alarmları ile entegre)
  - Offline'da son bilinen kurları gösterme
```

#### 10. API Rate Limiting & API Key Sistemi
```
Neden: Mevcut API tamamen açık, kötüye kullanıma müsait.
Kapsam:
  - Token-based API authentication
  - Rate limiting (IP/token bazlı)
  - API key yönetim paneli
  - Kullanım istatistikleri
  - Swagger/OpenAPI dokümantasyonu
```

### 4.3 Düşük Öncelikli (Nice to Have)

#### 11. Çoklu Dil Desteği Genişletme
```
Kapsam:
  - Arapça (RTL desteği), Almanca, Fransızca
  - Topluluk çeviri sistemi
  - Admin panelinden çeviri yönetimi
```

#### 12. Dark/Light Mode Toggle
```
Kapsam:
  - CSS variables zaten mevcut, light tema eklenmesi kolay
  - Sistem temasına otomatik uyum (prefers-color-scheme)
  - Kullanıcı tercihi kalıcı saklama
```

#### 13. Portföy Analitikleri
```
Kapsam:
  - Döviz bazlı dağılım pasta grafiği
  - Aylık performans raporu
  - Yıllık getiri hesaplama (XIRR)
  - Risk metrikleri (volatilite, Sharpe oranı)
  - Benchmark karşılaştırma (enflasyon, mevduat faizi)
```

#### 14. Admin Dashboard
```
Kapsam:
  - Scrape log'larını görüntüleme
  - Banka/para birimi yönetimi
  - Sistem sağlık durumu (son scrape, hata sayısı)
  - rate_history boyutu ve temizlik
  - OpenRouter kullanım istatistikleri
  - Kullanıcı yönetimi
```

#### 15. Webhook/Entegrasyon Sistemi
```
Kapsam:
  - Kur güncellemelerinde webhook tetikleme
  - Zapier/IFTTT entegrasyonu
  - Google Sheets otomatik güncelleme
  - Home Assistant entegrasyonu
```

#### 16. Docker Desteği
```
Kapsam:
  - Dockerfile + docker-compose.yml
  - PHP-FPM + Nginx + MySQL single compose
  - Ortam değişkenleri ile konfigürasyon
  - Health check endpoint
  - Otomatik migration desteği
```

---

## 5. Öncelik Matrisi

```
                    DEĞER (Kullanıcı Etkisi)
                    Düşük    Orta     Yüksek
               ┌─────────┬────────┬──────────┐
    Düşük      │ Dark    │Docker  │ Çoklu    │
    (Kolay)    │ Mode    │ Setup  │ Banka    │
    EFOR       ├─────────┼────────┼──────────┤
    Orta       │ Dil     │CSV     │Grafikler │
               │Genişlet │Export  │Alarm Sys.│
               ├─────────┼────────┼──────────┤
    Yüksek     │Admin    │Analitik│ Auth     │
    (Zor)      │Panel    │Rapor   │ Sistemi  │
               └─────────┴────────┴──────────┘
```

### Önerilen Uygulama Sırası:
1. **Auth Sistemi** — Güvenlik temeli
2. **Çoklu Banka** (TCMB + 2-3 büyük banka) — Temel değer
3. **Kur Grafiği** — Görsel çekicilik
4. **Kur Alarmları** — Aktif kullanım nedeni
5. **Docker** — Kolay deployment
6. **CSV Export** — Pratik ihtiyaç
7. **PWA** — Mobil erişim
8. **Admin Panel** — Operasyonel kontrol

---

## 6. Sonuç

Cybokron, **iyi düşünülmüş bir güvenlik mimarisi** ve **temiz PHP kodu** ile yazılmış sağlam bir temel projedir. Abstract Scraper pattern'i sayesinde yeni banka eklemek kolaydır ve AI fallback mekanizması yaratıcı bir çözümdür.

Ana gelişim alanları:
- **Authentication** eklenmeden production'a alınmamalı
- **Çoklu banka desteği** projenin asıl değer önerisini güçlendirir
- **Veri görselleştirme** (grafikler) kullanıcı deneyimini katlar
- **Test coverage** genişletilmeli (integration test, scraper mock test)
- **Docker desteği** ile deployment kolaylaştırılmalı

Proje, kişisel kullanım için iyi bir noktada; ticari veya çoklu kullanıcı senaryosu için yukarıdaki iyileştirmeler gereklidir.

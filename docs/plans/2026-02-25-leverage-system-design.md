# Kaldıraç (Leverage) Sistemi — Tasarım Dokümanı

**Tarih:** 2026-02-25
**Versiyon:** 1.1 (double-check sonrası güncellendi)
**Yaklaşım:** A — Portföy Entegreli Kaldıraç

---

## Özet

AI destekli kaldıraç takip sistemi. Kıymetli maden, döviz ve diğer varlıklarda belirlenen eşik değerlerine ulaşıldığında Gemini AI ön analiz yapar ve SendGrid ile email bildirimi gönderir. Mevcut portföy grup/etiket yapısıyla entegre çalışır.

## Gümüş Volatilite Analizi (2023-2026)

| Yıl | Dip | Tepe | Yıl İçi Volatilite |
|-----|-----|------|-------------------|
| 2023 | $20.04 | $26.05 | ~26% |
| 2024 | $22.07 | $34.81 | ~45% |
| 2025 | $28.97 | $79.28 | ~93% |
| 2026 YTD | $75.00 | $121.00 | ~47% (Ocak) |

**Optimal eşikler:**
- Dip alım: -%15~18 düzeltmede (tarihsel medyan: %15.5-16.9)
- Satış: +%30~40 yükselişte (tarihsel ralli ortancası: ~%30)
- Altın/Gümüş oranı: >80 = gümüş ucuz, <60 = altına geçiş düşünülebilir (AI bağlam bilgisi, hard filter değil)

**Default değerler:** buy_threshold: -15.00, sell_threshold: +30.00

**Uyarı:** Yüksek volatilite dönemlerinde (>%50 yıllık) default eşikler erken tetiklenebilir. Kullanıcı risk profiline göre ayarlamalıdır.

## Veritabanı Şeması

### leverage_rules

```sql
CREATE TABLE leverage_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    source_type     ENUM('group','tag','currency') NOT NULL,
    source_id       INT UNSIGNED NULL,
    currency_code   VARCHAR(10) NOT NULL,
    buy_threshold   DECIMAL(8,2) NOT NULL DEFAULT -15.00,
    sell_threshold  DECIMAL(8,2) NOT NULL DEFAULT 30.00,
    reference_price DECIMAL(18,6) NULL,
    ai_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    strategy_context TEXT NULL,
    status          ENUM('active','paused','completed') DEFAULT 'active',
    last_checked_at DATETIME NULL,
    last_triggered_at DATETIME NULL,
    last_trigger_direction ENUM('buy','sell') NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_currency (currency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**source_type semantiği:**
- `currency`: Direkt para birimi takibi. `source_id` NULL, sadece `currency_code` kullanılır.
- `group`: Portföy grubundan takip. `source_id` = `portfolio_groups.id`. `currency_code` = o gruptaki hangi currency takip edileceği (ör: "Kıymetli Madenler" grubundan XAG). Portföy verisi = o grubun XAG toplamı.
- `tag`: Portföy etiketinden takip. `source_id` = `portfolio_tags.id`. Aynı mantık.

**reference_price politikası:**
- Kural oluşturulurken: `[Güncel Fiyatı Al]` butonu ile rates tablosundan otomatik atanır. Manuel giriş de mümkün. NULL olamaz (zorunlu).
- Tetikleme sonrası: Otomatik güncellenmez. Admin leverage.php'den `[Referansı Güncelle]` butonu ile manuel günceller. Bu sayede admin bilinçli karar verir.

**last_trigger_direction:** Aynı yönde tekrar tetiklenmeyi önler. Buy sinyali geldikten sonra fiyat referansa dönüp tekrar düşmedikçe yeni buy sinyali gelmez.

### leverage_history

```sql
CREATE TABLE leverage_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id         INT UNSIGNED NOT NULL,
    event_type      ENUM('buy_signal','sell_signal','ai_analysis','price_update','status_change') NOT NULL,
    price_at_event  DECIMAL(18,6) NULL,
    reference_price_at_event DECIMAL(18,6) NULL,
    change_percent  DECIMAL(8,2) NULL,
    ai_response     TEXT NULL,
    ai_recommendation ENUM('strong_buy','buy','hold','sell','strong_sell') NULL,
    notification_sent TINYINT(1) DEFAULT 0,
    notification_channel VARCHAR(20) NULL,
    notes           TEXT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule (rule_id),
    INDEX idx_event (event_type),
    FOREIGN KEY (rule_id) REFERENCES leverage_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Settings seed

```sql
INSERT INTO settings (`key`, `value`) VALUES
    ('leverage_enabled', '1'),
    ('leverage_ai_model', 'google/gemini-3.1-pro-preview'),
    ('leverage_ai_enabled', '1'),
    ('leverage_check_interval_minutes', '15'),
    ('leverage_cooldown_minutes', '60'),
    ('sendgrid_enabled', '1'),
    ('sendgrid_api_key', ''),
    ('sendgrid_from_email', 'noreply@example.com'),
    ('sendgrid_from_name', 'Cybokron Leverage'),
    ('leverage_notify_emails', '["admin@example.com"]');
```

## Dosya Mimarisi

### Yeni dosyalar

```
leverage.php                          — Sayfa (UI + CRUD)
includes/LeverageEngine.php           — Kural motoru + AI çağrısı
includes/SendGridMailer.php           — SendGrid email (genel amaçlı)
cron/check_leverage.php               — Periyodik kontrol
assets/js/leverage.js                 — UI etkileşimleri
database/migrations/013_leverage.sql  — Şema + seed data
```

### Değişecek dosyalar

```
includes/header.php                   — "Kaldıraç" menü linki
config.sample.php                     — Leverage + SendGrid define'ları
locales/tr.php, en.php                — Çeviri key'leri
admin.php                             — Leverage AI + SendGrid ayarları tab'ı
database/database.sql                 — Yeni tablolar (fresh install için)
```

## Veri Akışı

```
leverage.php (CRUD) → leverage_rules (DB)
                           ↓
cron/check_leverage.php (her 15dk)
                           ↓
LeverageEngine::run()
  1. Aktif kuralları al (status='active')
  2. Her kural için:
     2.1. Güncel fiyatı rates tablosundan çek (en yüksek sell_rate, aktif banka)
     2.2. reference_price'a göre % değişim hesapla
     2.3. Eşik kontrolü:
          - change_percent <= buy_threshold → buy_signal
          - change_percent >= sell_threshold → sell_signal
     2.4. Eşik aşılmadı → last_checked_at güncelle, sonraki kurala geç
     2.5. Eşik aşıldı → cooldown kontrolü (last_triggered_at + cooldown)
     2.6. Yön kontrolü: last_trigger_direction aynı yön mü?
          - Aynı yön → fiyat referansa geri dönmüş mü kontrol et, dönmediyse atla
     2.7. Portföy verisini topla (source_type'a göre):
          - currency: Tüm portföydeki o currency toplamı
          - group: O gruptaki ilgili currency toplamı
          - tag: O etiketteki ilgili currency toplamı
     2.8. Au/Ag oranı hesapla: XAU sell_rate / XAG sell_rate (aynı banka)
          - XAU veya XAG yoksa "N/A"
     2.9. Son 30 günlük fiyat trendi hesapla:
          - rate_history'den son 30 gün sell_rate
          - Min, max, ortalama, trend yönü (yükselen/düşen/yatay)
          - Tek satırlık özet string oluştur
     2.10. AI ön analiz (ai_enabled ise):
          - OpenRouter API → settings'deki leverage_ai_model
          - Prompt gönder → JSON response al
          - Parse hatası durumunda: ai_recommendation=NULL, email AI'sız gönderilir, hata loglanır
     2.11. leverage_history'ye kaydet (reference_price_at_event dahil)
     2.12. last_triggered_at + last_trigger_direction güncelle
     2.13. SendGridMailer::send() → leverage_notify_emails'deki tüm alıcılara
```

## AI Prompt Yapısı

```
You are a precious metals and forex investment analyst.

Asset: {currency_code} ({currency_name})
Reference Price: {reference_price} TRY
Current Price: {current_price} TRY
Change: {change_percent}%
Gold/Silver Ratio: {gold_silver_ratio} (context: >80 = silver undervalued, <60 = consider switching to gold)
Trigger: {buy|sell} threshold breached ({threshold}%)

Strategy Context:
<user_context>
{strategy_context}
</user_context>

Portfolio Position:
- Total Amount: {amount}
- Average Cost: {avg_cost} TRY
- P/L: {pnl}%

Last 30 days price trend: {price_trend_summary}

Respond with JSON only:
{
  "recommendation": "strong_buy|buy|hold|sell|strong_sell",
  "confidence": 0-100,
  "reasoning": "brief analysis in Turkish",
  "risk_level": "low|medium|high",
  "suggested_action": "suggested action in Turkish"
}
```

**AI response parse:** Mevcut `OpenRouterRateRepair::extractJsonPayload()` pattern'i kullanılır. Parse hatası → ai_recommendation=NULL, email AI analizi olmadan gönderilir, hata loglanır.

## UI/UX

### leverage.php sayfa yapısı

1. **Özet kartları:** Aktif kurallar, tetiklenen sinyaller, bugün kontrol sayısı, AI analiz sayısı
2. **Aktif kurallar listesi:** Kart bazlı, her kartta:
   - İsim, currency, grup/etiket
   - Referans → güncel fiyat, % değişim
   - Görsel bar (kırmızı=al bölgesi, gri=bekle, yeşil=sat bölgesi)
   - Son kontrol zamanı, AI durumu
   - Düzenle/duraklat/sil/referans güncelle aksiyonları
3. **Kural oluşturma modalı:** İsim, kaynak tipi (grup/etiket/currency), eşikler, AI toggle, strateji notu
   - Kaynak tipi seçilince: grup→gruptaki currency'ler listelenir, etiket→etiketteki, currency→tüm aktif currency'ler
   - Referans fiyat: `[Güncel Fiyatı Al]` butonu veya manuel giriş. Zorunlu alan.
4. **Sinyal geçmişi tablosu:** Tarih, kural, olay tipi, AI önerisi, o anki referans fiyat

### Responsive

Desktop: kartlar yan yana. Mobile: alt alta. Fullwidth toggle aktif.

## Admin Panel (admin.php — yeni tab)

- Leverage aktif/pasif toggle
- Leverage AI Model (text input, default: google/gemini-3.1-pro-preview)
- AI aktif/pasif toggle
- AI bağlantı test butonu
- SendGrid aktif/pasif toggle
- SendGrid API Key (AES-256-GCM şifreli)
- SendGrid from email / from name
- Bildirim alıcıları (dinamik liste, JSON array, max 10 alıcı, FILTER_VALIDATE_EMAIL)
- Kontrol aralığı (dakika)
- Cooldown (dakika)
- Test email gönder butonu

## Config (config.sample.php)

```php
// ─── Leverage & AI Analysis ─────────────────────────────
define('LEVERAGE_ENABLED', true);
define('LEVERAGE_CHECK_INTERVAL_MINUTES', 15);
define('LEVERAGE_COOLDOWN_MINUTES', 60);
define('LEVERAGE_AI_ENABLED', true);
define('LEVERAGE_AI_MODEL', 'google/gemini-3.1-pro-preview');
define('LEVERAGE_AI_MAX_TOKENS', 800);
define('LEVERAGE_AI_TIMEOUT_SECONDS', 30);

// ─── SendGrid Email ─────────────────────────────────────
define('SENDGRID_API_KEY', '');
define('SENDGRID_FROM_EMAIL', 'noreply@example.com');
define('SENDGRID_FROM_NAME', 'Cybokron Leverage');
define('SENDGRID_ENABLED', true);
define('SENDGRID_ALLOWED_HOSTS', ['api.sendgrid.com']);
```

**Önceliklendirme:** Settings tablosu > config.php define > hardcoded default. Mevcut OpenRouter pattern ile aynı.

## Email

- SendGrid API v3 (`https://api.sendgrid.com/v3/mail/send`)
- Auth: `Authorization: Bearer {api_key}`
- Content array: text/plain + text/html (SendGrid formatı)
- Host doğrulaması: `SENDGRID_ALLOWED_HOSTS` ile URL host kontrolü
- Birden fazla alıcı: `personalizations[].to[]` array'i (settings: leverage_notify_emails JSON)
- Konu: `[Cybokron] {SAT|AL} Sinyali: {XAG} {+31.2%} — AI: {strong_sell} (%87)`
- Gövde: Fiyat özeti, AI analiz (varsa), portföy durumu, kural detayları

## Güvenlik

- SendGrid API key: AES-256-GCM (mevcut OpenRouter key pattern). Not: DB_PASS değişirse encrypted değerler yeniden şifrelenmeli.
- SendGrid host doğrulaması: `SENDGRID_ALLOWED_HOSTS` ile CURLOPT_URL öncesi kontrol
- CSRF: getCsrfToken() tüm POST'larda
- Rate limiting: mevcut API rate limit'ler
- AI prompt injection: strategy_context `<user_context>` bloğu içinde izole edilir, `mb_substr()` ile max 500 karakter, control char'lar strip edilir
- Email alıcı doğrulama: JSON decode + FILTER_VALIDATE_EMAIL + max 10 alıcı limiti
- source_type+source_id referans bütünlüğü: Uygulama katmanında kontrol (group→portfolio_groups'ta var mı, tag→portfolio_tags'te var mı, currency→source_id NULL mı)
- Cron: ENFORCE_CLI_CRON kontrolü
- Config: config.php .gitignored, config.sample.php'de boş default'lar

## Migration

`database/migrations/013_leverage.sql`:
1. CREATE TABLE leverage_rules
2. CREATE TABLE leverage_history
3. Settings INSERT'leri (ON DUPLICATE KEY UPDATE ile idempotent)

`database/database.sql` de güncellenir (fresh install için).

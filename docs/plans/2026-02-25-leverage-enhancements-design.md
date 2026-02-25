# Kaldıraç Sistemi Geliştirmeleri — Tasarım Dokümanı

**Tarih:** 2026-02-25
**Versiyon:** 1.0
**Baz versiyon:** v1.12.0
**Hedef versiyon:** v1.13.0

---

## Özet

Mevcut kaldıraç sistemine 4 alan ekleniyor:
1. **Bildirim kanalları** — Telegram bot + webhook (generic/Discord/Slack)
2. **Analitik & raporlama** — Backtesting (rate_history + 2 harici API) + haftalık rapor
3. **Gelişmiş kural mantığı** — Trailing stop (auto + threshold) + çoklu eşik (weak/strong)
4. **UX iyileştirme** — Backtest sonuç görüntüleme, trailing stop UI, admin ayarları

## Veritabanı Şeması

### Migration: `014_leverage_enhancements.sql`

#### Yeni tablo: `leverage_webhooks`

```sql
CREATE TABLE IF NOT EXISTS `leverage_webhooks` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `url` varchar(500) NOT NULL,
    `platform` enum('generic','discord','slack') NOT NULL DEFAULT 'generic',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Neden ayrı tablo:** Alert webhooks per-alert (kullanıcı bazlı), leverage webhooks sistem geneli (admin yönetir). Birden fazla endpoint desteklemek için tablo gerekli.

#### Yeni tablo: `leverage_backtests`

```sql
CREATE TABLE IF NOT EXISTS `leverage_backtests` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `rule_id` int unsigned NOT NULL,
    `data_source` enum('rate_history','metals_dev','exchangerate_host') NOT NULL DEFAULT 'rate_history',
    `date_from` date NOT NULL,
    `date_to` date NOT NULL,
    `total_signals` int unsigned NOT NULL DEFAULT 0,
    `buy_signals` int unsigned NOT NULL DEFAULT 0,
    `sell_signals` int unsigned NOT NULL DEFAULT 0,
    `result_json` longtext NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rule` (`rule_id`),
    CONSTRAINT `fk_leverage_backtests_rule` FOREIGN KEY (`rule_id`) REFERENCES `leverage_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**result_json formatı:**
```json
{
  "signals": [
    {"date": "2026-01-15", "direction": "buy", "price": 42.50, "change_pct": -16.2, "reference": 50.72},
    {"date": "2026-02-03", "direction": "sell", "price": 68.10, "change_pct": 31.5, "reference": 42.50}
  ],
  "summary": {
    "total_return_pct": 15.3,
    "max_drawdown_pct": -18.5,
    "avg_signal_interval_days": 14,
    "win_rate_pct": 66.7
  }
}
```

#### ALTER: `leverage_rules`

```sql
ALTER TABLE `leverage_rules`
    ADD COLUMN `trailing_stop_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `strategy_context`,
    ADD COLUMN `trailing_stop_type` enum('auto','threshold') NOT NULL DEFAULT 'auto' AFTER `trailing_stop_enabled`,
    ADD COLUMN `trailing_stop_pct` decimal(8,2) NOT NULL DEFAULT 5.00 AFTER `trailing_stop_type`,
    ADD COLUMN `peak_price` decimal(18,6) NULL AFTER `trailing_stop_pct`,
    ADD COLUMN `buy_threshold_weak` decimal(8,2) NULL AFTER `sell_threshold`,
    ADD COLUMN `sell_threshold_weak` decimal(8,2) NULL AFTER `buy_threshold_weak`;
```

**Kolon açıklamaları:**
- `trailing_stop_enabled`: Trailing stop aktif mi
- `trailing_stop_type`:
  - `auto`: Fiyat yükselince `peak_price` otomatik güncellenir. Satış sinyali: fiyat peak'ten `trailing_stop_pct` kadar düşünce tetiklenir.
  - `threshold`: Admin referansı manuel set eder. Fiyat referanstan `trailing_stop_pct` düşünce tetiklenir.
- `peak_price`: Auto modda high watermark. Her kontrol döngüsünde fiyat > peak ise güncellenir.
- `buy_threshold_weak` / `sell_threshold_weak`: Erken uyarı eşikleri. NULL = devre dışı. Güçlü sinyalden önce "dikkat" bildirimi. AI analiz tetiklemez, sadece bildirim.

#### ALTER: `leverage_history`

```sql
ALTER TABLE `leverage_history`
    MODIFY COLUMN `notification_channel` varchar(100) DEFAULT NULL,
    MODIFY COLUMN `event_type` enum('buy_signal','sell_signal','weak_buy_signal','weak_sell_signal','ai_analysis','price_update','status_change','trailing_stop_signal') NOT NULL;
```

**notification_channel** varchar(100): Birden fazla kanal virgülle ayrılmış: `'email,telegram,webhook'`

**Yeni event_type'lar:**
- `weak_buy_signal` / `weak_sell_signal` — Zayıf eşik uyarısı
- `trailing_stop_signal` — Trailing stop tetiklenmesi

#### Settings seed

```sql
INSERT INTO `settings` (`key`, `value`) VALUES
    ('telegram_enabled', '0'),
    ('telegram_bot_token', ''),
    ('telegram_chat_id', ''),
    ('webhook_enabled', '0'),
    ('backtesting_enabled', '1'),
    ('backtesting_default_source', 'rate_history'),
    ('backtesting_metals_dev_api_key', ''),
    ('backtesting_exchangerate_host_api_key', ''),
    ('leverage_weekly_report_enabled', '0'),
    ('leverage_weekly_report_day', '1')
ON DUPLICATE KEY UPDATE `key` = `key`;
```

## Dosya Mimarisi

### Yeni dosyalar

```
includes/TelegramNotifier.php          — Telegram Bot API v6+ entegrasyonu
includes/LeverageWebhookDispatcher.php — Webhook dispatch (generic/Discord/Slack)
includes/BacktestEngine.php            — Backtesting simulasyon motoru
cron/weekly_leverage_report.php        — Haftalık rapor cron'u
database/migrations/014_leverage_enhancements.sql
```

### Değişecek dosyalar

```
includes/LeverageEngine.php     — Trailing stop, weak threshold, çoklu kanal dispatch
leverage.php                    — Trailing stop UI, backtest UI, webhook yönetim
admin.php                       — Telegram/Webhook/Backtesting/Rapor ayarları
assets/js/leverage.js           — Yeni form elemanları + backtest modal
assets/css/admin.css            — Yeni section stilleri
config.sample.php               — Yeni define'lar
locales/tr.php, en.php, de.php, fr.php, ar.php — Yeni i18n key'leri
database/database.sql           — Yeni tablolar (fresh install)
```

## Bildirim Kanalları

### TelegramNotifier.php

```
Sorumluluk: Telegram Bot API üzerinden mesaj gönderimi
Pattern: SendGridMailer.php ve AlertChecker::sendTelegramAlert() referans

Methodlar:
- send(string $chatId, string $message): bool
- isEnabled(): bool — settings.telegram_enabled > config LEVERAGE_TELEGRAM_ENABLED
- resolveBotToken(): string — settings.telegram_bot_token (encrypted) > config ALERT_TELEGRAM_BOT_TOKEN
- resolveChatId(): string — settings.telegram_chat_id > config ALERT_TELEGRAM_CHAT_ID
- sendLeverageSignal(array $rule, string $direction, float $changePct, ?array $aiResult): bool
- sendTestMessage(): array — bağlantı testi
- buildSignalMessage(array $rule, string $direction, float $changePct, ?array $aiResult): string

Mesaj formatı (HTML parse_mode):
<b>⚡ KALDIRAC SİNYALİ</b>
🔴 <b>AL SİNYALİ</b> — XAG Gümüş
━━━━━━━━━━━━━━
📊 Referans: ₺42.50 → Güncel: ₺35.53
📉 Değişim: -16.40%
🤖 AI: strong_buy (%82)
💡 Fiyat referans noktasından önemli ölçüde düşmüştür...
━━━━━━━━━━━━━━
📋 Kural: Gümüş Takip
⏰ 2026-02-25 14:30

Güvenlik:
- Host doğrulama: api.telegram.org (AlertChecker pattern)
- SSL verification: VERIFYPEER + VERIFYHOST
- Bot token şifreli: AES-256-GCM (mevcut encryptSettingValue pattern)
```

### LeverageWebhookDispatcher.php

```
Sorumluluk: Leverage sinyallerini aktif webhook endpoint'lerine gönder
Pattern: AlertChecker::sendWebhookAlert() + WebhookDispatcher.php referans

Methodlar:
- dispatch(array $rule, string $direction, float $changePct, ?array $aiResult): array
  → Her aktif webhook'a gönder, sonuç: ['sent' => 2, 'failed' => 0]
- isEnabled(): bool — settings.webhook_enabled > config LEVERAGE_WEBHOOK_ENABLED
- getActiveWebhooks(): array — leverage_webhooks WHERE is_active = 1
- buildPayload(array $rule, string $direction, float $changePct, ?array $aiResult, string $platform): array

Platform payload'ları:

Generic:
{
  "event": "leverage_signal",
  "direction": "buy",
  "currency": "XAG",
  "rule_name": "Gümüş Takip",
  "reference_price": 42.50,
  "current_price": 35.53,
  "change_percent": -16.40,
  "ai_recommendation": "strong_buy",
  "ai_confidence": 82,
  "timestamp": "2026-02-25T14:30:00+03:00"
}

Discord:
{
  "embeds": [{
    "title": "⚡ AL SİNYALİ — XAG",
    "color": 15548997,  // kırmızı (buy) veya 5763719 (yeşil, sell)
    "fields": [
      {"name": "Referans", "value": "₺42.50", "inline": true},
      {"name": "Güncel", "value": "₺35.53", "inline": true},
      {"name": "Değişim", "value": "-16.40%", "inline": true},
      {"name": "AI", "value": "strong_buy (%82)"},
      {"name": "Kural", "value": "Gümüş Takip"}
    ],
    "timestamp": "2026-02-25T14:30:00+03:00"
  }]
}

Slack:
{
  "blocks": [
    {"type": "header", "text": {"type": "plain_text", "text": "⚡ AL SİNYALİ — XAG"}},
    {"type": "section", "fields": [
      {"type": "mrkdwn", "text": "*Referans:* ₺42.50"},
      {"type": "mrkdwn", "text": "*Güncel:* ₺35.53"},
      {"type": "mrkdwn", "text": "*Değişim:* -16.40%"},
      {"type": "mrkdwn", "text": "*AI:* strong_buy (%82)"}
    ]}
  ]
}

Güvenlik:
- HTTPS-only (AlertChecker pattern)
- Private/reserved IP bloklama: isPrivateOrReservedHost() (mevcut helper)
- CURLPROTO_HTTPS (mevcut pattern)
- SSL verification
- Timeout: 10 saniye
```

### CRUD: Webhook yönetimi

LeverageEngine'e veya ayrı LeverageWebhookDispatcher'a:
- `createWebhook(array $data)`: INSERT — url HTTPS kontrolü, private IP kontrolü
- `updateWebhook(int $id, array $data)`: UPDATE
- `deleteWebhook(int $id)`: DELETE
- `toggleWebhook(int $id)`: is_active toggle

## Backtesting

### BacktestEngine.php

```
Sorumluluk: Kaldıraç kuralını geçmiş fiyat verilerine karşı simüle et
Pattern: LeverageEngine::checkRule() mantığının offline versiyonu

Methodlar:
- run(int $ruleId, string $source, string $dateFrom, string $dateTo): array
  → Simülasyon çalıştır, leverage_backtests'e kaydet, sonuç dön
- simulateFromRateHistory(array $rule, string $dateFrom, string $dateTo): array
  → rate_history'den veri çek, sinyal simüle et
- simulateFromMetalsDev(array $rule, string $dateFrom, string $dateTo): array
  → metals.dev API'dan historical data çek, simüle et
- simulateFromExchangeRateHost(array $rule, string $dateFrom, string $dateTo): array
  → exchangerate.host API'dan historical data çek, simüle et
- simulateSignals(array $rule, array $priceData): array
  → Price data array'e checkRule mantığı uygula

Simülasyon mantığı (simulateSignals):
1. İlk fiyatı reference_price olarak al
2. Her veri noktasında:
   a. change_percent = ((price - reference) / reference) * 100
   b. Trailing stop aktifse: peak_price güncelle, trailing kontrol
   c. Eşik kontrolü: buy/sell/weak_buy/weak_sell
   d. Tetiklenirse: sinyal kaydet, referans güncelle
   e. Cooldown uygula (gerçek dakika yerine veri noktası sayısı)
3. Sonuç: sinyal listesi + summary stats

rate_history sorgusu:
SELECT rh.sell_rate, rh.scraped_at
FROM rate_history rh
JOIN currencies c ON c.id = rh.currency_id
WHERE c.code = ? AND rh.scraped_at BETWEEN ? AND ?
ORDER BY rh.scraped_at ASC

Harici API'lar:

metals.dev (kıymetli maden):
- Endpoint: https://api.metals.dev/v1/timeseries
- Params: api_key, start_date, end_date, base=TRY, currencies=XAU,XAG,XPT,XPD
- Host whitelist: BACKTESTING_ALLOWED_HOSTS
- API key: settings.backtesting_metals_dev_api_key (encrypted)

exchangerate.host (forex):
- Endpoint: https://api.exchangerate.host/timeseries
- Params: access_key, start_date, end_date, base=TRY, symbols=USD,EUR,GBP,...
- Host whitelist: BACKTESTING_ALLOWED_HOSTS
- API key: settings.backtesting_exchangerate_host_api_key (encrypted)

Her ikisi de:
- SSL verification
- HTTPS-only
- Host whitelist kontrolü
- Timeout: 15 saniye
- Response JSON parse + normalize (tek format: [{date, price}])
```

## Gelişmiş Kural Mantığı

### Trailing Stop

LeverageEngine::checkRule() güncellenmesi:

```
Mevcut akış:
1. Fiyat al → 2. Change hesapla → 3. Eşik kontrol → 4. Cooldown → 5. Yön kontrol

Yeni akış:
1. Fiyat al
2. Trailing stop aktifse:
   a. Auto mod: fiyat > peak_price ise peak_price güncelle
   b. Change'i peak_price'a göre hesapla (referans yerine)
   c. Trailing stop tetiklendi mi: change <= -trailing_stop_pct
   d. Tetiklendiyse: trailing_stop_signal event, bildirim gönder, peak_price sıfırla
3. Weak eşik kontrol:
   a. buy_threshold_weak != NULL ve change <= buy_threshold_weak → weak_buy_signal
   b. sell_threshold_weak != NULL ve change >= sell_threshold_weak → weak_sell_signal
   c. Weak sinyal: AI tetiklemez, bildirim gönderilir, cooldown uygulanmaz
4. Strong eşik kontrol (mevcut mantık)
5. Cooldown + yön kontrol
6. AI + bildirim (çoklu kanal)
```

### Çoklu Eşik (Weak/Strong)

```
Örnek konfigürasyon:
- buy_threshold (strong): -15%  → Tam sinyal (AI + tüm kanallar)
- buy_threshold_weak: -8%       → Erken uyarı (bildirim, AI yok)
- sell_threshold (strong): +30% → Tam sinyal
- sell_threshold_weak: +15%     → Erken uyarı

Weak sinyal özellikleri:
- AI analiz tetiklemez (maliyet tasarrufu)
- Bildirim gönderir (tüm aktif kanallar)
- Cooldown uygulanmaz (bilgi amaçlı)
- last_trigger_direction güncellemez (strong sinyali engellemez)
- Email/Telegram/Webhook: "⚠️ ERKEN UYARI" prefix'i ile
```

## Bildirim Akışı (Güncellendi)

```
Sinyal tetiklendi
    ├── Email (SendGridMailer) — mevcut
    ├── Telegram (TelegramNotifier) — yeni
    └── Webhooks (LeverageWebhookDispatcher) — yeni
          ├── Generic JSON
          ├── Discord embed
          └── Slack blocks

Her kanal bağımsız: biri başarısız olursa diğerleri etkilenmez.
notification_channel virgülle ayrılmış: 'email,telegram,webhook'
notification_sent: en az bir kanal başarılıysa 1
```

LeverageEngine::checkRule() dispatch kodu:
```
$channels = [];
// Email
if (SendGridMailer::isEnabled() && !empty($recipients)) {
    if (self::sendSignalEmail(...)) $channels[] = 'email';
}
// Telegram
if (TelegramNotifier::isEnabled()) {
    if (TelegramNotifier::sendLeverageSignal(...)) $channels[] = 'telegram';
}
// Webhooks
if (LeverageWebhookDispatcher::isEnabled()) {
    $whResult = LeverageWebhookDispatcher::dispatch(...);
    if ($whResult['sent'] > 0) $channels[] = 'webhook';
}

Database::update('leverage_history', [
    'notification_sent' => !empty($channels) ? 1 : 0,
    'notification_channel' => !empty($channels) ? implode(',', $channels) : null,
], 'id = ?', [$historyId]);
```

## Haftalık Rapor

### cron/weekly_leverage_report.php

```
Çalışma: Haftada bir (leverage_weekly_report_day ayarına göre, default: 1=Pazartesi)
Cron önerisi: 0 9 * * 1 php cron/weekly_leverage_report.php

İçerik:
- Kural bazlı özet (aktif/duraklatılmış sayıları)
- Bu hafta tetiklenen sinyaller listesi
- Kural bazlı fiyat değişimleri
- AI önerilerinin özeti (hafta içi strong_buy/buy/hold/sell/strong_sell dağılımı)
- Portföy genel durum (toplam değer, haftalık değişim)

Gönderim: SendGridMailer → leverage_notify_emails
Konu: [Cybokron] Haftalık Kaldıraç Raporu — 17-24 Şubat 2026
```

## Admin Panel Ayarları

### Telegram bölümü

```
📱 Telegram Bildirimi
├── Telegram Aktif: toggle (telegram_enabled)
├── Bot Token: text input, masked (telegram_bot_token, encrypted)
├── Chat ID: text input (telegram_chat_id)
├── Bağlantı Testi: buton → TelegramNotifier::sendTestMessage()
└── Kurulum Rehberi: bilgi kutusu
    "1. Telegram'da @BotFather'a /newbot yazın
     2. Bot adı ve kullanıcı adı belirleyin
     3. Verilen token'ı yukarıya yapıştırın
     4. Botu gruba/kanala ekleyin veya doğrudan mesaj başlatın
     5. Chat ID'yi öğrenmek için bota mesaj gönderin ve
        https://api.telegram.org/bot<TOKEN>/getUpdates adresini kontrol edin"
```

### Webhook bölümü

```
🔗 Webhook Bildirimi
├── Webhook Aktif: toggle (webhook_enabled)
├── Kayıtlı Endpoint'ler: tablo (leverage_webhooks listesi)
│   ├── Her satır: İsim, URL (masked), Platform, Aktif/Pasif, Düzenle/Sil
│   └── Yeni Ekle: buton → inline form (isim, url, platform select)
└── Not: "Sadece HTTPS URL'ler kabul edilir."
```

### Backtesting bölümü

```
📊 Backtesting
├── Backtesting Aktif: toggle (backtesting_enabled)
├── Varsayılan Veri Kaynağı: select (rate_history / metals.dev / exchangerate.host)
├── Metals.dev API Key: text input, masked (encrypted)
├── ExchangeRate.host API Key: text input, masked (encrypted)
└── Not: "rate_history yerel veritabanınızdaki fiyat geçmişini kullanır."
```

### Haftalık rapor bölümü

```
📅 Haftalık Rapor
├── Haftalık Rapor Aktif: toggle (leverage_weekly_report_enabled)
├── Gönderim Günü: select (Pazartesi-Pazar) (leverage_weekly_report_day)
└── Alıcılar: Mevcut leverage_notify_emails listesi kullanılır
```

## Config (config.sample.php eklentileri)

```php
// ─── Leverage Telegram ──────────────────────────────────────────────────
define('LEVERAGE_TELEGRAM_ENABLED', false);
// Bot token ve chat ID admin panelinden ayarlanır (encrypted)

// ─── Leverage Webhooks ──────────────────────────────────────────────────
define('LEVERAGE_WEBHOOK_ENABLED', false);

// ─── Backtesting ────────────────────────────────────────────────────────
define('BACKTESTING_ENABLED', true);
define('BACKTESTING_DEFAULT_SOURCE', 'rate_history');
define('BACKTESTING_ALLOWED_HOSTS', ['api.metals.dev', 'api.exchangerate.host']);
// API key'ler admin panelinden ayarlanır (encrypted)

// ─── Leverage Weekly Report ─────────────────────────────────────────────
define('LEVERAGE_WEEKLY_REPORT_ENABLED', false);
define('LEVERAGE_WEEKLY_REPORT_DAY', 1); // 1=Mon ... 7=Sun
```

## leverage.php UI Güncellemeleri

### Kural modalına eklemeler

```
Trailing Stop bölümü:
├── Trailing Stop Aktif: checkbox
├── Trailing Stop Tipi: radio (Otomatik / Eşik Bazlı)
│   └── Otomatik: "Fiyat yükselince referans otomatik güncellenir"
│   └── Eşik Bazlı: "Fiyattan X% düşünce tetiklenir"
├── Trailing Stop %: number input (default: 5.00)

Zayıf Eşik bölümü:
├── Alış Uyarı Eşiği (Zayıf): number input (opsiyonel)
│   └── Placeholder: "Ör: -8 (strong: -15'ten önce uyarı)"
├── Satış Uyarı Eşiği (Zayıf): number input (opsiyonel)
│   └── Placeholder: "Ör: 15 (strong: 30'dan önce uyarı)"
```

### Backtest butonu ve modal

```
Her kural kartına:
├── [📊 Backtest] butonu

Backtest modalı:
├── Veri Kaynağı: select (rate_history / metals.dev / exchangerate.host)
├── Tarih Aralığı: date picker (from / to)
│   └── Default: son 90 gün
├── [Çalıştır] butonu → AJAX POST
├── Sonuç alanı:
│   ├── Özet kartları: Toplam sinyal, AL/SAT, Toplam getiri %, Max drawdown
│   ├── Sinyal tablosu: Tarih, Yön, Fiyat, Değişim%, Referans
│   └── Boş durum: "Seçilen aralıkta sinyal tetiklenmedi"
```

### Kural kartı güncelleme

```
Mevcut: İsim, currency, grup/etiket, referans→güncel, bar, son kontrol, AI
Eklenen:
├── Trailing stop badge: "🔄 TS: Auto %5" veya "🔄 TS: Eşik %3"
├── Zayıf eşik göstergesi: progress bar'da sarı bölge (buy_weak..buy arası, sell_weak..sell arası)
├── Peak price göstergesi (trailing stop aktifse): "Tepe: ₺85.20"
```

## Güvenlik

- Telegram bot token: AES-256-GCM şifreli (mevcut encryptSettingValue pattern)
- Webhook URL'ler: HTTPS-only, private IP bloklama (mevcut isPrivateOrReservedHost)
- Backtesting API key'ler: AES-256-GCM şifreli
- Backtesting host whitelist: BACKTESTING_ALLOWED_HOSTS
- Webhook CRUD: CSRF token + admin kontrolü
- Backtest AJAX: CSRF token + admin kontrolü
- Config: Hassas bilgiler config.sample.php'de boş default, .gitignored config.php'de gerçek değerler
- GitHub: Hiçbir API key, token, şifre tracked dosyalarda bulunmayacak

## GitHub Actions Uyumu

Mevcut pipeline (quality-test-deploy.yml) otomatik çalışır:
- PHP syntax check → PHP 8.3 + 8.4 test → Migration check → Deploy
- Yeni dosyalar pipeline tarafından otomatik algılanır
- Migration 014 deploy sırasında çalıştırılır
- Hassas config: GitHub Secrets kullanılır (mevcut pattern)

# Kaldıraç Sistemi Geliştirmeleri — İmplementasyon Planı

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Kaldıraç sistemine Telegram bildirimi, webhook dispatch, backtesting, trailing stop, weak threshold ve haftalık rapor eklemek.

**Architecture:** Modüler servis pattern — her özellik ayrı sınıf (TelegramNotifier, LeverageWebhookDispatcher, BacktestEngine). LeverageEngine orchestrate eder. Mevcut SendGridMailer.php ve AlertChecker.php pattern'leri referans.

**Tech Stack:** PHP 8.3+, MySQL 8.4, Telegram Bot API, SendGrid API v3, metals.dev API, exchangerate.host API

**Design doc:** `docs/plans/2026-02-25-leverage-enhancements-design.md`

---

## Task 1: Veritabanı Migration

**Files:**
- Create: `database/migrations/014_leverage_enhancements.sql`
- Modify: `database/database.sql`

**Step 1: Migration dosyasını oluştur**

014_leverage_enhancements.sql: leverage_webhooks tablosu, leverage_backtests tablosu, leverage_rules ALTER (trailing_stop_enabled, trailing_stop_type, trailing_stop_pct, peak_price, buy_threshold_weak, sell_threshold_weak), leverage_history ALTER (notification_channel varchar(100), event_type ENUM genişletme), settings seed (telegram_enabled, telegram_bot_token, telegram_chat_id, webhook_enabled, backtesting_enabled, backtesting_default_source, backtesting_metals_dev_api_key, backtesting_exchangerate_host_api_key, leverage_weekly_report_enabled, leverage_weekly_report_day).

Tüm tablolar: ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci. IF NOT EXISTS. FK ile ON DELETE CASCADE.

**Step 2: database.sql güncelle — fresh install için**

**Step 3: Migration çalıştır ve doğrula**

Run: `php database/migrator.php`

**Step 4: Commit**

`git commit -m "feat(db): add leverage_webhooks, leverage_backtests tables and rule enhancements"`

---

## Task 2: Config ve Locales

**Files:**
- Modify: `config.sample.php`
- Modify: `locales/tr.php`, `locales/en.php`, `locales/de.php`, `locales/fr.php`, `locales/ar.php`

**Step 1: config.sample.php — SendGrid bölümünden sonra**

LEVERAGE_TELEGRAM_ENABLED, LEVERAGE_WEBHOOK_ENABLED, BACKTESTING_ENABLED, BACKTESTING_DEFAULT_SOURCE, BACKTESTING_ALLOWED_HOSTS, LEVERAGE_WEEKLY_REPORT_ENABLED, LEVERAGE_WEEKLY_REPORT_DAY

**Step 2: 5 locale dosyası — yeni key'ler**

Kategoriler:
- admin.leverage.section_telegram/webhook/backtesting/weekly_report + alt key'ler
- leverage.form.trailing_stop/weak threshold key'leri
- leverage.backtest.* key'leri
- leverage.history.event.weak_buy_signal/weak_sell_signal/trailing_stop_signal
- leverage.email.subject_weak/subject_trailing + signal label key'leri
- leverage.report.* key'leri
- leverage.telegram.* key'leri

**Step 3: Syntax check tüm dosyalar**

**Step 4: Commit**

`git commit -m "feat: add leverage enhancement config defines and locale translations (5 langs)"`

---

## Task 3: TelegramNotifier.php

**Files:**
- Create: `includes/TelegramNotifier.php`

Pattern referans: SendGridMailer.php (settings resolution, encryption) + AlertChecker::sendTelegramAlert() (cURL, host validation)

**Methodlar:**
- `isEnabled()`: settings.telegram_enabled > config LEVERAGE_TELEGRAM_ENABLED
- `send($chatId, $message)`: Telegram Bot API sendMessage, host validation (api.telegram.org), SSL, timeout 10s
- `sendLeverageSignal($rule, $direction, $changePct, $aiResult, $eventType)`: Formatted HTML signal message
- `sendTestMessage()`: Connection test
- `buildSignalMessage(...)`: HTML formatted Telegram message with signal title, prices, AI, rule info
- `resolveBotToken()`: settings (encrypted) > config ALERT_TELEGRAM_BOT_TOKEN
- `resolveChatId()`: settings > config ALERT_TELEGRAM_CHAT_ID

**Güvenlik:** Host validation, SSL VERIFYPEER+VERIFYHOST, AES-256-GCM encrypted token, htmlspecialchars on all user data.

**Syntax check + Commit**

`git commit -m "feat: add TelegramNotifier for Telegram Bot API signal delivery"`

---

## Task 4: LeverageWebhookDispatcher.php

**Files:**
- Create: `includes/LeverageWebhookDispatcher.php`

Pattern referans: AlertChecker::sendWebhookAlert() (HTTPS-only, SSRF) + WebhookDispatcher.php (multi-URL)

**Methodlar:**
- `isEnabled()`: settings.webhook_enabled > config LEVERAGE_WEBHOOK_ENABLED
- `dispatch($rule, $direction, $changePct, $aiResult, $eventType)`: All active webhooks, returns {sent, failed, errors}
- `buildPayload(...)`: Platform-specific payloads (generic JSON, Discord embeds, Slack blocks)
- `buildGenericPayload()`: {event, direction, currency, prices, ai, timestamp}
- `buildDiscordPayload()`: {embeds: [{title, color, fields, timestamp}]}
- `buildSlackPayload()`: {blocks: [header, section, context]}
- `sendRequest($url, $payload)`: cURL POST, HTTPS-only, CURLPROTO_HTTPS, timeout 10s
- CRUD: `getActiveWebhooks()`, `getAllWebhooks()`, `createWebhook($data)`, `deleteWebhook($id)`, `toggleWebhook($id)`
- `createWebhook()`: HTTPS URL validation, isPrivateOrReservedHost() SSRF check

**Syntax check + Commit**

`git commit -m "feat: add LeverageWebhookDispatcher for multi-platform webhook delivery"`

---

## Task 5: BacktestEngine.php

**Files:**
- Create: `includes/BacktestEngine.php`

**Methodlar:**
- `isEnabled()`: settings.backtesting_enabled > config BACKTESTING_ENABLED
- `run($ruleId, $source, $dateFrom, $dateTo)`: Main entry, fetches data, simulates, saves to leverage_backtests
- `simulateSignals($rule, $priceData)`: Offline checkRule logic — trailing stop, strong threshold, weak threshold, cooldown
- `calculateSummary($signals, $priceData)`: total_return_pct, max_drawdown_pct, win_rate_pct, signal counts
- `fetchFromRateHistory($currencyCode, $dateFrom, $dateTo)`: JOIN rate_history + currencies, ORDER BY scraped_at ASC
- `fetchFromMetalsDev($currencyCode, $dateFrom, $dateTo)`: metals.dev/v1/timeseries API, encrypted key, host whitelist
- `fetchFromExchangeRateHost($currencyCode, $dateFrom, $dateTo)`: exchangerate.host/timeseries API, encrypted key
- `httpGet($url)`: SSL verified, HTTPS-only, timeout 15s, BACKTESTING_ALLOWED_HOSTS check

**Syntax check + Commit**

`git commit -m "feat: add BacktestEngine for rule simulation against historical data"`

---

## Task 6: LeverageEngine Güncellemeleri

**Files:**
- Modify: `includes/LeverageEngine.php`

En kritik task. Mevcut engine'e yeni mantık ekleniyor.

**Step 1: checkRule() — trailing stop mantığı ekle**

Mevcut change hesaplama (line 73) sonrasında, direction belirleme öncesinde:
- trailing_stop_enabled kontrolü
- Auto mod: peak_price güncelleme, drop kontrolü
- Threshold mod: referanstan drop kontrolü
- Tetiklenirse: handleTrailingStopSignal() çağır, return

**Step 2: checkRule() — weak threshold mantığı ekle**

Trailing stop'tan sonra, strong threshold'dan önce:
- buy_threshold_weak ve sell_threshold_weak kontrolü
- Weak bölgede ise (strong'dan önce): handleWeakSignal() çağır
- Weak sinyal: AI tetiklemez, reference güncellemez, direction güncellemez

**Step 3: Mevcut email dispatch'i çoklu kanal dispatch'e çevir**

Mevcut satır 137-148 (sadece email) yerine dispatchAllChannels() çağır:
- Email (SendGridMailer) — mevcut
- Telegram (TelegramNotifier) — class_exists kontrolü
- Webhooks (LeverageWebhookDispatcher) — class_exists kontrolü
- Sonuç: virgülle ayrılmış channel string

**Step 4: Yeni private methodlar**

- `handleTrailingStopSignal()`: History kaydet, dispatchAllChannels, peak+reference reset
- `handleWeakSignal()`: History kaydet, dispatchAllChannels, reference güncellemez
- `dispatchAllChannels()`: Email+Telegram+Webhook dispatch, string[] channel listesi döner

**Step 5: create() ve update() — yeni alanları destekle**

create(): trailing_stop_enabled, trailing_stop_type, trailing_stop_pct, buy_threshold_weak, sell_threshold_weak
update(): Aynı alanlar, partial update

**Step 6: sendTestSignal() — dispatchAllChannels kullan**

Mevcut sendSignalEmail yerine dispatchAllChannels ile tüm kanalları test et.

**Step 7: Syntax check + Commit**

`git commit -m "feat: add trailing stop, weak thresholds, multi-channel dispatch to LeverageEngine"`

---

## Task 7: Cron Dosyaları

**Files:**
- Modify: `cron/check_leverage.php`
- Create: `cron/weekly_leverage_report.php`

**Step 1: check_leverage.php — TelegramNotifier ve LeverageWebhookDispatcher require ekle**

**Step 2: weekly_leverage_report.php oluştur**

Pattern referans: check_leverage.php

Akış:
1. require helpers, SendGridMailer, LeverageEngine
2. cybokron_init(), ensureCliExecution()
3. leverage_weekly_report_enabled kontrolü
4. Bugün doğru gün mü (leverage_weekly_report_day) kontrolü
5. Alıcı kontrolü (leverage_notify_emails)
6. Son 7 günün sinyallerini çek (leverage_history JOIN leverage_rules)
7. Aktif/duraklatılmış kural sayıları
8. HTML + text email oluştur (sinyal tablosu, kural özeti)
9. SendGridMailer::send()
10. Log + echo + exit code

**Syntax check + Commit**

`git commit -m "feat: add weekly leverage report cron and update check_leverage requires"`

---

## Task 8: leverage.php + JS Güncellemeleri

**Files:**
- Modify: `leverage.php`
- Modify: `assets/js/leverage.js`

**Step 1: POST handlers — yeni action'lar**

- `run_backtest`: BacktestEngine::run() çağır
- `create_webhook`, `delete_webhook`, `toggle_webhook`: CRUD

**Step 2: create_rule ve update_rule — yeni alanları ekle**

trailing_stop_enabled, trailing_stop_type, trailing_stop_pct, buy_threshold_weak, sell_threshold_weak

**Step 3: Modal form — trailing stop ve weak threshold UI**

Trailing Stop: checkbox, radio (auto/threshold), pct input
Weak Thresholds: buy_threshold_weak, sell_threshold_weak number inputs

**Step 4: Kural kartları — yeni data-attribute'lar ve badge'ler**

Trailing stop badge, peak price göstergesi, backtest butonu

**Step 5: Backtest modal HTML**

Source select, date range, submit, sonuç tablosu + summary cards

**Step 6: leverage.js — yeni handlers**

Trailing stop checkbox toggle, edit'te yeni alan doldurma, backtest modal open/close

**Syntax check + Commit**

`git commit -m "feat: add trailing stop, weak threshold, backtest UI to leverage page"`

---

## Task 9: Admin Panel Güncellemeleri

**Files:**
- Modify: `admin.php`

**Step 1: save_leverage_settings handler'a ekle**

Telegram (enabled, bot_token encrypted, chat_id), Webhook (enabled), Backtesting (enabled, default_source, metals_dev key encrypted, exchangerate_host key encrypted), Weekly Report (enabled, day)

**Step 2: test_telegram POST handler + action whitelist**

**Step 3: Settings initialization — yeni değişkenler yükle**

Tüm yeni settings'leri DB > config > default pattern'iyle oku. Encrypted key'ler için masked display.

**Step 4: HTML — 4 yeni leverage-section**

1. 📱 Telegram: enabled, bot token (masked), chat ID, test button, setup guide info
2. 🔗 Webhook: enabled, note ("endpoint'ler Kaldıraç sayfasından yönetilir")
3. 📊 Backtesting: enabled, default source select, 2 API key input
4. 📅 Haftalık Rapor: enabled, day select (Pazartesi-Pazar)

**Syntax check + Commit**

`git commit -m "feat: add Telegram, Webhook, Backtesting, Weekly Report settings to admin panel"`

---

## Task 10: database.sql + Test + Versiyon

**Files:**
- Modify: `database/database.sql`
- Modify: `VERSION` (1.13.0)
- Modify: `CHANGELOG.md`

**Step 1: database.sql fresh install güncelle**

**Step 2: Migration çalıştır + PHP syntax check (tüm dosyalar)**

**Step 3: Cron test**

Run: `php cron/check_leverage.php`

**Step 4: Browser doğrulama**

- Admin panel: yeni section'lar görünüyor, değerler kaydediliyor
- Leverage sayfası: trailing stop + weak threshold form çalışıyor, backtest modal açılıyor

**Step 5: VERSION 1.13.0, CHANGELOG, commit + tag + push**

`git commit -m "release: v1.13.0 — Telegram, Webhook, Backtesting, Trailing Stop, Weekly Report"`
`git tag v1.13.0`

---

## Paralelleştirme

```
[Task 1: DB Migration] ──────────────────┐
[Task 2: Config + Locales (5 dil)] ──────┤
[Task 3: TelegramNotifier.php] ──────────┼─→ [Task 6: LeverageEngine güncelleme] ─→ [Task 7: Cron'lar]
[Task 4: WebhookDispatcher.php] ─────────┤                                           │
[Task 5: BacktestEngine.php] ────────────┘                                           │
                                                                                      ↓
                                          [Task 8: leverage.php + JS] ────────→ [Task 10: Test + Release]
                                          [Task 9: admin.php ayarları] ───────┘
```

Task 1-5 paralel. Task 6 bunlara bağlı. Task 7-9 Task 6'dan sonra paralel. Task 10 son.

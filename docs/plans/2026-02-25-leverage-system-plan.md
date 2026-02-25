# Kaldıraç (Leverage) Sistemi — İmplementasyon Planı

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** AI destekli kaldıraç takip sistemi — eşik tetiklendiğinde Gemini ön analiz + SendGrid email bildirimi.

**Architecture:** Mevcut saf PHP yapısına uygun (dosya-per-sayfa pattern). Portföy grup/etiket entegreli. OpenRouter Gemini AI çağrısı mevcut pattern üzerine inşa edilir. SendGrid API v3 saf cURL.

**Tech Stack:** PHP 8.3+, MySQL 8.4, SendGrid API v3, OpenRouter API (Gemini 3.1 Pro Preview)

**Design doc:** `docs/plans/2026-02-25-leverage-system-design.md`

---

## Task 1: Veritabanı Migration

**Files:**
- Create: `database/migrations/013_leverage.sql`
- Modify: `database/database.sql` (sonuna leverage tabloları ekle)

**Step 1: Migration dosyasını oluştur**

013_leverage.sql:
- CREATE TABLE leverage_rules (id, name, source_type ENUM, source_id, currency_code, buy_threshold -15.00, sell_threshold 30.00, reference_price NOT NULL, ai_enabled, strategy_context TEXT, status ENUM active/paused/completed, last_checked_at, last_triggered_at, last_trigger_direction ENUM buy/sell, created_at, updated_at, INDEX idx_status, INDEX idx_currency)
- CREATE TABLE leverage_history (id, rule_id FK CASCADE, event_type ENUM, price_at_event, reference_price_at_event, change_percent, ai_response TEXT, ai_recommendation ENUM, notification_sent, notification_channel, notes TEXT, created_at, INDEX idx_rule, INDEX idx_event)
- Settings seed: leverage_enabled, leverage_ai_model, leverage_ai_enabled, leverage_check_interval_minutes, leverage_cooldown_minutes, sendgrid_enabled, sendgrid_api_key, sendgrid_from_email, sendgrid_from_name, leverage_notify_emails JSON
- ON DUPLICATE KEY UPDATE ile idempotent

**Step 2: database.sql sonuna aynı CREATE TABLE'ları ekle (fresh install için)**

**Step 3: Migration çalıştır**
Run: `php database/migrator.php`
Doğrula: `mysql -u root -p -h 127.0.0.1 cyb_exchange -e "SHOW TABLES LIKE 'leverage%';"`

**Step 4: Commit**
`git commit -m "feat(db): add leverage_rules and leverage_history tables with settings seed"`

---

## Task 2: Config ve Locales

**Files:**
- Modify: `config.sample.php`
- Modify: `config.php` (local, .gitignored)
- Modify: `locales/tr.php`
- Modify: `locales/en.php`

**Step 1: config.sample.php — Leverage + SendGrid section ekle**

Alert Notifications bölümünden sonra:
- LEVERAGE_ENABLED, LEVERAGE_CHECK_INTERVAL_MINUTES(15), LEVERAGE_COOLDOWN_MINUTES(60)
- LEVERAGE_AI_ENABLED, LEVERAGE_AI_MODEL('google/gemini-3.1-pro-preview'), LEVERAGE_AI_MAX_TOKENS(800), LEVERAGE_AI_TIMEOUT_SECONDS(30)
- SENDGRID_ENABLED, SENDGRID_API_KEY(''), SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME, SENDGRID_ALLOWED_HOSTS(['api.sendgrid.com'])

**Step 2: config.php (local) — gerçek SendGrid API key ile**

**Step 3: locales/tr.php — leverage.* key'leri**

nav.leverage, leverage.page_title, leverage.summary.*, leverage.rules.*, leverage.form.*, leverage.message.*, leverage.history.*, leverage.ai.*, leverage.email.*, admin.leverage.*

**Step 4: locales/en.php — aynı key'lerin İngilizce çevirileri**

**Step 5: Commit**
`git commit -m "feat: add leverage config defines and locale translations (tr/en)"`

---

## Task 3: SendGridMailer.php

**Files:**
- Create: `includes/SendGridMailer.php`

**Step 1: Class oluştur**

- `send($to, $subject, $htmlBody, $textBody)`: SendGrid API v3 cURL POST
  - Endpoint: https://api.sendgrid.com/v3/mail/send
  - Auth: Bearer token
  - Body: personalizations[].to[], from, subject, content array (text/plain + text/html)
  - Host doğrulama: SENDGRID_ALLOWED_HOSTS
  - SSL: VERIFYPEER + VERIFYHOST + CURLPROTO_HTTPS
  - Return: `['success' => bool, 'status_code' => int, 'error' => string]`
- `resolveApiKey()`: settings encrypted > config define (mevcut OpenRouter pattern)
- `isEnabled()`: settings > config
- `resolveSetting()`: settings > config > default
- `assertAllowedHost()`: URL host whitelist kontrolü
- `getNotifyEmails()`: settings.leverage_notify_emails JSON decode + FILTER_VALIDATE_EMAIL

**Step 2: Syntax check**
Run: `php -l includes/SendGridMailer.php`

**Step 3: Commit**
`git commit -m "feat: add SendGridMailer for SendGrid API v3 email delivery"`

---

## Task 4: LeverageEngine.php

**Files:**
- Create: `includes/LeverageEngine.php`

Bu en büyük ve kritik dosya. Referans pattern: OpenRouterRateRepair.php ve AlertChecker.php.

**Step 1: Core methods**

- `run()`: Ana döngü (cron çağırır)
  1. Aktif kuralları çek (status='active')
  2. Her kural için checkRule() çağır
  3. Sonuç dön: checked/triggered/sent/errors

- `checkRule(array $rule)`: Tek kural kontrolü
  1. Güncel fiyatı al (AlertChecker::getCurrentRate pattern)
  2. change_percent hesapla: ((current - reference) / reference) * 100
  3. Eşik kontrolü: change <= buy_threshold → buy, change >= sell_threshold → sell
  4. Cooldown kontrolü: last_triggered_at + cooldown dakika
  5. Yön kontrolü: last_trigger_direction aynı ve fiyat referansa dönmedi → skip
  6. AI analiz (ai_enabled ise): requestAiAnalysis()
  7. History kaydet (reference_price_at_event dahil)
  8. Email gönder: sendSignalEmail()
  9. last_triggered_at + last_trigger_direction güncelle

- `requestAiAnalysis()`: OpenRouter API çağrısı
  - resolveModel(): settings.leverage_ai_model > LEVERAGE_AI_MODEL > default
  - resolveApiKey(): settings.openrouter_api_key (encrypted) > OPENROUTER_API_KEY (mevcut key!)
  - buildAiPrompt(): İngilizce prompt, JSON response beklentisi
  - cURL: Mevcut OpenRouterRateRepair::requestModel() pattern'inin aynısı
  - extractJsonPayload(): OpenRouterRateRepair'den aynı mantık
  - Parse hatası → ai_recommendation=NULL, email AI'sız gönderilir

- `buildAiPrompt()`:
  - Asset info (code, name, reference, current, change%)
  - Gold/Silver ratio (getGoldSilverRatio())
  - Trigger info (buy/sell, threshold)
  - Strategy context (sanitized, <user_context> bloğu)
  - Portfolio position (getPortfolioContext())
  - Price trend (getPriceTrendSummary())
  - JSON schema beklentisi

- `getPortfolioContext(array $rule)`: source_type'a göre
  - currency: SUM(amount), AVG(buy_rate) WHERE currency_id AND deleted_at IS NULL
  - group: Aynı + AND group_id = source_id
  - tag: JOIN portfolio_items_tags AND tag_id = source_id

- `getGoldSilverRatio()`: XAU sell_rate / XAG sell_rate (en yüksek sell_rate banka)
  - XAU veya XAG yoksa "N/A"

- `getPriceTrendSummary(string $code)`: rate_history son 30 gün
  - MIN, MAX, AVG hesapla
  - Trend yönü (ilk hafta avg vs son hafta avg)
  - Tek satır özet string

- `sendSignalEmail()`: Email HTML+text oluştur, SendGridMailer::send() çağır
  - Konu: [Cybokron] SAT/AL Sinyali: XAG +31.2% — AI: strong_sell (%87)
  - Gövde: Fiyat özeti, AI analiz, portföy durumu, kural detayları

**Step 2: CRUD helpers**

- `create(array $data)`: INSERT + referans bütünlük kontrolü (source_type + source_id)
- `update(int $id, array $data)`: UPDATE
- `delete(int $id)`: DELETE (CASCADE history)
- `pause(int $id)`: status → paused
- `resume(int $id)`: status → active
- `updateReference(int $id)`: Güncel fiyatı reference_price'a set et

**Step 3: Güvenlik**

- strategy_context: mb_substr($ctx, 0, 500, 'UTF-8') + preg_replace('/[\x00-\x1F\x7F]/', '', $ctx)
- source_type referans bütünlük: group → portfolio_groups'ta var mı, tag → portfolio_tags'te var mı, currency → source_id NULL mı
- CSRF: Sayfa tarafında (leverage.php), engine'de değil

**Step 4: Syntax check**
Run: `php -l includes/LeverageEngine.php`

**Step 5: Commit**
`git commit -m "feat: add LeverageEngine — rule checking, AI analysis, email signals"`

---

## Task 5: Cron Job

**Files:**
- Create: `cron/check_leverage.php`

**Step 1: Cron dosyasını oluştur**

Mevcut check_alerts.php pattern:
- require helpers + SendGridMailer + LeverageEngine
- cybokron_init() + ensureCliExecution()
- LEVERAGE_ENABLED kontrolü
- LeverageEngine::run()
- Log + echo + exit code

**Step 2: Local test**
Run: `php cron/check_leverage.php`
Beklenen: "Leverage: 0 checked, 0 triggered, 0 sent"

**Step 3: Commit**
`git commit -m "feat: add leverage cron job for periodic rule checking"`

---

## Task 6: leverage.php + JS + Header

**Files:**
- Create: `leverage.php`
- Create: `assets/js/leverage.js`
- Modify: `includes/header.php`

**Step 1: leverage.php**

Referans: portfolio.php yapısı.
- require + init + auth + admin check
- POST handlers: create_rule, update_rule, delete_rule, pause_rule, resume_rule, update_reference (CSRF kontrolü)
- Data fetch: aktif kurallar, geçmiş (LIMIT 50), özet istatistikler, currency/group/tag listeleri (modal için)
- $activePage = 'leverage'
- HTML: header include, özet kartları (4'lü), kural kartları (progress bar'lı), modal form, geçmiş tablosu
- Progress bar: Sol=%buy_threshold, orta=referans(%0), sağ=%sell_threshold, işaretçi=güncel fiyat konumu
  - Kırmızı bölge=alış, gri=bekle, yeşil=satış
- Kural kartında: İsim, currency, grup/etiket bilgisi, referans→güncel fiyat, % değişim, son kontrol, AI durumu, aksiyon butonları (düzenle/duraklat/sil/referans güncelle)

**Step 2: assets/js/leverage.js**

- Modal açma/kapama
- Source type radio değiştiğinde: group→grup dropdown göster, tag→etiket dropdown göster, currency→gizle
- "Güncel Fiyatı Al" butonu: fetch(`api.php?action=rates`) → currency_code ile filtrele → input'a set et
- Delete confirm
- Client-side validation

**Step 3: header.php — menü linki**

Admin-only blok içinde, portfolio'dan sonra:
- Desktop: `<a href="leverage.php" class="header-nav-link">Kaldıraç</a>`
- Mobile: `<a href="leverage.php" class="mobile-nav-link"><span>⚡</span> Kaldıraç</a>`
- Active page: `$_headerActivePage === 'leverage'`

**Step 4: Browser doğrulama**
- Login → Kaldıraç menüde görünüyor
- Sayfa yükleniyor, boş durum mesajı
- Modal açılıyor, form çalışıyor
- Kural oluşturuluyor, listede görünüyor

**Step 5: Commit**
`git commit -m "feat: add leverage page with rule management UI and navigation link"`

---

## Task 7: Admin Panel — Leverage Ayarları

**Files:**
- Modify: `admin.php`

**Step 1: POST handlers ekle**

- `save_leverage_settings`: AI model, AI enabled, leverage enabled, SendGrid enabled, SendGrid API key (encrypt), from email/name, notify emails (JSON), intervals
- `test_leverage_email`: SendGridMailer::send() test

**Step 2: HTML card ekle**

admin-card pattern:
- ⚡ icon, "Kaldıraç Ayarları" başlık
- Form: togglelar, text input'lar, API key (masked), email listesi (comma-separated), interval sayısal
- Test email butonu (ayrı form)
- Settings'ten mevcut değerleri oku ve form'a prefill et

**Step 3: Browser doğrulama**
Admin → Leverage Ayarları kartı görünüyor, değerler kaydediliyor.

**Step 4: Commit**
`git commit -m "feat: add leverage settings card to admin panel"`

---

## Task 8: Entegrasyon Testi

**Step 1: Admin ayarları konfigüre et**
- SendGrid API key, from email, alıcılar, AI model

**Step 2: Test email gönder**
Admin → Test Email → Gmail'de kontrol

**Step 3: İlk kural oluştur**
Leverage → Yeni Kural → XAG Gümüş, -15%/+30%, AI aktif, strateji notu

**Step 4: Cron çalıştır**
`php cron/check_leverage.php` → "1 checked, 0 triggered, 0 sent"

**Step 5: Tetikleme testi**
DB'de sell_threshold=0.01 → cron → AI analiz + email → geri al

**Step 6: Playwright testi**
Login → Leverage menü → sayfa → modal → kural oluştur → admin ayarları

**Step 7: Commit**
`git commit -m "test: leverage system end-to-end integration verified"`

---

## Task 9: Versiyon, Changelog, Release

**Files:**
- Modify: `VERSION` → `1.11.0`
- Modify: `CHANGELOG.md`
- Modify: `README.md`

**Step 1: VERSION → 1.11.0**
**Step 2: CHANGELOG.md — v1.11.0 entry**
**Step 3: README.md — features listesine leverage ekle**
**Step 4: Commit + tag**
`git commit -m "release: v1.11.0 — AI-powered leverage tracking system"`
`git tag v1.11.0`

---

## Paralelleştirme

```
[Task 1: DB] ──┐
[Task 2: Config+Locales] ──┼──→ [Task 4: Engine] ──→ [Task 5: Cron]
[Task 3: SendGrid] ──┘         │                       │
                                ├──→ [Task 6: UI+Header]├──→ [Task 8: Test] → [Task 9: Release]
                                └──→ [Task 7: Admin] ──┘
```

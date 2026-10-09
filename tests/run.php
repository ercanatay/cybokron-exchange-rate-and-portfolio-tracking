<?php
/**
 * Minimal no-dependency test suite for CI.
 */

require_once __DIR__ . '/../includes/OpenRouterRateRepair.php';

if (!defined('AVAILABLE_LOCALES')) {
    define('AVAILABLE_LOCALES', ['tr', 'en']);
}
if (!defined('DEFAULT_LOCALE')) {
    define('DEFAULT_LOCALE', 'tr');
}
if (!defined('FALLBACK_LOCALE')) {
    define('FALLBACK_LOCALE', 'en');
}

require_once __DIR__ . '/../includes/helpers.php';

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function assertSameStrict($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' | actual=' . var_export($actual, true) . ' expected=' . var_export($expected, true));
    }
}

function assertTrueStrict(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertSameStrict(normalizeCurrencyCode(' usd '), 'USD', 'normalizeCurrencyCode failed for valid value');
assertSameStrict(normalizeCurrencyCode('DROP TABLE'), null, 'normalizeCurrencyCode failed for invalid value');
assertSameStrict(normalizeBankSlug(' dunya-katilim '), 'dunya-katilim', 'normalizeBankSlug failed for valid value');
assertSameStrict(normalizeBankSlug('..//bad'), null, 'normalizeBankSlug failed for invalid value');

$modelText = <<<TXT
```json
{
  "rates": [
    {"code": "usd", "buy": "43,5865", "sell": "43,7801", "change": "% -0,42"},
    {"code": "EUR", "buy_rate": "47.1000", "sell_rate": "47.5600", "change_percent": "0.12"},
    {"code": "BAD", "buy": 1, "sell": 1}
  ]
}
```
TXT;

$parsed = OpenRouterRateRepair::extractRatesFromModelText($modelText, ['USD', 'EUR', 'XAU']);
assertTrueStrict(count($parsed) === 2, 'AI payload parser should keep only allowed+valid rows');
assertSameStrict($parsed[0]['code'], 'EUR', 'Rates should be sorted by code');
assertSameStrict($parsed[1]['code'], 'USD', 'USD row missing after parse');
assertTrueStrict(abs($parsed[1]['buy'] - 43.5865) < 0.000001, 'Turkish decimal parsing failed for buy value');
assertTrueStrict(abs(($parsed[1]['change'] ?? 0.0) - (-0.42)) < 0.000001, 'Percent parsing failed');

// PortfolioAnalytics
require_once __DIR__ . '/../includes/PortfolioAnalytics.php';
$items = [
    ['currency_code' => 'USD', 'value_try' => 1000, 'cost_try' => 800],
    ['currency_code' => 'EUR', 'value_try' => 500, 'cost_try' => 400],
    ['currency_code' => 'USD', 'value_try' => 500, 'cost_try' => 400],
];
$dist = PortfolioAnalytics::getDistribution($items);
assertTrueStrict(count($dist) === 2, 'Distribution should merge same currency');
assertSameStrict($dist[0]['currency_code'], 'USD', 'USD should be first (larger value)');
assertTrueStrict(abs($dist[0]['value'] - 1500) < 0.01, 'USD total should be 1500');
assertTrueStrict(abs($dist[0]['percent'] - 75) < 0.1, 'USD percent should be 75');

$xirr = PortfolioAnalytics::annualizedReturn(1000, 1100, date('Y-m-d', strtotime('-1 year')));
assertTrueStrict($xirr !== null && $xirr > 0.09 && $xirr < 0.12, 'Annualized return ~10% for 1y');

$oldest = PortfolioAnalytics::getOldestDate([['buy_date' => '2024-01-01'], ['buy_date' => '2023-06-15']]);
assertSameStrict($oldest, '2023-06-15', 'Oldest date should be 2023-06-15');

// normalizeLocale
assertSameStrict(normalizeLocale('tr'), 'tr', 'normalizeLocale tr');
assertSameStrict(normalizeLocale('en'), 'en', 'normalizeLocale en');
assertSameStrict(normalizeLocale('xx'), 'tr', 'normalizeLocale invalid falls back to default');

// requestIsHttps
$originalServer = $_SERVER;
function withServer(array $overrides, callable $fn)
{
    global $originalServer;
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_VISITOR']);
    foreach ($overrides as $k => $v) {
        $_SERVER[$k] = $v;
    }
    return $fn();
}

assertTrueStrict(withServer([], fn () => requestIsHttps() === false), 'requestIsHttps: no signal at all should be false');
assertTrueStrict(withServer(['HTTPS' => 'on'], fn () => requestIsHttps() === true), 'requestIsHttps: HTTPS=on');
assertTrueStrict(withServer(['HTTPS' => '1'], fn () => requestIsHttps() === true), 'requestIsHttps: HTTPS=1');
assertTrueStrict(withServer(['HTTPS' => 'off'], fn () => requestIsHttps() === false), 'requestIsHttps: HTTPS=off alone stays false');
assertTrueStrict(withServer(['SERVER_PORT' => '443'], fn () => requestIsHttps() === true), 'requestIsHttps: port 443 alone');
assertTrueStrict(withServer(['SERVER_PORT' => '80'], fn () => requestIsHttps() === false), 'requestIsHttps: port 80 alone stays false');
assertTrueStrict(
    withServer(['HTTP_X_FORWARDED_PROTO' => 'https'], fn () => requestIsHttps() === true),
    'requestIsHttps: X-Forwarded-Proto https — the Cloudflare-Flexible case this guards against'
);
assertTrueStrict(withServer(['HTTP_X_FORWARDED_PROTO' => 'http'], fn () => requestIsHttps() === false), 'requestIsHttps: X-Forwarded-Proto http alone stays false');
assertTrueStrict(
    withServer(['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], fn () => requestIsHttps() === true),
    'requestIsHttps: X-Forwarded-Proto is case-insensitive'
);
assertTrueStrict(
    withServer(['HTTP_X_FORWARDED_PROTO' => 'https, http'], fn () => requestIsHttps() === true),
    'requestIsHttps: comma-separated X-Forwarded-Proto takes the first hop'
);
assertTrueStrict(
    withServer(['HTTP_CF_VISITOR' => '{"scheme":"https"}'], fn () => requestIsHttps() === true),
    'requestIsHttps: CF-Visitor scheme https'
);
assertTrueStrict(withServer(['HTTP_CF_VISITOR' => '{"scheme":"http"}'], fn () => requestIsHttps() === false), 'requestIsHttps: CF-Visitor scheme http alone stays false');

// --- Enflasyon korumalı hedef: zaman ağırlıklı hesap ---
// Eski formül (maliyet × (1 + yıllık enflasyon)) alış tarihine bakmıyordu; bugün
// eklenen bir kalem, hiç zaman geçmemiş olmasına rağmen hedefi bir tam yıllık
// enflasyon kadar sıçratıyordu. Aşağıdaki testler bu regresyonu kilitler.
require_once __DIR__ . '/../includes/Portfolio.php';

$yearsHeld = new ReflectionMethod('Portfolio', 'yearsHeld');
$yearsHeld->setAccessible(true);
$inflationExponent = new ReflectionMethod('Portfolio', 'inflationExponent');
$inflationExponent->setAccessible(true);

$refToday = new DateTimeImmutable('2026-09-21');
$held = fn (?string $d): float => $yearsHeld->invoke(null, $d, $refToday);
$factor = fn (float $years, float $mult): float => $mult ** $inflationExponent->invoke(null, $years);

assertSameStrict($held('2026-09-21'), 0.0, 'yearsHeld: bugün alınan kalem 0 yıl');
assertSameStrict($held('2027-01-01'), 0.0, 'yearsHeld: gelecek tarih 0 yıl — hedefi şişirmemeli');
assertSameStrict($held(null), 0.0, 'yearsHeld: null tarih 0 yıl');
assertSameStrict($held('   '), 0.0, 'yearsHeld: boş tarih 0 yıl');
assertSameStrict($held('not-a-date'), 0.0, 'yearsHeld: ayrıştırılamayan tarih 0 yıl');
assertTrueStrict(abs($held('2025-09-21') - (365 / 365.25)) < 0.000001, 'yearsHeld: tam bir yıl ≈ 1.0');
assertTrueStrict(abs($held('2026-03-21') - (184 / 365.25)) < 0.000001, 'yearsHeld: altı ay ≈ 0.5');

$mult = 1.5313; // %53,13 yıllık enflasyon

assertTrueStrict(
    abs(324348.11 * $factor($held('2026-09-21'), $mult) - 324348.11) < 0.000001,
    'Bugün alınan kalem enflasyon hedefine yalnızca maliyeti kadar katkı vermeli'
);
assertTrueStrict(
    abs(1000.0 * $factor($held('2025-09-21'), $mult) - 1000.0 * $mult) < 1.0,
    'Bir yıl tutulan kalem ≈ maliyet × (1 + enflasyon) olmalı'
);
assertTrueStrict(
    1000.0 * $factor($held('2026-03-21'), $mult) < 1000.0 * $mult,
    'Altı ay tutulan kalem bir yıllık şişmeden az olmalı'
);
// Bileşik (1+r)^0.5 ≈ 1.2395, doğrusal 1 + r/2 = 1.2657. Doğrusal yaklaşım kısa
// vadede hedefi kasıtsızca yukarı çeker; bileşik olanı kullandığımızı kilitle.
assertTrueStrict(
    1000.0 * $factor($held('2026-03-21'), $mult) < 1000.0 * (1 + (($mult - 1) / 2)),
    'Altı aylık şişme bileşik olmalı — doğrusal orantıdan düşük kalmalı'
);
assertTrueStrict(
    abs(1000.0 * $factor($held('2026-03-21'), $mult) - 1000.0 * sqrt($mult)) < 5.0,
    'Altı aylık şişme karekök çarpanına yakın olmalı'
);

// --- Enflasyon korumalı hedef: aylık seri ---
// Her kalem alış gününden bugüne gerçekleşen aylık enflasyonla, gün bazında
// bileşik büyütülür. Beklenen değerler bağımsız bir Python hesabından alındı.
require_once __DIR__ . '/../includes/InflationProvider.php';

$enag = [
    '2025-12' => 2.11, '2026-01' => 6.32, '2026-02' => 4.01, '2026-03' => 4.10, '2026-04' => 5.07,
    '2026-05' => 2.16, '2026-06' => 1.94, '2026-07' => 3.07, '2026-08' => 2.24, '2026-09' => 2.10,
];
$tuik = [
    '2025-12' => 0.89, '2026-01' => 4.84, '2026-02' => 2.96, '2026-03' => 1.94, '2026-04' => 4.18,
    '2026-05' => 1.71, '2026-06' => 0.99, '2026-07' => 1.78, '2026-08' => 1.84, '2026-09' => 1.84,
];
$cf = fn (string $buy, string $today, array $series): float =>
    InflationProvider::compoundFactor(new DateTimeImmutable($buy), new DateTimeImmutable($today), $series);

assertSameStrict($cf('2026-10-09', '2026-10-09', $enag), 1.0, 'compoundFactor: bugün alınan kalem 1.0');
assertSameStrict($cf('2026-11-01', '2026-10-09', $enag), 1.0, 'compoundFactor: gelecek tarih 1.0');
assertSameStrict($cf('2025-12-11', '2026-10-09', []), 1.0, 'compoundFactor: boş seri 1.0 (yıllık orana düşülür)');
assertTrueStrict(
    abs($cf('2026-09-01', '2026-10-01', $enag) - 1.021) < 1e-12,
    'compoundFactor: tam bir ay = 1 + aylık oran'
);
assertTrueStrict(
    abs($cf('2026-01-01', '2026-03-01', $enag) - (1.0632 * 1.0401)) < 1e-12,
    'compoundFactor: ardışık tam aylar çarpılır'
);
assertTrueStrict(
    abs($cf('2025-12-11', '2026-10-09', $enag) - 1.38196214879137) < 1e-9,
    'compoundFactor: ENAG 11.12.2025 → 09.10.2026 Python hesabıyla aynı'
);
assertTrueStrict(
    abs($cf('2025-12-11', '2026-10-09', $tuik) - 1.2563655832111453) < 1e-9,
    'compoundFactor: TÜİK 11.12.2025 → 09.10.2026 Python hesabıyla aynı'
);
assertTrueStrict(
    abs($cf('2026-02-25', '2026-10-09', $enag) - 1.239088588923819) < 1e-9,
    'compoundFactor: ENAG 25.02.2026 → 09.10.2026 Python hesabıyla aynı'
);
// Henüz açıklanmamış ay (Ekim) son açıklanan ayın (Eylül) oranını taşır.
assertTrueStrict(
    abs($cf('2026-10-01', '2026-10-16', $enag) - (1.021 ** (15 / 31))) < 1e-12,
    'compoundFactor: açıklanmamış ay son açıklanan ayın oranıyla'
);
// Seri ortasındaki boşluk bir önceki ayın oranını alır, serinin ilk ayını değil.
$gapped = ['2026-01' => 5.0, '2026-02' => 1.0, '2026-04' => 3.0];
assertTrueStrict(
    abs($cf('2026-03-01', '2026-04-01', $gapped) - 1.01) < 1e-12,
    'compoundFactor: eksik ay bir önceki açıklanan ayın oranıyla'
);
// Serinin başlangıcından önceki aylar serinin ilk ayının oranını alır.
assertTrueStrict(
    abs($cf('2025-12-01', '2026-01-01', $gapped) - 1.05) < 1e-12,
    'compoundFactor: seriden önceki ay serinin ilk oranıyla'
);
assertTrueStrict(InflationProvider::isValidPeriod('2026-09'), 'isValidPeriod: geçerli dönem');
assertTrueStrict(!InflationProvider::isValidPeriod('2026-13'), 'isValidPeriod: 13. ay geçersiz');
assertTrueStrict(!InflationProvider::isValidPeriod('2026-9'), 'isValidPeriod: tek haneli ay geçersiz');

// --- Satır bazında enflasyon hedefi ---
// Her kalem kendi hedefini taşır; kart toplamı bu satırların toplamıdır.
$withTargets = new ReflectionMethod('Portfolio', 'withInflationTargets');
$withTargets->setAccessible(true);
$todayStr = (new DateTimeImmutable('today'))->format('Y-m-d');
$rowItems = $withTargets->invoke(null, [
    ['cost_try' => 1000.0, 'value_try' => 1100.0, 'value_try_buy' => 1050.0, 'buy_date' => $todayStr],
    ['cost_try' => 2000.0, 'value_try' => 2000.0, 'value_try_buy' => 1900.0, 'buy_date' => '2026-01-01'],
], 'monthly', $enag, $tuik, 1.4661);
assertSameStrict($rowItems[0]['inflation_target'], 1000.0, 'Satır hedefi: bugün alınan kalemde hedef = maliyet');
assertSameStrict($rowItems[0]['inflation_target_tuik'], 1000.0, 'Satır TÜİK hedefi: bugün alınan kalemde = maliyet');
assertTrueStrict(abs($rowItems[0]['inflation_gap_percent'] - 5.0) < 1e-9, 'Satır reel farkı alış değerine göre: 1050 / 1000 = +%5');
$expectEnag = 2000.0 * InflationProvider::compoundFactor(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('today'), $enag);
assertTrueStrict(abs($rowItems[1]['inflation_target'] - $expectEnag) < 1e-6, 'Satır hedefi compoundFactor ile aynı');
assertTrueStrict($rowItems[1]['inflation_target_tuik'] < $rowItems[1]['inflation_target'], 'TÜİK satır hedefi ENAG hedefinin altında');
assertTrueStrict($rowItems[1]['inflation_gap_percent'] < 0, 'Hedefin altındaki kalemde reel fark negatif');
$noTuik = $withTargets->invoke(null, [['cost_try' => 1000.0, 'value_try' => 1000.0, 'buy_date' => '2026-01-01']], 'monthly', $enag, [], 1.4661);
assertSameStrict($noTuik[0]['inflation_target_tuik'], null, 'TÜİK serisi yoksa satır TÜİK hedefi null');

// --- 1 yıllık hedef: gerçekleşen + tahmin ---
// Alış → bugün gerçekleşen aylık seriyle, bugün → alış+1 yıl yıllık oranla.
$oy = fn (string $buy, string $today, array $series, float $mult): array =>
    InflationProvider::oneYearFactor(new DateTimeImmutable($buy), new DateTimeImmutable($today), $series, $mult);

$r = $oy('2026-10-09', '2026-10-09', $enag, 1.4661);
assertTrueStrict(abs($r['factor'] - 1.4661 ** (365 / 365.25)) < 1e-12, '1 yıl: bugün alınan kalem tamamen yıllık oranla tahmin edilir');
assertSameStrict($r['horizon']->format('Y-m-d'), '2027-10-09', '1 yıl: hedef tarihi alış + 1 yıl');

$r = $oy('2026-02-25', '2026-10-09', $enag, 1.4661);
$expect = $cf('2026-02-25', '2026-10-09', $enag) * 1.4661 ** (139 / 365.25);
assertTrueStrict(abs($r['factor'] - $expect) < 1e-12, '1 yıl: gerçekleşen kısım × kalan 139 günün tahmini');

$r = $oy('2025-12-11', '2027-03-01', $enag, 1.4661);
assertTrueStrict(abs($r['factor'] - $cf('2025-12-11', '2026-12-11', $enag)) < 1e-12, '1 yıl: yılı dolmuş kalemde tahmin yok, tamamen gerçekleşen');

$r = $oy('2026-02-25', '2026-10-09', [], 1.4661);
assertTrueStrict(abs($r['factor'] - 1.4661 ** (365 / 365.25)) < 1e-12, '1 yıl: seri yoksa tüm yıl yıllık oranla');

$r = $oy('2028-02-29', '2028-02-29', $enag, 1.0);
assertSameStrict($r['horizon']->format('Y-m-d'), '2029-03-01', '1 yıl: 29 Şubat alımı PHP +1 year ile 1 Mart');
assertSameStrict($r['factor'], 1.0, '1 yıl: yıllık oran 0 iken tahmin çarpanı 1');

$todayDt = new DateTimeImmutable('today');
$yearDays = (int) $todayDt->diff($todayDt->modify('+1 year'))->days; // 365 ya da artık yılda 366
$rowOneYear = 1000.0 * 1.4661 ** ($yearDays / 365.25);
assertTrueStrict(abs($rowItems[0]['inflation_target_1y'] - $rowOneYear) < 1e-6, 'Satır 1 yıllık hedefi: bugün alınan kalem');
assertTrueStrict($rowItems[1]['inflation_target_1y'] > $rowItems[1]['inflation_target'], 'Satır 1 yıllık hedefi bugünkü hedeften büyük');
assertTrueStrict(
    abs($rowItems[0]['inflation_target_1y_needed_percent'] - ($rowOneYear / 1050.0 - 1) * 100) < 1e-9,
    'Satır gereken artış: alış değerinden 1 yıllık hedefe'
);

fwrite(STDOUT, "All tests passed.\n");

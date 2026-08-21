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

fwrite(STDOUT, "All tests passed.\n");

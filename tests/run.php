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

fwrite(STDOUT, "All tests passed.\n");

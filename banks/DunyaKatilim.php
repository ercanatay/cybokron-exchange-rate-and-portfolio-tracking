<?php
/**
 * DunyaKatilim.php — Dunya Katilim Bank Scraper
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Scrapes exchange rates from: https://dunyakatilim.com.tr/gunluk-kurlar
 *
 * Table structure (as of 2025):
 * | Currency | Bank Buy | Bank Sell | Change |
 * Currencies: USD, EUR, GBP, XAU, XAG, AUD, CAD, CNY, JPY, SAR, CHF, AED, XPT, XPD
 */

class DunyaKatilim extends Scraper
{
    protected string $bankName = 'Dünya Katılım';
    protected string $bankSlug = 'dunya-katilim';
    protected string $url = 'https://dunyakatilim.com.tr/gunluk-kurlar';
    /** @var array<string, string>|null */
    private ?array $normalizedCurrencyMap = null;
    /** @var array<string, true>|null */
    private ?array $currencyCodeSet = null;

    /**
     * Currency code mapping from Turkish names to ISO codes.
     * Used for auto-detection when table structure changes.
     */
    private array $currencyMap = [
        'amerikan doları'                     => 'USD',
        'usd'                                 => 'USD',
        'euro'                                => 'EUR',
        'eur'                                 => 'EUR',
        'ingiliz sterlini'                    => 'GBP',
        'İngiliz sterlini'                    => 'GBP',
        'gbp'                                 => 'GBP',
        'altın'                               => 'XAU',
        'altin'                               => 'XAU',
        'xau'                                 => 'XAU',
        'gümüş'                               => 'XAG',
        'gumus'                               => 'XAG',
        'xag'                                 => 'XAG',
        'avustralya doları'                   => 'AUD',
        'aud'                                 => 'AUD',
        'kanada doları'                       => 'CAD',
        'cad'                                 => 'CAD',
        'çin yuanı'                           => 'CNY',
        'cin yuani'                           => 'CNY',
        'cny'                                 => 'CNY',
        'japon yeni'                          => 'JPY',
        'jpy'                                 => 'JPY',
        'suudi riyali'                        => 'SAR',
        'sar'                                 => 'SAR',
        'isviçre frangı'                      => 'CHF',
        'İsviçre frangı'                      => 'CHF',
        'chf'                                 => 'CHF',
        'birleşik arap emirlikleri dirhemi'   => 'AED',
        'bae dirhemi'                         => 'AED',
        'aed'                                 => 'AED',
        'platin'                              => 'XPT',
        'xpt'                                 => 'XPT',
        'paladyum'                            => 'XPD',
        'xpd'                                 => 'XPD',
    ];

    /**
     * Scrape the exchange rate table.
     */
    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $rates = [];

        // Find all table rows (skip header)
        $rows = $xpath->query('//table//tbody//tr');

        if ($rows->length === 0) {
            // Fallback: try all tr elements in table
            $rows = $xpath->query('//table//tr');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);

            if ($cells->length < 3) {
                continue; // Skip rows without enough data
            }

            // Auto-detect: first cell = currency name, then buy, sell, change
            $currencyText = $this->cleanText($cells->item(0)->textContent);
            $code = $this->detectCurrencyCode($currencyText);

            if (!$code) {
                continue; // Unknown currency, skip
            }

            // Determine column positions dynamically
            $buyRate = null;
            $sellRate = null;
            $changePercent = null;

            // Try standard layout: col 1 = buy, col 2 = sell, col 3 = change
            if ($cells->length >= 4) {
                $buyRate = $this->parseNumber($cells->item(1)->textContent);
                $sellRate = $this->parseNumber($cells->item(2)->textContent);
                $changePercent = $this->parsePercent($cells->item(3)->textContent);
            } elseif ($cells->length === 3) {
                $buyRate = $this->parseNumber($cells->item(1)->textContent);
                $sellRate = $this->parseNumber($cells->item(2)->textContent);
            }

            if ($buyRate === null || $sellRate === null) {
                continue; // Could not parse rates
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => $changePercent,
            ];
        }

        $minimumRates = defined('OPENROUTER_MIN_EXPECTED_RATES')
            ? max(1, (int) OPENROUTER_MIN_EXPECTED_RATES)
            : 8;

        if (count($rates) < $minimumRates) {
            $aiRates = $this->attemptOpenRouterRateRecovery($html, $minimumRates, $tableHash);
            if (!empty($aiRates)) {
                $rates = $this->mergeRatesByCode($rates, $aiRates);
                cybokron_log(
                    "OpenRouter fallback added rate rows for {$this->bankSlug}. Parsed: "
                    . count($rates) . ", minimum expected: {$minimumRates}",
                    'INFO'
                );
            }
        }

        return $rates;
    }

    /**
     * Detect currency ISO code from text.
     * Handles various formats:
     *   "Amerikan dolari (USD)" -> USD
     *   "Amerikan dolari Amerikan dolari (USD) Amerikan dolari" -> USD
     *   "USD" -> USD
     */
    private function detectCurrencyCode(string $text): ?string
    {
        $currencyCodeSet = $this->getCurrencyCodeSet();
        $normalizedMap = $this->getNormalizedCurrencyMap();

        // Try to extract code from parentheses: (USD), (EUR), etc.
        if (preg_match('/\(([A-Z]{3})\)/u', $text, $m)) {
            $code = strtoupper($m[1]);
            if (isset($currencyCodeSet[$code])) {
                return $code;
            }
        }

        $normalizedText = $this->normalizeLookupKey($text);
        if ($normalizedText === '') {
            return null;
        }

        // O(1) exact map lookups for the most common row formats.
        if (isset($normalizedMap[$normalizedText])) {
            return $normalizedMap[$normalizedText];
        }

        if (preg_match('/\b([A-Z]{3})\b/u', strtoupper($normalizedText), $m)) {
            $code = strtoupper($m[1]);
            if (isset($currencyCodeSet[$code])) {
                return $code;
            }
        }

        // Fallback for noisy rows where labels include extra repeated words.
        foreach ($normalizedMap as $name => $code) {
            if ($name !== '' && mb_strpos($normalizedText, $name, 0, 'UTF-8') !== false) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Parse a numeric value from text.
     * Handles Turkish format: 7.049,5249 or 43.5865
     */
    private function parseNumber(string $text): ?float
    {
        $text = trim($text);
        $text = preg_replace('/[^\d.,\-]/', '', $text);

        if ($text === '') {
            return null;
        }

        // Detect Turkish format (dot as thousands, comma as decimal)
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // Format: 7.049,5249 (Turkish)
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            }
            // else Format: 1,234.56 (English) — keep as-is
        } elseif ($lastComma !== false && $lastDot === false) {
            // Could be Turkish decimal: 0,2810
            $text = str_replace(',', '.', $text);
        }

        $text = str_replace(',', '', $text);
        $value = (float) $text;

        return $value > 0 ? $value : null;
    }

    /**
     * Parse a percentage value.
     * Handles: "% 0.02", "0.02%", "-0.42%", "% -0.42"
     */
    private function parsePercent(string $text): ?float
    {
        $text = trim($text);
        $text = str_replace(['%', ' '], '', $text);
        $text = str_replace(',', '.', $text);

        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * Clean text: trim, normalize whitespace.
     */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Normalize lookup keys for currency matching.
     */
    private function normalizeLookupKey(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return is_string($normalized) ? $normalized : '';
    }

    /**
     * Build normalized map once for O(1) exact lookup.
     *
     * @return array<string, string>
     */
    private function getNormalizedCurrencyMap(): array
    {
        if ($this->normalizedCurrencyMap !== null) {
            return $this->normalizedCurrencyMap;
        }

        $this->normalizedCurrencyMap = [];
        foreach ($this->currencyMap as $name => $code) {
            $normalizedName = $this->normalizeLookupKey($name);
            if ($normalizedName !== '') {
                $this->normalizedCurrencyMap[$normalizedName] = $code;
            }
        }

        return $this->normalizedCurrencyMap;
    }

    /**
     * Build known ISO code set once for O(1) membership checks.
     *
     * @return array<string, true>
     */
    private function getCurrencyCodeSet(): array
    {
        if ($this->currencyCodeSet !== null) {
            return $this->currencyCodeSet;
        }

        $this->currencyCodeSet = [];
        foreach ($this->currencyMap as $code) {
            $normalizedCode = strtoupper(trim((string) $code));
            if ($normalizedCode !== '') {
                $this->currencyCodeSet[$normalizedCode] = true;
            }
        }

        return $this->currencyCodeSet;
    }
}

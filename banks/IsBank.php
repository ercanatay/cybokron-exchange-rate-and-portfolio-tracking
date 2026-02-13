<?php
/**
 * IsBank.php — İş Bankası Scraper
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Scrapes exchange rates from: https://kur.doviz.com/isbankasi
 *
 * Table structure:
 * | Currency | Buy | Sell | Time |
 * Currencies: USD, EUR, GBP, CHF, CAD, AUD, DKK, SEK, NOK, JPY, KWD, SAR
 */

class IsBank extends Scraper
{
    protected string $bankName = 'İş Bankası';
    protected string $bankSlug = 'is-bankasi';
    protected string $url = 'https://kur.doviz.com/isbankasi';

    /**
     * Currency code mapping from Turkish names to ISO codes.
     */
    private array $currencyMap = [
        'amerikan doları'           => 'USD',
        'usd'                       => 'USD',
        'euro'                      => 'EUR',
        'eur'                       => 'EUR',
        'ingiliz sterlini'          => 'GBP',
        'İngiliz sterlini'          => 'GBP',
        'gbp'                       => 'GBP',
        'isviçre frangı'            => 'CHF',
        'İsviçre frangı'            => 'CHF',
        'chf'                       => 'CHF',
        'kanada doları'             => 'CAD',
        'cad'                       => 'CAD',
        'avustralya doları'         => 'AUD',
        'aud'                       => 'AUD',
        'danimarka kronu'           => 'DKK',
        'dkk'                       => 'DKK',
        'isveç kronu'               => 'SEK',
        'İsveç kronu'               => 'SEK',
        'sek'                       => 'SEK',
        'norveç kronu'              => 'NOK',
        'norvec kronu'              => 'NOK',
        'nok'                       => 'NOK',
        'japon yeni'                => 'JPY',
        'jpy'                       => 'JPY',
        'kuveyt dinarı'             => 'KWD',
        'kuveyt dinari'             => 'KWD',
        'kwd'                       => 'KWD',
        'suudi arabistan riyali'    => 'SAR',
        'suudi riyali'              => 'SAR',
        'sar'                       => 'SAR',
    ];

    /**
     * Scrape the exchange rate table from doviz.com.
     */
    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $rates = [];

        // Find all table rows in tbody
        $rows = $xpath->query('//table//tbody//tr');

        if ($rows->length === 0) {
            // Fallback: try all tr elements
            $rows = $xpath->query('//table//tr');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);

            if ($cells->length < 3) {
                continue; // Skip rows without enough cells
            }

            // Extract currency code from first cell
            $currencyCell = $cells->item(0);
            if (!$currencyCell) {
                continue;
            }

            // Try to find currency code in div with class "currency-details"
            $codeNode = $xpath->query('.//div[@class="currency-details"]/div[1]', $currencyCell)->item(0);
            $nameNode = $xpath->query('.//div[@class="currency-details"]/div[2]', $currencyCell)->item(0);

            $code = '';
            if ($codeNode) {
                $code = strtoupper(trim($codeNode->textContent));
            }

            // If code not found, try to extract from currency name
            if ($code === '' && $nameNode) {
                $name = mb_strtolower(trim($nameNode->textContent), 'UTF-8');
                $code = $this->mapCurrencyName($name);
            }

            // Skip if we couldn't determine the currency code
            if ($code === '' || !in_array($code, $this->getKnownCurrencyCodes(), true)) {
                continue;
            }

            // Extract buy rate (2nd cell)
            $buyCell = $cells->item(1);
            $buyRate = $buyCell ? $this->parseRate($buyCell->textContent) : null;

            // Extract sell rate (3rd cell)
            $sellCell = $cells->item(2);
            $sellRate = $sellCell ? $this->parseRate($sellCell->textContent) : null;

            // Skip if rates are invalid
            if ($buyRate === null || $sellRate === null || $buyRate <= 0 || $sellRate <= 0) {
                continue;
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => null, // doviz.com doesn't provide change percentage
            ];
        }

        // If we got fewer than 8 rates, something might be wrong
        if (count($rates) < 8) {
            cybokron_log(
                "İş Bankası scraper: Only " . count($rates) . " rates found, expected at least 8",
                'WARNING'
            );
        }

        return $rates;
    }

    /**
     * Map Turkish currency name to ISO code.
     */
    private function mapCurrencyName(string $name): string
    {
        $normalized = mb_strtolower(trim($name), 'UTF-8');
        $normalized = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'g', 'u', 's', 'o', 'c'], $normalized);

        return $this->currencyMap[$normalized] ?? '';
    }

    /**
     * Parse rate string to float.
     */
    private function parseRate(string $text): ?float
    {
        // Remove whitespace and convert Turkish decimal separator
        $cleaned = str_replace([' ', '.', ','], ['', '', '.'], trim($text));

        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        $rate = (float) $cleaned;

        return $rate > 0 ? $rate : null;
    }
}

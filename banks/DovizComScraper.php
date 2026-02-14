<?php
/**
 * DovizComScraper.php — Generic kur.doviz.com Scraper
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Scrapes exchange rates from any bank page on kur.doviz.com.
 * All banks share the same HTML table structure:
 * | Currency | Buy | Sell | Time |
 *
 * Bank-specific data (name, slug, url) is injected via setBankData().
 */

class DovizComScraper extends Scraper
{
    protected string $bankName = '';
    protected string $bankSlug = '';
    protected string $url = '';

    /**
     * Currency code mapping from Turkish names to ISO codes.
     */
    private array $currencyMap = [
        'amerikan doları'           => 'USD',
        'amerikan dolari'           => 'USD',
        'usd'                       => 'USD',
        'euro'                      => 'EUR',
        'eur'                       => 'EUR',
        'ingiliz sterlini'          => 'GBP',
        'İngiliz sterlini'          => 'GBP',
        'gbp'                       => 'GBP',
        'isviçre frangı'            => 'CHF',
        'isvicre frangi'            => 'CHF',
        'İsviçre frangı'            => 'CHF',
        'chf'                       => 'CHF',
        'kanada doları'             => 'CAD',
        'kanada dolari'             => 'CAD',
        'cad'                       => 'CAD',
        'avustralya doları'         => 'AUD',
        'avustralya dolari'         => 'AUD',
        'aud'                       => 'AUD',
        'danimarka kronu'           => 'DKK',
        'dkk'                       => 'DKK',
        'isveç kronu'               => 'SEK',
        'isvec kronu'               => 'SEK',
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
        'çin yuanı'                 => 'CNY',
        'cin yuani'                 => 'CNY',
        'cny'                       => 'CNY',
        'katar riyali'              => 'QAR',
        'qar'                       => 'QAR',
        'bae dirhemi'               => 'AED',
        'aed'                       => 'AED',
        'rus rublesi'               => 'RUB',
        'rub'                       => 'RUB',
        'rumen leyi'                => 'RON',
        'ron'                       => 'RON',
        'pakistan rupisi'            => 'PKR',
        'pkr'                       => 'PKR',
        'güney kore wonu'           => 'KRW',
        'guney kore wonu'           => 'KRW',
        'krw'                       => 'KRW',
        'azerbaycan manatı'         => 'AZN',
        'azerbaycan manati'         => 'AZN',
        'azn'                       => 'AZN',
        'kazakistan tengesi'        => 'KZT',
        'kzt'                       => 'KZT',
        'özel çekme hakkı'          => 'XDR',
        'ozel cekme hakki'          => 'XDR',
        'xdr'                       => 'XDR',
        'altın'                     => 'XAU',
        'altin'                     => 'XAU',
        'xau'                       => 'XAU',
        'gümüş'                     => 'XAG',
        'gumus'                     => 'XAG',
        'xag'                       => 'XAG',
        'platin'                    => 'XPT',
        'xpt'                       => 'XPT',
        'paladyum'                  => 'XPD',
        'xpd'                       => 'XPD',
    ];

    /**
     * Scrape the exchange rate table from kur.doviz.com.
     */
    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $rates = [];

        // Target the exchange rates table (id="indexes" on kur.doviz.com)
        $rows = $xpath->query('//table[@id="indexes"]//tr');

        if ($rows->length === 0) {
            $rows = $xpath->query('//table//tbody//tr');
        }

        if ($rows->length === 0) {
            $rows = $xpath->query('//table//tr');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);

            if ($cells->length < 3) {
                continue;
            }

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

            if ($code === '' || !in_array($code, $this->getKnownCurrencyCodes(), true)) {
                continue;
            }

            // Extract buy rate (2nd cell)
            $buyCell = $cells->item(1);
            $buyRate = $buyCell ? $this->parseRate($buyCell->textContent) : null;

            // Extract sell rate (3rd cell)
            $sellCell = $cells->item(2);
            $sellRate = $sellCell ? $this->parseRate($sellCell->textContent) : null;

            if ($buyRate === null || $sellRate === null || $buyRate <= 0 || $sellRate <= 0) {
                continue;
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => null,
            ];
        }

        // Some banks may offer fewer currencies
        if (count($rates) < 3) {
            cybokron_log(
                "{$this->bankName} scraper: Only " . count($rates) . " rates found, expected at least 3",
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
     * Parse rate string to float (Turkish format: 42,5690).
     */
    private function parseRate(string $text): ?float
    {
        $cleaned = str_replace([' ', '.', ','], ['', '', '.'], trim($text));

        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        $rate = (float) $cleaned;

        return $rate > 0 ? $rate : null;
    }
}

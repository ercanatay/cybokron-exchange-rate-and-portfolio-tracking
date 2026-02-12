<?php
/**
 * TCMB.php — TCMB (Central Bank of Turkey) Exchange Rate Fetcher
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Fetches official rates from: https://www.tcmb.gov.tr/kurlar/today.xml
 * Uses XML parsing (not HTML scraping).
 */

class TCMB extends Scraper
{
    protected string $bankName = 'TCMB';
    protected string $bankSlug = 'tcmb';
    protected string $url = 'https://www.tcmb.gov.tr/kurlar/today.xml';

    /**
     * TCMB returns XML, not HTML. Override run() to fetch XML and parse.
     */
    public function run(): array
    {
        $this->init();
        $startTime = microtime(true);

        try {
            $xmlContent = $this->fetchPage($this->url);
            $rates = $this->parseXml($xmlContent);

            if (empty($rates)) {
                throw new RuntimeException("No rates parsed from TCMB XML.");
            }

            $scrapedAt = date('Y-m-d H:i:s');
            $savedCount = $this->saveRates($rates, $scrapedAt);

            Database::update('banks', ['last_scraped_at' => $scrapedAt], 'id = ?', [$this->bankId]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logScrape('success', "Scraped {$savedCount} rates from TCMB XML.", $savedCount, $durationMs);

            return [
                'status'        => 'success',
                'bank'          => $this->bankName,
                'rates_count'   => $savedCount,
                'table_changed' => false,
                'duration_ms'   => $durationMs,
            ];
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logScrape('error', $e->getMessage(), 0, $durationMs);

            return [
                'status'  => 'error',
                'bank'    => $this->bankName,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse TCMB XML and return rate array compatible with saveRates.
     *
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>
     */
    private function parseXml(string $xmlContent): array
    {
        $dom = new DOMDocument();
        $useErrors = libxml_use_internal_errors(true);

        if ($dom->loadXML($xmlContent) === false) {
            libxml_use_internal_errors($useErrors);
            throw new RuntimeException("Invalid XML from TCMB.");
        }

        libxml_use_internal_errors($useErrors);

        $currencies = $dom->getElementsByTagName('Currency');
        $knownCodes = array_fill_keys($this->getKnownCurrencyCodes(), true);
        $rates = [];

        foreach ($currencies as $node) {
            $code = strtoupper(trim((string) ($node->getAttribute('Kod') ?? $node->getAttribute('CurrencyCode') ?? '')));
            if ($code === '' || !isset($knownCodes[$code])) {
                continue;
            }

            $unit = (float) ($this->getNodeText($node, 'Unit') ?: 1);
            if ($unit <= 0) {
                $unit = 1;
            }

            $forexBuy = $this->getNodeText($node, 'ForexBuying');
            $forexSell = $this->getNodeText($node, 'ForexSelling');

            if ($forexBuy === '' || $forexSell === '') {
                $banknoteBuy = $this->getNodeText($node, 'BanknoteBuying');
                $banknoteSell = $this->getNodeText($node, 'BanknoteSelling');
                if ($forexBuy === '') {
                    $forexBuy = $banknoteBuy;
                }
                if ($forexSell === '') {
                    $forexSell = $banknoteSell;
                }
            }

            if (!is_numeric($forexBuy) || !is_numeric($forexSell)) {
                continue;
            }

            $buyRate = (float) $forexBuy / $unit;
            $sellRate = (float) $forexSell / $unit;

            if ($buyRate <= 0 || $sellRate <= 0) {
                continue;
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => null,
            ];
        }

        return $rates;
    }

    private function getNodeText(DOMElement $parent, string $tagName): string
    {
        $nodes = $parent->getElementsByTagName($tagName);
        if ($nodes->length === 0) {
            return '';
        }

        $text = trim((string) $nodes->item(0)->textContent);
        return $text !== '' ? $text : '';
    }

    /**
     * Required by abstract Scraper - not used for TCMB (XML source).
     */
    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        return [];
    }
}

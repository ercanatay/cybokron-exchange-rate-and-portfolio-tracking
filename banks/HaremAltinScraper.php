<?php
/**
 * HaremAltinScraper.php — Harem Fiziki Altın Scraper (altin.doviz.com)
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Scrapes physical gold prices from altin.doviz.com/harem/*.
 * Unlike kur.doviz.com (bank FX table parsed by DovizComScraper), altin.doviz.com
 * publishes each price in a live socket-bound cell:
 *   <td data-socket-key="23-gram-altin" data-socket-attr="bid">6.534,39</td>
 *   <td data-socket-key="23-gram-altin" data-socket-attr="ask">6.617,70</td>
 *
 * Prefix "23" is Harem's provider id on doviz.com. A single Harem page lists every
 * Harem product, so we map only the products we track to our currency codes.
 * bid = Alış (buy), ask = Satış (sell). Prices are TR-formatted (6.534,39).
 *
 * The provider id is hard-coded by design: if doviz.com changes it, scrape() returns
 * 0 rates and the failure surfaces in scrape_logs — we never fall back to a different
 * provider's price, because that would silently mis-value the user's holdings.
 */

class HaremAltinScraper extends Scraper
{
    // AI table-repair targets HTML <table> structure, not socket cells — disable
    // it here so a low rate count never triggers a useless (and costly) OpenRouter call.
    protected bool $supportsAutoRepair = false;

    /**
     * Harem socket-key (provider 23) → our currency code.
     *
     * @var array<string, string>
     */
    private array $productMap = [
        '23-gram-altin'      => 'GRAMALTIN',
        '23-22-ayar-bilezik' => 'BILEZIK22',
    ];

    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $rates = [];

        foreach ($this->productMap as $socketKey => $code) {
            $buy  = $this->readSocketValue($xpath, $socketKey, 'bid');
            $sell = $this->readSocketValue($xpath, $socketKey, 'ask');

            if ($buy === null || $sell === null || $buy <= 0 || $sell <= 0) {
                cybokron_log(
                    "Harem scraper: missing/invalid price for {$socketKey} ({$code})",
                    'WARNING'
                );
                continue;
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buy,
                'sell'   => $sell,
                'change' => null,
            ];
        }

        return $rates;
    }

    /**
     * Read the first socket-bound cell value for a given key + attr (bid|ask).
     */
    private function readSocketValue(DOMXPath $xpath, string $socketKey, string $attr): ?float
    {
        // socketKey/attr come only from the trusted productMap above, so the
        // literal XPath predicate below is safe from injection.
        $nodes = $xpath->query(
            '//*[@data-socket-key="' . $socketKey . '" and @data-socket-attr="' . $attr . '"]'
        );

        if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        return $this->parseRate($nodes->item(0)->textContent);
    }

    /**
     * Parse TR-formatted price string to float (6.534,39 → 6534.39).
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

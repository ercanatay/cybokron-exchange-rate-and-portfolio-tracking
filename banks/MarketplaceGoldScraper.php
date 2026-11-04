<?php
/**
 * MarketplaceGoldScraper.php — Pazaryeri Fiziki Altın Scraper (idefix, n11)
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Dual-sourced physical gold rates for products bought from a marketplace store:
 *   sell_rate (SATIŞ) = the store's current product price ÷ grams in the product
 *   buy_rate  (ALIŞ)  = what the item fetches when cashed out, from one of:
 *     harem    — Harem's current bid for the same code (harem-fiziki rates row)
 *     albaraka — Albaraka's gram gold bid (albaraka-turk XAU) × the gram-for-gram
 *                conversion Albaraka applies at its physical gold collection days
 *
 * The store price is what it costs to buy the same item again today, so it is the
 * sell side; nobody buys a bracelet back at a marketplace price. The price is read
 * from the page's schema.org Product JSON-LD (offers.price), which idefix serves
 * as a number and n11 as a string.
 *
 * One class serves every marketplace source; the store and buy side are picked by
 * the bank slug injected via setBankData(), like DovizComScraper.
 *
 * Like HaremAltinScraper, a product that cannot be read is skipped and logged —
 * we never fall back to another store's price or an unrelated number. A price far
 * outside Harem's ask is rejected for the same reason (wrong product / placeholder).
 */

class MarketplaceGoldScraper extends Scraper
{
    // Two products per source is always below OPENROUTER_MIN_EXPECTED_RATES and the
    // pages carry no rate <table>, so AI table repair would only burn calls.
    protected bool $supportsAutoRepair = false;

    private const HAREM_SLUG = 'harem-fiziki';
    private const ALBARAKA_SLUG = 'albaraka-turk';

    // Accepted per-gram store price relative to Harem's ask. Store prices include
    // labour (bracelet ≈ +6%, gram bar ≈ +8% at launch), so the band is asymmetric.
    private const MIN_RATIO_TO_HAREM = 0.8;
    private const MAX_RATIO_TO_HAREM = 1.5;

    // Harem and Albaraka run in the same 15-minute cron tick, so a fresh row is at most
    // one tick old. Anything older means that source stopped updating; republishing its
    // bid under a fresh scraped_at would make a stale ALIŞ look current.
    private const MAX_SOURCE_AGE_SECONDS = 3600;

    /**
     * Albaraka does not buy jewellery for TL: it credits the 24K 995/1000 grams the
     * piece converts to, which are then sold at its gram gold bid. Observed on the
     * 2026-07-27 collection day: 5 × 22K bracelets 50.02 g → 45.49 g, a 1 g 24K bar
     * → 0.99 g. The bracelet factor sits ~1.2% under the theoretical 0.916 / 0.995,
     * which is solder, measured fineness and loss. Update from the next receipt.
     *
     * @var array<string, float>
     */
    private const ALBARAKA_GRAMS_PER_GRAM = [
        'BILEZIK22' => 45.49 / 50.02,
        'GRAMALTIN' => 0.99,
    ];

    /**
     * Bank slug → store whose products set SATIŞ, and the ALIŞ side.
     *
     * @var array<string, array{store: string, buy: string}>
     */
    private const SOURCES = [
        'idefix-ahlatci'          => ['store' => 'idefix', 'buy' => 'harem'],
        'idefix-ahlatci-albaraka' => ['store' => 'idefix', 'buy' => 'albaraka'],
        'n11-ahlatci'             => ['store' => 'n11',    'buy' => 'harem'],
    ];

    /**
     * Store → tracked products.
     *
     * @var array<string, array<int, array{code: string, grams: float, url: string}>>
     */
    private const PRODUCTS = [
        'idefix' => [
            ['code' => 'BILEZIK22', 'grams' => 10.0, 'url' => 'https://www.idefix.com/10-gr-22-ayar-3-mm-kibrit-copu-p-6555382?vendorId=5397'],
            ['code' => 'GRAMALTIN', 'grams' => 1.0,  'url' => 'https://www.idefix.com/24-ayar-1-gram-altin-p-6555489'],
        ],
        'n11' => [
            ['code' => 'BILEZIK22', 'grams' => 10.0, 'url' => 'https://www.n11.com/urun/ahlatci-10-gr-22-ayar-3-mm-kibrit-copu-bilezik-19291978?magaza=ahlatcikuyumculuk'],
            ['code' => 'GRAMALTIN', 'grams' => 1.0,  'url' => 'https://www.n11.com/urun/ahlatci-kuyumculuk-24-ayar-kulce-altin-1-g-2178110?magaza=ahlatcikuyumculuk'],
        ],
    ];

    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $source = self::SOURCES[$this->bankSlug] ?? null;
        $products = $source !== null ? (self::PRODUCTS[$source['store']] ?? []) : [];
        if ($products === []) {
            cybokron_log("Marketplace scraper: no products configured for {$this->bankSlug}", 'WARNING');
            return [];
        }

        $rates = [];

        foreach ($products as $product) {
            $code = $product['code'];

            try {
                // The bank URL is the first product, so run() has already cached it.
                $price = self::extractProductPrice($this->fetchPage($product['url']));
            } catch (Throwable $e) {
                cybokron_log("Marketplace scraper {$this->bankSlug}: fetch failed for {$code}: {$e->getMessage()}", 'WARNING');
                continue;
            }

            if ($price === null) {
                cybokron_log("Marketplace scraper {$this->bankSlug}: no Product price for {$code}", 'WARNING');
                continue;
            }

            // Harem's ask anchors the sanity band for every source, whatever the buy side.
            $harem = $this->loadFreshRate(self::HAREM_SLUG, $code);
            if ($harem === null) {
                cybokron_log("Marketplace scraper {$this->bankSlug}: no fresh Harem rate for {$code}, skipped", 'WARNING');
                continue;
            }

            $buy = $source['buy'] === 'albaraka' ? $this->albarakaBuyRate($code) : $harem['buy'];
            if ($buy === null) {
                cybokron_log("Marketplace scraper {$this->bankSlug}: no fresh Albaraka rate for {$code}, skipped", 'WARNING');
                continue;
            }
            // Bids from different houses stay within a few percent; a wider gap means a
            // unit or product mix-up (e.g. XAU quoted per ounce), not a real price.
            $buyRatio = $buy / $harem['buy'];
            if ($buyRatio < self::MIN_RATIO_TO_HAREM || $buyRatio > self::MAX_RATIO_TO_HAREM) {
                cybokron_log(
                    sprintf('Marketplace scraper %s: %s ALIŞ %.2f is %.2fx Harem bid, rejected', $this->bankSlug, $code, $buy, $buyRatio),
                    'WARNING'
                );
                continue;
            }

            $sell = round($price / $product['grams'], 4);
            $ratio = $sell / $harem['sell'];
            if ($ratio < self::MIN_RATIO_TO_HAREM || $ratio > self::MAX_RATIO_TO_HAREM) {
                cybokron_log(
                    sprintf(
                        'Marketplace scraper %s: %s per-gram %.2f is %.2fx Harem ask %.2f, rejected',
                        $this->bankSlug,
                        $code,
                        $sell,
                        $ratio,
                        $harem['sell']
                    ),
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
     * Read offers.price from the first schema.org Product JSON-LD block.
     *
     * JSON-LD uses a dot decimal separator ("7082.25"), so the value is cast as-is;
     * the Turkish-format parsing used for socket cells would turn it into 708225.
     */
    public static function extractProductPrice(string $html): ?float
    {
        if (!preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) {
                continue;
            }

            // A block may hold a single node, a list of nodes or an @graph.
            $nodes = isset($data['@graph']) && is_array($data['@graph'])
                ? $data['@graph']
                : (array_is_list($data) ? $data : [$data]);

            foreach ($nodes as $node) {
                if (!is_array($node) || ($node['@type'] ?? null) !== 'Product') {
                    continue;
                }

                $offers = $node['offers'] ?? null;
                if (is_array($offers) && array_is_list($offers)) {
                    $offers = $offers[0] ?? null;
                }

                $price = is_array($offers) ? ($offers['price'] ?? null) : null;
                if ((is_int($price) || is_float($price) || is_string($price)) && is_numeric($price)) {
                    $value = (float) $price;
                    return $value > 0 ? $value : null;
                }
            }
        }

        return null;
    }

    /**
     * Albaraka's cash-out value per gram of the item: gram gold bid × the grams of
     * 24K it converts to. Null when the code has no factor or the rate is stale.
     */
    private function albarakaBuyRate(string $code): ?float
    {
        $factor = self::ALBARAKA_GRAMS_PER_GRAM[$code] ?? null;
        $albaraka = $factor !== null ? $this->loadFreshRate(self::ALBARAKA_SLUG, 'XAU') : null;

        return $albaraka !== null ? round($albaraka['buy'] * $factor, 4) : null;
    }

    /**
     * A source's current bid/ask for a code, or null when missing or older than
     * MAX_SOURCE_AGE_SECONDS.
     *
     * @return array{buy: float, sell: float}|null
     */
    private function loadFreshRate(string $bankSlug, string $code): ?array
    {
        $row = Database::queryOne(
            'SELECT r.buy_rate, r.sell_rate, r.scraped_at
             FROM rates r
             JOIN banks b ON b.id = r.bank_id
             JOIN currencies c ON c.id = r.currency_id
             WHERE b.slug = ? AND c.code = ?',
            [$bankSlug, $code]
        );

        if (!$row || (float) $row['buy_rate'] <= 0 || (float) $row['sell_rate'] <= 0) {
            return null;
        }

        // scraped_at is written with date() in the app timezone, so strtotime() matches it.
        $scrapedAt = strtotime((string) $row['scraped_at']);
        if ($scrapedAt === false || time() - $scrapedAt > self::MAX_SOURCE_AGE_SECONDS) {
            return null;
        }

        return ['buy' => (float) $row['buy_rate'], 'sell' => (float) $row['sell_rate']];
    }
}

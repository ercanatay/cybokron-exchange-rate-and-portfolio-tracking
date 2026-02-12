<?php
/**
 * Scraper.php — Base Scraper Class
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * All bank scrapers extend this class and implement scrape().
 */

abstract class Scraper
{
    protected string $bankName = '';
    protected string $bankSlug = '';
    protected string $url = '';
    protected int $bankId = 0;

    /** @var array<string, string> */
    private array $pageCache = [];
    /** @var array<string, int>|null */
    private ?array $currencyIdMap = null;

    /**
     * Must be implemented by each bank scraper.
     * Returns array of parsed rate data.
     */
    abstract public function scrape(): array;

    /**
     * Load bank record from database and set bankId.
     */
    public function init(): void
    {
        $bank = Database::queryOne(
            'SELECT id FROM banks WHERE slug = ? AND is_active = 1',
            [$this->bankSlug]
        );

        if ($bank) {
            $this->bankId = (int) $bank['id'];
            return;
        }

        throw new RuntimeException("Bank '{$this->bankSlug}' not found or inactive.");
    }

    /**
     * Fetch a web page with cURL.
     */
    protected function fetchPage(string $url): string
    {
        if (isset($this->pageCache[$url])) {
            return $this->pageCache[$url];
        }

        $parsedUrl = parse_url($url);
        $scheme = strtolower((string) ($parsedUrl['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Unsupported URL scheme for scraping: {$url}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => defined('SCRAPE_TIMEOUT') ? SCRAPE_TIMEOUT : 30,
            CURLOPT_USERAGENT      => defined('SCRAPE_USER_AGENT') ? SCRAPE_USER_AGENT : 'Cybokron/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            ],
        ]);

        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        $retries = defined('SCRAPE_RETRY_COUNT') ? SCRAPE_RETRY_COUNT : 3;
        $delay = defined('SCRAPE_RETRY_DELAY') ? SCRAPE_RETRY_DELAY : 5;
        $html = false;
        $error = '';
        $httpCode = 0;

        for ($i = 0; $i < $retries; $i++) {
            $html = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($html !== false && $httpCode === 200) {
                break;
            }

            if ($i < $retries - 1) {
                sleep($delay);
            }
        }

        curl_close($ch);

        if ($html === false || $html === '' || $httpCode !== 200) {
            throw new RuntimeException("Failed to fetch {$url}: {$error}");
        }

        $this->pageCache[$url] = (string) $html;

        return $this->pageCache[$url];
    }

    /**
     * Compute a hash of the HTML table structure for change detection.
     */
    protected function computeTableHash(string $html): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $headers = $xpath->query('//table//thead//th');
        $structure = '';

        if ($headers->length > 0) {
            foreach ($headers as $th) {
                $structure .= trim($th->textContent) . '|';
            }
        } else {
            $firstRow = $xpath->query('//table//tr[1]//td');
            $structure = 'cols:' . $firstRow->length;
        }

        return hash('sha256', $structure);
    }

    /**
     * Save rates to the database (current + history).
     */
    protected function saveRates(array $rates, string $scrapedAt): int
    {
        $saved = 0;
        $currencyIdMap = $this->getCurrencyIdMap();

        Database::runInTransaction(function () use ($rates, $scrapedAt, $currencyIdMap, &$saved): void {
            foreach ($rates as $rate) {
                $code = strtoupper((string) ($rate['code'] ?? ''));
                if ($code === '' || !isset($currencyIdMap[$code])) {
                    continue;
                }

                $currencyId = $currencyIdMap[$code];

                Database::upsert('rates', [
                    'bank_id'        => $this->bankId,
                    'currency_id'    => $currencyId,
                    'buy_rate'       => $rate['buy'],
                    'sell_rate'      => $rate['sell'],
                    'change_percent' => $rate['change'] ?? null,
                    'scraped_at'     => $scrapedAt,
                ], ['buy_rate', 'sell_rate', 'change_percent', 'scraped_at']);

                Database::insert('rate_history', [
                    'bank_id'        => $this->bankId,
                    'currency_id'    => $currencyId,
                    'buy_rate'       => $rate['buy'],
                    'sell_rate'      => $rate['sell'],
                    'change_percent' => $rate['change'] ?? null,
                    'scraped_at'     => $scrapedAt,
                ]);

                $saved++;
            }
        });

        return $saved;
    }

    /**
     * Build and cache code->currency_id map.
     *
     * @return array<string, int>
     */
    private function getCurrencyIdMap(): array
    {
        if ($this->currencyIdMap !== null) {
            return $this->currencyIdMap;
        }

        $rows = Database::query('SELECT id, code FROM currencies WHERE is_active = 1');
        $this->currencyIdMap = [];

        foreach ($rows as $row) {
            $code = strtoupper((string) $row['code']);
            $this->currencyIdMap[$code] = (int) $row['id'];
        }

        return $this->currencyIdMap;
    }

    /**
     * Get allowed currency codes as uppercase list.
     *
     * @return string[]
     */
    protected function getKnownCurrencyCodes(): array
    {
        return array_keys($this->getCurrencyIdMap());
    }

    /**
     * Merge two rate lists by code, preferring existing parsed rows and filling missing ones.
     *
     * @param array<int, array{code:string,buy:float,sell:float,change:?float}> $primary
     * @param array<int, array{code:string,buy:float,sell:float,change:?float}> $secondary
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>
     */
    protected function mergeRatesByCode(array $primary, array $secondary): array
    {
        $merged = [];

        foreach ($primary as $rate) {
            if (!isset($rate['code'])) {
                continue;
            }
            $code = strtoupper((string) $rate['code']);
            $merged[$code] = $rate;
        }

        foreach ($secondary as $rate) {
            if (!isset($rate['code'])) {
                continue;
            }
            $code = strtoupper((string) $rate['code']);
            if (!isset($merged[$code])) {
                $merged[$code] = $rate;
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Try OpenRouter-based recovery when scraping output is incomplete.
     *
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>
     */
    protected function attemptOpenRouterRateRecovery(string $html, int $minimumRates = 8): array
    {
        try {
            $repair = new OpenRouterRateRepair(
                $this->bankSlug,
                $this->bankName,
                $this->getKnownCurrencyCodes()
            );

            return $repair->recover($html, $this->computeTableHash($html), $minimumRates);
        } catch (Throwable $e) {
            cybokron_log(
                "OpenRouter fallback error for {$this->bankSlug}: {$e->getMessage()}",
                'ERROR'
            );

            return [];
        }
    }

    /**
     * Log a scrape result.
     */
    protected function logScrape(string $status, string $message, int $ratesCount, int $durationMs, bool $tableChanged = false): void
    {
        Database::insert('scrape_logs', [
            'bank_id'       => $this->bankId,
            'status'        => $status,
            'message'       => $message,
            'rates_count'   => $ratesCount,
            'duration_ms'   => $durationMs,
            'table_changed' => $tableChanged ? 1 : 0,
        ]);
    }

    /**
     * Run the full scrape cycle: fetch, parse, detect changes, save, log.
     */
    public function run(): array
    {
        $this->init();
        $startTime = microtime(true);

        try {
            $html = $this->fetchPage($this->url);

            $newHash = $this->computeTableHash($html);
            $bank = Database::queryOne('SELECT table_hash FROM banks WHERE id = ?', [$this->bankId]);
            $oldHash = $bank['table_hash'] ?? '';
            $tableChanged = ($oldHash !== '' && $oldHash !== $newHash);

            Database::update('banks', ['table_hash' => $newHash], 'id = ?', [$this->bankId]);

            $rates = $this->scrape();
            $scrapedAt = date('Y-m-d H:i:s');

            $savedCount = $this->saveRates($rates, $scrapedAt);

            Database::update('banks', ['last_scraped_at' => $scrapedAt], 'id = ?', [$this->bankId]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $message = $tableChanged
                ? "Table structure changed. Scraped {$savedCount} rates."
                : "Scraped {$savedCount} rates successfully.";

            $this->logScrape('success', $message, $savedCount, $durationMs, $tableChanged);

            return [
                'status'        => 'success',
                'bank'          => $this->bankName,
                'rates_count'   => $savedCount,
                'table_changed' => $tableChanged,
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
}

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
            "SELECT id FROM banks WHERE slug = ? AND is_active = 1",
            [$this->bankSlug]
        );

        if ($bank) {
            $this->bankId = (int) $bank['id'];
        } else {
            throw new RuntimeException("Bank '{$this->bankSlug}' not found or inactive.");
        }
    }

    /**
     * Fetch a web page with cURL.
     */
    protected function fetchPage(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => defined('SCRAPE_TIMEOUT') ? SCRAPE_TIMEOUT : 30,
            CURLOPT_USERAGENT      => defined('SCRAPE_USER_AGENT') ? SCRAPE_USER_AGENT : 'Cybokron/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            ],
        ]);

        $retries = defined('SCRAPE_RETRY_COUNT') ? SCRAPE_RETRY_COUNT : 3;
        $delay = defined('SCRAPE_RETRY_DELAY') ? SCRAPE_RETRY_DELAY : 5;
        $html = false;
        $error = '';

        for ($i = 0; $i < $retries; $i++) {
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($html !== false && $httpCode === 200) {
                break;
            }

            if ($i < $retries - 1) {
                sleep($delay);
            }
        }

        curl_close($ch);

        if ($html === false || empty($html)) {
            throw new RuntimeException("Failed to fetch {$url}: {$error}");
        }

        return $html;
    }

    /**
     * Compute a hash of the HTML table structure for change detection.
     */
    protected function computeTableHash(string $html, string $tableSelector = 'table'): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        // Get all table headers to detect structure changes
        $headers = $xpath->query('//table//thead//th');
        $structure = '';

        if ($headers->length > 0) {
            foreach ($headers as $th) {
                $structure .= trim($th->textContent) . '|';
            }
        } else {
            // Fallback: count columns from first row
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

        foreach ($rates as $rate) {
            // Find currency ID
            $currency = Database::queryOne(
                "SELECT id FROM currencies WHERE code = ?",
                [$rate['code']]
            );

            if (!$currency) {
                continue; // Skip unknown currencies
            }

            $currencyId = (int) $currency['id'];

            // Upsert into rates (latest)
            Database::upsert('rates', [
                'bank_id'        => $this->bankId,
                'currency_id'    => $currencyId,
                'buy_rate'       => $rate['buy'],
                'sell_rate'      => $rate['sell'],
                'change_percent' => $rate['change'] ?? null,
                'scraped_at'     => $scrapedAt,
            ], ['buy_rate', 'sell_rate', 'change_percent', 'scraped_at']);

            // Insert into history
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

        return $saved;
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

            // Check if table structure changed
            $newHash = $this->computeTableHash($html);
            $bank = Database::queryOne("SELECT table_hash FROM banks WHERE id = ?", [$this->bankId]);
            $oldHash = $bank['table_hash'] ?? '';
            $tableChanged = ($oldHash !== '' && $oldHash !== $newHash);

            // Update table hash
            Database::update('banks', ['table_hash' => $newHash], 'id = ?', [$this->bankId]);

            // Scrape rates
            $rates = $this->scrape();
            $scrapedAt = date('Y-m-d H:i:s');

            // Save to database
            $savedCount = $this->saveRates($rates, $scrapedAt);

            // Update bank last scraped
            Database::update('banks', ['last_scraped_at' => $scrapedAt], 'id = ?', [$this->bankId]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $msg = $tableChanged
                ? "Table structure changed! Scraped {$savedCount} rates."
                : "Scraped {$savedCount} rates successfully.";

            $this->logScrape('success', $msg, $savedCount, $durationMs, $tableChanged);

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

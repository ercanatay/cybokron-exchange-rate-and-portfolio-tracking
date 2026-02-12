<?php
/**
 * Base Scraper class for bank exchange rate scrapers
 * 
 * All bank scrapers must extend this class and implement the scrape() method.
 */
abstract class Scraper
{
    protected string $bankName = '';
    protected string $bankSlug = '';
    protected string $url = '';
    protected array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
    }

    /**
     * Scrape exchange rates from the bank website.
     * Must return an array of rates:
     * [
     *   ['code' => 'USD', 'buy' => 36.1234, 'sell' => 36.5678, 'change' => 0.02],
     *   ...
     * ]
     */
    abstract public function scrape(): array;

    /**
     * Fetch a web page via cURL
     */
    protected function fetchPage(string $url): string
    {
        $scraperConfig = $this->config['scraper'];
        $attempt = 0;

        while ($attempt < $scraperConfig['retry']) {
            $attempt++;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $scraperConfig['timeout'],
                CURLOPT_USERAGENT      => $scraperConfig['user_agent'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $httpCode === 200) {
                return $response;
            }

            if ($attempt < $scraperConfig['retry']) {
                sleep($scraperConfig['retry_delay']);
            }
        }

        throw new RuntimeException(
            "Failed to fetch {$url} after {$scraperConfig['retry']} attempts. " .
            "Last error: {$error}, HTTP code: {$httpCode}"
        );
    }

    /**
     * Create a DOMDocument from HTML string
     */
    protected function createDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        return $dom;
    }

    /**
     * Generate a hash of the table structure for change detection
     */
    protected function generateTableHash(DOMDocument $dom, string $tableSelector = 'table'): string
    {
        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table');

        if ($tables->length === 0) {
            return '';
        }

        // Hash the table headers to detect structure changes
        $headers = [];
        $ths = $xpath->query('//table//th');
        foreach ($ths as $th) {
            $headers[] = trim($th->textContent);
        }

        return md5(implode('|', $headers));
    }

    /**
     * Parse a Turkish-formatted number (e.g., "7.049,5249" or "43.5865")
     */
    protected function parseNumber(string $value): float
    {
        $value = trim($value);
        
        // Remove any whitespace and % signs
        $value = preg_replace('/[\s%]/', '', $value);
        
        // Check if it uses Turkish format (dot as thousands, comma as decimal)
        // Pattern: digits, then dots, then comma, then digits
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            // Turkish format: 7.049,5249
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (strpos($value, ',') !== false && strpos($value, '.') === false) {
            // Only comma: 0,2810
            $value = str_replace(',', '.', $value);
        }
        // Otherwise assume standard format: 43.5865

        return (float) $value;
    }

    /**
     * Save scraped rates to database
     */
    public function saveRates(array $rates): int
    {
        require_once __DIR__ . '/Database.php';

        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        // Get bank ID
        $bank = Database::fetchOne("SELECT id FROM banks WHERE slug = ?", [$this->bankSlug]);
        if (!$bank) {
            throw new RuntimeException("Bank '{$this->bankSlug}' not found in database");
        }
        $bankId = $bank['id'];

        $saved = 0;
        foreach ($rates as $rate) {
            // Get currency ID
            $currency = Database::fetchOne("SELECT id FROM currencies WHERE code = ?", [$rate['code']]);
            if (!$currency) {
                continue; // Skip unknown currencies
            }
            $currencyId = $currency['id'];

            // Upsert into rates (latest snapshot)
            $existing = Database::fetchOne(
                "SELECT id FROM rates WHERE bank_id = ? AND currency_id = ?",
                [$bankId, $currencyId]
            );

            if ($existing) {
                Database::update('rates', [
                    'buy_rate'       => $rate['buy'],
                    'sell_rate'      => $rate['sell'],
                    'change_percent' => $rate['change'] ?? null,
                    'fetched_at'     => $now,
                ], 'id = ?', [$existing['id']]);
            } else {
                Database::insert('rates', [
                    'bank_id'        => $bankId,
                    'currency_id'    => $currencyId,
                    'buy_rate'       => $rate['buy'],
                    'sell_rate'      => $rate['sell'],
                    'change_percent' => $rate['change'] ?? null,
                    'fetched_at'     => $now,
                ]);
            }

            // Insert into rate_history
            Database::insert('rate_history', [
                'bank_id'        => $bankId,
                'currency_id'    => $currencyId,
                'buy_rate'       => $rate['buy'],
                'sell_rate'      => $rate['sell'],
                'change_percent' => $rate['change'] ?? null,
                'fetched_at'     => $now,
            ]);

            $saved++;
        }

        // Update bank last scraped time
        Database::update('banks', ['last_scraped_at' => $now], 'id = ?', [$bankId]);

        return $saved;
    }

    /**
     * Log scrape result
     */
    public function logScrape(string $status, ?string $message = null, ?int $ratesCount = null, ?int $durationMs = null): void
    {
        require_once __DIR__ . '/Database.php';

        $bank = Database::fetchOne("SELECT id FROM banks WHERE slug = ?", [$this->bankSlug]);
        if (!$bank) return;

        Database::insert('scrape_logs', [
            'bank_id'     => $bank['id'],
            'status'      => $status,
            'message'     => $message,
            'rates_count' => $ratesCount,
            'duration_ms' => $durationMs,
        ]);
    }

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function getBankSlug(): string
    {
        return $this->bankSlug;
    }
}

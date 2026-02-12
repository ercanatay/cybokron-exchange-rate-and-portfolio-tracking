<?php
/**
 * Dünya Katılım Bankası Exchange Rate Scraper
 * 
 * Scrapes exchange rates from: https://dunyakatilim.com.tr/gunluk-kurlar
 * 
 * Table structure (as of 2025):
 *   Column 0: Currency name + code (e.g., "Amerikan doları (USD)")
 *   Column 1: Bank Buy Rate (Banka Alış)
 *   Column 2: Bank Sell Rate (Banka Satış)
 *   Column 3: Change % (Değişim)
 */

require_once __DIR__ . '/../includes/Scraper.php';

class DunyaKatilim extends Scraper
{
    protected string $bankName = 'Dünya Katılım Bankası';
    protected string $bankSlug = 'dunya-katilim';
    protected string $url = 'https://dunyakatilim.com.tr/gunluk-kurlar';

    /**
     * Known currency code mappings (fallback if code extraction fails)
     */
    private array $currencyMap = [
        'amerikan doları'                      => 'USD',
        'euro'                                  => 'EUR',
        'ingiliz sterlini'                      => 'GBP',
        'İngiliz sterlini'                      => 'GBP',
        'altın'                                 => 'XAU',
        'gümüş'                                 => 'XAG',
        'avustralya doları'                     => 'AUD',
        'kanada doları'                         => 'CAD',
        'çin yuanı'                             => 'CNY',
        'japon yeni'                            => 'JPY',
        'suudi riyali'                          => 'SAR',
        'isviçre frangı'                        => 'CHF',
        'İsviçre frangı'                        => 'CHF',
        'birleşik arap emirlikleri dirhemi'     => 'AED',
        'platin'                                => 'XPT',
        'paladyum'                              => 'XPD',
    ];

    public function scrape(): array
    {
        $html = $this->fetchPage($this->url);
        $dom = $this->createDom($html);

        // Check for table structure changes
        $currentHash = $this->generateTableHash($dom);
        $this->detectStructureChange($currentHash);

        return $this->parseRates($dom);
    }

    /**
     * Parse exchange rates from the DOM
     */
    private function parseRates(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $rates = [];

        // Find all table rows (skip header)
        $rows = $xpath->query('//table//tbody//tr');

        if ($rows->length === 0) {
            // Try without tbody (some pages render differently)
            $rows = $xpath->query('//table//tr');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            
            if ($cells->length < 3) {
                continue; // Skip header rows or incomplete rows
            }

            // Extract currency code from cell text
            $currencyText = trim($cells->item(0)->textContent);
            $code = $this->extractCurrencyCode($currencyText);

            if (!$code) {
                continue; // Skip if we can't identify the currency
            }

            // Parse buy and sell rates
            $buyText = trim($cells->item(1)->textContent);
            $sellText = trim($cells->item(2)->textContent);

            $buyRate = $this->parseNumber($buyText);
            $sellRate = $this->parseNumber($sellText);

            if ($buyRate <= 0 || $sellRate <= 0) {
                continue; // Skip invalid rates
            }

            // Parse change percentage (optional, column 3)
            $changePercent = null;
            if ($cells->length >= 4) {
                $changeText = trim($cells->item(3)->textContent);
                $changeText = str_replace(['%', ' '], '', $changeText);
                if (is_numeric(str_replace(',', '.', $changeText))) {
                    $changePercent = $this->parseNumber($changeText);
                }
            }

            $rates[] = [
                'code'   => $code,
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => $changePercent,
            ];
        }

        return $rates;
    }

    /**
     * Extract currency code from cell text
     * Handles formats like: "Amerikan doları (USD)" or just "Amerikan doları"
     */
    private function extractCurrencyCode(string $text): ?string
    {
        // Try to extract code from parentheses: "... (USD)"
        if (preg_match('/\(([A-Z]{3})\)/', $text, $matches)) {
            return $matches[1];
        }

        // Fallback: match against known currency names
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        
        foreach ($this->currencyMap as $name => $code) {
            if (mb_strpos($normalized, mb_strtolower($name, 'UTF-8')) !== false) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Detect if the table structure has changed
     */
    private function detectStructureChange(string $currentHash): void
    {
        if (empty($currentHash)) {
            return;
        }

        require_once __DIR__ . '/../includes/Database.php';

        try {
            $bank = Database::fetchOne(
                "SELECT id, table_hash FROM banks WHERE slug = ?",
                [$this->bankSlug]
            );

            if ($bank && $bank['table_hash'] && $bank['table_hash'] !== $currentHash) {
                // Structure changed! Log it
                $this->logScrape('structure_changed', 
                    "Table structure changed. Old hash: {$bank['table_hash']}, New hash: {$currentHash}"
                );
            }

            // Update hash
            if ($bank) {
                Database::update('banks', ['table_hash' => $currentHash], 'id = ?', [$bank['id']]);
            }
        } catch (Exception $e) {
            // Database might not be set up yet, skip silently
        }
    }
}

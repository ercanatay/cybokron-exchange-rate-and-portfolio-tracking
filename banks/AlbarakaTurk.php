<?php
/**
 * AlbarakaTurk.php — Albaraka Türk Katılım Bankası Scraper
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Scrapes exchange rates from: https://www.albaraka.com.tr/tr/doviz-kurlari
 *
 * Table structure (as of 2026-10):
 * thead row 1: | Son Güncelleme: 09.10.2026-15:03 (colspan) |
 * thead row 2: | Döviz Cinsi | Banka Alış | Banka Satış |
 * tbody rows:  | Amerikan Doları(USD) | 48,872 | 49,593 |
 *
 * The ISO code is always in parentheses at the end of the first cell, and
 * numbers use a decimal comma with no thousands separator ("6527,324").
 * The page has no change column, so `change` is always null.
 */

class AlbarakaTurk extends Scraper
{
    protected string $bankName = 'Albaraka Türk';
    protected string $bankSlug = 'albaraka-turk';
    protected string $url = 'https://www.albaraka.com.tr/tr/doviz-kurlari';

    /**
     * Scrape the exchange rate table.
     */
    public function scrape(string $html, DOMXPath $xpath, string $tableHash): array
    {
        $rates = [];

        $rows = $xpath->query('//table//tbody//tr');
        if ($rows->length === 0) {
            $rows = $xpath->query('//table//tr');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 3) {
                continue;
            }

            $label = preg_replace('/\s+/u', ' ', trim($cells->item(0)->textContent));
            if (!preg_match('/\(([A-Z]{3})\)\s*$/u', (string) $label, $m)) {
                continue;
            }

            $buyRate = $this->parseNumber($cells->item(1)->textContent);
            $sellRate = $this->parseNumber($cells->item(2)->textContent);
            if ($buyRate === null || $sellRate === null) {
                continue;
            }

            $rates[] = [
                'code'   => $m[1],
                'buy'    => $buyRate,
                'sell'   => $sellRate,
                'change' => null,
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
     * Hash only the column-header row.
     *
     * The first thead row carries "Son Güncelleme: <timestamp>", which changes
     * on every update. Hashing it would flag a table change on every cron run
     * and reset the per-hash OpenRouter cooldown, so colspan headers are skipped.
     */
    protected function computeTableHashFromXPath(DOMXPath $xpath): string
    {
        $headers = $xpath->query('//table//thead//th[not(@colspan)]');
        $structure = '';

        if ($headers instanceof DOMNodeList && $headers->length > 0) {
            foreach ($headers as $th) {
                $structure .= trim($th->textContent) . '|';
            }
        } else {
            $firstRow = $xpath->query('//table//tbody//tr[1]//td');
            $columnCount = ($firstRow instanceof DOMNodeList) ? $firstRow->length : 0;
            $structure = 'cols:' . $columnCount;
        }

        return hash('sha256', $structure);
    }

    /**
     * Parse a number in Albaraka's format: decimal comma, no thousands separator.
     * Also tolerates "7.049,52" in case a thousands dot is ever added.
     */
    private function parseNumber(string $text): ?float
    {
        $text = preg_replace('/[^\d.,]/', '', trim($text));
        if ($text === null || $text === '') {
            return null;
        }

        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        }

        if (!is_numeric($text)) {
            return null;
        }

        $value = (float) $text;

        return $value > 0 ? $value : null;
    }
}

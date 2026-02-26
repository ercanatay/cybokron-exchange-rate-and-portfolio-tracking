<?php
/**
 * BacktestEngine.php — Rule simulation against historical price data
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Simulates LeverageEngine::checkRule() logic offline against historical data.
 * Supports 3 data sources: rate_history (local), metals.dev API, exchangerate.host API.
 */

class BacktestEngine
{
    private PDO $pdo;

    /**
     * Metal code mapping for metals.dev API.
     */
    private const METAL_MAP = [
        'XAU' => 'gold',
        'XAG' => 'silver',
        'XPT' => 'platinum',
        'XPD' => 'palladium',
    ];

    /**
     * Maximum backtest date range in days.
     */
    private const MAX_DATE_RANGE_DAYS = 365;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Public API ─────────────────────────────────────────────────────────

    /**
     * Check if backtesting is enabled.
     */
    public function isEnabled(): bool
    {
        $dbVal = $this->getSettingValue('backtesting_enabled');
        if ($dbVal !== null) {
            return $dbVal === '1';
        }
        return defined('BACKTESTING_ENABLED') ? (bool) BACKTESTING_ENABLED : true;
    }

    /**
     * Run backtest for a rule against historical data.
     *
     * @param int    $ruleId  leverage_rules ID
     * @param string $source  'rate_history', 'metals_dev', 'exchangerate_host'
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     * @return array{success: bool, backtest_id: int|null, summary: array|null, error: string}
     */
    public function run(int $ruleId, string $source, string $dateFrom, string $dateTo): array
    {
        // 1. Verify backtesting is enabled
        if (!$this->isEnabled()) {
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => 'Backtesting is disabled'];
        }

        // 2. Validate source
        $allowedSources = ['rate_history', 'metals_dev', 'exchangerate_host'];
        if (!in_array($source, $allowedSources, true)) {
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => 'Invalid data source'];
        }

        // 3. Validate date range
        $dateValidation = $this->validateDateRange($dateFrom, $dateTo);
        if ($dateValidation !== null) {
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => $dateValidation];
        }

        // 4. Fetch the rule
        $rule = $this->getRule($ruleId);
        if ($rule === null) {
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => 'Rule not found'];
        }

        // 5. Fetch price data from source
        try {
            $priceData = $this->fetchPriceData($source, $rule['currency_code'], $dateFrom, $dateTo);
        } catch (Throwable $e) {
            cybokron_log("Backtest data fetch failed for rule #{$ruleId}: {$e->getMessage()}", 'ERROR');
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => 'Failed to fetch price data: ' . $e->getMessage()];
        }

        if (empty($priceData)) {
            return ['success' => false, 'backtest_id' => null, 'summary' => null, 'error' => 'No price data available for the selected range and source'];
        }

        // 6. Simulate signals
        $signals = $this->simulateSignals($rule, $priceData);

        // 7. Calculate summary
        $summary = $this->calculateSummary($signals, $priceData);

        // 8. Save to leverage_backtests table
        $backtestId = $this->saveBacktest($ruleId, $source, $dateFrom, $dateTo, $signals, $summary);

        cybokron_log("Backtest completed for rule #{$ruleId}: {$summary['total_signals']} signals, source={$source}");

        return [
            'success' => true,
            'backtest_id' => $backtestId,
            'summary' => $summary,
            'error' => '',
        ];
    }

    /**
     * Get backtest result by ID.
     *
     * @return array|null
     */
    public function getBacktest(int $backtestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM leverage_backtests WHERE id = ?');
        $stmt->execute([$backtestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get backtests for a rule, newest first.
     *
     * @return array
     */
    public function getBacktestsForRule(int $ruleId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM leverage_backtests WHERE rule_id = ? ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$ruleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ─── Signal simulation ──────────────────────────────────────────────────

    /**
     * Simulate signals against historical price data.
     *
     * Replicates LeverageEngine::checkRule() logic:
     * - Trailing stop (auto peak tracking + threshold mode)
     * - Weak thresholds (buy_threshold_weak, sell_threshold_weak)
     * - Strong thresholds (buy_threshold, sell_threshold)
     * - Cooldown between signals
     * - Direction lock (no same-direction repeat unless price returns to reference)
     *
     * @param array $rule      The leverage rule
     * @param array $priceData Array of ['date' => string, 'price' => float]
     * @return array List of signals: ['date', 'price', 'type', 'change_pct', 'reference_price']
     */
    private function simulateSignals(array $rule, array $priceData): array
    {
        $signals = [];
        $referencePrice = (float) $rule['reference_price'];
        $peakPrice = $referencePrice;
        $lastDirection = null;
        $lastSignalTime = null;
        $cooldownMinutes = $this->resolveIntSetting('leverage_cooldown_minutes', 'LEVERAGE_COOLDOWN_MINUTES', 60);

        $buyThreshold = (float) $rule['buy_threshold'];
        $sellThreshold = (float) $rule['sell_threshold'];
        $buyThresholdWeak = isset($rule['buy_threshold_weak']) && $rule['buy_threshold_weak'] !== null
            ? (float) $rule['buy_threshold_weak'] : null;
        $sellThresholdWeak = isset($rule['sell_threshold_weak']) && $rule['sell_threshold_weak'] !== null
            ? (float) $rule['sell_threshold_weak'] : null;

        $trailingStopEnabled = !empty($rule['trailing_stop_enabled']);
        $trailingStopPct = isset($rule['trailing_stop_pct']) && $rule['trailing_stop_pct'] !== null
            ? (float) $rule['trailing_stop_pct'] : 0.0;
        $trailingStopType = $rule['trailing_stop_type'] ?? 'auto';

        if ($referencePrice <= 0) {
            return $signals;
        }

        foreach ($priceData as $dataPoint) {
            $currentPrice = (float) $dataPoint['price'];
            $date = $dataPoint['date'];

            if ($currentPrice <= 0) {
                continue;
            }

            // Calculate change from reference
            $changePct = (($currentPrice - $referencePrice) / $referencePrice) * 100;

            // 1. Trailing stop check (if enabled)
            if ($trailingStopEnabled && $trailingStopPct > 0) {
                if ($trailingStopType === 'auto') {
                    // Auto mode: track peak, signal on drop from peak
                    if ($currentPrice > $peakPrice) {
                        $peakPrice = $currentPrice;
                    }
                    $dropFromPeak = $peakPrice > 0
                        ? (($peakPrice - $currentPrice) / $peakPrice) * 100
                        : 0;
                    if ($dropFromPeak >= $trailingStopPct) {
                        // Cooldown check for trailing stop
                        if ($lastSignalTime !== null && (strtotime($date) - $lastSignalTime) < ($cooldownMinutes * 60)) {
                            continue;
                        }
                        $signals[] = [
                            'date' => $date,
                            'price' => $currentPrice,
                            'type' => 'trailing_stop_signal',
                            'change_pct' => round($changePct, 2),
                            'reference_price' => $referencePrice,
                        ];
                        // Reset after trailing stop
                        $referencePrice = $currentPrice;
                        $peakPrice = $currentPrice;
                        $lastDirection = 'sell';
                        $lastSignalTime = strtotime($date);
                        continue;
                    }
                } else {
                    // Threshold mode: signal on drop from reference
                    $dropFromRef = $referencePrice > 0
                        ? (($referencePrice - $currentPrice) / $referencePrice) * 100
                        : 0;
                    if ($dropFromRef >= $trailingStopPct) {
                        if ($lastSignalTime !== null && (strtotime($date) - $lastSignalTime) < ($cooldownMinutes * 60)) {
                            continue;
                        }
                        $signals[] = [
                            'date' => $date,
                            'price' => $currentPrice,
                            'type' => 'trailing_stop_signal',
                            'change_pct' => round($changePct, 2),
                            'reference_price' => $referencePrice,
                        ];
                        $referencePrice = $currentPrice;
                        $peakPrice = $currentPrice;
                        $lastDirection = 'sell';
                        $lastSignalTime = strtotime($date);
                        continue;
                    }
                }
            }

            // 2. Weak threshold check (early warning — no direction lock, separate cooldown behavior)
            if ($buyThresholdWeak !== null && $changePct <= $buyThresholdWeak && $changePct > $buyThreshold) {
                // Weak buy zone — no cooldown, no direction lock, no reference update (informational only)
                $signals[] = [
                    'date' => $date,
                    'price' => $currentPrice,
                    'type' => 'weak_buy_signal',
                    'change_pct' => round($changePct, 2),
                    'reference_price' => $referencePrice,
                ];
                continue;
            }
            if ($sellThresholdWeak !== null && $changePct >= $sellThresholdWeak && $changePct < $sellThreshold) {
                // Weak sell zone — no cooldown, no direction lock, no reference update (informational only)
                $signals[] = [
                    'date' => $date,
                    'price' => $currentPrice,
                    'type' => 'weak_sell_signal',
                    'change_pct' => round($changePct, 2),
                    'reference_price' => $referencePrice,
                ];
                continue;
            }

            // 3. Strong threshold check (replicates LeverageEngine::checkRule)
            $direction = null;
            if ($changePct <= $buyThreshold) {
                $direction = 'buy';
            } elseif ($changePct >= $sellThreshold) {
                $direction = 'sell';
            }

            if ($direction !== null) {
                // Cooldown check
                if ($lastSignalTime !== null && (strtotime($date) - $lastSignalTime) < ($cooldownMinutes * 60)) {
                    continue;
                }
                // Direction lock: don't re-trigger same direction unless price returned to reference
                if ($lastDirection === $direction) {
                    continue;
                }

                $signals[] = [
                    'date' => $date,
                    'price' => $currentPrice,
                    'type' => $direction . '_signal',
                    'change_pct' => round($changePct, 2),
                    'reference_price' => $referencePrice,
                ];
                $lastDirection = $direction;
                $lastSignalTime = strtotime($date);
                // Update reference for strong signals (auto-reset behavior from LeverageEngine)
                $referencePrice = $currentPrice;
                $peakPrice = $currentPrice;
            }
        }

        return $signals;
    }

    // ─── Summary calculation ────────────────────────────────────────────────

    /**
     * Calculate performance summary from signals.
     *
     * @param array $signals   Signal list from simulateSignals()
     * @param array $priceData Price data used for drawdown calculation
     * @return array Summary statistics
     */
    private function calculateSummary(array $signals, array $priceData): array
    {
        $buySignals = array_filter($signals, fn($s) => in_array($s['type'], ['buy_signal', 'weak_buy_signal'], true));
        $sellSignals = array_filter($signals, fn($s) => in_array($s['type'], ['sell_signal', 'weak_sell_signal', 'trailing_stop_signal'], true));

        // Calculate max drawdown from price series
        $maxDrawdown = 0.0;
        $peak = 0.0;
        foreach ($priceData as $dp) {
            $p = (float) $dp['price'];
            if ($p > $peak) {
                $peak = $p;
            }
            if ($peak > 0) {
                $dd = (($peak - $p) / $peak) * 100;
                if ($dd > $maxDrawdown) {
                    $maxDrawdown = $dd;
                }
            }
        }

        // Pair buy -> sell signals for win rate and total return calculation
        $totalReturn = 0.0;
        $wins = 0;
        $trades = 0;
        $buyQueue = [];

        foreach ($signals as $s) {
            if (in_array($s['type'], ['buy_signal', 'weak_buy_signal'], true)) {
                $buyQueue[] = $s['price'];
            } elseif (in_array($s['type'], ['sell_signal', 'weak_sell_signal', 'trailing_stop_signal'], true) && !empty($buyQueue)) {
                $buyPrice = array_shift($buyQueue);
                $trades++;
                $pnl = $buyPrice > 0 ? (($s['price'] - $buyPrice) / $buyPrice) * 100 : 0;
                $totalReturn += $pnl;
                if ($pnl > 0) {
                    $wins++;
                }
            }
        }

        // Calculate average signal interval in days
        $avgIntervalDays = 0;
        if (count($signals) >= 2) {
            $firstDate = strtotime($signals[0]['date']);
            $lastDate = strtotime($signals[count($signals) - 1]['date']);
            if ($firstDate !== false && $lastDate !== false && $lastDate > $firstDate) {
                $avgIntervalDays = round(($lastDate - $firstDate) / (86400 * (count($signals) - 1)), 1);
            }
        }

        return [
            'total_signals' => count($signals),
            'buy_signals' => count($buySignals),
            'sell_signals' => count($sellSignals),
            'total_return_pct' => round($totalReturn, 2),
            'max_drawdown_pct' => round($maxDrawdown, 2),
            'win_rate_pct' => $trades > 0 ? round(($wins / $trades) * 100, 2) : 0,
            'avg_signal_interval_days' => $avgIntervalDays,
            'completed_trades' => $trades,
        ];
    }

    // ─── Data fetching ──────────────────────────────────────────────────────

    /**
     * Route to the correct data source.
     *
     * @return array Array of ['date' => string, 'price' => float]
     */
    private function fetchPriceData(string $source, string $currencyCode, string $dateFrom, string $dateTo): array
    {
        $currencyCode = strtoupper(trim($currencyCode));

        switch ($source) {
            case 'rate_history':
                return $this->fetchFromRateHistory($currencyCode, $dateFrom, $dateTo);
            case 'metals_dev':
                return $this->fetchFromMetalsDev($currencyCode, $dateFrom, $dateTo);
            case 'exchangerate_host':
                return $this->fetchFromExchangeRateHost($currencyCode, $dateFrom, $dateTo);
            default:
                throw new InvalidArgumentException("Unknown data source: {$source}");
        }
    }

    /**
     * Fetch from local rate_history table.
     *
     * Joins rate_history with currencies, groups by date (takes last entry per date
     * for daily data), and returns normalized price array.
     *
     * @return array Array of ['date' => string, 'price' => float]
     */
    private function fetchFromRateHistory(string $currencyCode, string $dateFrom, string $dateTo): array
    {
        // Get daily data: last sell_rate entry per date for the currency
        $sql = "
            SELECT DATE(rh.scraped_at) AS trade_date,
                   rh.sell_rate
            FROM rate_history rh
            JOIN currencies c ON c.id = rh.currency_id
            WHERE c.code = ?
              AND DATE(rh.scraped_at) BETWEEN ? AND ?
            ORDER BY rh.scraped_at ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$currencyCode, $dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        // Group by date, take the last entry per date (latest scrape)
        $dailyData = [];
        foreach ($rows as $row) {
            $date = $row['trade_date'];
            $dailyData[$date] = (float) $row['sell_rate'];
        }

        $result = [];
        foreach ($dailyData as $date => $price) {
            if ($price > 0) {
                $result[] = ['date' => $date, 'price' => $price];
            }
        }

        return $result;
    }

    /**
     * Fetch from metals.dev API.
     *
     * API: https://api.metals.dev/v1/timeseries
     * Maps currency codes: XAU->gold, XAG->silver, XPT->platinum, XPD->palladium
     *
     * @return array Array of ['date' => string, 'price' => float]
     */
    private function fetchFromMetalsDev(string $currencyCode, string $dateFrom, string $dateTo): array
    {
        $apiKey = $this->resolveEncryptedSetting('backtesting_metals_dev_api_key');
        if ($apiKey === '') {
            throw new RuntimeException('metals.dev API key not configured');
        }

        // Map currency code to metal name
        $metal = self::METAL_MAP[$currencyCode] ?? null;
        if ($metal === null) {
            throw new InvalidArgumentException(
                "Currency code '{$currencyCode}' is not a supported metal for metals.dev (supported: "
                . implode(', ', array_keys(self::METAL_MAP)) . ')'
            );
        }

        // Timeseries always returns USD/troy oz with limited currencies (TRY not included).
        // We need to fetch USD/TRY rate from our own rates table to convert.
        $usdTryRate = $this->getUsdTryRate();
        if ($usdTryRate <= 0) {
            throw new RuntimeException('Could not determine USD/TRY rate for metals.dev price conversion');
        }

        // 1 troy ounce = 31.1035 grams
        $troyOzToGram = 31.1035;

        // metals.dev API limits timeseries to 30 days per request.
        // Split longer ranges into 30-day chunks.
        $chunks = $this->splitDateRange($dateFrom, $dateTo, 30);

        $result = [];
        foreach ($chunks as [$chunkStart, $chunkEnd]) {
            $url = 'https://api.metals.dev/v1/timeseries'
                . '?api_key=' . urlencode($apiKey)
                . '&start_date=' . urlencode($chunkStart)
                . '&end_date=' . urlencode($chunkEnd)
                . '&metals=' . urlencode($metal);

            $response = $this->httpGet($url);
            if (!$response['success']) {
                throw new RuntimeException('metals.dev API request failed: ' . $response['error']);
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                throw new RuntimeException('metals.dev API returned invalid response');
            }

            // Actual format: { "rates": { "2026-01-01": { "metals": { "gold": 5108.25 }, "currencies": {...} }, ... } }
            // Prices are in USD per troy ounce
            $rates = $data['rates'] ?? [];
            if (!is_array($rates)) {
                continue;
            }

            foreach ($rates as $date => $dayData) {
                if (!is_array($dayData)) {
                    continue;
                }
                $metals = $dayData['metals'] ?? $dayData;
                if (is_array($metals) && isset($metals[$metal])) {
                    $priceUsdPerOz = (float) $metals[$metal];
                    if ($priceUsdPerOz > 0) {
                        // Convert: USD/oz -> TRY/gram
                        $priceTryPerGram = ($priceUsdPerOz * $usdTryRate) / $troyOzToGram;
                        $result[] = ['date' => $date, 'price' => round($priceTryPerGram, 6)];
                    }
                }
            }
        }

        // Sort by date ascending
        usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * Split a date range into chunks of max $maxDays days each.
     *
     * @return array<array{0: string, 1: string}> Array of [start, end] pairs
     */
    private function splitDateRange(string $dateFrom, string $dateTo, int $maxDays): array
    {
        $start = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
        $chunks = [];

        while ($start <= $end) {
            $chunkEnd = $start->modify('+' . ($maxDays - 1) . ' days');
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }
            $chunks[] = [$start->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
            $start = $chunkEnd->modify('+1 day');
        }

        return $chunks;
    }

    /**
     * Get current USD/TRY exchange rate from our rates table.
     */
    private function getUsdTryRate(): float
    {
        $row = Database::queryOne(
            "SELECT r.sell_rate FROM rates r
             JOIN currencies c ON r.currency_id = c.id
             WHERE c.code = 'USD' AND r.sell_rate > 0
             ORDER BY r.sell_rate DESC LIMIT 1"
        );
        return $row ? (float) $row['sell_rate'] : 0.0;
    }

    /**
     * Fetch from exchangerate.host API.
     *
     * API: https://api.exchangerate.host/timeseries
     *
     * @return array Array of ['date' => string, 'price' => float]
     */
    private function fetchFromExchangeRateHost(string $currencyCode, string $dateFrom, string $dateTo): array
    {
        $apiKey = $this->resolveEncryptedSetting('backtesting_exchangerate_host_api_key');
        if ($apiKey === '') {
            throw new RuntimeException('exchangerate.host API key not configured');
        }

        $url = 'https://api.exchangerate.host/timeseries'
            . '?access_key=' . urlencode($apiKey)
            . '&start_date=' . urlencode($dateFrom)
            . '&end_date=' . urlencode($dateTo)
            . '&source=TRY'
            . '&currencies=' . urlencode($currencyCode);

        $response = $this->httpGet($url);
        if (!$response['success']) {
            throw new RuntimeException('exchangerate.host API request failed: ' . $response['error']);
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('exchangerate.host API returned invalid JSON');
        }

        if (isset($data['success']) && $data['success'] === false) {
            $errorMsg = $data['error']['info'] ?? ($data['error']['type'] ?? 'Unknown error');
            throw new RuntimeException('exchangerate.host API error: ' . $errorMsg);
        }

        // Parse timeseries response
        // Expected format: { "quotes": { "2026-01-01": { "TRYUSD": 0.0285 }, ... } }
        $quotes = $data['quotes'] ?? [];
        if (!is_array($quotes)) {
            throw new RuntimeException('exchangerate.host API response missing quotes data');
        }

        $pairKey = 'TRY' . $currencyCode;
        $result = [];
        foreach ($quotes as $date => $pairs) {
            if (is_array($pairs) && isset($pairs[$pairKey])) {
                $rate = (float) $pairs[$pairKey];
                // The rate is TRY->currency, we need currency->TRY (invert)
                if ($rate > 0) {
                    $price = 1.0 / $rate;
                    $result[] = ['date' => $date, 'price' => round($price, 6)];
                }
            }
        }

        // Sort by date ascending
        usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    // ─── HTTP ───────────────────────────────────────────────────────────────

    /**
     * HTTP GET with SSL verification and host whitelist.
     *
     * Security measures:
     * - HTTPS-only (scheme validation + CURLPROTO_HTTPS)
     * - Host whitelist via BACKTESTING_ALLOWED_HOSTS config
     * - SSRF protection via isPrivateOrReservedHost()
     * - SSL peer + host verification
     * - No redirects allowed
     * - 15 second timeout
     *
     * @return array{success: bool, body: string, status_code: int, error: string}
     */
    private function httpGet(string $url): array
    {
        // 1. Parse URL, validate HTTPS
        $scheme = strtolower(trim((string) (parse_url($url, PHP_URL_SCHEME) ?? '')));
        if ($scheme !== 'https') {
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => 'Only HTTPS URLs are allowed'];
        }

        // 2. Check host against allowed hosts whitelist
        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        if ($host === '') {
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => 'Invalid URL: no host'];
        }

        $allowedHosts = (defined('BACKTESTING_ALLOWED_HOSTS') && is_array(BACKTESTING_ALLOWED_HOSTS))
            ? BACKTESTING_ALLOWED_HOSTS
            : ['api.metals.dev', 'api.exchangerate.host'];

        $hostAllowed = false;
        foreach ($allowedHosts as $allowed) {
            if (strtolower(trim($allowed)) === $host) {
                $hostAllowed = true;
                break;
            }
        }
        if (!$hostAllowed) {
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => "Blocked host: '{$host}'"];
        }

        // 3. SSRF protection: block private/reserved IPs
        if (function_exists('isPrivateOrReservedHost') && isPrivateOrReservedHost($url)) {
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => "Host resolves to private/reserved IP: '{$host}'"];
        }

        // 4. cURL GET with SSL verification
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Cybokron/BacktestEngine',
            ],
        ]);

        // Enforce HTTPS-only protocol
        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            cybokron_log("BacktestEngine HTTP request failed: {$curlError}", 'ERROR');
            return ['success' => false, 'body' => '', 'status_code' => 0, 'error' => $curlError];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        $error = '';

        if (!$success) {
            $error = "HTTP {$httpCode}";
            cybokron_log("BacktestEngine HTTP error ({$httpCode}): URL={$url}", 'ERROR');
        }

        return ['success' => $success, 'body' => (string) $body, 'status_code' => $httpCode, 'error' => $error];
    }

    // ─── Persistence ────────────────────────────────────────────────────────

    /**
     * Save backtest result to leverage_backtests table.
     */
    private function saveBacktest(int $ruleId, string $source, string $dateFrom, string $dateTo, array $signals, array $summary): int
    {
        $resultData = json_encode([
            'signals' => array_map(function ($s) {
                return [
                    'date' => $s['date'],
                    'direction' => str_replace('_signal', '', $s['type']),
                    'price' => $s['price'],
                    'change_pct' => $s['change_pct'],
                    'reference' => $s['reference_price'],
                ];
            }, $signals),
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare('
            INSERT INTO leverage_backtests
                (rule_id, data_source, date_from, date_to, total_signals, buy_signals, sell_signals,
                 total_return_pct, max_drawdown_pct, win_rate_pct, result_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $ruleId,
            $source,
            $dateFrom,
            $dateTo,
            $summary['total_signals'],
            $summary['buy_signals'],
            $summary['sell_signals'],
            $summary['total_return_pct'],
            $summary['max_drawdown_pct'],
            $summary['win_rate_pct'],
            $resultData,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ─── Validation ─────────────────────────────────────────────────────────

    /**
     * Validate date range for backtesting.
     *
     * @return string|null Error message or null if valid
     */
    private function validateDateRange(string $dateFrom, string $dateTo): ?string
    {
        // Validate format
        $from = DateTime::createFromFormat('Y-m-d', $dateFrom);
        $fromErrors = DateTime::getLastErrors();
        $hasFromErrors = is_array($fromErrors)
            && (($fromErrors['warning_count'] ?? 0) > 0 || ($fromErrors['error_count'] ?? 0) > 0);

        if (!$from || $hasFromErrors || $from->format('Y-m-d') !== $dateFrom) {
            return 'Invalid date_from format (expected Y-m-d)';
        }

        $to = DateTime::createFromFormat('Y-m-d', $dateTo);
        $toErrors = DateTime::getLastErrors();
        $hasToErrors = is_array($toErrors)
            && (($toErrors['warning_count'] ?? 0) > 0 || ($toErrors['error_count'] ?? 0) > 0);

        if (!$to || $hasToErrors || $to->format('Y-m-d') !== $dateTo) {
            return 'Invalid date_to format (expected Y-m-d)';
        }

        // dateFrom must be before dateTo
        if ($from >= $to) {
            return 'date_from must be before date_to';
        }

        // Max range: 365 days
        $diff = $from->diff($to);
        if ($diff->days > self::MAX_DATE_RANGE_DAYS) {
            return 'Date range exceeds maximum of ' . self::MAX_DATE_RANGE_DAYS . ' days';
        }

        // dateTo must not be in the future
        $today = new DateTime('today');
        if ($to > $today) {
            return 'date_to cannot be in the future';
        }

        return null;
    }

    // ─── Data access ────────────────────────────────────────────────────────

    /**
     * Fetch a leverage rule by ID.
     */
    private function getRule(int $ruleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM leverage_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ─── Settings resolution ────────────────────────────────────────────────

    /**
     * Resolve a setting value: DB > config constant > default.
     * Follows SendGridMailer::resolveSetting() pattern.
     */
    private function resolveSetting(string $settingKey, string $configConstant, string $default): string
    {
        $dbVal = $this->getSettingValue($settingKey);
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }
        if ($configConstant !== '' && defined($configConstant)) {
            $val = trim((string) constant($configConstant));
            if ($val !== '') {
                return $val;
            }
        }
        return $default;
    }

    /**
     * Resolve an integer setting: DB > config constant > default.
     * Follows LeverageEngine::resolveIntSetting() pattern.
     */
    private function resolveIntSetting(string $settingKey, string $configConstant, int $default): int
    {
        $dbVal = $this->getSettingValue($settingKey);
        if ($dbVal !== null && is_numeric($dbVal)) {
            return (int) $dbVal;
        }
        if ($configConstant !== '' && defined($configConstant)) {
            return (int) constant($configConstant);
        }
        return $default;
    }

    /**
     * Resolve an encrypted setting value (e.g. API keys).
     * Follows LeverageEngine::resolveOpenRouterApiKey() pattern.
     */
    private function resolveEncryptedSetting(string $settingKey): string
    {
        $dbVal = $this->getSettingValue($settingKey);
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim(decryptSettingValue(trim($dbVal)));
        }
        return '';
    }

    /**
     * Read a single setting value from the database.
     */
    private function getSettingValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
    }
}

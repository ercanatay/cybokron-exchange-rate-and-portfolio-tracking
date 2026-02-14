<?php
/**
 * ScraperAutoRepair.php — Self-Healing Scraper Orchestrator
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * When a bank's HTML table structure changes and the scraper returns too few rates,
 * this class generates a declarative JSON parse config via OpenRouter AI,
 * validates it against the live HTML, saves it to the database and file system,
 * and commits it to GitHub with an issue.
 *
 * SECURITY: AI never generates executable PHP code. It only produces declarative
 * JSON config (XPath + column indices). A hardcoded PHP parser applies the config.
 */

class ScraperAutoRepair
{
    private int $bankId;
    private string $bankSlug;
    private string $bankName;
    private string $bankUrl;

    /** @var callable|null Progress callback: function(string $step, string $status, string $message, ?int $durationMs, ?array $meta): void */
    private $progressCallback = null;

    /** @var string[] Dangerous XPath patterns that must be rejected. */
    private const DANGEROUS_XPATH_PATTERNS = [
        'document(',
        'php:',
        'system-property(',
        'unparsed-entity-uri(',
        'generate-id(',
    ];

    public function __construct(int $bankId, string $bankSlug, string $bankName, string $bankUrl)
    {
        $this->bankId = $bankId;
        $this->bankSlug = $bankSlug;
        $this->bankName = $bankName;
        $this->bankUrl = $bankUrl;
    }

    /**
     * Set a callback to receive live progress updates.
     */
    public function setProgressCallback(callable $cb): void
    {
        $this->progressCallback = $cb;
    }

    /**
     * Emit progress via callback (if set) and log to DB.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function emitProgress(string $step, string $status, string $message, ?int $durationMs = null, ?array $metadata = null): void
    {
        $this->logStep($step, $status, $message, $durationMs, $metadata);

        if ($this->progressCallback !== null) {
            ($this->progressCallback)($step, $status, $message, $durationMs, $metadata);
        }
    }

    /**
     * Full self-healing pipeline.
     *
     * @param string[] $currencyCodes Known ISO currency codes
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>|null Parsed rates or null on failure
     */
    public function attemptRepair(
        string $html,
        string $oldHash,
        string $newHash,
        array $currencyCodes
    ): ?array {
        $this->emitProgress('check_enabled', 'in_progress', 'Checking if self-healing is enabled');
        if (!$this->isEnabled()) {
            $this->emitProgress('check_enabled', 'skipped', 'Self-healing is disabled');
            return null;
        }
        $this->emitProgress('check_enabled', 'success', 'Self-healing is enabled');

        $this->emitProgress('cooldown_check', 'in_progress', 'Checking cooldown');
        if ($this->isCooldownActive()) {
            $this->emitProgress('cooldown_check', 'skipped', 'Cooldown active, skipping repair');
            return null;
        }
        $this->emitProgress('cooldown_check', 'success', 'Cooldown clear');

        $pipelineStart = microtime(true);

        // Step 1: Generate repair config via AI
        $this->emitProgress('generate_config', 'in_progress', 'Generating config via AI');
        $config = $this->generateRepairConfig($html, $currencyCodes);
        if ($config === null) {
            return null;
        }

        // Step 2: Validate config against live HTML
        $this->emitProgress('validate_config', 'in_progress', 'Validating config against live HTML');
        $rates = $this->validateConfig($config, $html, $currencyCodes);
        if ($rates === null) {
            return null;
        }

        // Step 3: Save config to DB and filesystem
        $this->emitProgress('save_config', 'in_progress', 'Saving config to database');
        $configId = $this->saveRepairConfig($config, $newHash);
        if ($configId === null) {
            return null;
        }

        // Step 4: Commit to GitHub and create issue
        $this->emitProgress('github_commit', 'in_progress', 'Committing to GitHub');
        $this->commitToGitHub($config, $configId);

        $totalMs = (int) ((microtime(true) - $pipelineStart) * 1000);
        $rateCount = count($rates);
        $this->emitProgress('pipeline_complete', 'success', "Repair completed in {$totalMs}ms, {$rateCount} rates", $totalMs, [
            'config_id' => $configId,
            'rate_count' => $rateCount,
        ]);

        return $rates;
    }

    /**
     * Generate a repair config by sending HTML snapshot to OpenRouter AI.
     *
     * @param string[] $currencyCodes
     */
    private function generateRepairConfig(string $html, array $currencyCodes): ?array
    {
        $stepStart = microtime(true);

        try {
            $snapshot = $this->buildHtmlSnapshot($html);
            if ($snapshot === '') {
                $this->emitProgress('generate_config', 'error', 'Could not build HTML snapshot');
                return null;
            }

            $modelResponse = $this->requestRepairModel($snapshot, $currencyCodes);
            $config = $this->parseModelResponse($modelResponse);

            if ($config === null) {
                $this->emitProgress('generate_config', 'error', 'AI returned invalid config');
                return null;
            }

            if (!$this->isConfigSafe($config)) {
                $this->emitProgress('generate_config', 'error', 'Config contains dangerous XPath patterns');
                return null;
            }

            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('generate_config', 'success', 'Config generated via AI', $durationMs);
            return $config;
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('generate_config', 'error', $e->getMessage(), $durationMs);
            return null;
        }
    }

    /**
     * Validate a repair config by applying it to live HTML and checking rate count.
     *
     * @param string[] $currencyCodes
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>|null
     */
    private function validateConfig(array $config, string $html, array $currencyCodes): ?array
    {
        $stepStart = microtime(true);
        $minimumRates = defined('OPENROUTER_MIN_EXPECTED_RATES')
            ? max(1, (int) OPENROUTER_MIN_EXPECTED_RATES)
            : 8;

        try {
            $rates = self::applyRepairConfig($config, $html, $currencyCodes);
            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);

            if (count($rates) < $minimumRates) {
                $this->emitProgress('validate_config', 'error',
                    "Config produced only " . count($rates) . " rates (minimum: {$minimumRates})",
                    $durationMs
                );
                return null;
            }

            $this->emitProgress('validate_config', 'success',
                "Config validated: " . count($rates) . " rates parsed",
                $durationMs
            );
            return $rates;
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('validate_config', 'error', $e->getMessage(), $durationMs);
            return null;
        }
    }

    /**
     * Apply a repair config to HTML and extract rates.
     * This is the hardcoded parser — no eval, no dynamic code.
     *
     * @param string[] $currencyCodes
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>
     */
    public static function applyRepairConfig(array $config, string $html, array $currencyCodes): array
    {
        $xpathRows = (string) ($config['xpath_rows'] ?? '');
        $columns = $config['columns'] ?? [];
        $currencyMap = $config['currency_map'] ?? [];
        $numberFormat = (string) ($config['number_format'] ?? 'turkish');
        $skipHeaderRows = max(0, (int) ($config['skip_header_rows'] ?? 0));

        if ($xpathRows === '' || !is_array($columns)) {
            return [];
        }

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query($xpathRows);
        if (!$rows instanceof DOMNodeList || $rows->length === 0) {
            return [];
        }

        $codeSet = [];
        foreach ($currencyCodes as $c) {
            $codeSet[strtoupper(trim((string) $c))] = true;
        }

        $normalizedMap = [];
        if (is_array($currencyMap)) {
            foreach ($currencyMap as $localName => $isoCode) {
                $key = mb_strtolower(trim((string) $localName), 'UTF-8');
                if ($key !== '') {
                    $normalizedMap[$key] = strtoupper(trim((string) $isoCode));
                }
            }
        }

        $currencyCol = $columns['currency'] ?? null;
        $buyCol = $columns['buy'] ?? null;
        $sellCol = $columns['sell'] ?? null;
        $changeCol = $columns['change'] ?? null;

        $rates = [];
        $rowIndex = 0;

        foreach ($rows as $row) {
            if ($rowIndex < $skipHeaderRows) {
                $rowIndex++;
                continue;
            }
            $rowIndex++;

            $cells = $xpath->query('.//td|.//th', $row);
            if (!$cells instanceof DOMNodeList) {
                continue;
            }

            // Extract currency code
            $code = self::extractCellValue($xpath, $cells, $currencyCol);
            if ($code === null) {
                continue;
            }
            $code = self::resolveCurrencyCode($code, $codeSet, $normalizedMap);
            if ($code === null) {
                continue;
            }

            // Extract buy rate
            $buyText = self::extractCellValue($xpath, $cells, $buyCol);
            if ($buyText === null) {
                continue;
            }
            $buy = self::parseNumericValue($buyText, $numberFormat);
            if ($buy === null || $buy <= 0) {
                continue;
            }

            // Extract sell rate
            $sellText = self::extractCellValue($xpath, $cells, $sellCol);
            if ($sellText === null) {
                continue;
            }
            $sell = self::parseNumericValue($sellText, $numberFormat);
            if ($sell === null || $sell <= 0) {
                continue;
            }

            // Extract change (optional)
            $change = null;
            if ($changeCol !== null) {
                $changeText = self::extractCellValue($xpath, $cells, $changeCol);
                if ($changeText !== null) {
                    $change = self::parseNumericValue(
                        str_replace(['%', ' '], '', $changeText),
                        $numberFormat
                    );
                }
            }

            $rates[$code] = [
                'code'   => $code,
                'buy'    => $buy,
                'sell'   => $sell,
                'change' => $change,
            ];
        }

        ksort($rates);
        return array_values($rates);
    }

    /**
     * Save repair config to database and filesystem.
     */
    private function saveRepairConfig(array $config, string $tableHash): ?int
    {
        $stepStart = microtime(true);

        try {
            // Deactivate previous configs for this bank
            Database::execute(
                'UPDATE repair_configs SET is_active = 0, deactivated_at = NOW(), deactivation_reason = ? WHERE bank_id = ? AND is_active = 1',
                ['Superseded by new repair config', $this->bankId]
            );

            $configId = Database::insert('repair_configs', [
                'bank_id'      => $this->bankId,
                'xpath_rows'   => (string) ($config['xpath_rows'] ?? ''),
                'columns'      => json_encode($config['columns'] ?? [], JSON_UNESCAPED_UNICODE),
                'currency_map' => json_encode($config['currency_map'] ?? [], JSON_UNESCAPED_UNICODE),
                'number_format' => (string) ($config['number_format'] ?? 'turkish'),
                'skip_header_rows' => (int) ($config['skip_header_rows'] ?? 0),
                'table_hash'   => $tableHash,
                'is_active'    => 1,
            ]);

            // Write to repairs/ directory
            $filePath = dirname(__DIR__) . "/repairs/bank_{$this->bankId}_repair.json";
            $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($filePath, $jsonContent, LOCK_EX);

            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('save_config', 'success', "Config saved (ID: {$configId})", $durationMs);
            return $configId;
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('save_config', 'error', $e->getMessage(), $durationMs);
            return null;
        }
    }

    /**
     * Commit repair config to GitHub and create an issue.
     */
    private function commitToGitHub(array $config, int $configId): void
    {
        $stepStart = microtime(true);

        try {
            $github = new GitHubIntegration();
            if (!$github->isConfigured()) {
                $this->emitProgress('github_commit', 'skipped', 'GitHub integration not configured');
                return;
            }

            $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $filePath = "repairs/bank_{$this->bankId}_repair.json";
            $commitMessage = "[self-healing] Auto-repair config for {$this->bankName} (bank #{$this->bankId})";

            $commitResult = $github->commitFile($filePath, $jsonContent, $commitMessage);

            $commitSha = null;
            if ($commitResult !== null) {
                $commitSha = $commitResult['sha'];
                Database::update(
                    'repair_configs',
                    ['github_commit_sha' => $commitSha],
                    'id = ?',
                    [$configId]
                );
            }

            // Create issue
            $issueTitle = "[Self-Healing] {$this->bankName} scraper auto-repaired";
            $issueBody = "## Self-Healing Repair Report\n\n"
                . "- **Bank:** {$this->bankName} (`{$this->bankSlug}`)\n"
                . "- **Bank ID:** {$this->bankId}\n"
                . "- **URL:** {$this->bankUrl}\n"
                . "- **Config ID:** {$configId}\n"
                . ($commitSha ? "- **Commit:** {$commitSha}\n" : '')
                . "- **Date:** " . date('Y-m-d H:i:s') . "\n\n"
                . "### Generated Config\n\n"
                . "```json\n{$jsonContent}\n```\n\n"
                . "### Action Required\n\n"
                . "1. Review the auto-generated parse config above.\n"
                . "2. Verify rates are being parsed correctly.\n"
                . "3. If the config is wrong, deactivate it via the admin panel.\n"
                . "4. Consider updating the bank scraper class for a permanent fix.\n";

            $issueResult = $github->createIssue($issueTitle, $issueBody, ['self-healing', 'auto-repair']);

            if ($issueResult !== null) {
                Database::update(
                    'repair_configs',
                    ['github_issue_url' => $issueResult['html_url']],
                    'id = ?',
                    [$configId]
                );
            }

            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('github_commit', 'success', 'Committed to GitHub and issue created', $durationMs, [
                'commit_sha' => $commitSha,
                'issue_url'  => $issueResult['html_url'] ?? null,
            ]);
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $stepStart) * 1000);
            $this->emitProgress('github_commit', 'error', $e->getMessage(), $durationMs);
        }
    }

    /**
     * Rollback (deactivate) a repair config.
     */
    public static function rollbackRepairConfig(int $bankId, string $reason): bool
    {
        $affected = Database::execute(
            'UPDATE repair_configs SET is_active = 0, deactivated_at = NOW(), deactivation_reason = ? WHERE bank_id = ? AND is_active = 1',
            [$reason, $bankId]
        );

        if ($affected > 0) {
            Database::insert('repair_logs', [
                'bank_id'  => $bankId,
                'step'     => 'rollback',
                'status'   => 'success',
                'message'  => "Config deactivated: {$reason}",
            ]);
        }

        return $affected > 0;
    }

    /**
     * Load active repair config from DB for a bank.
     *
     * @return array{id: int, config: array}|null
     */
    public static function loadActiveConfig(int $bankId): ?array
    {
        $row = Database::queryOne(
            'SELECT id, xpath_rows, columns, currency_map, number_format, skip_header_rows FROM repair_configs WHERE bank_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
            [$bankId]
        );

        if ($row === null) {
            return null;
        }

        $columns = json_decode((string) $row['columns'], true);
        $currencyMap = json_decode((string) $row['currency_map'], true);

        return [
            'id'     => (int) $row['id'],
            'config' => [
                'xpath_rows'      => (string) $row['xpath_rows'],
                'columns'         => is_array($columns) ? $columns : [],
                'currency_map'    => is_array($currencyMap) ? $currencyMap : [],
                'number_format'   => (string) $row['number_format'],
                'skip_header_rows' => (int) $row['skip_header_rows'],
            ],
        ];
    }

    /**
     * Request OpenRouter to generate a parse config (not rate data).
     *
     * @param string[] $currencyCodes
     */
    private function requestRepairModel(string $snapshot, array $currencyCodes): string
    {
        $apiKey = trim((string) (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : ''));
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY not configured');
        }

        $apiUrl = (string) (defined('OPENROUTER_API_URL') ? OPENROUTER_API_URL : 'https://openrouter.ai/api/v1/chat/completions');
        $model = $this->resolveModel();
        $timeout = defined('OPENROUTER_AI_TIMEOUT_SECONDS') ? max(5, (int) OPENROUTER_AI_TIMEOUT_SECONDS) : 25;
        $maxTokens = defined('OPENROUTER_AI_MAX_TOKENS') ? max(100, (int) OPENROUTER_AI_MAX_TOKENS) : 800;

        $scheme = strtolower((string) (parse_url($apiUrl, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException('OPENROUTER_API_URL must use HTTPS');
        }

        $codesList = implode(', ', $currencyCodes);

        $configSchema = json_encode([
            'xpath_rows' => '//table//tbody//tr',
            'columns' => [
                'currency' => ['index' => 0, 'selector' => 'optional CSS selector within cell'],
                'buy' => ['index' => 1],
                'sell' => ['index' => 2],
                'change' => ['index' => 3],
            ],
            'currency_map' => ['amerikan dolari' => 'USD', 'euro' => 'EUR'],
            'number_format' => 'turkish',
            'skip_header_rows' => 0,
        ], JSON_PRETTY_PRINT);

        $messages = [
            [
                'role'    => 'system',
                'content' => 'You analyze HTML table structures and generate parse configurations. '
                    . 'Return strict JSON only. Do NOT return rate data - return parsing RULES. '
                    . 'The config tells a parser how to find and extract data from the table.',
            ],
            [
                'role'    => 'user',
                'content' => "Bank: {$this->bankName} ({$this->bankSlug})\n"
                    . "URL: {$this->bankUrl}\n"
                    . "Known currency codes: {$codesList}\n\n"
                    . "Generate a JSON parse config that matches this exact schema:\n{$configSchema}\n\n"
                    . "Rules:\n"
                    . "- xpath_rows: XPath expression to select table data rows\n"
                    . "- columns: map column names to their 0-based cell index\n"
                    . "- columns.currency can have an optional 'selector' for nested elements\n"
                    . "- currency_map: map local currency names (lowercase) to ISO codes\n"
                    . "- number_format: 'turkish' (comma decimal) or 'standard' (dot decimal)\n"
                    . "- skip_header_rows: number of header rows to skip (usually 0 if using tbody)\n"
                    . "- Return JSON only, no markdown\n\n"
                    . "HTML table snapshot:\n{$snapshot}",
            ],
        ];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0,
            'max_tokens'  => $maxTokens,
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        if (defined('APP_URL')) {
            $headers[] = 'HTTP-Referer: ' . APP_URL;
        }
        if (defined('APP_NAME')) {
            $headers[] = 'X-Title: ' . APP_NAME;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('OpenRouter repair request failed: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('OpenRouter repair HTTP error: ' . $httpCode);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenRouter returned invalid JSON');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenRouter returned empty content');
        }

        return trim($content);
    }

    /**
     * Parse AI model response into a config array.
     */
    private function parseModelResponse(string $response): ?array
    {
        $json = $this->extractJsonPayload($response);
        if ($json === null) {
            return null;
        }

        $config = json_decode($json, true);
        if (!is_array($config)) {
            return null;
        }

        // Validate required fields
        if (!isset($config['xpath_rows']) || !is_string($config['xpath_rows'])) {
            return null;
        }
        if (!isset($config['columns']) || !is_array($config['columns'])) {
            return null;
        }
        if (!isset($config['columns']['currency']) && !isset($config['columns']['buy'])) {
            return null;
        }

        // Normalize optional fields
        if (!isset($config['currency_map']) || !is_array($config['currency_map'])) {
            $config['currency_map'] = [];
        }
        if (!isset($config['number_format']) || !is_string($config['number_format'])) {
            $config['number_format'] = 'turkish';
        }
        if (!isset($config['skip_header_rows'])) {
            $config['skip_header_rows'] = 0;
        }

        return $config;
    }

    /**
     * Check if config contains dangerous XPath patterns (injection protection).
     */
    private function isConfigSafe(array $config): bool
    {
        $xpathRows = strtolower((string) ($config['xpath_rows'] ?? ''));

        foreach (self::DANGEROUS_XPATH_PATTERNS as $pattern) {
            if (str_contains($xpathRows, $pattern)) {
                return false;
            }
        }

        // Check column selectors
        if (isset($config['columns']) && is_array($config['columns'])) {
            foreach ($config['columns'] as $col) {
                if (!is_array($col)) {
                    continue;
                }
                $selector = strtolower((string) ($col['selector'] ?? ''));
                foreach (self::DANGEROUS_XPATH_PATTERNS as $pattern) {
                    if (str_contains($selector, $pattern)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Build compact HTML table snapshot for AI input.
     */
    private function buildHtmlSnapshot(string $html): string
    {
        $maxRows = 160;
        $maxChars = 12000;

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        // Capture raw HTML structure hints
        $tables = $xpath->query('//table');
        $hints = [];
        if ($tables instanceof DOMNodeList) {
            foreach ($tables as $tableIndex => $table) {
                $id = $table->getAttribute('id');
                $class = $table->getAttribute('class');
                $hint = "Table[{$tableIndex}]";
                if ($id !== '') {
                    $hint .= " id=\"{$id}\"";
                }
                if ($class !== '') {
                    $hint .= " class=\"{$class}\"";
                }
                $hints[] = $hint;
            }
        }

        $rows = $xpath->query('//table//tr');
        $lines = [];

        if (!empty($hints)) {
            $lines[] = 'Tables found: ' . implode(', ', $hints);
            $lines[] = '---';
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex >= $maxRows) {
                break;
            }

            $cells = $xpath->query('.//th|.//td', $row);
            $parts = [];

            foreach ($cells as $cell) {
                $text = preg_replace('/\s+/', ' ', trim((string) $cell->textContent));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            if (count($parts) >= 2) {
                $lines[] = implode(' | ', $parts);
            }
        }

        $snapshot = implode("\n", $lines);
        if ($snapshot === '') {
            return '';
        }

        if (mb_strlen($snapshot, 'UTF-8') > $maxChars) {
            $snapshot = mb_substr($snapshot, 0, $maxChars, 'UTF-8');
        }

        return $snapshot;
    }

    /**
     * Extract a cell value by column config.
     *
     * @param array|int|null $colConfig
     */
    private static function extractCellValue(DOMXPath $xpath, DOMNodeList $cells, $colConfig): ?string
    {
        if ($colConfig === null) {
            return null;
        }

        $index = is_array($colConfig) ? (int) ($colConfig['index'] ?? 0) : (int) $colConfig;
        $selector = is_array($colConfig) ? (string) ($colConfig['selector'] ?? '') : '';

        if ($index < 0 || $index >= $cells->length) {
            return null;
        }

        $cell = $cells->item($index);
        if ($cell === null) {
            return null;
        }

        if ($selector !== '') {
            $subNodes = $xpath->query('.//' . ltrim($selector, './'), $cell);
            if ($subNodes instanceof DOMNodeList && $subNodes->length > 0) {
                return trim((string) $subNodes->item(0)->textContent);
            }
        }

        return trim((string) $cell->textContent);
    }

    /**
     * Resolve currency code from text using code set and currency map.
     *
     * @param array<string, true> $codeSet
     * @param array<string, string> $normalizedMap
     */
    private static function resolveCurrencyCode(string $text, array $codeSet, array $normalizedMap): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Try extracting code from parentheses: (USD)
        if (preg_match('/\(([A-Z]{3})\)/u', $text, $m)) {
            $code = strtoupper($m[1]);
            if (isset($codeSet[$code])) {
                return $code;
            }
        }

        // Try direct code match
        $upper = strtoupper($text);
        if (isset($codeSet[$upper]) && strlen($upper) <= 4) {
            return $upper;
        }

        // Try currency map
        $lower = mb_strtolower($text, 'UTF-8');
        $lower = preg_replace('/\s+/', ' ', $lower);
        if (is_string($lower) && isset($normalizedMap[$lower])) {
            return $normalizedMap[$lower];
        }

        // Try partial match in map
        if (is_string($lower)) {
            foreach ($normalizedMap as $name => $code) {
                if ($name !== '' && mb_strpos($lower, $name, 0, 'UTF-8') !== false) {
                    return $code;
                }
            }
        }

        // Try 3-letter code in text
        if (preg_match('/\b([A-Z]{3})\b/u', strtoupper($text), $m)) {
            $code = $m[1];
            if (isset($codeSet[$code])) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Parse a numeric value respecting the configured number format.
     */
    private static function parseNumericValue(string $text, string $format): ?float
    {
        $text = trim($text);
        $text = preg_replace('/[^\d.,\-]/', '', $text);
        if ($text === '') {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($lastComma !== false) {
            $text = str_replace(',', '.', $text);
        }

        if (!is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * Extract JSON from model text (handles markdown fences).
     */
    private function extractJsonPayload(string $content): ?string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (($trimmed[0] ?? '') === '{') {
            return $trimmed;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $m)) {
            $candidate = trim((string) $m[1]);
            if ($candidate !== '' && ($candidate[0] ?? '') === '{') {
                return $candidate;
            }
        }

        $firstObj = strpos($trimmed, '{');
        $lastObj = strrpos($trimmed, '}');
        if ($firstObj !== false && $lastObj !== false && $lastObj > $firstObj) {
            return substr($trimmed, $firstObj, ($lastObj - $firstObj + 1));
        }

        return null;
    }

    /**
     * Resolve OpenRouter model.
     */
    private function resolveModel(): string
    {
        $row = Database::queryOne("SELECT value FROM settings WHERE `key` = 'openrouter_model'");
        if ($row !== null && isset($row['value']) && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', (string) $row['value'])) {
            return (string) $row['value'];
        }

        $configModel = defined('OPENROUTER_MODEL') ? trim((string) OPENROUTER_MODEL) : '';
        if ($configModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $configModel)) {
            return $configModel;
        }

        return 'z-ai/glm-5';
    }

    /**
     * Check if self-healing is enabled.
     */
    private function isEnabled(): bool
    {
        if (!defined('SELF_HEALING_ENABLED') || SELF_HEALING_ENABLED !== true) {
            return false;
        }

        $apiKey = trim((string) (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : ''));
        return $apiKey !== '';
    }

    /**
     * Check cooldown to avoid repeated repair attempts.
     */
    private function isCooldownActive(): bool
    {
        $cooldown = defined('SELF_HEALING_COOLDOWN_SECONDS')
            ? max(0, (int) SELF_HEALING_COOLDOWN_SECONDS)
            : 3600;

        if ($cooldown === 0) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT created_at FROM repair_logs WHERE bank_id = ? AND step = 'pipeline_complete' ORDER BY id DESC LIMIT 1",
            [$this->bankId]
        );

        if ($row === null) {
            return false;
        }

        $lastRepair = strtotime((string) $row['created_at']);
        return $lastRepair !== false && (time() - $lastRepair) < $cooldown;
    }

    /**
     * Log a repair pipeline step.
     *
     * @param array<string, mixed>|null $metadata
     */
    private function logStep(string $step, string $status, string $message, ?int $durationMs = null, ?array $metadata = null): void
    {
        try {
            Database::insert('repair_logs', [
                'bank_id'     => $this->bankId,
                'step'        => $step,
                'status'      => $status,
                'message'     => $message,
                'duration_ms' => $durationMs,
                'metadata'    => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            cybokron_log("Failed to log repair step: {$e->getMessage()}", 'ERROR');
        }
    }
}

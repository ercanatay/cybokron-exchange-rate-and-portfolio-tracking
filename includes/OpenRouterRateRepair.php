<?php
/**
 * OpenRouterRateRepair.php — AI-assisted rate extraction fallback
 *
 * Uses OpenRouter only when normal scraping returns too few rows and applies
 * strict normalization/validation before persisting data.
 */

class OpenRouterRateRepair
{
    private string $bankSlug;
    private string $bankName;
    /** @var array<string, bool> */
    private array $allowedCodeSet;
    private string $bankCacheKey;

    /**
     * @param string[] $allowedCodes
     */
    public function __construct(string $bankSlug, string $bankName, array $allowedCodes)
    {
        $this->bankSlug = $bankSlug;
        $this->bankName = $bankName;
        $this->allowedCodeSet = [];

        foreach ($allowedCodes as $code) {
            $normalized = strtoupper(trim((string) $code));
            if ($normalized !== '') {
                $this->allowedCodeSet[$normalized] = true;
            }
        }

        $this->bankCacheKey = substr(hash('sha1', $bankSlug), 0, 12);
    }

    /**
     * Trigger OpenRouter-based extraction fallback.
     */
    public function recover(string $html, string $tableHash, int $minimumRates = 8): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if (empty($this->allowedCodeSet)) {
            return [];
        }

        if ($this->isCooldownActive($tableHash)) {
            cybokron_log("OpenRouter repair skipped due to cooldown for {$this->bankSlug}", 'INFO');
            return [];
        }

        $snapshot = $this->buildTableSnapshot($html);
        if ($snapshot === '') {
            return [];
        }

        try {
            $modelResponse = $this->requestModel($snapshot);
            $rates = self::extractRatesFromModelText($modelResponse, array_keys($this->allowedCodeSet));

            $this->rememberAttempt($tableHash, count($rates));

            if (count($rates) < $minimumRates) {
                cybokron_log(
                    "OpenRouter returned insufficient rates for {$this->bankSlug}: " . count($rates),
                    'WARNING'
                );
                return [];
            }

            return $rates;
        } catch (Throwable $e) {
            $this->rememberAttempt($tableHash, 0);
            cybokron_log("OpenRouter repair failed for {$this->bankSlug}: {$e->getMessage()}", 'ERROR');
            return [];
        }
    }

    /**
     * Parse model content into validated rates.
     *
     * @param string[] $allowedCodes
     * @return array<int, array{code:string,buy:float,sell:float,change:?float}>
     */
    public static function extractRatesFromModelText(string $content, array $allowedCodes): array
    {
        $jsonPayload = self::extractJsonPayload($content);
        if ($jsonPayload === null) {
            return [];
        }

        $decoded = json_decode($jsonPayload, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        if (isset($decoded['rates']) && is_array($decoded['rates'])) {
            $rows = $decoded['rates'];
        } elseif (array_is_list($decoded)) {
            $rows = $decoded;
        }

        if (!is_array($rows)) {
            return [];
        }

        $allowed = [];
        foreach ($allowedCodes as $code) {
            $normalized = strtoupper(trim((string) $code));
            if ($normalized !== '') {
                $allowed[$normalized] = true;
            }
        }

        $resultByCode = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? $row['currency_code'] ?? '')));
            if ($code === '' || !isset($allowed[$code])) {
                continue;
            }

            $buy = self::toFloat($row['buy'] ?? $row['buy_rate'] ?? null);
            $sell = self::toFloat($row['sell'] ?? $row['sell_rate'] ?? null);
            if ($buy === null || $sell === null || $buy <= 0 || $sell <= 0) {
                continue;
            }

            $change = self::toFloat($row['change'] ?? $row['change_percent'] ?? null, true);

            $resultByCode[$code] = [
                'code' => $code,
                'buy' => $buy,
                'sell' => $sell,
                'change' => $change,
            ];
        }

        ksort($resultByCode);

        return array_values($resultByCode);
    }

    /**
     * AI fallback enabled only when key and feature flag are present.
     */
    private function isEnabled(): bool
    {
        if (!defined('OPENROUTER_AI_REPAIR_ENABLED') || OPENROUTER_AI_REPAIR_ENABLED !== true) {
            return false;
        }

        return $this->resolveApiKey() !== '';
    }

    /**
     * Resolve API key from DB setting or config fallback.
     */
    private function resolveApiKey(): string
    {
        $dbKey = $this->getSetting('openrouter_api_key');
        if ($dbKey !== null && trim($dbKey) !== '') {
            return trim(decryptSettingValue(trim($dbKey)));
        }

        return trim((string) (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : ''));
    }

    /**
     * Check cooldown for same table hash to avoid repeated AI calls.
     */
    private function isCooldownActive(string $tableHash): bool
    {
        $cooldownSeconds = defined('OPENROUTER_AI_COOLDOWN_SECONDS')
            ? max(0, (int) OPENROUTER_AI_COOLDOWN_SECONDS)
            : 21600;

        if ($cooldownSeconds === 0) {
            return false;
        }

        $lastHash = $this->getSetting('or_ai_h_' . $this->bankCacheKey);
        $lastTs = (int) ($this->getSetting('or_ai_t_' . $this->bankCacheKey) ?? 0);

        if ($lastHash !== $tableHash) {
            return false;
        }

        return $lastTs > 0 && (time() - $lastTs) < $cooldownSeconds;
    }

    /**
     * Save latest AI call metadata.
     */
    private function rememberAttempt(string $tableHash, int $ratesCount): void
    {
        $this->setSetting('or_ai_h_' . $this->bankCacheKey, $tableHash);
        $this->setSetting('or_ai_t_' . $this->bankCacheKey, (string) time());
        $this->setSetting('or_ai_c_' . $this->bankCacheKey, (string) $ratesCount);
    }

    /**
     * Build compact table snapshot for AI input.
     */
    private function buildTableSnapshot(string $html): string
    {
        $maxRows = defined('OPENROUTER_AI_MAX_ROWS') ? max(20, (int) OPENROUTER_AI_MAX_ROWS) : 160;
        $maxChars = defined('OPENROUTER_AI_MAX_INPUT_CHARS') ? max(1000, (int) OPENROUTER_AI_MAX_INPUT_CHARS) : 12000;

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query('//table//tr');
        $lines = [];

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
     * Request OpenRouter chat completions endpoint.
     */
    private function requestModel(string $tableSnapshot): string
    {
        $model = $this->resolveModel();
        $apiUrl = (string) (defined('OPENROUTER_API_URL') ? OPENROUTER_API_URL : 'https://openrouter.ai/api/v1/chat/completions');
        $apiKey = $this->resolveApiKey();
        $timeout = defined('OPENROUTER_AI_TIMEOUT_SECONDS') ? max(5, (int) OPENROUTER_AI_TIMEOUT_SECONDS) : 25;
        $maxTokens = defined('OPENROUTER_AI_MAX_TOKENS') ? max(100, (int) OPENROUTER_AI_MAX_TOKENS) : 600;

        $scheme = strtolower((string) (parse_url($apiUrl, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException('OPENROUTER_API_URL must use HTTPS');
        }
        $allowedHosts = $this->getAllowedOpenRouterHosts();
        $this->assertAllowedOpenRouterHost($apiUrl, $allowedHosts, 'OpenRouter API URL');

        $allowedCodes = implode(', ', array_keys($this->allowedCodeSet));

        $messages = [
            [
                'role' => 'system',
                'content' => 'You extract exchange rates from bank table text. Return strict JSON only.',
            ],
            [
                'role' => 'user',
                'content' => "Bank: {$this->bankName} ({$this->bankSlug})\n"
                    . "Allowed currency codes: {$allowedCodes}\n"
                    . "Task: Extract buy, sell and change values from the table text below.\n"
                    . "Output JSON schema exactly:\n"
                    . '{"rates":[{"code":"USD","buy":0.0,"sell":0.0,"change":null}]}' . "\n"
                    . "Rules:\n"
                    . "- Include only allowed currency codes.\n"
                    . "- Parse Turkish number formats too (e.g. 43,5865 and 7.049,5249).\n"
                    . "- If change is unavailable, set null.\n"
                    . "- Return JSON only, no markdown.\n\n"
                    . "Table text:\n{$tableSnapshot}",
            ],
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => $maxTokens,
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
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
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
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($effectiveUrl !== '') {
            $this->assertAllowedOpenRouterHost($effectiveUrl, $allowedHosts, 'OpenRouter redirect target');
        }

        if ($raw === false || $raw === '') {
            throw new RuntimeException('OpenRouter request failed: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('OpenRouter HTTP error: ' . $httpCode);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenRouter invalid JSON response');
        }

        $content = $this->extractAssistantContent($decoded);
        if ($content === '') {
            throw new RuntimeException('OpenRouter returned empty content');
        }

        return $content;
    }

    /**
     * Extract model output content from response payload.
     */
    private function extractAssistantContent(array $payload): string
    {
        $messageContent = $payload['choices'][0]['message']['content'] ?? '';

        if (is_string($messageContent)) {
            return trim($messageContent);
        }

        if (is_array($messageContent)) {
            $parts = [];
            foreach ($messageContent as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['text']) && is_string($chunk['text'])) {
                    $parts[] = $chunk['text'];
                } elseif (isset($chunk['content']) && is_string($chunk['content'])) {
                    $parts[] = $chunk['content'];
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    /**
     * Read setting value.
     */
    private function getSetting(string $key): ?string
    {
        $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if (!$row || !array_key_exists('value', $row)) {
            return null;
        }

        $value = $row['value'];
        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Upsert a setting key.
     */
    private function setSetting(string $key, string $value): void
    {
        Database::upsert('settings', [
            'key' => $key,
            'value' => $value,
        ], ['value']);
    }

    /**
     * Resolve model from DB setting or config default.
     */
    private function resolveModel(): string
    {
        $settingModel = $this->getSetting('openrouter_model');
        if ($settingModel !== null && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $settingModel)) {
            return $settingModel;
        }

        $configModel = defined('OPENROUTER_MODEL') ? trim((string) OPENROUTER_MODEL) : '';
        if ($configModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $configModel)) {
            return $configModel;
        }

        return 'z-ai/glm-5';
    }

    /**
     * @return string[]
     */
    private function getAllowedOpenRouterHosts(): array
    {
        $configured = (defined('OPENROUTER_ALLOWED_HOSTS') && is_array(OPENROUTER_ALLOWED_HOSTS))
            ? OPENROUTER_ALLOWED_HOSTS
            : ['openrouter.ai'];

        $normalized = [];
        foreach ($configured as $host) {
            $candidate = strtolower(trim((string) $host));
            $candidate = rtrim($candidate, '.');
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param string[] $allowedHosts
     */
    private function assertAllowedOpenRouterHost(string $url, array $allowedHosts, string $context): void
    {
        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new RuntimeException("Missing host for {$context}");
        }

        if (empty($allowedHosts)) {
            return;
        }

        foreach ($allowedHosts as $allowedHost) {
            $candidate = strtolower(trim((string) $allowedHost));
            $candidate = rtrim($candidate, '.');
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.' . $candidate))) {
                return;
            }
        }

        throw new RuntimeException("Blocked outbound host '{$host}' for {$context}");
    }

    /**
     * Try to extract JSON object/array from model text.
     */
    private static function extractJsonPayload(string $content): ?string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            return $trimmed;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $m)) {
            $candidate = trim((string) $m[1]);
            if ($candidate !== '' && (($candidate[0] ?? '') === '{' || ($candidate[0] ?? '') === '[')) {
                return $candidate;
            }
        }

        $firstObj = strpos($trimmed, '{');
        $lastObj = strrpos($trimmed, '}');
        if ($firstObj !== false && $lastObj !== false && $lastObj > $firstObj) {
            return substr($trimmed, $firstObj, ($lastObj - $firstObj + 1));
        }

        $firstArr = strpos($trimmed, '[');
        $lastArr = strrpos($trimmed, ']');
        if ($firstArr !== false && $lastArr !== false && $lastArr > $firstArr) {
            return substr($trimmed, $firstArr, ($lastArr - $firstArr + 1));
        }

        return null;
    }

    /**
     * Normalize value to float (handles Turkish number formatting).
     */
    private static function toFloat($value, bool $nullable = false): ?float
    {
        if ($value === null || $value === '') {
            return $nullable ? null : null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return $nullable ? null : null;
        }

        $text = str_replace(['%', ' '], '', $text);
        $text = preg_replace('/[^\d.,\-]/', '', $text);
        if ($text === '') {
            return $nullable ? null : null;
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
}

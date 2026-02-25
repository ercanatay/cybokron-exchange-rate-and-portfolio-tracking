<?php
/**
 * LeverageWebhookDispatcher.php — Multi-platform webhook delivery for leverage signals
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Supports: generic JSON, Discord embeds, Slack blocks
 * Pattern: AlertChecker::sendWebhookAlert() (SSRF, HTTPS-only) + SendGridMailer (settings resolution)
 */

class LeverageWebhookDispatcher
{
    /**
     * Check if webhook notifications are enabled.
     * Resolution: settings.webhook_enabled > config LEVERAGE_WEBHOOK_ENABLED > false
     */
    public static function isEnabled(): bool
    {
        $dbVal = self::getSettingValue('webhook_enabled');
        if ($dbVal !== null) {
            return $dbVal === '1';
        }
        return defined('LEVERAGE_WEBHOOK_ENABLED') ? (bool) LEVERAGE_WEBHOOK_ENABLED : false;
    }

    /**
     * Dispatch signal to all active webhooks.
     *
     * @return array{sent:int, failed:int, errors:string[]}
     */
    public static function dispatch(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType = 'leverage_signal'): array
    {
        $webhooks = self::getActiveWebhooks();
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];

        if (empty($webhooks)) {
            return $result;
        }

        foreach ($webhooks as $webhook) {
            $payload = self::buildPayload(
                $webhook['platform'] ?? 'generic',
                $rule,
                $direction,
                $changePct,
                $aiResult,
                $eventType
            );
            $sendResult = self::sendRequest($webhook['url'], $payload, $eventType);

            if ($sendResult['success']) {
                $result['sent']++;
            } else {
                $result['failed']++;
                $result['errors'][] = ($webhook['name'] ?? 'unknown') . ': ' . $sendResult['error'];
            }

            self::updateWebhookStatus((int) $webhook['id'], $sendResult['status_code']);
        }

        return $result;
    }

    // ─── Payload Builders ────────────────────────────────────────────────

    /**
     * Build platform-specific payload.
     */
    private static function buildPayload(string $platform, array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): array
    {
        switch ($platform) {
            case 'discord':
                return self::buildDiscordPayload($rule, $direction, $changePct, $aiResult, $eventType);
            case 'slack':
                return self::buildSlackPayload($rule, $direction, $changePct, $aiResult, $eventType);
            default:
                return self::buildGenericPayload($rule, $direction, $changePct, $aiResult, $eventType);
        }
    }

    /**
     * Generic JSON payload.
     */
    private static function buildGenericPayload(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): array
    {
        $payload = [
            'event' => $eventType,
            'direction' => $direction,
            'currency' => $rule['currency_code'] ?? '',
            'rule_name' => $rule['name'] ?? '',
            'current_price' => isset($rule['_current_price']) ? (float) $rule['_current_price'] : null,
            'reference_price' => isset($rule['reference_price']) ? (float) $rule['reference_price'] : null,
            'change_percent' => round($changePct, 2),
            'ai' => null,
            'timestamp' => date('c'),
        ];

        if ($aiResult !== null) {
            $payload['ai'] = [
                'recommendation' => $aiResult['recommendation'] ?? null,
                'confidence' => $aiResult['confidence'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * Discord embed payload.
     *
     * @see https://discord.com/developers/docs/resources/webhook#execute-webhook
     */
    private static function buildDiscordPayload(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): array
    {
        $currencyCode = $rule['currency_code'] ?? '???';
        $ruleName = $rule['name'] ?? '';
        $referencePrice = isset($rule['reference_price']) ? number_format((float) $rule['reference_price'], 4) : '—';
        $currentPrice = isset($rule['_current_price']) ? number_format((float) $rule['_current_price'], 4) : '—';
        $changeFormatted = number_format($changePct, 2) . '%';

        $isBuy = str_contains($direction, 'buy');
        $color = $isBuy ? 15548997 : 5763719; // red for buy (price dropped), green for sell (price rose)

        $directionLabel = strtoupper($direction === 'buy' ? 'AL' : ($direction === 'sell' ? 'SAT' : $direction));
        $title = self::resolveEventEmoji($eventType) . ' ' . $directionLabel . ' — ' . $currencyCode;

        $fields = [
            ['name' => 'Referans', 'value' => $referencePrice, 'inline' => true],
            ['name' => 'Guncel', 'value' => $currentPrice, 'inline' => true],
            ['name' => 'Degisim', 'value' => $changeFormatted, 'inline' => true],
        ];

        if ($aiResult !== null) {
            $rec = $aiResult['recommendation'] ?? '—';
            $conf = isset($aiResult['confidence']) ? '%' . (int) $aiResult['confidence'] : '';
            $fields[] = ['name' => 'AI', 'value' => $rec . ($conf !== '' ? ' (' . $conf . ')' : ''), 'inline' => false];
        }

        $fields[] = ['name' => 'Kural', 'value' => $ruleName, 'inline' => false];

        return [
            'embeds' => [
                [
                    'title' => $title,
                    'color' => $color,
                    'fields' => $fields,
                    'timestamp' => date('c'),
                ],
            ],
        ];
    }

    /**
     * Slack blocks payload.
     *
     * @see https://api.slack.com/reference/messaging/blocks
     */
    private static function buildSlackPayload(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): array
    {
        $currencyCode = $rule['currency_code'] ?? '???';
        $ruleName = $rule['name'] ?? '';
        $referencePrice = isset($rule['reference_price']) ? number_format((float) $rule['reference_price'], 4) : '—';
        $currentPrice = isset($rule['_current_price']) ? number_format((float) $rule['_current_price'], 4) : '—';
        $changeFormatted = number_format($changePct, 2) . '%';

        $directionLabel = strtoupper($direction === 'buy' ? 'AL' : ($direction === 'sell' ? 'SAT' : $direction));
        $headerText = self::resolveEventEmoji($eventType) . ' ' . $directionLabel . ' — ' . $currencyCode;

        $sectionFields = [
            ['type' => 'mrkdwn', 'text' => '*Referans:* ' . $referencePrice],
            ['type' => 'mrkdwn', 'text' => '*Guncel:* ' . $currentPrice],
            ['type' => 'mrkdwn', 'text' => '*Degisim:* ' . $changeFormatted],
        ];

        if ($aiResult !== null) {
            $rec = $aiResult['recommendation'] ?? '—';
            $conf = isset($aiResult['confidence']) ? '%' . (int) $aiResult['confidence'] : '';
            $sectionFields[] = ['type' => 'mrkdwn', 'text' => '*AI:* ' . $rec . ($conf !== '' ? ' (' . $conf . ')' : '')];
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $headerText],
            ],
            [
                'type' => 'section',
                'fields' => $sectionFields,
            ],
            [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => 'Kural: ' . $ruleName . ' | ' . date('Y-m-d H:i')],
                ],
            ],
        ];

        return ['blocks' => $blocks];
    }

    /**
     * Resolve event label prefix for webhook titles.
     */
    private static function resolveEventEmoji(string $eventType): string
    {
        switch ($eventType) {
            case 'weak_buy_signal':
            case 'weak_sell_signal':
                return 'ERKEN UYARI';
            case 'trailing_stop_signal':
                return 'TRAILING STOP';
            default:
                return 'KALDIRAC SINYALI';
        }
    }

    // ─── HTTP Request ────────────────────────────────────────────────────

    /**
     * Send HTTP POST request to webhook URL.
     * HTTPS-only + SSRF protection (AlertChecker::sendWebhookAlert pattern).
     *
     * @return array{success:bool, status_code:int, error:string}
     */
    private static function sendRequest(string $url, array $payload, string $eventType = 'leverage_signal'): array
    {
        // 1. Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            cybokron_log('LeverageWebhook: invalid URL format', 'WARNING');
            return ['success' => false, 'status_code' => 0, 'error' => 'Invalid URL format'];
        }

        // 2. HTTPS-only
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            cybokron_log('LeverageWebhook: only HTTPS URLs are allowed', 'WARNING');
            return ['success' => false, 'status_code' => 0, 'error' => 'Only HTTPS URLs are allowed'];
        }

        // 3. SSRF protection: block private/reserved IPs
        if (isPrivateOrReservedHost($url)) {
            cybokron_log('LeverageWebhook: private/reserved IP blocked', 'WARNING');
            return ['success' => false, 'status_code' => 0, 'error' => 'Private/reserved IP blocked'];
        }

        // 4. cURL POST with JSON body
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            return ['success' => false, 'status_code' => 0, 'error' => 'JSON encode failed'];
        }

        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: Cybokron-LeverageWebhook/1.0',
                'X-Cybokron-Event: ' . $eventType,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // 5. Restrict protocol to HTTPS only, disable redirects
        if (defined('CURLPROTO_HTTPS')) {
            $curlOpts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $curlOpts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        $curlOpts[CURLOPT_FOLLOWLOCATION] = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            cybokron_log("LeverageWebhook failed {$url}: {$curlError}", 'WARNING');
            return ['success' => false, 'status_code' => $httpCode, 'error' => $curlError ?: 'cURL request failed'];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        if (!$success) {
            cybokron_log("LeverageWebhook failed {$url}: HTTP {$httpCode}", 'WARNING');
            return ['success' => false, 'status_code' => $httpCode, 'error' => "HTTP {$httpCode}"];
        }

        return ['success' => true, 'status_code' => $httpCode, 'error' => ''];
    }

    // ─── CRUD Methods ────────────────────────────────────────────────────

    /**
     * Get all active webhooks.
     *
     * @return array<int, array{id:int, name:string, url:string, platform:string, is_active:int}>
     */
    public static function getActiveWebhooks(): array
    {
        return Database::query(
            'SELECT id, name, url, platform, is_active, last_sent_at, last_status_code
             FROM leverage_webhooks
             WHERE is_active = 1
             ORDER BY created_at ASC'
        );
    }

    /**
     * Get all webhooks (active and inactive).
     *
     * @return array<int, array>
     */
    public static function getAllWebhooks(): array
    {
        return Database::query(
            'SELECT id, name, url, platform, is_active, last_sent_at, last_status_code, created_at, updated_at
             FROM leverage_webhooks
             ORDER BY created_at DESC'
        );
    }

    /**
     * Create a new webhook with HTTPS URL validation + SSRF check.
     *
     * @return array{success:bool, id:int|null, error:string}
     */
    public static function createWebhook(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        $platform = $data['platform'] ?? 'generic';

        // Validate name
        if ($name === '' || mb_strlen($name) > 100) {
            return ['success' => false, 'id' => null, 'error' => 'Webhook name is required (max 100 chars)'];
        }

        // Validate platform
        $allowedPlatforms = ['generic', 'discord', 'slack'];
        if (!in_array($platform, $allowedPlatforms, true)) {
            return ['success' => false, 'id' => null, 'error' => 'Invalid platform'];
        }

        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'id' => null, 'error' => 'Invalid URL format'];
        }

        // HTTPS-only
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            return ['success' => false, 'id' => null, 'error' => 'Only HTTPS URLs are allowed'];
        }

        // SSRF protection
        if (isPrivateOrReservedHost($url)) {
            return ['success' => false, 'id' => null, 'error' => 'Private/reserved IP addresses are not allowed'];
        }

        // URL length check
        if (strlen($url) > 500) {
            return ['success' => false, 'id' => null, 'error' => 'URL too long (max 500 chars)'];
        }

        $id = Database::insert('leverage_webhooks', [
            'name' => $name,
            'url' => $url,
            'platform' => $platform,
            'is_active' => 1,
        ]);

        return ['success' => true, 'id' => $id, 'error' => ''];
    }

    /**
     * Update an existing webhook with HTTPS + SSRF validation.
     *
     * @return array{success:bool, error:string}
     */
    public static function updateWebhook(int $id, array $data): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Invalid webhook ID'];
        }

        $fields = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                return ['success' => false, 'error' => 'Webhook name is required (max 100 chars)'];
            }
            $fields['name'] = $name;
        }

        if (isset($data['url'])) {
            $url = trim((string) $data['url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'error' => 'Invalid URL format'];
            }
            $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
            if ($scheme !== 'https') {
                return ['success' => false, 'error' => 'Only HTTPS URLs are allowed'];
            }
            if (isPrivateOrReservedHost($url)) {
                return ['success' => false, 'error' => 'Private/reserved IP addresses are not allowed'];
            }
            if (strlen($url) > 500) {
                return ['success' => false, 'error' => 'URL too long (max 500 chars)'];
            }
            $fields['url'] = $url;
        }

        if (isset($data['platform'])) {
            $allowedPlatforms = ['generic', 'discord', 'slack'];
            if (!in_array($data['platform'], $allowedPlatforms, true)) {
                return ['success' => false, 'error' => 'Invalid platform'];
            }
            $fields['platform'] = $data['platform'];
        }

        if (empty($fields)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }

        $updated = Database::update('leverage_webhooks', $fields, 'id = ?', [$id]);
        return ['success' => $updated > 0, 'error' => ''];
    }

    /**
     * Delete a webhook by ID.
     */
    public static function deleteWebhook(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return Database::execute('DELETE FROM leverage_webhooks WHERE id = ?', [$id]) > 0;
    }

    /**
     * Toggle webhook active/inactive state.
     */
    public static function toggleWebhook(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $webhook = Database::queryOne('SELECT is_active FROM leverage_webhooks WHERE id = ?', [$id]);
        if ($webhook === null) {
            return false;
        }

        $newState = ((int) $webhook['is_active'] === 1) ? 0 : 1;
        return Database::update('leverage_webhooks', ['is_active' => $newState], 'id = ?', [$id]) > 0;
    }

    /**
     * Update last_sent_at and last_status_code for a webhook.
     */
    private static function updateWebhookStatus(int $id, ?int $statusCode): void
    {
        if ($id <= 0) {
            return;
        }
        Database::update(
            'leverage_webhooks',
            [
                'last_sent_at' => date('Y-m-d H:i:s'),
                'last_status_code' => $statusCode,
            ],
            'id = ?',
            [$id]
        );
    }

    // ─── Settings Helpers ────────────────────────────────────────────────

    /**
     * Resolve a setting: DB > config constant > default.
     * Follows SendGridMailer::resolveSetting() pattern.
     *
     * @param string $settingKey Database settings key
     * @param string $configConstant PHP config constant name
     * @param mixed $default Default value if neither DB nor config has a value
     * @return mixed
     */
    private static function resolveSetting(string $settingKey, string $configConstant = '', $default = null)
    {
        $dbVal = self::getSettingValue($settingKey);
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }
        if ($configConstant !== '' && defined($configConstant)) {
            $val = constant($configConstant);
            if (is_string($val)) {
                $val = trim($val);
                if ($val !== '') {
                    return $val;
                }
            } elseif ($val !== null) {
                return $val;
            }
        }
        return $default;
    }

    /**
     * Read a single setting value from the database.
     */
    private static function getSettingValue(string $key): ?string
    {
        $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
    }
}

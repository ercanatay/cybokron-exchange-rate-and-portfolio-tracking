<?php
/**
 * TelegramNotifier — Telegram Bot API signal delivery for leverage system
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Pattern: SendGridMailer.php (settings resolution) + AlertChecker::sendTelegramAlert() (cURL)
 */

class TelegramNotifier
{
    private const TELEGRAM_API_HOST = 'api.telegram.org';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if Telegram notifications are enabled.
     * Priority: settings.telegram_enabled > config LEVERAGE_TELEGRAM_ENABLED > false
     */
    public function isEnabled(): bool
    {
        $dbVal = $this->getSettingValue('telegram_enabled');
        if ($dbVal !== null) {
            return $dbVal === '1';
        }
        return defined('LEVERAGE_TELEGRAM_ENABLED') ? (bool) LEVERAGE_TELEGRAM_ENABLED : false;
    }

    /**
     * Send a message via Telegram Bot API.
     *
     * @return array{success: bool, error: string}
     */
    public function send(string $chatId, string $message, string $parseMode = 'HTML'): array
    {
        $botToken = $this->resolveBotToken();
        if ($botToken === null || $botToken === '') {
            return ['success' => false, 'error' => 'Bot token not configured'];
        }

        if ($chatId === '') {
            return ['success' => false, 'error' => 'Chat ID is empty'];
        }

        $url = 'https://' . self::TELEGRAM_API_HOST . '/bot' . $botToken . '/sendMessage';

        // Host validation: only api.telegram.org allowed (AlertChecker pattern)
        $parsedHost = parse_url($url, PHP_URL_HOST);
        if (!is_string($parsedHost) || $parsedHost !== self::TELEGRAM_API_HOST) {
            cybokron_log('TelegramNotifier: constructed URL points to unexpected host', 'WARNING');
            return ['success' => false, 'error' => 'Host validation failed'];
        }

        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $message,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (defined('CURLPROTO_HTTPS')) {
            $curlOpts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $curlOpts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $curlOpts);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            cybokron_log("TelegramNotifier request failed: {$curlError}", 'ERROR');
            return ['success' => false, 'error' => $curlError];
        }

        $success = $httpCode === 200;
        $error   = '';

        if (!$success) {
            $decoded = json_decode((string) $response, true);
            $error   = $decoded['description'] ?? "HTTP {$httpCode}";
            cybokron_log("TelegramNotifier error ({$httpCode}): {$error}", 'ERROR');
        }

        return ['success' => $success, 'error' => $error];
    }

    /**
     * Send leverage signal notification via Telegram.
     *
     * @return array{success: bool, error: string}
     */
    public function sendLeverageSignal(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): array
    {
        $chatId = $this->resolveChatId();
        if ($chatId === null || $chatId === '') {
            return ['success' => false, 'error' => 'No chat ID configured'];
        }

        $message = $this->buildSignalMessage($rule, $direction, $changePct, $aiResult, $eventType);

        return $this->send($chatId, $message);
    }

    /**
     * Send test message to verify connection.
     *
     * @return array{success: bool, error: string}
     */
    public function sendTestMessage(): array
    {
        $chatId = $this->resolveChatId();
        if ($chatId === null || $chatId === '') {
            return ['success' => false, 'error' => 'No chat ID configured'];
        }

        $message = "✅ <b>Cybokron Leverage</b>\n\nTelegram bağlantısı başarılı! Test mesajı.\n\n📅 " . date('Y-m-d H:i:s');

        return $this->send($chatId, $message);
    }

    /**
     * Build HTML formatted Telegram message for leverage signal.
     */
    private function buildSignalMessage(array $rule, string $direction, float $changePct, ?array $aiResult, string $eventType): string
    {
        // Escape all user-supplied data
        $ruleName     = htmlspecialchars((string) ($rule['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currencyCode = htmlspecialchars((string) ($rule['currency_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currencyName = htmlspecialchars((string) ($rule['currency_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $referencePrice = (float) ($rule['reference_price'] ?? 0);
        $currentPrice   = (float) ($rule['current_price'] ?? 0);

        // Direction emoji and label
        $isWeakSignal    = str_contains($eventType, 'weak_');
        $isTrailingStop  = $eventType === 'trailing_stop_signal';

        if ($isWeakSignal) {
            $signalPrefix = '⚠️ ERKEN UYARI';
        } elseif ($isTrailingStop) {
            $signalPrefix = '🔄 TRAILING STOP';
        } else {
            $signalPrefix = '⚡ KALDIRAC SİNYALİ';
        }

        if ($direction === 'buy') {
            $directionEmoji = '🔴';
            $directionLabel = 'AL SİNYALİ';
        } elseif ($direction === 'sell') {
            $directionEmoji = '🟢';
            $directionLabel = 'SAT SİNYALİ';
        } else {
            $directionEmoji = '📊';
            $directionLabel = htmlspecialchars(strtoupper($direction), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $changeEmoji = $changePct < 0 ? '📉' : '📈';

        // Build message lines
        $lines = [];
        $lines[] = "<b>{$signalPrefix}</b>";
        $lines[] = "{$directionEmoji} <b>{$directionLabel}</b> — {$currencyCode}" . ($currencyName !== '' ? " {$currencyName}" : '');
        $lines[] = '━━━━━━━━━━━━━━';
        $lines[] = "📊 Referans: ₺" . number_format($referencePrice, 2, '.', ',') . " → Güncel: ₺" . number_format($currentPrice, 2, '.', ',');
        $lines[] = "{$changeEmoji} Değişim: " . number_format($changePct, 2) . '%';

        // AI result if available
        if ($aiResult !== null && !$isWeakSignal) {
            $aiRecommendation = htmlspecialchars((string) ($aiResult['recommendation'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $aiConfidence     = isset($aiResult['confidence']) ? (int) $aiResult['confidence'] : null;
            $aiSummary        = htmlspecialchars((string) ($aiResult['summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($aiRecommendation !== '') {
                $aiLine = "🤖 AI: {$aiRecommendation}";
                if ($aiConfidence !== null) {
                    $aiLine .= " (%{$aiConfidence})";
                }
                $lines[] = $aiLine;
            }

            if ($aiSummary !== '') {
                $lines[] = "💡 {$aiSummary}";
            }
        }

        $lines[] = '━━━━━━━━━━━━━━';
        $lines[] = "📋 Kural: {$ruleName}";
        $lines[] = '⏰ ' . date('Y-m-d H:i');

        return implode("\n", $lines);
    }

    /**
     * Resolve bot token from settings (encrypted) or config.
     * Priority: settings.telegram_bot_token (may be encrypted) > config ALERT_TELEGRAM_BOT_TOKEN > null
     */
    private function resolveBotToken(): ?string
    {
        $dbVal = $this->getSettingValue('telegram_bot_token');
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim(decryptSettingValue(trim($dbVal)));
        }

        if (defined('ALERT_TELEGRAM_BOT_TOKEN')) {
            $val = trim((string) ALERT_TELEGRAM_BOT_TOKEN);
            if ($val !== '') {
                return $val;
            }
        }

        return null;
    }

    /**
     * Resolve chat ID from settings or config.
     * Priority: settings.telegram_chat_id > config ALERT_TELEGRAM_CHAT_ID > null
     */
    private function resolveChatId(): ?string
    {
        $dbVal = $this->getSettingValue('telegram_chat_id');
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }

        if (defined('ALERT_TELEGRAM_CHAT_ID')) {
            $val = trim((string) ALERT_TELEGRAM_CHAT_ID);
            if ($val !== '') {
                return $val;
            }
        }

        return null;
    }

    /**
     * Helper: resolve setting value with DB > config constant > default priority.
     * Follows SendGridMailer::resolveSetting() pattern.
     */
    private function resolveSetting(string $settingKey, string $configConstant = '', $default = null)
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
     * Read a single setting value from the database.
     */
    private function getSettingValue(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
        } catch (Throwable $e) {
            cybokron_log("TelegramNotifier: failed to read setting '{$key}': {$e->getMessage()}", 'ERROR');
            return null;
        }
    }
}

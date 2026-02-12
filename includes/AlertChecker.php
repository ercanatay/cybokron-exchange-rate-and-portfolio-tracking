<?php
/**
 * AlertChecker.php — Alert Evaluation & Notification
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Checks active alerts against current rates and dispatches notifications.
 */

class AlertChecker
{
    /**
     * Get all active alerts.
     *
     * @return array<int, array{id:int,currency_code:string,condition_type:string,threshold:float,channel:string,channel_config:?string,user_id:?int}>
     */
    public static function getActiveAlerts(): array
    {
        return Database::query(
            'SELECT id, user_id, currency_code, condition_type, threshold, channel, channel_config, last_triggered_at
             FROM alerts WHERE is_active = 1'
        );
    }

    /**
     * Get current sell rate for a currency (best bank rate).
     */
    public static function getCurrentRate(string $currencyCode): ?array
    {
        $row = Database::queryOne(
            'SELECT r.buy_rate, r.sell_rate, r.change_percent, r.scraped_at, b.slug AS bank_slug
             FROM rates r
             JOIN currencies c ON c.id = r.currency_id
             JOIN banks b ON b.id = r.bank_id
             WHERE c.code = ? AND b.is_active = 1 AND c.is_active = 1
             ORDER BY r.sell_rate DESC
             LIMIT 1',
            [strtoupper($currencyCode)]
        );

        return $row ?: null;
    }

    /**
     * Get sell rate from yesterday for change_pct calculation.
     */
    public static function getPreviousRate(string $currencyCode): ?float
    {
        $row = Database::queryOne(
            'SELECT rh.sell_rate
             FROM rate_history rh
             JOIN currencies c ON c.id = rh.currency_id
             WHERE c.code = ? AND rh.scraped_at < CURDATE()
             ORDER BY rh.scraped_at DESC
             LIMIT 1',
            [strtoupper($currencyCode)]
        );

        return $row ? (float) $row['sell_rate'] : null;
    }

    /**
     * Check if alert condition is triggered.
     */
    public static function isTriggered(array $alert, array $currentRate, ?float $previousRate): bool
    {
        $type = $alert['condition_type'] ?? '';
        $threshold = (float) ($alert['threshold'] ?? 0);
        $sellRate = (float) ($currentRate['sell_rate'] ?? 0);

        switch ($type) {
            case 'above':
                return $sellRate >= $threshold;
            case 'below':
                return $sellRate <= $threshold;
            case 'change_pct':
                if ($previousRate === null || $previousRate <= 0) {
                    return false;
                }
                $changePct = (($sellRate - $previousRate) / $previousRate) * 100;
                return abs($changePct) >= $threshold;
            default:
                return false;
        }
    }

    /**
     * Update last_triggered_at for an alert.
     */
    public static function markTriggered(int $alertId): void
    {
        Database::update(
            'alerts',
            ['last_triggered_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$alertId]
        );
    }

    /**
     * Send notification via configured channel.
     *
     * @param array{id:int,currency_code:string,condition_type:string,threshold:float,channel:string,channel_config:?string,user_id:?int} $alert
     * @param array{buy_rate:float,sell_rate:float,change_percent:?float,scraped_at:string,bank_slug:string} $currentRate
     */
    public static function sendNotification(array $alert, array $currentRate, string $subject, string $body): bool
    {
        $channel = $alert['channel'] ?? 'email';
        $config = $alert['channel_config'] ? json_decode($alert['channel_config'], true) : [];

        if (!is_array($config)) {
            $config = [];
        }

        switch ($channel) {
            case 'email':
                return self::sendEmailAlert($config, $subject, $body);
            case 'telegram':
                return self::sendTelegramAlert($config, $body);
            case 'webhook':
                return self::sendWebhookAlert($config, $subject, $body);
            default:
                cybokron_log("Alert channel '{$channel}' not supported for alert #{$alert['id']}", 'WARNING');
                return false;
        }
    }

    /**
     * Send notification via configured channel.
     */
    private static function sendEmailAlert(array $config, string $subject, string $body): bool
    {
        $to = $config['email'] ?? (defined('ALERT_EMAIL_TO') ? ALERT_EMAIL_TO : '');
        if ($to === '') {
            cybokron_log('Alert email: no recipient configured', 'WARNING');
            return false;
        }

        $from = defined('ALERT_EMAIL_FROM') ? ALERT_EMAIL_FROM : 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $headers = [
            'From: ' . $from,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . PHP_VERSION,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private static function sendTelegramAlert(array $config, string $body): bool
    {
        $botToken = $config['bot_token'] ?? (defined('ALERT_TELEGRAM_BOT_TOKEN') ? ALERT_TELEGRAM_BOT_TOKEN : '');
        $chatId = $config['chat_id'] ?? (defined('ALERT_TELEGRAM_CHAT_ID') ? ALERT_TELEGRAM_CHAT_ID : '');

        if ($botToken === '' || $chatId === '') {
            cybokron_log('Alert Telegram: bot_token or chat_id not configured', 'WARNING');
            return false;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $body,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 && $response !== false;
    }

    private static function sendWebhookAlert(array $config, string $subject, string $body): bool
    {
        $url = $config['url'] ?? (defined('ALERT_WEBHOOK_URL') ? ALERT_WEBHOOK_URL : '');
        if ($url === '') {
            cybokron_log('Alert webhook: URL not configured', 'WARNING');
            return false;
        }

        $payload = [
            'subject' => $subject,
            'body' => $body,
            'timestamp' => date('c'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Run the full alert check cycle.
     *
     * @return array{checked:int,triggered:int,sent:int,errors:array<string>}
     */
    public static function run(): array
    {
        $alerts = self::getActiveAlerts();
        $result = ['checked' => count($alerts), 'triggered' => 0, 'sent' => 0, 'errors' => []];

        $cooldownMinutes = defined('ALERT_COOLDOWN_MINUTES') ? (int) ALERT_COOLDOWN_MINUTES : 60;

        foreach ($alerts as $alert) {
            $currencyCode = strtoupper((string) ($alert['currency_code'] ?? ''));
            if ($currencyCode === '') {
                continue;
            }

            $currentRate = self::getCurrentRate($currencyCode);
            if ($currentRate === null) {
                $result['errors'][] = "No rate for {$currencyCode}";
                continue;
            }

            $previousRate = null;
            if (($alert['condition_type'] ?? '') === 'change_pct') {
                $previousRate = self::getPreviousRate($currencyCode);
            }

            if (!self::isTriggered($alert, $currentRate, $previousRate)) {
                continue;
            }

            $lastTriggered = $alert['last_triggered_at'] ?? null;
            if ($lastTriggered !== null && $lastTriggered !== '') {
                $lastTime = strtotime($lastTriggered);
                if ($lastTime !== false && (time() - $lastTime) < ($cooldownMinutes * 60)) {
                    continue;
                }
            }

            $result['triggered']++;

            $subject = self::buildSubject($alert, $currentRate);
            $body = self::buildBody($alert, $currentRate);

            try {
                if (self::sendNotification($alert, $currentRate, $subject, $body)) {
                    self::markTriggered($alert['id']);
                    $result['sent']++;
                }

                cybokron_log("Alert #{$alert['id']} triggered for {$currencyCode}");
            } catch (Throwable $e) {
                $result['errors'][] = "Alert #{$alert['id']}: {$e->getMessage()}";
                cybokron_log("Alert #{$alert['id']} notification failed: {$e->getMessage()}", 'ERROR');
            }
        }

        return $result;
    }

    private static function buildSubject(array $alert, array $currentRate): string
    {
        $currencyCode = $alert['currency_code'] ?? '???';
        $sellRate = formatRate((float) ($currentRate['sell_rate'] ?? 0));
        return "Cybokron kur alarmı: {$currencyCode} = {$sellRate} ₺";
    }

    private static function buildBody(array $alert, array $currentRate): string
    {
        $currencyCode = $alert['currency_code'] ?? '???';
        $conditionType = $alert['condition_type'] ?? '';
        $threshold = $alert['threshold'] ?? 0;
        $sellRate = $currentRate['sell_rate'] ?? 0;
        $buyRate = $currentRate['buy_rate'] ?? 0;
        $change = $currentRate['change_percent'] ?? null;
        $scrapedAt = $currentRate['scraped_at'] ?? '';

        $lines = [
            "Cybokron Kur Alarmı",
            "",
            "Para birimi: {$currencyCode}",
            "Koşul: {$conditionType} (eşik: {$threshold})",
            "Satış kuru: " . formatRate((float) $sellRate) . " ₺",
            "Alış kuru: " . formatRate((float) $buyRate) . " ₺",
        ];

        if ($change !== null) {
            $lines[] = "Değişim: % " . number_format($change, 2);
        }

        $lines[] = "Son güncelleme: {$scrapedAt}";
        $lines[] = "";
        $lines[] = "Bu e-posta otomatik olarak gönderilmiştir.";

        return implode("\n", $lines);
    }
}

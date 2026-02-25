<?php
/**
 * SendGridMailer.php — SendGrid API v3 email sender
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Generic mailer using SendGrid REST API. No SDK dependency.
 */

class SendGridMailer
{
    private const API_URL = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * Send email via SendGrid API v3.
     *
     * @param string|string[] $to Recipient email(s)
     * @param string $subject Email subject
     * @param string $htmlBody HTML content
     * @param string $textBody Plain text content (optional)
     * @return array{success:bool, status_code:int, error:string}
     */
    public static function send($to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        $apiKey = self::resolveApiKey();
        if ($apiKey === '') {
            return ['success' => false, 'status_code' => 0, 'error' => 'SendGrid API key not configured'];
        }

        if (!self::isEnabled()) {
            return ['success' => false, 'status_code' => 0, 'error' => 'SendGrid is disabled'];
        }

        $fromEmail = self::resolveSetting('sendgrid_from_email', 'SENDGRID_FROM_EMAIL', 'noreply@localhost');
        $fromName = self::resolveSetting('sendgrid_from_name', 'SENDGRID_FROM_NAME', 'Cybokron');

        // Normalize recipients
        $recipients = is_array($to) ? $to : [$to];
        $recipients = array_filter($recipients, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if (empty($recipients)) {
            return ['success' => false, 'status_code' => 0, 'error' => 'No valid recipients'];
        }

        // Build personalizations (SendGrid format)
        $toArray = array_map(fn($email) => ['email' => $email], array_values($recipients));

        $payload = [
            'personalizations' => [['to' => $toArray]],
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => $subject,
            'content' => [],
        ];

        if ($textBody !== '') {
            $payload['content'][] = ['type' => 'text/plain', 'value' => $textBody];
        }
        $payload['content'][] = ['type' => 'text/html', 'value' => $htmlBody];

        // Host validation
        self::assertAllowedHost(self::API_URL);

        // cURL request
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            cybokron_log("SendGrid request failed: {$curlError}", 'ERROR');
            return ['success' => false, 'status_code' => 0, 'error' => $curlError];
        }

        // SendGrid returns 202 Accepted on success
        $success = $httpCode >= 200 && $httpCode < 300;
        $error = '';

        if (!$success) {
            $decoded = json_decode((string) $response, true);
            $error = $decoded['errors'][0]['message'] ?? "HTTP {$httpCode}";
            cybokron_log("SendGrid error ({$httpCode}): {$error}", 'ERROR');
        }

        return ['success' => $success, 'status_code' => $httpCode, 'error' => $error];
    }

    /**
     * Resolve API key: settings (encrypted) > config define.
     */
    private static function resolveApiKey(): string
    {
        $dbKey = self::getSettingValue('sendgrid_api_key');
        if ($dbKey !== null && trim($dbKey) !== '') {
            return trim(decryptSettingValue(trim($dbKey)));
        }
        return trim((string) (defined('SENDGRID_API_KEY') ? SENDGRID_API_KEY : ''));
    }

    /**
     * Check if SendGrid is enabled.
     */
    private static function isEnabled(): bool
    {
        $dbVal = self::getSettingValue('sendgrid_enabled');
        if ($dbVal !== null) {
            return $dbVal === '1';
        }
        return defined('SENDGRID_ENABLED') ? (bool) SENDGRID_ENABLED : false;
    }

    /**
     * Resolve a setting: DB > config constant > default.
     */
    private static function resolveSetting(string $settingKey, string $configConstant, string $default): string
    {
        $dbVal = self::getSettingValue($settingKey);
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }
        if (defined($configConstant)) {
            $val = trim((string) constant($configConstant));
            if ($val !== '') {
                return $val;
            }
        }
        return $default;
    }

    /**
     * Read a single setting value.
     */
    private static function getSettingValue(string $key): ?string
    {
        $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
    }

    /**
     * Validate URL host against allowed hosts list.
     */
    private static function assertAllowedHost(string $url): void
    {
        $allowedHosts = (defined('SENDGRID_ALLOWED_HOSTS') && is_array(SENDGRID_ALLOWED_HOSTS))
            ? SENDGRID_ALLOWED_HOSTS
            : ['api.sendgrid.com'];

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        foreach ($allowedHosts as $allowed) {
            if (strtolower(trim($allowed)) === $host) {
                return;
            }
        }
        throw new RuntimeException("SendGrid: blocked host '{$host}'");
    }

    /**
     * Get notification recipients from settings.
     *
     * @return string[]
     */
    public static function getNotifyEmails(): array
    {
        $json = self::getSettingValue('leverage_notify_emails');
        if ($json === null || $json === '') {
            return [];
        }
        $emails = json_decode($json, true);
        if (!is_array($emails)) {
            return [];
        }
        return array_values(array_filter($emails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

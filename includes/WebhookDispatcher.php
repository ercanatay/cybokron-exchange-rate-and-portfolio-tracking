<?php
/**
 * WebhookDispatcher.php — Send webhook notifications on rate updates
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class WebhookDispatcher
{
    /**
     * Dispatch rate update event to configured webhooks.
     *
     * @param array{total_rates:int,banks:array,bank_results:array} $payload
     * @return array{success:int,failed:array<string>}
     */
    public static function dispatchRateUpdate(array $payload): array
    {
        $urls = self::getWebhookUrls();
        if (empty($urls)) {
            return ['success' => 0, 'failed' => []];
        }

        $body = json_encode([
            'event' => 'rates_updated',
            'timestamp' => date('c'),
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        $success = 0;
        $failed = [];

        foreach ($urls as $url) {
            if (self::post($url, $body)) {
                $success++;
            } else {
                $failed[] = $url;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * @return string[]
     */
    private static function getWebhookUrls(): array
    {
        $urls = [];
        if (defined('RATE_UPDATE_WEBHOOK_URLS') && is_array(RATE_UPDATE_WEBHOOK_URLS)) {
            foreach (RATE_UPDATE_WEBHOOK_URLS as $u) {
                $u = trim((string) $u);
                if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) {
                    $urls[] = $u;
                }
            }
        }
        if (defined('RATE_UPDATE_WEBHOOK_URL') && RATE_UPDATE_WEBHOOK_URL !== '') {
            $u = trim((string) RATE_UPDATE_WEBHOOK_URL);
            if (filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            }
        }
        return array_unique($urls);
    }

    private static function post(string $url, string $body): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Cybokron-Webhook/1.0',
                'X-Cybokron-Event: rates_updated',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || ($httpCode < 200 || $httpCode >= 300)) {
            cybokron_log("Webhook failed {$url}: HTTP {$httpCode} {$err}", 'WARNING');
            return false;
        }

        return true;
    }
}

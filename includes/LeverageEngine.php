<?php
/**
 * LeverageEngine.php — AI-powered leverage rule engine
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Checks leverage rules against current rates, triggers AI analysis
 * via OpenRouter (Gemini), and dispatches email notifications via SendGrid.
 */

class LeverageEngine
{
    // ─── Cron entry point ───────────────────────────────────────────────────

    /**
     * Run the full leverage check cycle (called from cron).
     *
     * @return array{checked:int, triggered:int, sent:int, errors:string[]}
     */
    public static function run(): array
    {
        $rules = self::getActiveRules();
        $result = ['checked' => count($rules), 'triggered' => 0, 'sent' => 0, 'errors' => []];

        foreach ($rules as $rule) {
            try {
                $outcome = self::checkRule($rule);
                if ($outcome === null) {
                    continue;
                }
                $result['triggered']++;
                if ($outcome['notification_sent']) {
                    $result['sent']++;
                }
            } catch (Throwable $e) {
                $result['errors'][] = "Rule #{$rule['id']}: {$e->getMessage()}";
                cybokron_log("Leverage rule #{$rule['id']} failed: {$e->getMessage()}", 'ERROR');
            }
        }

        return $result;
    }

    // ─── Rule checking ──────────────────────────────────────────────────────

    /**
     * Check a single rule against current price.
     *
     * @return array|null Outcome if triggered, null otherwise
     */
    private static function checkRule(array $rule): ?array
    {
        $ruleId = (int) $rule['id'];
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $referencePrice = (float) $rule['reference_price'];

        if ($referencePrice <= 0) {
            return null;
        }

        // Get current price
        $currentRate = self::getCurrentRate($currencyCode);
        if ($currentRate === null) {
            self::updateLastChecked($ruleId);
            return null;
        }

        $currentPrice = (float) $currentRate['sell_rate'];
        if ($currentPrice <= 0) {
            self::updateLastChecked($ruleId);
            return null;
        }

        $changePercent = (($currentPrice - $referencePrice) / $referencePrice) * 100;
        $buyThreshold = (float) $rule['buy_threshold'];
        $sellThreshold = (float) $rule['sell_threshold'];

        // Determine signal direction
        $direction = null;
        if ($changePercent <= $buyThreshold) {
            $direction = 'buy';
        } elseif ($changePercent >= $sellThreshold) {
            $direction = 'sell';
        }

        self::updateLastChecked($ruleId);

        if ($direction === null) {
            return null;
        }

        // Cooldown check
        $cooldownMinutes = self::resolveIntSetting('leverage_cooldown_minutes', 'LEVERAGE_COOLDOWN_MINUTES', 60);
        $lastTriggered = $rule['last_triggered_at'] ?? null;
        if ($lastTriggered !== null && $lastTriggered !== '') {
            $lastTime = strtotime($lastTriggered);
            if ($lastTime !== false && (time() - $lastTime) < ($cooldownMinutes * 60)) {
                return null;
            }
        }

        // Direction check: don't re-trigger same direction unless price returned to reference
        $lastDirection = $rule['last_trigger_direction'] ?? null;
        if ($lastDirection === $direction) {
            return null;
        }

        // ── Signal triggered ────────────────────────────────────────────────

        $eventType = $direction === 'buy' ? 'buy_signal' : 'sell_signal';
        $aiResult = null;

        // AI pre-analysis
        if ((int) ($rule['ai_enabled'] ?? 1) === 1 && self::isAiEnabled()) {
            try {
                $aiResult = self::requestAiAnalysis($rule, $currentRate, $changePercent, $direction);
            } catch (Throwable $e) {
                cybokron_log("Leverage AI analysis failed for rule #{$ruleId}: {$e->getMessage()}", 'ERROR');
            }
        }

        // Save history
        $historyData = [
            'rule_id' => $ruleId,
            'event_type' => $eventType,
            'price_at_event' => $currentPrice,
            'reference_price_at_event' => $referencePrice,
            'change_percent' => round($changePercent, 2),
            'ai_response' => $aiResult !== null ? json_encode($aiResult, JSON_UNESCAPED_UNICODE) : null,
            'ai_recommendation' => $aiResult['recommendation'] ?? null,
            'notification_sent' => 0,
            'notification_channel' => null,
            'notes' => null,
        ];

        $historyId = Database::insert('leverage_history', $historyData);

        // Send email
        $emailSent = false;
        $recipients = SendGridMailer::getNotifyEmails();
        if (!empty($recipients)) {
            $emailSent = self::sendSignalEmail($rule, $currentRate, $changePercent, $direction, $aiResult, $recipients);
            if ($emailSent) {
                Database::update('leverage_history', [
                    'notification_sent' => 1,
                    'notification_channel' => 'email',
                ], 'id = ?', [$historyId]);
            }
        }

        // Update rule trigger state
        Database::update('leverage_rules', [
            'last_triggered_at' => date('Y-m-d H:i:s'),
            'last_trigger_direction' => $direction,
        ], 'id = ?', [$ruleId]);

        cybokron_log("Leverage rule #{$ruleId} triggered: {$direction} signal, change={$changePercent}%");

        return [
            'direction' => $direction,
            'change_percent' => $changePercent,
            'ai_recommendation' => $aiResult['recommendation'] ?? null,
            'notification_sent' => $emailSent,
        ];
    }

    // ─── AI Analysis ────────────────────────────────────────────────────────

    /**
     * Request AI pre-analysis via OpenRouter.
     */
    private static function requestAiAnalysis(array $rule, array $currentRate, float $changePercent, string $direction): ?array
    {
        $model = self::resolveModel();
        $apiKey = self::resolveOpenRouterApiKey();
        if ($apiKey === '' || $model === '') {
            return null;
        }

        $apiUrl = defined('OPENROUTER_API_URL')
            ? (string) OPENROUTER_API_URL
            : 'https://openrouter.ai/api/v1/chat/completions';

        $timeout = self::resolveIntSetting('leverage_ai_timeout', 'LEVERAGE_AI_TIMEOUT_SECONDS', 30);
        $maxTokens = defined('LEVERAGE_AI_MAX_TOKENS') ? max(100, (int) LEVERAGE_AI_MAX_TOKENS) : 800;

        // Build prompt
        $prompt = self::buildAiPrompt($rule, $currentRate, $changePercent, $direction);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a precious metals and forex investment analyst. Respond with JSON only, no markdown.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.1,
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

        // Validate host
        $allowedHosts = (defined('OPENROUTER_ALLOWED_HOSTS') && is_array(OPENROUTER_ALLOWED_HOSTS))
            ? OPENROUTER_ALLOWED_HOSTS
            : ['openrouter.ai'];
        $host = strtolower(trim((string) (parse_url($apiUrl, PHP_URL_HOST) ?? '')));
        $hostAllowed = false;
        foreach ($allowedHosts as $ah) {
            if (strtolower(trim($ah)) === $host) {
                $hostAllowed = true;
                break;
            }
        }
        if (!$hostAllowed) {
            throw new RuntimeException("Blocked host '{$host}' for OpenRouter API");
        }

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
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
        $curlError = curl_error($ch);
        curl_close($ch);

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

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') {
            return null;
        }

        return self::parseAiResponse($content);
    }

    /**
     * Build the AI analysis prompt.
     */
    private static function buildAiPrompt(array $rule, array $currentRate, float $changePercent, string $direction): string
    {
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $referencePrice = (float) $rule['reference_price'];
        $currentPrice = (float) $currentRate['sell_rate'];
        $threshold = $direction === 'buy' ? $rule['buy_threshold'] : $rule['sell_threshold'];

        $currRow = Database::queryOne(
            'SELECT name_tr, name_en FROM currencies WHERE code = ? AND is_active = 1',
            [$currencyCode]
        );
        $currencyName = $currRow['name_en'] ?? $currencyCode;

        $auAgRatio = self::getGoldSilverRatio();
        $portfolio = self::getPortfolioContext($rule);
        $trend = self::getPriceTrendSummary($currencyCode);
        $strategyContext = self::sanitizeStrategyContext($rule['strategy_context'] ?? '');

        $prompt = "Asset: {$currencyCode} ({$currencyName})\n"
            . "Reference Price: " . number_format($referencePrice, 2) . " TRY\n"
            . "Current Price: " . number_format($currentPrice, 2) . " TRY\n"
            . "Change: " . number_format($changePercent, 2) . "%\n"
            . "Gold/Silver Ratio: {$auAgRatio} (context: >80 = silver undervalued, <60 = consider switching to gold)\n"
            . "Trigger: {$direction} threshold breached ({$threshold}%)\n\n";

        if ($strategyContext !== '') {
            $prompt .= "Strategy Context:\n<user_context>\n{$strategyContext}\n</user_context>\n\n";
        }

        $prompt .= "Portfolio Position:\n"
            . "- Total Amount: {$portfolio['amount']}\n"
            . "- Average Cost: {$portfolio['avg_cost']} TRY\n"
            . "- P/L: {$portfolio['pnl']}%\n\n"
            . "Last 30 days price trend: {$trend}\n\n"
            . "Respond with JSON only:\n"
            . "{\n"
            . "  \"recommendation\": \"strong_buy|buy|hold|sell|strong_sell\",\n"
            . "  \"confidence\": 0-100,\n"
            . "  \"reasoning\": \"brief analysis in Turkish\",\n"
            . "  \"risk_level\": \"low|medium|high\",\n"
            . "  \"suggested_action\": \"suggested action in Turkish\"\n"
            . "}";

        return $prompt;
    }

    /**
     * Parse AI response JSON.
     */
    private static function parseAiResponse(string $content): ?array
    {
        $json = self::extractJsonPayload($content);
        if ($json === null) {
            cybokron_log('Leverage AI: could not extract JSON from response', 'WARNING');
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            cybokron_log('Leverage AI: invalid JSON in response', 'WARNING');
            return null;
        }

        $validRecs = ['strong_buy', 'buy', 'hold', 'sell', 'strong_sell'];
        $rec = strtolower(trim($decoded['recommendation'] ?? ''));
        if (!in_array($rec, $validRecs, true)) {
            $rec = 'hold';
        }

        return [
            'recommendation' => $rec,
            'confidence' => max(0, min(100, (int) ($decoded['confidence'] ?? 50))),
            'reasoning' => mb_substr(trim($decoded['reasoning'] ?? ''), 0, 1000, 'UTF-8'),
            'risk_level' => in_array($decoded['risk_level'] ?? '', ['low', 'medium', 'high'], true)
                ? $decoded['risk_level']
                : 'medium',
            'suggested_action' => mb_substr(trim($decoded['suggested_action'] ?? ''), 0, 500, 'UTF-8'),
        ];
    }

    // ─── Email ──────────────────────────────────────────────────────────────

    /**
     * Send signal email via SendGrid.
     */
    private static function sendSignalEmail(
        array $rule,
        array $currentRate,
        float $changePercent,
        string $direction,
        ?array $aiResult,
        array $recipients
    ): bool {
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $changeStr = ($changePercent >= 0 ? '+' : '') . number_format($changePercent, 1) . '%';

        if ($aiResult !== null) {
            $subjectKey = $direction === 'buy' ? 'leverage.email.subject_buy' : 'leverage.email.subject_sell';
            $subject = t($subjectKey, [
                'currency' => $currencyCode,
                'change' => $changeStr,
                'recommendation' => $aiResult['recommendation'],
                'confidence' => $aiResult['confidence'],
            ]);
        } else {
            $signal = $direction === 'buy' ? t('leverage.email.signal_buy') : t('leverage.email.signal_sell');
            $subject = t('leverage.email.subject_no_ai', [
                'signal' => $signal,
                'currency' => $currencyCode,
                'change' => $changeStr,
            ]);
        }

        $referencePrice = (float) $rule['reference_price'];
        $currentPrice = (float) $currentRate['sell_rate'];

        $html = self::buildEmailHtml($rule, $referencePrice, $currentPrice, $changePercent, $direction, $aiResult);
        $text = self::buildEmailText($rule, $referencePrice, $currentPrice, $changePercent, $direction, $aiResult);

        $result = SendGridMailer::send($recipients, $subject, $html, $text);
        return $result['success'];
    }

    private static function buildEmailHtml(
        array $rule,
        float $referencePrice,
        float $currentPrice,
        float $changePercent,
        string $direction,
        ?array $aiResult
    ): string {
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $signalLabel = $direction === 'buy' ? t('leverage.email.signal_buy') : t('leverage.email.signal_sell');
        $signalColor = $direction === 'buy' ? '#e74c3c' : '#27ae60';
        $changeStr = ($changePercent >= 0 ? '+' : '') . number_format($changePercent, 2) . '%';
        $threshold = $direction === 'buy' ? $rule['buy_threshold'] : $rule['sell_threshold'];
        $portfolio = self::getPortfolioContext($rule);
        $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $td = '<td style="padding:8px;border:1px solid #ddd">';
        $th = '<td style="padding:8px;border:1px solid #ddd;font-weight:bold">';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
        $html .= '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">';
        $html .= '<h2 style="color:' . $signalColor . '">' . $e(t('leverage.email.signal_label', ['signal' => $signalLabel, 'currency' => $currencyCode])) . '</h2>';

        $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
        $html .= "<tr>{$th}" . $e(t('leverage.email.reference')) . "</td>{$td}₺" . number_format($referencePrice, 2, ',', '.') . '</td></tr>';
        $html .= "<tr>{$th}" . $e(t('leverage.email.current')) . "</td>{$td}₺" . number_format($currentPrice, 2, ',', '.') . '</td></tr>';
        $html .= "<tr>{$th}" . $e(t('leverage.email.change')) . "</td><td style=\"padding:8px;border:1px solid #ddd;color:{$signalColor}\">" . $changeStr . '</td></tr>';
        $html .= "<tr>{$th}" . $e(t('leverage.email.threshold')) . "</td>{$td}" . $e(t('leverage.email.threshold_breached', ['threshold' => $threshold])) . '</td></tr>';
        $html .= '</table>';

        if ($aiResult !== null) {
            $recLabel = self::getRecommendationLabel($aiResult['recommendation']);
            $html .= '<h3>' . $e(t('leverage.email.ai_title')) . '</h3>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
            $html .= "<tr>{$th}" . $e(t('leverage.email.recommendation')) . "</td>{$td}" . $e($recLabel) . '</td></tr>';
            $html .= "<tr>{$th}" . $e(t('leverage.email.confidence')) . "</td>{$td}%" . $aiResult['confidence'] . '</td></tr>';
            $html .= "<tr>{$th}" . $e(t('leverage.email.risk')) . "</td>{$td}" . ucfirst($aiResult['risk_level']) . '</td></tr>';
            $html .= '</table>';
            if (!empty($aiResult['reasoning'])) {
                $html .= '<p style="background:#f8f9fa;padding:12px;border-radius:6px;font-style:italic">' . nl2br($e($aiResult['reasoning'])) . '</p>';
            }
            if (!empty($aiResult['suggested_action'])) {
                $html .= '<p><strong>' . $e(t('leverage.email.suggested_action')) . ':</strong> ' . $e($aiResult['suggested_action']) . '</p>';
            }
        }

        if ($portfolio['amount'] !== 'N/A') {
            $html .= '<h3>' . $e(t('leverage.email.portfolio_title')) . '</h3>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
            $html .= "<tr>{$th}" . $e(t('leverage.email.amount')) . "</td>{$td}" . $portfolio['amount'] . '</td></tr>';
            $html .= "<tr>{$th}" . $e(t('leverage.email.avg_cost')) . "</td>{$td}₺" . $portfolio['avg_cost'] . '</td></tr>';
            $html .= "<tr>{$th}" . $e(t('leverage.email.pnl')) . "</td>{$td}" . $portfolio['pnl'] . '%</td></tr>';
            $html .= '</table>';
        }

        $html .= '<hr style="margin:20px 0;border:none;border-top:1px solid #ddd">';
        $html .= '<p style="color:#666;font-size:12px">' . $e(t('leverage.email.rule')) . ': ' . $e($rule['name']) . '<br>';
        $html .= $e(t('leverage.email.date')) . ': ' . date('d.m.Y H:i') . '<br>';
        $appUrl = defined('APP_URL') ? APP_URL : '';
        if ($appUrl !== '') {
            $html .= '<a href="' . $e($appUrl) . '/leverage.php">' . $e(t('leverage.email.panel_link')) . '</a>';
        }
        $html .= '</p></div></body></html>';

        return $html;
    }

    private static function buildEmailText(
        array $rule,
        float $referencePrice,
        float $currentPrice,
        float $changePercent,
        string $direction,
        ?array $aiResult
    ): string {
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $signalLabel = $direction === 'buy' ? t('leverage.email.signal_buy') : t('leverage.email.signal_sell');
        $changeStr = ($changePercent >= 0 ? '+' : '') . number_format($changePercent, 2) . '%';
        $threshold = $direction === 'buy' ? $rule['buy_threshold'] : $rule['sell_threshold'];

        $text = t('leverage.email.signal_label', ['signal' => $signalLabel, 'currency' => $currencyCode]) . "\n\n";
        $text .= t('leverage.email.reference') . ": TL " . number_format($referencePrice, 2) . "\n";
        $text .= t('leverage.email.current') . ": TL " . number_format($currentPrice, 2) . "\n";
        $text .= t('leverage.email.change') . ": {$changeStr}\n";
        $text .= t('leverage.email.threshold') . ": " . t('leverage.email.threshold_breached', ['threshold' => $threshold]) . "\n\n";

        if ($aiResult !== null) {
            $recLabel = self::getRecommendationLabel($aiResult['recommendation']);
            $text .= t('leverage.email.ai_title') . ":\n";
            $text .= t('leverage.email.recommendation') . ": {$recLabel}\n";
            $text .= t('leverage.email.confidence') . ": %{$aiResult['confidence']}\n";
            $text .= t('leverage.email.risk') . ": " . ucfirst($aiResult['risk_level']) . "\n";
            if (!empty($aiResult['reasoning'])) {
                $text .= "\n{$aiResult['reasoning']}\n";
            }
            if (!empty($aiResult['suggested_action'])) {
                $text .= "\n" . t('leverage.email.suggested_action') . ": {$aiResult['suggested_action']}\n";
            }
        }

        $text .= "\n---\n";
        $text .= t('leverage.email.rule') . ": {$rule['name']}\n";
        $text .= t('leverage.email.date') . ": " . date('d.m.Y H:i') . "\n";

        return $text;
    }

    // ─── Data helpers ───────────────────────────────────────────────────────

    /**
     * Get current sell rate for a currency (best bank rate).
     */
    private static function getCurrentRate(string $currencyCode): ?array
    {
        $row = Database::queryOne(
            'SELECT r.buy_rate, r.sell_rate, r.change_percent, r.scraped_at, b.slug AS bank_slug
             FROM rates r
             JOIN currencies c ON c.id = r.currency_id
             JOIN banks b ON b.id = r.bank_id
             WHERE c.code = ? AND b.is_active = 1 AND c.is_active = 1
             ORDER BY r.sell_rate DESC
             LIMIT 1',
            [$currencyCode]
        );
        return $row ?: null;
    }

    /**
     * Get portfolio context for a rule (amount, avg cost, P/L).
     */
    private static function getPortfolioContext(array $rule): array
    {
        $default = ['amount' => 'N/A', 'avg_cost' => 'N/A', 'pnl' => 'N/A'];
        $currencyCode = strtoupper(trim($rule['currency_code']));
        $sourceType = $rule['source_type'] ?? 'currency';
        $sourceId = isset($rule['source_id']) ? (int) $rule['source_id'] : null;

        $baseSql = 'SELECT SUM(p.amount) AS total_amount, AVG(p.buy_rate) AS avg_cost
                    FROM portfolio p
                    JOIN currencies c ON c.id = p.currency_id
                    WHERE c.code = ? AND p.deleted_at IS NULL';
        $params = [$currencyCode];

        if ($sourceType === 'group' && $sourceId !== null) {
            $baseSql .= ' AND p.group_id = ?';
            $params[] = $sourceId;
        } elseif ($sourceType === 'tag' && $sourceId !== null) {
            $baseSql .= ' AND p.id IN (SELECT portfolio_id FROM portfolio_tag_items WHERE tag_id = ?)';
            $params[] = $sourceId;
        }

        $row = Database::queryOne($baseSql, $params);
        if (!$row || $row['total_amount'] === null || (float) $row['total_amount'] <= 0) {
            return $default;
        }

        $amount = (float) $row['total_amount'];
        $avgCost = (float) $row['avg_cost'];

        $currentRate = self::getCurrentRate($currencyCode);
        $pnl = 'N/A';
        if ($currentRate !== null && $avgCost > 0) {
            $currentPrice = (float) $currentRate['sell_rate'];
            $pnl = number_format((($currentPrice - $avgCost) / $avgCost) * 100, 2);
        }

        return [
            'amount' => number_format($amount, 4),
            'avg_cost' => number_format($avgCost, 2),
            'pnl' => $pnl,
        ];
    }

    /**
     * Calculate Gold/Silver ratio from current rates.
     */
    private static function getGoldSilverRatio(): string
    {
        $xauRate = Database::queryOne(
            'SELECT r.sell_rate FROM rates r
             JOIN currencies c ON c.id = r.currency_id
             JOIN banks b ON b.id = r.bank_id
             WHERE c.code = ? AND b.is_active = 1 AND c.is_active = 1
             ORDER BY r.sell_rate DESC LIMIT 1',
            ['XAU']
        );
        $xagRate = Database::queryOne(
            'SELECT r.sell_rate FROM rates r
             JOIN currencies c ON c.id = r.currency_id
             JOIN banks b ON b.id = r.bank_id
             WHERE c.code = ? AND b.is_active = 1 AND c.is_active = 1
             ORDER BY r.sell_rate DESC LIMIT 1',
            ['XAG']
        );

        if (!$xauRate || !$xagRate || (float) $xagRate['sell_rate'] <= 0) {
            return 'N/A';
        }

        $ratio = (float) $xauRate['sell_rate'] / (float) $xagRate['sell_rate'];
        return number_format($ratio, 1) . ':1';
    }

    /**
     * Get 30-day price trend summary.
     */
    private static function getPriceTrendSummary(string $currencyCode): string
    {
        $rows = Database::query(
            'SELECT rh.sell_rate, rh.scraped_at
             FROM rate_history rh
             JOIN currencies c ON c.id = rh.currency_id
             WHERE c.code = ? AND rh.scraped_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY rh.scraped_at ASC',
            [$currencyCode]
        );

        if (empty($rows)) {
            return 'No data available';
        }

        $prices = array_map(fn($r) => (float) $r['sell_rate'], $rows);
        $min = min($prices);
        $max = max($prices);
        $avg = array_sum($prices) / count($prices);

        $count = count($prices);
        $weekSize = max(1, (int) ($count / 4));
        $firstWeek = array_slice($prices, 0, $weekSize);
        $lastWeek = array_slice($prices, -$weekSize);
        $firstAvg = array_sum($firstWeek) / count($firstWeek);
        $lastAvg = array_sum($lastWeek) / count($lastWeek);

        if ($firstAvg > 0) {
            $trendPct = (($lastAvg - $firstAvg) / $firstAvg) * 100;
            if ($trendPct > 3) {
                $trendDir = 'upward';
            } elseif ($trendPct < -3) {
                $trendDir = 'downward';
            } else {
                $trendDir = 'sideways';
            }
            $trendStr = $trendDir . ' (' . ($trendPct >= 0 ? '+' : '') . number_format($trendPct, 1) . '%)';
        } else {
            $trendStr = 'insufficient data';
        }

        return "Min: " . number_format($min, 2) . " TRY, Max: " . number_format($max, 2)
            . " TRY, Avg: " . number_format($avg, 2) . " TRY, Trend: " . $trendStr
            . " (" . count($rows) . " data points)";
    }

    // ─── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Create a new leverage rule.
     *
     * @return int Inserted rule ID
     */
    public static function create(array $data): int
    {
        $name = trim(mb_substr($data['name'] ?? '', 0, 100, 'UTF-8'));
        $sourceType = $data['source_type'] ?? 'currency';
        $sourceId = isset($data['source_id']) && $data['source_id'] !== '' ? (int) $data['source_id'] : null;
        $currencyCode = strtoupper(trim($data['currency_code'] ?? ''));
        $buyThreshold = (float) ($data['buy_threshold'] ?? -15.00);
        $sellThreshold = (float) ($data['sell_threshold'] ?? 30.00);
        $referencePrice = (float) ($data['reference_price'] ?? 0);
        $aiEnabled = isset($data['ai_enabled']) ? 1 : 0;
        $strategyContext = self::sanitizeStrategyContext($data['strategy_context'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException(t('leverage.form.error.name'));
        }
        if ($currencyCode === '') {
            throw new InvalidArgumentException(t('leverage.form.error.currency'));
        }
        if ($referencePrice <= 0) {
            throw new InvalidArgumentException(t('leverage.form.error.reference_price'));
        }

        if (!in_array($sourceType, ['group', 'tag', 'currency'], true)) {
            $sourceType = 'currency';
        }
        if ($sourceType === 'currency') {
            $sourceId = null;
        } elseif ($sourceType === 'group' && $sourceId !== null) {
            $exists = Database::queryOne('SELECT id FROM portfolio_groups WHERE id = ?', [$sourceId]);
            if (!$exists) {
                throw new InvalidArgumentException(t('leverage.form.error.source'));
            }
        } elseif ($sourceType === 'tag' && $sourceId !== null) {
            $exists = Database::queryOne('SELECT id FROM portfolio_tags WHERE id = ?', [$sourceId]);
            if (!$exists) {
                throw new InvalidArgumentException(t('leverage.form.error.source'));
            }
        }

        return Database::insert('leverage_rules', [
            'name' => $name,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'currency_code' => $currencyCode,
            'buy_threshold' => $buyThreshold,
            'sell_threshold' => $sellThreshold,
            'reference_price' => $referencePrice,
            'ai_enabled' => $aiEnabled,
            'strategy_context' => $strategyContext !== '' ? $strategyContext : null,
            'status' => 'active',
        ]);
    }

    /**
     * Update an existing rule.
     */
    public static function update(int $id, array $data): void
    {
        $rule = Database::queryOne('SELECT id FROM leverage_rules WHERE id = ?', [$id]);
        if (!$rule) {
            throw new InvalidArgumentException('Rule not found');
        }

        $update = [];

        if (isset($data['name'])) {
            $update['name'] = trim(mb_substr($data['name'], 0, 100, 'UTF-8'));
        }
        if (isset($data['buy_threshold'])) {
            $update['buy_threshold'] = (float) $data['buy_threshold'];
        }
        if (isset($data['sell_threshold'])) {
            $update['sell_threshold'] = (float) $data['sell_threshold'];
        }
        if (isset($data['reference_price']) && (float) $data['reference_price'] > 0) {
            $update['reference_price'] = (float) $data['reference_price'];
        }
        if (array_key_exists('ai_enabled', $data)) {
            $update['ai_enabled'] = $data['ai_enabled'] ? 1 : 0;
        }
        if (array_key_exists('strategy_context', $data)) {
            $ctx = self::sanitizeStrategyContext($data['strategy_context'] ?? '');
            $update['strategy_context'] = $ctx !== '' ? $ctx : null;
        }

        if (!empty($update)) {
            Database::update('leverage_rules', $update, 'id = ?', [$id]);
        }
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM leverage_rules WHERE id = ?', [$id]);
    }

    public static function pause(int $id): void
    {
        Database::update('leverage_rules', ['status' => 'paused'], 'id = ?', [$id]);
    }

    public static function resume(int $id): void
    {
        Database::update('leverage_rules', ['status' => 'active'], 'id = ?', [$id]);
    }

    public static function complete(int $id): void
    {
        Database::update('leverage_rules', ['status' => 'completed'], 'id = ?', [$id]);
    }

    /**
     * Update reference price to current market price.
     */
    public static function updateReference(int $id): void
    {
        $rule = Database::queryOne('SELECT currency_code FROM leverage_rules WHERE id = ?', [$id]);
        if (!$rule) {
            throw new InvalidArgumentException('Rule not found');
        }

        $currentRate = self::getCurrentRate(strtoupper(trim($rule['currency_code'])));
        if ($currentRate === null) {
            throw new RuntimeException('No current rate available');
        }

        Database::update('leverage_rules', [
            'reference_price' => (float) $currentRate['sell_rate'],
            'last_trigger_direction' => null,
        ], 'id = ?', [$id]);
    }

    // ─── Query helpers ──────────────────────────────────────────────────────

    public static function getActiveRules(): array
    {
        return Database::query(
            'SELECT * FROM leverage_rules WHERE status = ? ORDER BY created_at ASC',
            ['active']
        );
    }

    public static function getAllRules(): array
    {
        return Database::query(
            'SELECT * FROM leverage_rules ORDER BY FIELD(status, "active", "paused", "completed"), created_at DESC'
        );
    }

    public static function getRule(int $id): ?array
    {
        return Database::queryOne('SELECT * FROM leverage_rules WHERE id = ?', [$id]) ?: null;
    }

    public static function getHistory(int $limit = 50, ?int $ruleId = null): array
    {
        $sql = 'SELECT h.*, r.name AS rule_name, r.currency_code
                FROM leverage_history h
                JOIN leverage_rules r ON r.id = h.rule_id';
        $params = [];

        if ($ruleId !== null) {
            $sql .= ' WHERE h.rule_id = ?';
            $params[] = $ruleId;
        }

        $sql .= ' ORDER BY h.created_at DESC LIMIT ' . max(1, min(200, $limit));
        return Database::query($sql, $params);
    }

    public static function getSummaryStats(): array
    {
        $activeCount = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM leverage_rules WHERE status = ?',
            ['active']
        );
        $triggeredToday = Database::queryOne(
            "SELECT COUNT(*) AS cnt FROM leverage_history
             WHERE event_type IN ('buy_signal','sell_signal') AND DATE(created_at) = CURDATE()"
        );
        $checksToday = Database::queryOne(
            "SELECT COUNT(*) AS cnt FROM leverage_rules WHERE DATE(last_checked_at) = CURDATE()"
        );
        $aiToday = Database::queryOne(
            "SELECT COUNT(*) AS cnt FROM leverage_history
             WHERE ai_recommendation IS NOT NULL AND DATE(created_at) = CURDATE()"
        );

        return [
            'active_rules' => (int) ($activeCount['cnt'] ?? 0),
            'triggered_today' => (int) ($triggeredToday['cnt'] ?? 0),
            'checks_today' => (int) ($checksToday['cnt'] ?? 0),
            'ai_today' => (int) ($aiToday['cnt'] ?? 0),
        ];
    }

    // ─── Internal utilities ─────────────────────────────────────────────────

    private static function updateLastChecked(int $ruleId): void
    {
        Database::update('leverage_rules', ['last_checked_at' => date('Y-m-d H:i:s')], 'id = ?', [$ruleId]);
    }

    private static function sanitizeStrategyContext(string $context): string
    {
        $context = mb_substr(trim($context), 0, 500, 'UTF-8');
        $context = preg_replace('/[\x00-\x1F\x7F]/', '', $context);
        return trim($context);
    }

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

        return null;
    }

    private static function resolveModel(): string
    {
        $dbModel = self::getSettingValue('leverage_ai_model');
        if ($dbModel !== null && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $dbModel)) {
            return $dbModel;
        }
        $configModel = defined('LEVERAGE_AI_MODEL') ? trim((string) LEVERAGE_AI_MODEL) : '';
        if ($configModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $configModel)) {
            return $configModel;
        }
        return 'google/gemini-3.1-pro-preview';
    }

    private static function resolveOpenRouterApiKey(): string
    {
        $dbKey = self::getSettingValue('openrouter_api_key');
        if ($dbKey !== null && trim($dbKey) !== '') {
            return trim(decryptSettingValue(trim($dbKey)));
        }
        return trim((string) (defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : ''));
    }

    private static function isAiEnabled(): bool
    {
        $dbVal = self::getSettingValue('leverage_ai_enabled');
        if ($dbVal !== null) {
            return $dbVal === '1';
        }
        return defined('LEVERAGE_AI_ENABLED') ? (bool) LEVERAGE_AI_ENABLED : false;
    }

    private static function resolveIntSetting(string $settingKey, string $configConstant, int $default): int
    {
        $dbVal = self::getSettingValue($settingKey);
        if ($dbVal !== null && is_numeric($dbVal)) {
            return (int) $dbVal;
        }
        if (defined($configConstant)) {
            return (int) constant($configConstant);
        }
        return $default;
    }

    private static function getSettingValue(string $key): ?string
    {
        $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return ($row && array_key_exists('value', $row)) ? (string) $row['value'] : null;
    }

    private static function getRecommendationLabel(string $recommendation): string
    {
        $key = 'leverage.ai.' . $recommendation;
        $label = t($key);
        // If translation returns the key itself, fallback to uppercase
        return $label !== $key ? $label : strtoupper($recommendation);
    }
}

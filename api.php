<?php
/**
 * api.php — JSON API Endpoint
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();

applySecurityHeaders('api');
header('Content-Type: application/json; charset=utf-8');

$corsApplied = applyApiCorsHeaders();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code($corsApplied ? 204 : 403);
    exit;
}

$action = trim((string) ($_GET['action'] ?? ''));
$locale = getAppLocale();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$portfolioActions = ['portfolio', 'portfolio_add', 'portfolio_update', 'portfolio_delete', 'goal_progress', 'alerts', 'alerts_add', 'alerts_delete'];
$writeActions = ['portfolio_add', 'portfolio_update', 'portfolio_delete', 'alerts_add', 'alerts_delete'];
$readActions = ['rates', 'history', 'banks', 'currencies', 'version', 'ai_model', 'alerts'];

if (in_array($action, $portfolioActions, true)) {
    requirePortfolioAccessForApi();
}

// Read rate limiting (higher limit than write)
if (in_array($action, $readActions, true) || $action === '') {
    $readLimit = defined('API_READ_RATE_LIMIT') ? max(1, (int) API_READ_RATE_LIMIT) : 120;
    $readWindow = defined('API_READ_RATE_WINDOW_SECONDS') ? max(1, (int) API_READ_RATE_WINDOW_SECONDS) : 60;
    if (!enforceIpRateLimit('api_read:' . ($action ?: 'default'), $readLimit, $readWindow)) {
        jsonResponse(['status' => 'error', 'message' => t('api.error.too_many_requests')], 429);
    }
}

if (in_array($action, $writeActions, true)) {
    $maxBodyBytes = defined('API_MAX_BODY_BYTES') ? max(1024, (int) API_MAX_BODY_BYTES) : 32768;
    if (requestBodyExceedsLimit($maxBodyBytes)) {
        jsonResponse(['status' => 'error', 'message' => t('api.error.body_too_large')], 413);
    }

    $rateLimit = defined('API_WRITE_RATE_LIMIT') ? max(1, (int) API_WRITE_RATE_LIMIT) : 30;
    $rateWindowSeconds = defined('API_WRITE_RATE_WINDOW_SECONDS') ? max(1, (int) API_WRITE_RATE_WINDOW_SECONDS) : 60;
    if (!enforceIpRateLimit('api_write:' . $action, $rateLimit, $rateWindowSeconds)) {
        jsonResponse(['status' => 'error', 'message' => t('api.error.too_many_requests')], 429);
    }
}

try {
    switch ($action) {
        case 'rates':
            $bankSlug = $_GET['bank'] ?? null;
            $currencyCode = $_GET['currency'] ?? null;
            $compactParam = $_GET['compact'] ?? null;
            $clientVersion = $_GET['version'] ?? null;
            $compact = false;
            if (is_string($compactParam)) {
                $compact = in_array(strtolower(trim($compactParam)), ['1', 'true', 'yes', 'on'], true);
            }
            $clientVersion = is_string($clientVersion) ? trim($clientVersion) : '';

            // Fast version check: lets clients skip full payload + DB row query when rates did not change.
            $versionRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['last_rate_update']);
            $currentVersion = (is_array($versionRow) && isset($versionRow['value']))
                ? trim((string) $versionRow['value'])
                : '';

            if ($currentVersion === '') {
                $fallbackVersionRow = Database::queryOne('SELECT MAX(scraped_at) AS value FROM rates');
                $currentVersion = (is_array($fallbackVersionRow) && isset($fallbackVersionRow['value']))
                    ? trim((string) $fallbackVersionRow['value'])
                    : '';
            }

            if ($clientVersion !== '' && $currentVersion !== '' && hash_equals($currentVersion, $clientVersion)) {
                jsonResponse([
                    'status' => 'ok',
                    'locale' => $locale,
                    'version' => $currentVersion,
                    'unchanged' => true,
                    'data' => [],
                    'count' => 0,
                ]);
            }

            $rates = getLatestRates(
                is_string($bankSlug) ? $bankSlug : null,
                is_string($currencyCode) ? $currencyCode : null,
                $compact
            );
            jsonResponse([
                'status' => 'ok',
                'locale' => $locale,
                'version' => $currentVersion,
                'unchanged' => false,
                'compact' => $compact,
                'data' => $rates,
                'count' => count($rates),
            ]);
            break;

        case 'history':
            $currencyParam = $_GET['currency'] ?? '';
            if (!is_string($currencyParam) || trim($currencyParam) === '') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.currency_required')], 400);
            }

            $days = (int) ($_GET['days'] ?? 30);
            $days = max(1, min($days, 3650));
            $limit = (int) ($_GET['limit'] ?? 1000);
            $limit = max(1, min($limit, 5000));
            $bank = $_GET['bank'] ?? null;
            $before = $_GET['before'] ?? null;
            $history = getRateHistory(
                $currencyParam,
                $days,
                is_string($bank) ? $bank : null,
                $limit,
                is_string($before) ? $before : null
            );
            $nextBefore = (count($history) === $limit && isset($history[0]['scraped_at']))
                ? (string) $history[0]['scraped_at']
                : null;

            jsonResponse([
                'status' => 'ok',
                'locale' => $locale,
                'data' => $history,
                'count' => count($history),
                'limit' => $limit,
                'next_before' => $nextBefore,
            ]);
            break;

        case 'portfolio':
            $summary = Portfolio::getSummary();
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $summary]);
            break;

        case 'portfolio_add':
            if ($method !== 'POST') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.post_required')], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.invalid_csrf')], 419);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $id = Portfolio::add($input);
            jsonResponse(['status' => 'ok', 'id' => $id, 'message' => t('api.message.added_portfolio')]);
            break;

        case 'portfolio_update':
            if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.post_put_patch_required')], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.invalid_csrf')], 419);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.valid_id_required')], 400);
            }

            $updateData = array_filter([
                'amount' => $input['amount'] ?? null,
                'buy_rate' => $input['buy_rate'] ?? null,
                'buy_date' => $input['buy_date'] ?? null,
                'notes' => $input['notes'] ?? null,
                'bank_slug' => $input['bank_slug'] ?? null,
            ], fn($v) => $v !== null);

            if (empty($updateData)) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.no_fields_to_update')], 400);
            }

            $updated = Portfolio::update($id, $updateData);
            jsonResponse([
                'status' => $updated ? 'ok' : 'error',
                'message' => $updated ? t('api.message.updated') : t('api.message.not_found'),
            ], $updated ? 200 : 404);
            break;

        case 'portfolio_delete':
            if ($method !== 'POST' && $method !== 'DELETE') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.post_delete_required')], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.invalid_csrf')], 419);
            }

            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.valid_id_required')], 400);
            }

            $deleted = Portfolio::delete($id);
            jsonResponse([
                'status' => $deleted ? 'ok' : 'error',
                'message' => $deleted ? t('api.message.deleted') : t('api.message.not_found'),
            ], $deleted ? 200 : 404);
            break;

        case 'banks':
            $banks = Database::query('SELECT name, slug, last_scraped_at, is_active FROM banks ORDER BY name');
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $banks]);
            break;

        case 'currencies':
            $nameField = in_array($locale, ['en', 'tr'], true) && $locale === 'en' ? 'name_en' : 'name_tr';
            $currencies = Database::query(
                "SELECT code, name_tr, name_en, `{$nameField}` AS name, symbol, type FROM currencies WHERE is_active = 1 ORDER BY code"
            );
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $currencies]);
            break;

        case 'version':
            $version = trim((string) file_get_contents(__DIR__ . '/VERSION'));
            jsonResponse(['status' => 'ok', 'version' => $version]);
            break;

        case 'ai_model':
            $configured = defined('OPENROUTER_MODEL') ? (string) OPENROUTER_MODEL : 'z-ai/glm-5';
            $row = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_model']);
            $active = (is_array($row) && isset($row['value']) && (string) $row['value'] !== '')
                ? (string) $row['value']
                : $configured;

            jsonResponse([
                'status' => 'ok',
                'active_model' => $active,
                'default_model' => $configured,
                'openrouter_enabled' => defined('OPENROUTER_AI_REPAIR_ENABLED') ? (bool) OPENROUTER_AI_REPAIR_ENABLED : false,
            ]);
            break;

        case 'goal_progress':
            requirePortfolioAccessForApi();
            $goalId = (int) ($_GET['goal_id'] ?? 0);
            $period = trim((string) ($_GET['period'] ?? ''));
            $customStart = trim((string) ($_GET['start'] ?? ''));
            $customEnd = trim((string) ($_GET['end'] ?? ''));

            if ($goalId <= 0) {
                jsonResponse(['status' => 'error', 'message' => 'goal_id required'], 400);
            }

            $goal = Portfolio::getGoal($goalId);
            if (!$goal) {
                jsonResponse(['status' => 'error', 'message' => 'Goal not found'], 404);
            }

            $summary = Portfolio::getSummary();
            $allItemTags = Portfolio::getAllItemTags();
            $allGoalSources = Portfolio::getAllGoalSources();

            // Build currency rates
            $currencyRates = [];
            $ratesRows = Database::query('SELECT c.code, r.sell_rate FROM rates r JOIN currencies c ON c.id = r.currency_id WHERE r.sell_rate > 0');
            foreach ($ratesRows as $rr) {
                $currencyRates[strtoupper($rr['code'])] = (float) $rr['sell_rate'];
            }

            $periodFilter = null;
            $periodStart = null;
            $periodEnd = null;
            $validPeriods = ['7d', '14d', '1m', '3m', '6m', '9m', '1y'];
            if (in_array($period, $validPeriods, true)) {
                $periodFilter = $period;
            } elseif ($period === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
                $periodStart = $customStart;
                $periodEnd = $customEnd;
            }

            $progress = Portfolio::computeGoalProgress(
                [$goal],
                $summary['items'],
                $allItemTags,
                $allGoalSources,
                $currencyRates,
                $periodFilter,
                $periodStart,
                $periodEnd
            );

            $gp = $progress[$goalId] ?? ['current' => 0, 'target' => 0, 'percent' => 0, 'item_count' => 0, 'unit' => '₺'];
            jsonResponse(['status' => 'ok', 'goal_id' => $goalId, 'progress' => $gp]);
            break;

        case 'alerts':
            $where = '1=1';
            $params = [];

            if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                $userId = Auth::id();
                if ($userId !== null) {
                    $where .= ' AND (user_id IS NULL OR user_id = ?)';
                    $params[] = $userId;
                }
            }

            $alerts = Database::query(
                "SELECT id, currency_code, condition_type, threshold, channel, is_active, last_triggered_at, created_at
                 FROM alerts WHERE {$where} ORDER BY created_at DESC",
                $params
            );
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $alerts]);
            break;

        case 'alerts_add':
            requirePortfolioAccessForApi();
            if ($method !== 'POST') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.post_required')], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.invalid_csrf')], 419);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $currencyCode = normalizeCurrencyCode($input['currency_code'] ?? '');
            $conditionType = $input['condition_type'] ?? '';
            $threshold = isset($input['threshold']) ? (float) $input['threshold'] : 0;
            $channel = $input['channel'] ?? 'email';
            $channelConfig = $input['channel_config'] ?? null;

            if ($currencyCode === null || !in_array($conditionType, ['above', 'below', 'change_pct'], true) || $threshold <= 0) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.alert_fields_required')], 400);
            }

            $userId = null;
            if (class_exists('Auth') && Auth::check()) {
                $userId = Auth::id();
            }

            $configJson = is_string($channelConfig) ? $channelConfig : (is_array($channelConfig) ? json_encode($channelConfig) : null);

            $id = Database::insert('alerts', [
                'user_id' => $userId,
                'currency_code' => $currencyCode,
                'condition_type' => $conditionType,
                'threshold' => $threshold,
                'channel' => in_array($channel, ['email', 'telegram', 'webhook'], true) ? $channel : 'email',
                'channel_config' => $configJson,
                'is_active' => 1,
            ]);

            jsonResponse(['status' => 'ok', 'id' => $id, 'message' => t('api.message.alert_created')]);
            break;

        case 'alerts_delete':
            requirePortfolioAccessForApi();
            if ($method !== 'POST' && $method !== 'DELETE') {
                jsonResponse(['status' => 'error', 'message' => t('api.error.post_delete_required')], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.invalid_csrf')], 419);
            }

            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => t('api.error.valid_id_required')], 400);
            }

            $where = 'id = ?';
            $params = [$id];

            if (class_exists('Auth') && Auth::check() && !Auth::isAdmin()) {
                $userId = Auth::id();
                if ($userId !== null) {
                    $where .= ' AND (user_id IS NULL OR user_id = ?)';
                    $params[] = $userId;
                }
            }

            $deleted = Database::execute("DELETE FROM alerts WHERE {$where}", $params);
            jsonResponse([
                'status' => $deleted ? 'ok' : 'error',
                'message' => $deleted ? t('api.message.deleted') : t('api.message.not_found'),
            ], $deleted ? 200 : 404);
            break;

        default:
            jsonResponse([
                'status' => 'ok',
                'app' => APP_NAME,
                'version' => trim((string) file_get_contents(__DIR__ . '/VERSION')),
                'endpoints' => [
                    'GET /api.php?action=rates' => t('api.endpoint.rates'),
                    'GET /api.php?action=rates&compact=1' => t('api.endpoint.rates_compact'),
                    'GET /api.php?action=rates&bank=dunya-katilim' => t('api.endpoint.rates_bank'),
                    'GET /api.php?action=rates&currency=USD' => t('api.endpoint.rates_currency'),
                    'GET /api.php?action=history&currency=USD&days=30&limit=500' => t('api.endpoint.history'),
                    'GET /api.php?action=portfolio' => t('api.endpoint.portfolio'),
                    'POST /api.php?action=portfolio_add' => t('api.endpoint.portfolio_add'),
                    'POST|PUT|PATCH /api.php?action=portfolio_update' => t('api.endpoint.portfolio_update'),
                    'POST|DELETE /api.php?action=portfolio_delete&id=1' => t('api.endpoint.portfolio_delete'),
                    'GET /api.php?action=banks' => t('api.endpoint.banks'),
                    'GET /api.php?action=currencies' => t('api.endpoint.currencies'),
                    'GET /api.php?action=version' => t('api.endpoint.version'),
                    'GET /api.php?action=ai_model' => t('api.endpoint.ai_model'),
                ],
            ]);
            break;
    }
} catch (Throwable $e) {
    $isClientError = $e instanceof InvalidArgumentException;
    $code = $isClientError ? 400 : 500;

    // Security: never expose internal exception details to external API consumers.
    cybokron_log(
        sprintf(
            'API error action=%s method=%s code=%d detail=%s',
            $action !== '' ? $action : 'default',
            $method,
            $code,
            $e->getMessage()
        ),
        'ERROR'
    );

    $publicMessage = $isClientError ? t('api.error.invalid_params') : t('api.error.internal');
    jsonResponse(['status' => 'error', 'message' => $publicMessage], $code);
}

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

$portfolioActions = ['portfolio', 'portfolio_add', 'portfolio_update', 'portfolio_delete', 'alerts', 'alerts_add', 'alerts_delete'];
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
        jsonResponse(['status' => 'error', 'message' => 'Too many requests'], 429);
    }
}

if (in_array($action, $writeActions, true)) {
    $maxBodyBytes = defined('API_MAX_BODY_BYTES') ? max(1024, (int) API_MAX_BODY_BYTES) : 32768;
    if (requestBodyExceedsLimit($maxBodyBytes)) {
        jsonResponse(['status' => 'error', 'message' => 'Request body too large'], 413);
    }

    $rateLimit = defined('API_WRITE_RATE_LIMIT') ? max(1, (int) API_WRITE_RATE_LIMIT) : 30;
    $rateWindowSeconds = defined('API_WRITE_RATE_WINDOW_SECONDS') ? max(1, (int) API_WRITE_RATE_WINDOW_SECONDS) : 60;
    if (!enforceIpRateLimit('api_write:' . $action, $rateLimit, $rateWindowSeconds)) {
        jsonResponse(['status' => 'error', 'message' => 'Too many requests'], 429);
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
                jsonResponse(['status' => 'error', 'message' => 'currency parameter required'], 400);
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
                jsonResponse(['status' => 'error', 'message' => 'POST method required'], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $id = Portfolio::add($input);
            jsonResponse(['status' => 'ok', 'id' => $id, 'message' => 'Added to portfolio']);
            break;

        case 'portfolio_update':
            if ($method !== 'POST' && $method !== 'PUT' && $method !== 'PATCH') {
                jsonResponse(['status' => 'error', 'message' => 'POST, PUT or PATCH method required'], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                $input = $_POST;
            }

            $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => 'Valid id parameter required'], 400);
            }

            $updateData = array_filter([
                'amount'   => $input['amount'] ?? null,
                'buy_rate' => $input['buy_rate'] ?? null,
                'buy_date' => $input['buy_date'] ?? null,
                'notes'    => $input['notes'] ?? null,
            ], fn ($v) => $v !== null);

            if (empty($updateData)) {
                jsonResponse(['status' => 'error', 'message' => 'No fields to update'], 400);
            }

            $updated = Portfolio::update($id, $updateData);
            jsonResponse([
                'status'  => $updated ? 'ok' : 'error',
                'message' => $updated ? 'Updated' : 'Not found',
            ], $updated ? 200 : 404);
            break;

        case 'portfolio_delete':
            if ($method !== 'POST' && $method !== 'DELETE') {
                jsonResponse(['status' => 'error', 'message' => 'POST or DELETE method required'], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
            }

            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => 'Valid id parameter required'], 400);
            }

            $deleted = Portfolio::delete($id);
            jsonResponse([
                'status' => $deleted ? 'ok' : 'error',
                'message' => $deleted ? 'Deleted' : 'Not found',
            ], $deleted ? 200 : 404);
            break;

        case 'banks':
            $banks = Database::query('SELECT name, slug, last_scraped_at, is_active FROM banks ORDER BY name');
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $banks]);
            break;

        case 'currencies':
            $nameField = $locale === 'en' ? 'name_en' : 'name_tr';
            $currencies = Database::query(
                "SELECT code, name_tr, name_en, {$nameField} AS name, symbol, type FROM currencies WHERE is_active = 1 ORDER BY code"
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

        case 'alerts':
            requirePortfolioAccessForApi();
            $alerts = Database::query(
                'SELECT id, currency_code, condition_type, threshold, channel, is_active, last_triggered_at, created_at
                 FROM alerts ORDER BY created_at DESC'
            );
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $alerts]);
            break;

        case 'alerts_add':
            requirePortfolioAccessForApi();
            if ($method !== 'POST') {
                jsonResponse(['status' => 'error', 'message' => 'POST method required'], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
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
                jsonResponse(['status' => 'error', 'message' => 'currency_code, condition_type (above|below|change_pct), threshold required'], 400);
            }

            $userId = null;
            if (function_exists('Auth::check') && Auth::check()) {
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

            jsonResponse(['status' => 'ok', 'id' => $id, 'message' => 'Alert created']);
            break;

        case 'alerts_delete':
            requirePortfolioAccessForApi();
            if ($method !== 'POST' && $method !== 'DELETE') {
                jsonResponse(['status' => 'error', 'message' => 'POST or DELETE method required'], 405);
            }

            $requireCsrf = !defined('API_REQUIRE_CSRF') || API_REQUIRE_CSRF;
            if ($requireCsrf && !verifyCsrfToken(getRequestCsrfToken())) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
            }

            $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(['status' => 'error', 'message' => 'Valid id parameter required'], 400);
            }

            $deleted = Database::execute('DELETE FROM alerts WHERE id = ?', [$id]);
            jsonResponse([
                'status' => $deleted ? 'ok' : 'error',
                'message' => $deleted ? 'Deleted' : 'Not found',
            ], $deleted ? 200 : 404);
            break;

        default:
            jsonResponse([
                'status' => 'ok',
                'app' => APP_NAME,
                'version' => trim((string) file_get_contents(__DIR__ . '/VERSION')),
                'endpoints' => [
                    'GET /api.php?action=rates' => 'Latest exchange rates',
                    'GET /api.php?action=rates&compact=1' => 'Compact rates payload for polling clients',
                    'GET /api.php?action=rates&bank=dunya-katilim' => 'Rates for specific bank',
                    'GET /api.php?action=rates&currency=USD' => 'Rates for specific currency',
                    'GET /api.php?action=history&currency=USD&days=30&limit=500' => 'Rate history with pagination support',
                    'GET /api.php?action=portfolio' => 'Portfolio summary',
                    'POST /api.php?action=portfolio_add' => 'Add to portfolio',
                    'POST|PUT|PATCH /api.php?action=portfolio_update' => 'Update portfolio entry (body: id, amount, buy_rate, buy_date, notes)',
                    'POST|DELETE /api.php?action=portfolio_delete&id=1' => 'Delete from portfolio',
                    'GET /api.php?action=banks' => 'List banks',
                    'GET /api.php?action=currencies' => 'List currencies',
                    'GET /api.php?action=version' => 'App version',
                    'GET /api.php?action=ai_model' => 'OpenRouter model status',
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

    $publicMessage = $isClientError ? 'Invalid request parameters' : 'An internal error occurred';
    jsonResponse(['status' => 'error', 'message' => $publicMessage], $code);
}

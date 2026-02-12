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

try {
    switch ($action) {
        case 'rates':
            $bankSlug = $_GET['bank'] ?? null;
            $currencyCode = $_GET['currency'] ?? null;
            $rates = getLatestRates(
                is_string($bankSlug) ? $bankSlug : null,
                is_string($currencyCode) ? $currencyCode : null
            );
            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $rates, 'count' => count($rates)]);
            break;

        case 'history':
            $currencyParam = $_GET['currency'] ?? '';
            if (!is_string($currencyParam) || trim($currencyParam) === '') {
                jsonResponse(['status' => 'error', 'message' => 'currency parameter required'], 400);
            }

            $days = (int) ($_GET['days'] ?? 30);
            $days = max(1, min($days, 3650));
            $bank = $_GET['bank'] ?? null;
            $history = getRateHistory($currencyParam, $days, is_string($bank) ? $bank : null);

            jsonResponse(['status' => 'ok', 'locale' => $locale, 'data' => $history, 'count' => count($history)]);
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
            $banks = Database::query('SELECT name, slug, url, last_scraped_at, is_active FROM banks ORDER BY name');
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

        default:
            jsonResponse([
                'status' => 'ok',
                'app' => APP_NAME,
                'version' => trim((string) file_get_contents(__DIR__ . '/VERSION')),
                'endpoints' => [
                    'GET /api.php?action=rates' => 'Latest exchange rates',
                    'GET /api.php?action=rates&bank=dunya-katilim' => 'Rates for specific bank',
                    'GET /api.php?action=rates&currency=USD' => 'Rates for specific currency',
                    'GET /api.php?action=history&currency=USD&days=30' => 'Rate history',
                    'GET /api.php?action=portfolio' => 'Portfolio summary',
                    'POST /api.php?action=portfolio_add' => 'Add to portfolio',
                    'POST|DELETE /api.php?action=portfolio_delete&id=1' => 'Delete from portfolio',
                    'GET /api.php?action=banks' => 'List banks',
                    'GET /api.php?action=currencies' => 'List currencies',
                    'GET /api.php?action=version' => 'App version',
                ],
            ]);
            break;
    }
} catch (Throwable $e) {
    $code = ($e instanceof InvalidArgumentException) ? 400 : 500;
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], $code);
}

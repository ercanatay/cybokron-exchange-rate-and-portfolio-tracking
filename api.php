<?php
/**
 * api.php — JSON API Endpoint
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'rates':
            $bankSlug = $_GET['bank'] ?? null;
            $currencyCode = $_GET['currency'] ?? null;
            $rates = getLatestRates($bankSlug, $currencyCode);
            jsonResponse(['status' => 'ok', 'data' => $rates, 'count' => count($rates)]);
            break;

        case 'history':
            $currency = $_GET['currency'] ?? '';
            $days = (int) ($_GET['days'] ?? 30);
            $bank = $_GET['bank'] ?? null;

            if (empty($currency)) {
                jsonResponse(['status' => 'error', 'message' => 'currency parameter required'], 400);
            }

            $history = getRateHistory($currency, $days, $bank);
            jsonResponse(['status' => 'ok', 'data' => $history, 'count' => count($history)]);
            break;

        case 'portfolio':
            $summary = Portfolio::getSummary();
            jsonResponse(['status' => 'ok', 'data' => $summary]);
            break;

        case 'portfolio_add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                jsonResponse(['status' => 'error', 'message' => 'POST method required'], 405);
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = Portfolio::add($input);
            jsonResponse(['status' => 'ok', 'id' => $id, 'message' => 'Added to portfolio']);
            break;

        case 'portfolio_delete':
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
            $banks = Database::query("SELECT name, slug, url, last_scraped_at, is_active FROM banks ORDER BY name");
            jsonResponse(['status' => 'ok', 'data' => $banks]);
            break;

        case 'currencies':
            $currencies = Database::query("SELECT code, name_tr, name_en, symbol, type FROM currencies WHERE is_active = 1 ORDER BY code");
            jsonResponse(['status' => 'ok', 'data' => $currencies]);
            break;

        case 'version':
            $version = trim(file_get_contents(__DIR__ . '/VERSION'));
            jsonResponse(['status' => 'ok', 'version' => $version]);
            break;

        default:
            jsonResponse([
                'status' => 'ok',
                'app' => APP_NAME,
                'version' => trim(file_get_contents(__DIR__ . '/VERSION')),
                'endpoints' => [
                    'GET /api.php?action=rates' => 'Latest exchange rates',
                    'GET /api.php?action=rates&bank=dunya-katilim' => 'Rates for specific bank',
                    'GET /api.php?action=rates&currency=USD' => 'Rates for specific currency',
                    'GET /api.php?action=history&currency=USD&days=30' => 'Rate history',
                    'GET /api.php?action=portfolio' => 'Portfolio summary',
                    'POST /api.php?action=portfolio_add' => 'Add to portfolio',
                    'DELETE /api.php?action=portfolio_delete&id=1' => 'Delete from portfolio',
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

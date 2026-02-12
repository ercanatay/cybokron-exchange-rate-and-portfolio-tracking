<?php
/**
 * Cybokron API Endpoint
 * 
 * Provides JSON API for rates, history, and portfolio management.
 */

define('CYBOKRON_ROOT', __DIR__);

$config = require CYBOKRON_ROOT . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once CYBOKRON_ROOT . '/includes/Database.php';
require_once CYBOKRON_ROOT . '/includes/helpers.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'rates':
            handleGetRates();
            break;

        case 'history':
            handleGetHistory();
            break;

        case 'portfolio':
            handleGetPortfolio();
            break;

        case 'portfolio_add':
            handleAddPortfolio();
            break;

        case 'portfolio_delete':
            handleDeletePortfolio();
            break;

        case 'banks':
            handleGetBanks();
            break;

        case 'currencies':
            handleGetCurrencies();
            break;

        case 'version':
            handleGetVersion();
            break;

        default:
            jsonResponse([
                'error' => 'Unknown action',
                'available_actions' => [
                    'rates', 'history', 'portfolio', 'portfolio_add',
                    'portfolio_delete', 'banks', 'currencies', 'version'
                ],
            ], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

// ---- Handler Functions ----

function handleGetRates(): void
{
    $bank = $_GET['bank'] ?? null;
    $currency = $_GET['currency'] ?? null;

    $sql = "
        SELECT 
            r.buy_rate, r.sell_rate, r.change_percent, r.fetched_at,
            c.code AS currency_code, c.name_tr AS currency_name, c.type AS currency_type,
            b.slug AS bank_slug, b.name AS bank_name
        FROM rates r
        JOIN currencies c ON r.currency_id = c.id
        JOIN banks b ON r.bank_id = b.id
        WHERE b.is_active = 1
    ";
    $params = [];

    if ($bank) {
        $sql .= " AND b.slug = ?";
        $params[] = $bank;
    }
    if ($currency) {
        $sql .= " AND c.code = ?";
        $params[] = strtoupper($currency);
    }

    $sql .= " ORDER BY c.code ASC";

    $rates = Database::fetchAll($sql, $params);

    jsonResponse([
        'success' => true,
        'count'   => count($rates),
        'data'    => $rates,
    ]);
}

function handleGetHistory(): void
{
    $currency = strtoupper($_GET['currency'] ?? 'USD');
    $days = (int) ($_GET['days'] ?? 30);
    $bank = $_GET['bank'] ?? 'dunya-katilim';

    $days = max(1, min($days, 365));

    $history = Database::fetchAll("
        SELECT 
            rh.buy_rate, rh.sell_rate, rh.change_percent, rh.fetched_at,
            c.code AS currency_code
        FROM rate_history rh
        JOIN currencies c ON rh.currency_id = c.id
        JOIN banks b ON rh.bank_id = b.id
        WHERE c.code = ? AND b.slug = ? AND rh.fetched_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY rh.fetched_at ASC
    ", [$currency, $bank, $days]);

    jsonResponse([
        'success'  => true,
        'currency' => $currency,
        'bank'     => $bank,
        'days'     => $days,
        'count'    => count($history),
        'data'     => $history,
    ]);
}

function handleGetPortfolio(): void
{
    require_once CYBOKRON_ROOT . '/includes/Portfolio.php';
    $summary = Portfolio::getSummary();

    jsonResponse([
        'success' => true,
        'data'    => $summary,
    ]);
}

function handleAddPortfolio(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST method required'], 405);
    }

    require_once CYBOKRON_ROOT . '/includes/Portfolio.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $required = ['currency', 'amount', 'buy_rate', 'buy_date'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }

    $id = Portfolio::add(
        $input['currency'],
        (float) $input['amount'],
        (float) $input['buy_rate'],
        $input['buy_date'],
        $input['bank'] ?? null,
        $input['notes'] ?? null
    );

    jsonResponse([
        'success' => true,
        'id'      => $id,
        'message' => 'Portfolio entry added',
    ], 201);
}

function handleDeletePortfolio(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'DELETE or POST method required'], 405);
    }

    require_once CYBOKRON_ROOT . '/includes/Portfolio.php';

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid portfolio ID'], 400);
    }

    $deleted = Portfolio::delete($id);

    jsonResponse([
        'success' => $deleted,
        'message' => $deleted ? 'Entry deleted' : 'Entry not found',
    ]);
}

function handleGetBanks(): void
{
    $banks = Database::fetchAll("
        SELECT slug, name, url, is_active, last_scraped_at 
        FROM banks ORDER BY name
    ");
    jsonResponse(['success' => true, 'data' => $banks]);
}

function handleGetCurrencies(): void
{
    $currencies = Database::fetchAll("
        SELECT code, name_tr, name_en, type, is_active 
        FROM currencies ORDER BY code
    ");
    jsonResponse(['success' => true, 'data' => $currencies]);
}

function handleGetVersion(): void
{
    $version = trim(file_get_contents(CYBOKRON_ROOT . '/VERSION'));
    jsonResponse([
        'success' => true,
        'version' => $version,
        'app'     => 'Cybokron Exchange Rate & Portfolio Tracking',
    ]);
}

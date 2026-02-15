<?php
/**
 * api_repair_stream.php — SSE endpoint for live repair progress
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Streams Server-Sent Events as the self-healing pipeline runs.
 * Security: admin auth + CSRF query param + rate limit (5/min).
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

// ── Auth check ──────────────────────────────────────────────────────────────
if (!Auth::check() || !Auth::isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

// ── CSRF check (via query param since EventSource sends GET) ────────────────
$csrfToken = $_GET['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Invalid CSRF token';
    exit;
}

// ── Bank ID validation ──────────────────────────────────────────────────────
$bankId = filter_input(INPUT_GET, 'bank_id', FILTER_VALIDATE_INT);
if ($bankId === null || $bankId === false || $bankId < 1) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid bank_id';
    exit;
}

// ── Rate limit: 5 repair attempts per minute per IP ─────────────────────────
if (!enforceIpRateLimit('repair_stream', 5, 60)) {
    http_response_code(429);
    header('Content-Type: text/plain');
    echo 'Rate limit exceeded. Max 5 repairs per minute.';
    exit;
}

// ── Release session lock so other pages work during the long SSE stream ─────
session_write_close();

// ── SSE headers ─────────────────────────────────────────────────────────────
set_time_limit(120);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
while (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * Send an SSE event.
 */
function sendSSE(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/**
 * Progress callback for ScraperAutoRepair.
 */
function onRepairProgress(string $step, string $status, string $message, ?int $durationMs = null, ?array $meta = null): void
{
    sendSSE('step', [
        'step'        => $step,
        'status'      => $status,
        'message'     => $message,
        'duration_ms' => $durationMs,
        'meta'        => $meta,
    ]);
}

// ── Look up bank and its scraper class ──────────────────────────────────────
$bank = Database::queryOne(
    'SELECT id, name, slug, url, scraper_class FROM banks WHERE id = ? AND is_active = 1',
    [$bankId]
);

if ($bank === null) {
    sendSSE('error', ['message' => 'Bank not found or inactive']);
    exit;
}

$scraperClass = $bank['scraper_class'] ?? '';
if ($scraperClass === '') {
    sendSSE('error', ['message' => 'No scraper class configured for this bank']);
    exit;
}

// ── Step 1: Fetch HTML ──────────────────────────────────────────────────────
sendSSE('step', [
    'step'    => 'fetch_html',
    'status'  => 'in_progress',
    'message' => 'Fetching bank page',
]);

try {
    $scraper = loadBankScraper($scraperClass, $bank);
    $fetchStart = microtime(true);
    $context = $scraper->prepareRepairContext();
    $fetchMs = (int) ((microtime(true) - $fetchStart) * 1000);

    sendSSE('step', [
        'step'        => 'fetch_html',
        'status'      => 'success',
        'message'     => 'Page fetched successfully',
        'duration_ms' => $fetchMs,
    ]);
} catch (Throwable $e) {
    sendSSE('step', [
        'step'    => 'fetch_html',
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
    sendSSE('complete', [
        'status'      => 'error',
        'message'     => 'Failed to fetch bank page',
        'rates_count' => 0,
    ]);
    exit;
}

// ── Steps 2-7: Run repair pipeline (callbacks stream SSE) ───────────────────
$autoRepair = new ScraperAutoRepair(
    $context['bank_id'],
    $context['bank_slug'],
    $context['bank_name'],
    $context['bank_url']
);
$autoRepair->setProgressCallback('onRepairProgress');

$rates = $autoRepair->attemptRepair(
    $context['html'],
    $context['old_hash'],
    $context['new_hash'],
    $context['currency_codes']
);

// ── Complete event ──────────────────────────────────────────────────────────
$rateCount = $rates !== null ? count($rates) : 0;
$status = $rates !== null && $rateCount > 0 ? 'success' : 'error';

sendSSE('complete', [
    'status'      => $status,
    'rates_count' => $rateCount,
    'message'     => $status === 'success'
        ? "Repair completed: {$rateCount} rates found"
        : 'Repair failed or produced no rates',
]);

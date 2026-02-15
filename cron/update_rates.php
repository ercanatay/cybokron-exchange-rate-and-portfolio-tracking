<?php
/**
 * update_rates.php — Cron: Fetch latest exchange rates
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Usage: php cron/update_rates.php
 * Cron example: every 15 minutes during weekdays, 09:00-18:00
 *             php /path/to/cron/update_rates.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/WebhookDispatcher.php';
cybokron_init();
require_once __DIR__ . '/../includes/Scraper.php';
require_once __DIR__ . '/../includes/OpenRouterRateRepair.php';
require_once __DIR__ . '/../includes/Updater.php';
ensureCliExecution();

// ── Prevent concurrent execution via file lock ──────────────────────────────
$lockFile = sys_get_temp_dir() . '/cybokron_update_rates.lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another update_rates instance is already running. Exiting.\n";
    cybokron_log('update_rates: skipped — another instance is running', 'WARNING');
    exit(0);
}
register_shutdown_function(function () use ($lockFp, $lockFile) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
});

// Load active banks from database
$activeBanks = Database::query('SELECT id, name, slug, url, scraper_class FROM banks WHERE is_active = 1');

if (empty($activeBanks)) {
    cybokron_log('No active banks configured.', 'WARNING');
    echo "No active banks configured.\n";
    exit(1);
}

$results = [];
$totalRates = 0;

foreach ($activeBanks as $bankRow) {
    $bankClass = $bankRow['scraper_class'];
    try {
        $scraper = loadBankScraper($bankClass, $bankRow);
        $result = $scraper->run();
        $results[] = $result;

        if ($result['status'] === 'success') {
            $totalRates += $result['rates_count'];
            $changed = $result['table_changed'] ? ' [TABLE CHANGED!]' : '';
            $msg = "{$result['bank']}: {$result['rates_count']} rates in {$result['duration_ms']}ms{$changed}";
            cybokron_log($msg);
            echo $msg . "\n";
        } else {
            $msg = "{$result['bank']}: ERROR - {$result['message']}";
            cybokron_log($msg, 'ERROR');
            echo $msg . "\n";
        }

    } catch (Throwable $e) {
        $msg = "{$bankClass}: EXCEPTION - {$e->getMessage()}";
        cybokron_log($msg, 'ERROR');
        echo $msg . "\n";
    }
}

// Update last_rate_update setting
Database::update(
    'settings',
    ['value' => date('Y-m-d H:i:s')],
    '`key` = ?',
    ['last_rate_update']
);

// Dispatch webhook notification on successful update
if ($totalRates > 0) {
    $webhookResult = WebhookDispatcher::dispatchRateUpdate([
        'total_rates' => $totalRates,
        'banks' => array_column(array_filter($results, fn($r) => ($r['status'] ?? '') === 'success'), 'bank'),
        'bank_results' => $results,
    ]);
    if ($webhookResult['success'] > 0) {
        cybokron_log("Webhook dispatched to {$webhookResult['success']} URL(s)");
    }
    if (!empty($webhookResult['failed'])) {
        cybokron_log('Webhook failed for: ' . implode(', ', $webhookResult['failed']), 'WARNING');
    }
}

echo "\nDone. Total rates updated: {$totalRates}\n";
cybokron_log("Rate update completed. Total: {$totalRates} rates.");

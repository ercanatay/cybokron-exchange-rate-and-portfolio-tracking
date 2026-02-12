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
cybokron_init();
ensureCliExecution();

// Load active banks from config
global $ACTIVE_BANKS;

if (empty($ACTIVE_BANKS)) {
    cybokron_log('No active banks configured.', 'WARNING');
    echo "No active banks configured.\n";
    exit(1);
}

$results = [];
$totalRates = 0;

foreach ($ACTIVE_BANKS as $bankClass) {
    try {
        $scraper = loadBankScraper($bankClass);
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

echo "\nDone. Total rates updated: {$totalRates}\n";
cybokron_log("Rate update completed. Total: {$totalRates} rates.");

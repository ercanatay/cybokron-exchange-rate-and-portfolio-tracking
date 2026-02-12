<?php
/**
 * Cron: Update exchange rates from all active banks
 * 
 * Usage: php cron/update_rates.php
 * Recommended: Run every 15 minutes during market hours
 */

define('CYBOKRON_ROOT', dirname(__DIR__));

// Load config
$config = require CYBOKRON_ROOT . '/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

require_once CYBOKRON_ROOT . '/includes/Database.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting rate update...\n";

$totalSaved = 0;
$errors = [];

foreach ($config['banks'] as $slug => $bankConfig) {
    $startTime = microtime(true);
    
    try {
        $bankFile = CYBOKRON_ROOT . '/' . $bankConfig['file'];
        
        if (!file_exists($bankFile)) {
            throw new RuntimeException("Bank file not found: {$bankConfig['file']}");
        }

        require_once $bankFile;
        
        $className = $bankConfig['class'];
        $scraper = new $className();

        echo "  Scraping: {$scraper->getBankName()}... ";

        // Scrape rates
        $rates = $scraper->scrape();
        
        if (empty($rates)) {
            throw new RuntimeException("No rates returned");
        }

        // Save to database
        $saved = $scraper->saveRates($rates);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Log success
        $scraper->logScrape('success', "Fetched {$saved} rates", $saved, $durationMs);

        echo "OK ({$saved} rates, {$durationMs}ms)\n";
        $totalSaved += $saved;

    } catch (Exception $e) {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $errorMsg = $e->getMessage();
        
        echo "ERROR: {$errorMsg}\n";
        $errors[] = "{$slug}: {$errorMsg}";

        // Log error
        try {
            if (isset($scraper)) {
                $scraper->logScrape('error', $errorMsg, null, $durationMs);
            }
        } catch (Exception $logError) {
            echo "  (Could not log error: {$logError->getMessage()})\n";
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Total rates saved: {$totalSaved}";
if (!empty($errors)) {
    echo ", Errors: " . count($errors);
}
echo "\n";

// Exit with error code if any errors occurred
exit(empty($errors) ? 0 : 1);

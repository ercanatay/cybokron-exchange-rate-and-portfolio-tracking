<?php
/**
 * check_leverage.php — Cron: Evaluate leverage rules and send notifications
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Usage: php cron/check_leverage.php
 * Cron example: every 15 minutes during market hours
 *             (e.g. 0,15,30,45 9-18 * * 1-5) php /path/to/cron/check_leverage.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/SendGridMailer.php';
require_once __DIR__ . '/../includes/TelegramNotifier.php';
require_once __DIR__ . '/../includes/LeverageWebhookDispatcher.php';
require_once __DIR__ . '/../includes/LeverageEngine.php';
cybokron_init();
ensureCliExecution();

// Check if leverage system is enabled (DB setting or config constant)
$dbEnabled = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_enabled']);
$enabled = $dbEnabled ? ($dbEnabled['value'] === '1') : (defined('LEVERAGE_ENABLED') && LEVERAGE_ENABLED);

if (!$enabled) {
    $msg = 'Leverage system is disabled — skipping';
    cybokron_log($msg);
    echo $msg . "\n";
    exit(0);
}

$result = LeverageEngine::run();

$msg = "Leverage: {$result['checked']} checked, {$result['triggered']} triggered, {$result['sent']} sent";
if (!empty($result['errors'])) {
    $msg .= ' | Errors: ' . implode('; ', $result['errors']);
}

cybokron_log($msg);
echo $msg . "\n";

exit(empty($result['errors']) ? 0 : 1);

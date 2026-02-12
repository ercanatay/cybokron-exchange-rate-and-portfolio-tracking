<?php
/**
 * check_alerts.php — Cron: Evaluate alerts and send notifications
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Usage: php cron/check_alerts.php
 * Cron example: every 15 minutes during market hours
 *             (e.g. 0,15,30,45 9-18 * * 1-5) php /path/to/cron/check_alerts.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/AlertChecker.php';
cybokron_init();
ensureCliExecution();

$result = AlertChecker::run();

$msg = "Alerts: {$result['checked']} checked, {$result['triggered']} triggered, {$result['sent']} sent";
if (!empty($result['errors'])) {
    $msg .= ' | Errors: ' . implode('; ', $result['errors']);
}

cybokron_log($msg);
echo $msg . "\n";

exit(empty($result['errors']) ? 0 : 1);

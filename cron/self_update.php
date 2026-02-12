<?php
/**
 * self_update.php — Cron: Check GitHub for app updates
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Usage: php cron/self_update.php
 * Cron:  0 0 * * * php /path/to/cron/self_update.php
 */

require_once __DIR__ . '/../includes/helpers.php';
cybokron_init();

if (!defined('AUTO_UPDATE') || !AUTO_UPDATE) {
    echo "Auto-update is disabled in config.\n";
    exit(0);
}

$updater = new Updater();
echo "Current version: {$updater->getCurrentVersion()}\n";
cybokron_log("Checking for updates. Current: {$updater->getCurrentVersion()}");

$update = $updater->checkForUpdate();

if (!$update) {
    echo "Already up to date.\n";
    cybokron_log("No updates available.");

    Database::update('settings', ['value' => date('Y-m-d H:i:s')], '`key` = ?', ['last_update_check']);
    exit(0);
}

echo "New version available: {$update['latest_version']}\n";
echo "Downloading and applying update...\n";
cybokron_log("Update available: {$update['current_version']} → {$update['latest_version']}");

$result = $updater->applyUpdate($update);

if ($result['status'] === 'success') {
    echo "Updated successfully: {$result['message']}\n";
    cybokron_log("Update applied: {$result['message']}");
} else {
    echo "Update failed: {$result['message']}\n";
    cybokron_log("Update failed: {$result['message']}", 'ERROR');
}

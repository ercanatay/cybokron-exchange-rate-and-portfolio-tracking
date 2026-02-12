<?php
/**
 * Cron: Check for application updates from GitHub releases
 * 
 * Usage: php cron/self_update.php
 * Recommended: Run daily at midnight
 */

define('CYBOKRON_ROOT', dirname(__DIR__));

$config = require CYBOKRON_ROOT . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once CYBOKRON_ROOT . '/includes/Updater.php';

echo "[" . date('Y-m-d H:i:s') . "] Checking for updates...\n";

try {
    $updater = new Updater();
    echo "  Current version: {$updater->getCurrentVersion()}\n";

    $updateInfo = $updater->checkForUpdate();

    if ($updateInfo === null) {
        echo "  Already up to date.\n";
        exit(0);
    }

    echo "  New version available: {$updateInfo['latest_version']}\n";
    echo "  Published: {$updateInfo['published_at']}\n";

    if (!empty($updateInfo['release_notes'])) {
        echo "  Release notes: " . substr($updateInfo['release_notes'], 0, 200) . "\n";
    }

    // Apply update
    echo "  Downloading and applying update...\n";
    $success = $updater->applyUpdate($updateInfo);

    if ($success) {
        echo "  ✅ Update successful! Now running version {$updateInfo['latest_version']}\n";

        // Update version in database settings
        try {
            require_once CYBOKRON_ROOT . '/includes/Database.php';
            Database::execute(
                "UPDATE settings SET value = ? WHERE `key` = 'app_version'",
                [$updateInfo['latest_version']]
            );
            Database::execute(
                "UPDATE settings SET value = ? WHERE `key` = 'last_update_check'",
                [date('Y-m-d H:i:s')]
            );
        } catch (Exception $e) {
            // Non-critical
        }
    } else {
        echo "  ❌ Update failed.\n";
    }

} catch (Exception $e) {
    echo "  ERROR: {$e->getMessage()}\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
exit(0);

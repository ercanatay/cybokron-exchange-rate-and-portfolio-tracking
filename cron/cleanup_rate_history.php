<?php
/**
 * cleanup_rate_history.php — Cron: Remove old rate_history records
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Usage: php cron/cleanup_rate_history.php
 * Cron:  0 3 * * * php /path/to/cron/cleanup_rate_history.php
 *
 * Keeps only the last N days of rate history (default 365).
 * Configure via RATE_HISTORY_RETENTION_DAYS in config.php.
 */

require_once __DIR__ . '/../includes/helpers.php';
cybokron_init();
ensureCliExecution();

$retentionDays = defined('RATE_HISTORY_RETENTION_DAYS') ? max(1, (int) RATE_HISTORY_RETENTION_DAYS) : 365;
$cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

$deleted = Database::execute('DELETE FROM rate_history WHERE scraped_at < ?', [$cutoff]);

echo "Cleaned up {$deleted} rate_history rows older than {$cutoff}.\n";
cybokron_log("Rate history cleanup: removed {$deleted} rows older than {$retentionDays} days.");
exit(0);

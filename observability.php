<?php
/**
 * observability.php — Scrape Logs & System Health Dashboard
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'observability.php'));
    exit;
}

// Bank stats (last 7 days)
$bankStats = Database::query("
    SELECT
        b.id,
        b.name,
        b.slug,
        b.last_scraped_at,
        COUNT(sl.id) AS total_runs,
        SUM(CASE WHEN sl.status = 'success' THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN sl.status = 'error' THEN 1 ELSE 0 END) AS error_count,
        AVG(sl.duration_ms) AS avg_duration_ms,
        MAX(sl.created_at) AS last_log_at
    FROM banks b
    LEFT JOIN scrape_logs sl ON sl.bank_id = b.id AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    WHERE b.is_active = 1
    GROUP BY b.id, b.name, b.slug, b.last_scraped_at
    ORDER BY b.name
");

// Recent logs (last 50)
$recentLogs = Database::query("
    SELECT
        sl.id,
        sl.status,
        sl.message,
        sl.rates_count,
        sl.duration_ms,
        sl.table_changed,
        sl.created_at,
        b.name AS bank_name,
        b.slug AS bank_slug
    FROM scrape_logs sl
    JOIN banks b ON b.id = sl.bank_id
    ORDER BY sl.created_at DESC
    LIMIT 50
");

$currentLocale = getAppLocale();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('observability.title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body>
    <?php $activePage = 'observability';
    include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="container">
        <section class="bank-section">
            <h2><?= t('observability.bank_stats') ?></h2>
            <p class="last-update"><?= t('observability.stats_period') ?></p>

            <div class="table-responsive">
                <table class="rates-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= t('observability.bank') ?></th>
                            <th scope="col"><?= t('observability.last_scrape') ?></th>
                            <th scope="col"><?= t('observability.runs_7d') ?></th>
                            <th scope="col"><?= t('observability.success_rate') ?></th>
                            <th scope="col"><?= t('observability.avg_duration') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bankStats as $row): ?>
                            <?php
                            $total = (int) $row['total_runs'];
                            $success = (int) $row['success_count'];
                            $successRate = $total > 0 ? round(($success / $total) * 100) : 0;
                            $avgDuration = $row['avg_duration_ms'] !== null ? round((float) $row['avg_duration_ms']) : 0;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= $row['last_scraped_at'] ? formatDateTime($row['last_scraped_at']) : t('common.not_available') ?>
                                </td>
                                <td><?= $total ?></td>
                                <td>
                                    <span
                                        class="rate-change <?= $successRate >= 90 ? 'text-success' : ($successRate >= 70 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $successRate ?>%
                                    </span>
                                    <?php if ((int) $row['error_count'] > 0): ?>
                                        <span class="text-danger"
                                            title="<?= t('observability.errors') ?>">(<?= (int) $row['error_count'] ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $avgDuration ?> ms</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bank-section">
            <h2><?= t('observability.recent_logs') ?></h2>
            <div class="table-responsive">
                <table class="rates-table">
                    <thead>
                        <tr>
                            <th scope="col"><?= t('observability.time') ?></th>
                            <th scope="col"><?= t('observability.bank') ?></th>
                            <th scope="col"><?= t('observability.status') ?></th>
                            <th scope="col"><?= t('observability.rates') ?></th>
                            <th scope="col"><?= t('observability.duration') ?></th>
                            <th scope="col"><?= t('observability.message') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?= formatDateTime($log['created_at']) ?></td>
                                <td><?= htmlspecialchars($log['bank_name']) ?></td>
                                <td>
                                    <span
                                        class="rate-change <?= $log['status'] === 'success' ? 'text-success' : ($log['status'] === 'warning' ? 'text-warning' : 'text-danger') ?>">
                                        <?= htmlspecialchars($log['status']) ?>
                                    </span>
                                </td>
                                <td><?= (int) $log['rates_count'] ?></td>
                                <td><?= $log['duration_ms'] !== null ? (int) $log['duration_ms'] . ' ms' : '—' ?></td>
                                <td class="message-cell">
                                    <?= htmlspecialchars($log['message'] ?? '') ?>
                                    <?= $log['table_changed'] ? ' [TABLE CHANGED]' : '' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a href="index.php"><?= t('nav.rates') ?></a></p>
        </div>
    </footer>
</body>

</html>
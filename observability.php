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

// Handle self-healing actions (deactivate repair config)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken(getRequestCsrfToken())) {
        $_SESSION['flash_error'] = t('common.invalid_request');
    } elseif ($_POST['action'] === 'deactivate_repair' && !empty($_POST['bank_id'])) {
        $bankId = (int) $_POST['bank_id'];
        $result = ScraperAutoRepair::rollbackRepairConfig($bankId, 'Manual deactivation via admin panel');
        $_SESSION['flash_success'] = $result
            ? t('selfhealing.config_deactivated')
            : t('selfhealing.no_active_config');
    } elseif ($_POST['action'] === 'trigger_repair' && !empty($_POST['bank_id'])) {
        $bankId = (int) $_POST['bank_id'];
        $bankRow = Database::queryOne('SELECT slug, name, scraper_class FROM banks WHERE id = ? AND is_active = 1', [$bankId]);
        if ($bankRow) {
            try {
                $scraper = loadBankScraper($bankRow['scraper_class']);
                $result = $scraper->run();
                $_SESSION['flash_success'] = t('selfhealing.repair_triggered', ['bank' => $bankRow['name']]);
            } catch (Throwable $e) {
                $_SESSION['flash_error'] = t('selfhealing.repair_failed', ['error' => $e->getMessage()]);
            }
        }
    }
    header('Location: observability.php');
    exit;
}

// Active repair configs
$activeRepairConfigs = Database::query("
    SELECT
        rc.id,
        rc.bank_id,
        rc.xpath_rows,
        rc.table_hash,
        rc.is_active,
        rc.github_issue_url,
        rc.github_commit_sha,
        rc.created_at,
        b.name AS bank_name,
        b.slug AS bank_slug
    FROM repair_configs rc
    JOIN banks b ON b.id = rc.bank_id
    WHERE rc.is_active = 1
    ORDER BY rc.created_at DESC
");

// Recent repair logs (last 30)
$repairLogs = Database::query("
    SELECT
        rl.id,
        rl.step,
        rl.status,
        rl.message,
        rl.duration_ms,
        rl.created_at,
        b.name AS bank_name
    FROM repair_logs rl
    JOIN banks b ON b.id = rl.bank_id
    ORDER BY rl.created_at DESC
    LIMIT 30
");

$currentLocale = getAppLocale();
$version = getAppVersion();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('observability.title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('observability.title') . ' — ' . APP_NAME,
    'description' => t('seo.observability_description'),
    'page' => 'observability.php',
]) ?>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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

        <?php if (defined('SELF_HEALING_ENABLED') && SELF_HEALING_ENABLED): ?>
        <section class="bank-section">
            <h2><?= t('selfhealing.title') ?></h2>

            <?php if (!empty($_SESSION['flash_success'])): ?>
                <p class="text-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></p>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <p class="text-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></p>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <h3><?= t('selfhealing.active_configs') ?></h3>
            <?php if (empty($activeRepairConfigs)): ?>
                <p><?= t('selfhealing.no_active_configs') ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= t('observability.bank') ?></th>
                                <th scope="col">XPath</th>
                                <th scope="col">GitHub</th>
                                <th scope="col"><?= t('observability.time') ?></th>
                                <th scope="col"><?= t('portfolio.table.actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeRepairConfigs as $rc): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rc['bank_name']) ?></td>
                                    <td><code><?= htmlspecialchars(mb_substr($rc['xpath_rows'], 0, 50)) ?></code></td>
                                    <td>
                                        <?php if ($rc['github_issue_url']): ?>
                                            <a href="<?= htmlspecialchars($rc['github_issue_url']) ?>" target="_blank" rel="noopener">Issue</a>
                                        <?php endif; ?>
                                        <?php if ($rc['github_commit_sha']): ?>
                                            <code><?= htmlspecialchars(substr($rc['github_commit_sha'], 0, 7)) ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatDateTime($rc['created_at']) ?></td>
                                    <td>
                                        <form method="post" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="deactivate_repair">
                                            <input type="hidden" name="bank_id" value="<?= (int) $rc['bank_id'] ?>">
                                            <button type="submit" class="btn btn-sm" onclick="return confirm('<?= t('selfhealing.deactivate_confirm') ?>')"><?= t('admin.deactivate') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3><?= t('selfhealing.repair_logs') ?></h3>
            <?php if (empty($repairLogs)): ?>
                <p><?= t('selfhealing.no_repair_logs') ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= t('observability.time') ?></th>
                                <th scope="col"><?= t('observability.bank') ?></th>
                                <th scope="col"><?= t('selfhealing.step') ?></th>
                                <th scope="col"><?= t('observability.status') ?></th>
                                <th scope="col"><?= t('observability.duration') ?></th>
                                <th scope="col"><?= t('observability.message') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repairLogs as $rl): ?>
                                <tr>
                                    <td><?= formatDateTime($rl['created_at']) ?></td>
                                    <td><?= htmlspecialchars($rl['bank_name']) ?></td>
                                    <td><code><?= htmlspecialchars($rl['step']) ?></code></td>
                                    <td>
                                        <span class="rate-change <?= $rl['status'] === 'success' ? 'text-success' : ($rl['status'] === 'skipped' ? 'text-warning' : 'text-danger') ?>">
                                            <?= htmlspecialchars($rl['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= $rl['duration_ms'] !== null ? (int) $rl['duration_ms'] . ' ms' : '—' ?></td>
                                    <td class="message-cell"><?= htmlspecialchars($rl['message'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h3><?= t('selfhealing.manual_trigger') ?></h3>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem;">
                <?php foreach ($bankStats as $bs): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="trigger_repair">
                        <input type="hidden" name="bank_id" value="<?= (int) $bs['id'] ?>">
                        <button type="submit" class="btn btn-sm"><?= htmlspecialchars($bs['name']) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

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
                                        <?= htmlspecialchars(t('observability.status_' . ($log['status'] === 'success' ? 'success' : ($log['status'] === 'warning' ? 'warning' : 'error')))) ?>
                                    </span>
                                </td>
                                <td><?= (int) $log['rates_count'] ?></td>
                                <td><?= $log['duration_ms'] !== null ? (int) $log['duration_ms'] . ' ms' : '—' ?></td>
                                <td class="message-cell">
                                    <?= htmlspecialchars($log['message'] ?? '') ?>
                                    <?= $log['table_changed'] ? ' ' . t('observability.table_changed') : '' ?>
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
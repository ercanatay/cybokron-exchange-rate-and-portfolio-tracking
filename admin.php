<?php
/**
 * admin.php — Admin Dashboard: Banks, Currencies, Users
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'admin.php'));
    exit;
}

// Handle bank toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: admin.php');
        exit;
    }
    if ($_POST['action'] === 'toggle_bank' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $bank = Database::queryOne('SELECT is_active FROM banks WHERE id = ?', [$id]);
        if ($bank) {
            $new = $bank['is_active'] ? 0 : 1;
            Database::update('banks', ['is_active' => $new], 'id = ?', [$id]);
        }
    }
    if ($_POST['action'] === 'toggle_currency' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $cur = Database::queryOne('SELECT is_active FROM currencies WHERE id = ?', [$id]);
        if ($cur) {
            $new = $cur['is_active'] ? 0 : 1;
            Database::update('currencies', ['is_active' => $new], 'id = ?', [$id]);
        }
    }
    header('Location: admin.php');
    exit;
}

$banks = Database::query('SELECT id, name, slug, is_active, last_scraped_at FROM banks ORDER BY name');
$currencies = Database::query('SELECT id, code, name_tr, name_en, is_active, type FROM currencies ORDER BY code');
$users = Database::query('SELECT id, username, role, is_active, created_at FROM users ORDER BY username');

$lastRateUpdate = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['last_rate_update']);
$currentLocale = getAppLocale();
$csrfToken = getCsrfToken();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('admin.title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>⚙️ <?= t('admin.title') ?></h1>
            <nav class="header-nav">
                <a href="index.php"><?= t('nav.rates') ?></a>
                <a href="portfolio.php"><?= t('nav.portfolio') ?></a>
                <a href="observability.php"><?= t('observability.title') ?></a>
                <a href="admin.php" class="active" aria-current="page"><?= t('admin.title') ?></a>
                <a href="logout.php"><?= t('nav.logout') ?></a>
            </nav>
        </div>
    </header>

    <main id="main-content" class="container">
        <section class="bank-section">
            <h2><?= t('admin.health') ?></h2>
            <p>
                <?= t('admin.last_rate_update') ?>:
                <?= $lastRateUpdate && $lastRateUpdate['value'] ? formatDateTime($lastRateUpdate['value']) : t('common.not_available') ?>
            </p>
        </section>

        <section class="bank-section">
            <h2><?= t('admin.banks') ?></h2>
            <div class="table-responsive">
                <table class="rates-table">
                    <thead>
                        <tr>
                            <th><?= t('observability.bank') ?></th>
                            <th><?= t('admin.slug') ?></th>
                            <th><?= t('observability.last_scrape') ?></th>
                            <th><?= t('admin.status') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banks as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><code><?= htmlspecialchars($b['slug']) ?></code></td>
                            <td><?= $b['last_scraped_at'] ? formatDateTime($b['last_scraped_at']) : '—' ?></td>
                            <td><span class="<?= $b['is_active'] ? 'text-success' : 'text-muted' ?>"><?= $b['is_active'] ? t('admin.active') : t('admin.inactive') ?></span></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_bank">
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn btn-sm"><?= $b['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bank-section">
            <h2><?= t('admin.currencies') ?></h2>
            <div class="table-responsive">
                <table class="rates-table">
                    <thead>
                        <tr>
                            <th><?= t('admin.code') ?></th>
                            <th><?= t('index.table.currency') ?></th>
                            <th><?= t('admin.type') ?></th>
                            <th><?= t('admin.status') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currencies as $c): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
                            <td><?= htmlspecialchars(localizedCurrencyName($c)) ?></td>
                            <td><?= htmlspecialchars($c['type'] ?? 'fiat') ?></td>
                            <td><span class="<?= $c['is_active'] ? 'text-success' : 'text-muted' ?>"><?= $c['is_active'] ? t('admin.active') : t('admin.inactive') ?></span></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_currency">
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn btn-sm"><?= $c['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bank-section">
            <h2><?= t('admin.users') ?></h2>
            <div class="table-responsive">
                <table class="rates-table">
                    <thead>
                        <tr>
                            <th><?= t('admin.id') ?></th>
                            <th><?= t('auth.username') ?></th>
                            <th><?= t('admin.role') ?></th>
                            <th><?= t('admin.status') ?></th>
                            <th><?= t('admin.created') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= (int) $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['role'] ?? 'user') ?></td>
                            <td><span class="<?= $u['is_active'] ? 'text-success' : 'text-muted' ?>"><?= $u['is_active'] ? t('admin.active') : t('admin.inactive') ?></span></td>
                            <td><?= formatDateTime($u['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a href="observability.php"><?= t('observability.title') ?></a></p>
        </div>
    </footer>
</body>
</html>

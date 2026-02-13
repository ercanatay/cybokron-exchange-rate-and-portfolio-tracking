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

// Handle POST actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: admin.php');
        exit;
    }

    if ($_POST['action'] === 'update_rates') {
        $output = [];
        $returnCode = 0;
        $phpBinary = '/Applications/ServBay/bin/php';
        if (!file_exists($phpBinary)) {
            $phpBinary = 'php';
        }
        exec('cd ' . escapeshellarg(__DIR__) . ' && ' . escapeshellarg($phpBinary) . ' cron/update_rates.php 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            $message = t('admin.rates_updated_success');
            $messageType = 'success';
        } else {
            $message = t('admin.rates_updated_error') . ': ' . implode("\n", $output);
            $messageType = 'error';
        }
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

    if ($_POST['action'] === 'toggle_homepage' && isset($_POST['rate_id'])) {
        $rateId = (int) $_POST['rate_id'];
        $rate = Database::queryOne('SELECT show_on_homepage FROM rates WHERE id = ?', [$rateId]);
        if ($rate) {
            $new = $rate['show_on_homepage'] ? 0 : 1;
            Database::update('rates', ['show_on_homepage' => $new], 'id = ?', [$rateId]);
            $message = $new ? t('admin.rate_shown_on_homepage') : t('admin.rate_hidden_from_homepage');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_default_bank' && isset($_POST['default_bank'])) {
        $defaultBank = $_POST['default_bank'];
        if ($defaultBank === 'all' || Database::queryOne('SELECT id FROM banks WHERE slug = ? AND is_active = 1', [$defaultBank])) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['default_bank', $defaultBank, $defaultBank]
            );
            $message = t('admin.default_bank_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'update_rate_order' && isset($_POST['rate_orders'])) {
        $rateOrders = json_decode($_POST['rate_orders'], true);
        if (is_array($rateOrders)) {
            foreach ($rateOrders as $rateId => $order) {
                Database::update('rates', ['display_order' => (int) $order], 'id = ?', [(int) $rateId]);
            }
            // If AJAX request, respond JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'ok', 'message' => t('admin.rate_order_updated')]);
                exit;
            }
            $message = t('admin.rate_order_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_chart_defaults' && isset($_POST['chart_currency']) && isset($_POST['chart_days'])) {
        $chartCurrency = trim($_POST['chart_currency']);
        $chartDays = (int) $_POST['chart_days'];
        if (Database::queryOne('SELECT id FROM currencies WHERE code = ? AND is_active = 1', [$chartCurrency])) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['chart_default_currency', $chartCurrency, $chartCurrency]
            );
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['chart_default_days', (string) $chartDays, (string) $chartDays]
            );
            $message = t('admin.chart_defaults_updated');
            $messageType = 'success';
        }
    }

    if (!in_array($_POST['action'], ['update_rates', 'toggle_homepage', 'set_default_bank', 'update_rate_order', 'set_chart_defaults'], true)) {
        header('Location: admin.php');
        exit;
    }
}

$banks = Database::query('SELECT id, name, slug, is_active, last_scraped_at FROM banks ORDER BY name');
$currencies = Database::query('SELECT id, code, name_tr, name_en, is_active, type FROM currencies ORDER BY code');
$users = Database::query('SELECT id, username, role, is_active, created_at FROM users ORDER BY username');

$allRates = Database::query('
    SELECT 
        r.id,
        r.show_on_homepage,
        r.display_order,
        r.buy_rate,
        r.sell_rate,
        r.scraped_at,
        c.code AS currency_code,
        c.name_tr AS currency_name_tr,
        c.name_en AS currency_name_en,
        b.name AS bank_name,
        b.slug AS bank_slug
    FROM rates r
    JOIN currencies c ON c.id = r.currency_id
    JOIN banks b ON b.id = r.bank_id
    WHERE b.is_active = 1 AND c.is_active = 1
    ORDER BY r.display_order ASC, b.name, c.code
');

$activeCurrencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');
$lastRateUpdate = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['last_rate_update']);
$defaultBank = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['default_bank']);
$defaultBankValue = $defaultBank['value'] ?? 'all';
$chartDefaultCurrency = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_currency']);
$chartDefaultCurrencyValue = $chartDefaultCurrency['value'] ?? 'USD';
$chartDefaultDays = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_days']);
$chartDefaultDaysValue = (int) ($chartDefaultDays['value'] ?? 30);
$currentLocale = getAppLocale();
$csrfToken = getCsrfToken();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));

// Collect unique bank names for filter
$bankNames = [];
foreach ($allRates as $r) {
    $bankNames[$r['bank_slug']] = $r['bank_name'];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('admin.title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/admin.css">
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

    <div id="toast-container" class="toast-container"></div>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="admin-grid">

            <!-- Row 1: Health + Default Bank -->
            <div class="row-2">
                <!-- System Health -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon health">💚</div>
                            <div>
                                <h2><?= t('admin.health') ?></h2>
                                <p><?= t('admin.last_rate_update') ?>:
                                    <?= $lastRateUpdate && $lastRateUpdate['value'] ? formatDateTime($lastRateUpdate['value']) : t('common.not_available') ?>
                                </p>
                            </div>
                        </div>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="action" value="update_rates">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="btn btn-primary" style="white-space:nowrap">🔄
                                <?= t('admin.update_rates_now') ?></button>
                        </form>
                    </div>
                </div>

                <!-- Default Bank -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon bank">🏦</div>
                            <div>
                                <h2><?= t('admin.default_bank_setting') ?></h2>
                                <p><?= t('admin.default_bank_desc') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="admin-card-body">
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="action" value="set_default_bank">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="form-field">
                                <label for="default_bank"><?= t('admin.default_bank') ?></label>
                                <select id="default_bank" name="default_bank">
                                    <option value="all" <?= $defaultBankValue === 'all' ? 'selected' : '' ?>>
                                        <?= t('admin.all_banks') ?></option>
                                    <?php foreach ($banks as $bank): ?>
                                        <?php if ($bank['is_active']): ?>
                                            <option value="<?= htmlspecialchars($bank['slug']) ?>"
                                                <?= $defaultBankValue === $bank['slug'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($bank['name']) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Row 2: Chart Defaults -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon chart">📊</div>
                        <div>
                            <h2><?= t('admin.chart_defaults') ?></h2>
                            <p><?= t('admin.chart_defaults_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="action" value="set_chart_defaults">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="chart_currency"><?= t('admin.chart_currency') ?></label>
                            <select id="chart_currency" name="chart_currency">
                                <?php foreach ($activeCurrencies as $curr): ?>
                                    <option value="<?= htmlspecialchars($curr['code']) ?>"
                                        <?= $chartDefaultCurrencyValue === $curr['code'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curr['code']) ?> —
                                        <?= htmlspecialchars(localizedCurrencyName($curr)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="chart_days"><?= t('admin.chart_days') ?></label>
                            <select id="chart_days" name="chart_days">
                                <?php foreach ([7, 30, 90, 180, 365] as $d): ?>
                                    <option value="<?= $d ?>" <?= $chartDefaultDaysValue === $d ? 'selected' : '' ?>><?= $d ?>
                                        <?= t('index.chart.days_unit') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>

            <!-- Row 3: Banks -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon bank">🏛️</div>
                        <h2><?= t('admin.banks') ?></h2>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
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
                                    <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                                    <td><code
                                            style="font-size: 0.78rem; background: var(--surface-hover); padding: 2px 8px; border-radius: 4px;"><?= htmlspecialchars($b['slug']) ?></code>
                                    </td>
                                    <td><?= $b['last_scraped_at'] ? formatDateTime($b['last_scraped_at']) : '—' ?></td>
                                    <td><span
                                            class="badge <?= $b['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $b['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_bank">
                                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                            <button type="submit"
                                                class="btn-action"><?= $b['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Row 4: Homepage Rates (Drag & Drop) -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon rates">📋</div>
                        <div>
                            <h2><?= t('admin.homepage_rates') ?></h2>
                            <p><?= t('admin.homepage_rates_desc') ?></p>
                        </div>
                    </div>
                    <div id="save-status" class="save-status" aria-live="polite"></div>
                </div>
                <div class="admin-card-body" style="padding-top: 8px;">
                    <div class="hint-box">
                        <span>💡</span>
                        <span><strong><?= t('admin.drag_drop_hint') ?>:</strong> <?= t('admin.drag_drop_desc') ?></span>
                    </div>
                    <?php if (count($bankNames) > 1): ?>
                        <div class="filter-tabs" style="margin-bottom: 12px;">
                            <button class="filter-tab active" data-bank="all"><?= t('admin.all') ?></button>
                            <?php foreach ($bankNames as $slug => $name): ?>
                                <button class="filter-tab"
                                    data-bank="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($name) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="admin-table" id="rates-sortable-table">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th style="width:36px"></th>
                                <th><?= t('observability.bank') ?></th>
                                <th><?= t('index.table.currency') ?></th>
                                <th><?= t('index.table.code') ?></th>
                                <th class="text-right"><?= t('index.table.bank_buy') ?></th>
                                <th class="text-right"><?= t('index.table.bank_sell') ?></th>
                                <th><?= t('admin.homepage_visibility') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rates-sortable">
                            <?php $idx = 0;
                            foreach ($allRates as $rate):
                                $idx++; ?>
                                <tr class="sortable-row" data-rate-id="<?= (int) $rate['id'] ?>"
                                    data-bank="<?= htmlspecialchars($rate['bank_slug']) ?>" draggable="true">
                                    <td><span class="order-num"><?= $idx ?></span></td>
                                    <td class="drag-handle" title="<?= t('admin.drag_drop_hint') ?>">⋮⋮</td>
                                    <td><?= htmlspecialchars($rate['bank_name']) ?></td>
                                    <td><?= htmlspecialchars(localizedCurrencyName($rate)) ?></td>
                                    <td><strong><?= htmlspecialchars($rate['currency_code']) ?></strong></td>
                                    <td class="text-right mono"><?= formatRate((float) $rate['buy_rate']) ?></td>
                                    <td class="text-right mono"><?= formatRate((float) $rate['sell_rate']) ?></td>
                                    <td>
                                        <span
                                            class="badge <?= $rate['show_on_homepage'] ? 'badge-success' : 'badge-muted' ?>">
                                            <?= $rate['show_on_homepage'] ? '✓ ' . t('admin.visible') : '✗ ' . t('admin.hidden') ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_homepage">
                                            <input type="hidden" name="rate_id" value="<?= (int) $rate['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                            <button type="submit"
                                                class="btn-action"><?= $rate['show_on_homepage'] ? t('admin.hide') : t('admin.show') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Row 5: Currencies + Users -->
            <div class="row-2">
                <!-- Currencies -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon currency">💱</div>
                            <h2><?= t('admin.currencies') ?></h2>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
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
                                        <td><span
                                                class="badge badge-muted"><?= htmlspecialchars($c['type'] ?? 'fiat') ?></span>
                                        </td>
                                        <td><span
                                                class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $c['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                        </td>
                                        <td style="text-align:right">
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="action" value="toggle_currency">
                                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?= htmlspecialchars($csrfToken) ?>">
                                                <button type="submit"
                                                    class="btn-action"><?= $c['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Users -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon users">👥</div>
                            <h2><?= t('admin.users') ?></h2>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
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
                                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                        <td><span
                                                class="badge badge-muted"><?= htmlspecialchars($u['role'] ?? 'user') ?></span>
                                        </td>
                                        <td><span
                                                class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $u['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                        </td>
                                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                                            <?= formatDateTime($u['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /admin-grid -->
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a
                    href="observability.php"><?= t('observability.title') ?></a></p>
        </div>
    </footer>

    <script>
        // ── Toast system ──
        function showToast(msg, type) {
            var c = document.getElementById('toast-container');
            if (!c) return;
            var t = document.createElement('div');
            t.className = 'toast toast-' + (type || 'info');
            t.textContent = msg;
            c.appendChild(t);
            setTimeout(function () {
                t.classList.add('hiding');
                setTimeout(function () { t.remove(); }, 300);
            }, 3000);
        }

        // ── Bank filter tabs ──
        (function () {
            var tabs = document.querySelectorAll('.filter-tab');
            var rows = document.querySelectorAll('#rates-sortable .sortable-row');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    var bank = tab.getAttribute('data-bank');
                    rows.forEach(function (row) {
                        if (bank === 'all' || row.getAttribute('data-bank') === bank) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            });
        })();

        // ── Drag & Drop with AJAX auto-save ──
        (function () {
            var tbody = document.getElementById('rates-sortable');
            var statusEl = document.getElementById('save-status');
            var csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
            if (!tbody) return;

            var draggedRow = null;
            var saveTimeout = null;

            function updateOrderNumbers() {
                var rows = tbody.querySelectorAll('.sortable-row');
                var i = 0;
                rows.forEach(function (r) {
                    i++;
                    var num = r.querySelector('.order-num');
                    if (num) num.textContent = i;
                });
            }

            function showStatus(type, text) {
                if (!statusEl) return;
                statusEl.className = 'save-status visible ' + type;
                statusEl.innerHTML = (type === 'saving' ? '<span class="save-spinner"></span>' : '') + text;
                if (type !== 'saving') {
                    setTimeout(function () { statusEl.className = 'save-status'; }, 2500);
                }
            }

            function saveOrder() {
                var rows = tbody.querySelectorAll('.sortable-row');
                var orders = {};
                var i = 0;
                rows.forEach(function (r) {
                    i++;
                    var id = r.getAttribute('data-rate-id');
                    if (id) orders[id] = i;
                });

                showStatus('saving', '<?= t('admin.order_saving') ?>');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.status === 'ok') {
                                showStatus('saved', '✓ <?= t('admin.order_saved') ?>');
                                showToast('<?= t('admin.order_saved') ?>', 'success');
                            } else {
                                showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                                showToast('<?= t('admin.order_save_error') ?>', 'error');
                            }
                        } catch (e) {
                            showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                        }
                    } else {
                        showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                    }
                };
                xhr.onerror = function () {
                    showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                };
                var params = 'action=update_rate_order&csrf_token=' + encodeURIComponent(csrfToken) +
                    '&rate_orders=' + encodeURIComponent(JSON.stringify(orders));
                xhr.send(params);
            }

            function scheduleSave() {
                if (saveTimeout) clearTimeout(saveTimeout);
                saveTimeout = setTimeout(saveOrder, 400);
            }

            // Set up drag events on each row
            var allRows = tbody.querySelectorAll('.sortable-row');
            allRows.forEach(function (row) {
                row.addEventListener('dragstart', function (e) {
                    draggedRow = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');
                });

                row.addEventListener('dragend', function () {
                    this.classList.remove('dragging');
                    draggedRow = null;
                    tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                });

                row.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (draggedRow && draggedRow !== this) {
                        tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                        this.classList.add('drag-over');
                    }
                });

                row.addEventListener('dragleave', function () {
                    this.classList.remove('drag-over');
                });

                row.addEventListener('drop', function (e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    if (draggedRow && draggedRow !== this) {
                        var rect = this.getBoundingClientRect();
                        var mid = rect.top + rect.height / 2;
                        if (e.clientY < mid) {
                            this.parentNode.insertBefore(draggedRow, this);
                        } else {
                            this.parentNode.insertBefore(draggedRow, this.nextSibling);
                        }
                        updateOrderNumbers();
                        scheduleSave();
                    }
                });
            });

            // Touch support for mobile
            var touchRow = null, touchClone = null, touchStartY = 0;
            tbody.addEventListener('touchstart', function (e) {
                var handle = e.target.closest('.drag-handle');
                if (!handle) return;
                touchRow = handle.closest('.sortable-row');
                if (!touchRow) return;
                touchStartY = e.touches[0].clientY;
                touchRow.classList.add('dragging');
            }, { passive: true });

            tbody.addEventListener('touchmove', function (e) {
                if (!touchRow) return;
                e.preventDefault();
                var y = e.touches[0].clientY;
                var rows = Array.from(tbody.querySelectorAll('.sortable-row:not(.dragging)'));
                rows.forEach(function (r) { r.classList.remove('drag-over'); });
                for (var i = 0; i < rows.length; i++) {
                    var rect = rows[i].getBoundingClientRect();
                    if (y > rect.top && y < rect.bottom) {
                        rows[i].classList.add('drag-over');
                        var mid = rect.top + rect.height / 2;
                        if (y < mid) {
                            tbody.insertBefore(touchRow, rows[i]);
                        } else {
                            tbody.insertBefore(touchRow, rows[i].nextSibling);
                        }
                        break;
                    }
                }
            }, { passive: false });

            tbody.addEventListener('touchend', function () {
                if (!touchRow) return;
                touchRow.classList.remove('dragging');
                tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                updateOrderNumbers();
                scheduleSave();
                touchRow = null;
            });
        })();
    </script>
</body>

</html>
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
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: admin.php');
        exit;
    }
    
    if ($_POST['action'] === 'update_rates') {
        // Manuel kur güncelleme
        $output = [];
        $returnCode = 0;
        
        // ServBay için doğru PHP CLI binary'sini kullan
        $phpBinary = '/Applications/ServBay/bin/php';
        if (!file_exists($phpBinary)) {
            $phpBinary = 'php'; // Fallback to system PHP
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
        // Validate bank slug
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
        // Update display order for rates
        $rateOrders = json_decode($_POST['rate_orders'], true);
        if (is_array($rateOrders)) {
            foreach ($rateOrders as $rateId => $order) {
                Database::update('rates', ['display_order' => (int) $order], 'id = ?', [(int) $rateId]);
            }
            $message = t('admin.rate_order_updated');
            $messageType = 'success';
        }
    }
    
    if ($_POST['action'] === 'set_chart_defaults' && isset($_POST['chart_currency']) && isset($_POST['chart_days'])) {
        $chartCurrency = trim($_POST['chart_currency']);
        $chartDays = (int) $_POST['chart_days'];
        
        // Validate currency exists
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
    
    if ($_POST['action'] !== 'update_rates' && $_POST['action'] !== 'toggle_homepage' && $_POST['action'] !== 'set_default_bank' && $_POST['action'] !== 'update_rate_order' && $_POST['action'] !== 'set_chart_defaults') {
        header('Location: admin.php');
        exit;
    }
}

$banks = Database::query('SELECT id, name, slug, is_active, last_scraped_at FROM banks ORDER BY name');
$currencies = Database::query('SELECT id, code, name_tr, name_en, is_active, type FROM currencies ORDER BY code');
$users = Database::query('SELECT id, username, role, is_active, created_at FROM users ORDER BY username');

// Get all rates with homepage visibility (ordered by display_order)
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

// Get active currencies for chart defaults
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
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <section class="bank-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 style="margin: 0;"><?= t('admin.health') ?></h2>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="update_rates">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem;">
                        🔄 <?= t('admin.update_rates_now') ?>
                    </button>
                </form>
            </div>
            <p>
                <?= t('admin.last_rate_update') ?>:
                <?= $lastRateUpdate && $lastRateUpdate['value'] ? formatDateTime($lastRateUpdate['value']) : t('common.not_available') ?>
            </p>
        </section>

        <section class="bank-section">
            <h2><?= t('admin.default_bank_setting') ?></h2>
            <p><?= t('admin.default_bank_desc') ?></p>
            <form method="POST" style="max-width: 400px;">
                <input type="hidden" name="action" value="set_default_bank">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div style="display: flex; gap: 1rem; align-items: flex-end;">
                    <div style="flex: 1;">
                        <label for="default_bank" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                            <?= t('admin.default_bank') ?>:
                        </label>
                        <select id="default_bank" name="default_bank" style="width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid #ddd;">
                            <option value="all" <?= $defaultBankValue === 'all' ? 'selected' : '' ?>>
                                <?= t('admin.all_banks') ?>
                            </option>
                            <?php foreach ($banks as $bank): ?>
                                <?php if ($bank['is_active']): ?>
                                    <option value="<?= htmlspecialchars($bank['slug']) ?>" <?= $defaultBankValue === $bank['slug'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($bank['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= t('admin.save') ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="bank-section">
            <h2><?= t('admin.chart_defaults') ?></h2>
            <p><?= t('admin.chart_defaults_desc') ?></p>
            <form method="POST" style="max-width: 600px;">
                <input type="hidden" name="action" value="set_chart_defaults">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="chart_currency" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                            <?= t('admin.chart_currency') ?>:
                        </label>
                        <select id="chart_currency" name="chart_currency" style="width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid #ddd;">
                            <?php foreach ($activeCurrencies as $curr): ?>
                                <option value="<?= htmlspecialchars($curr['code']) ?>" <?= $chartDefaultCurrencyValue === $curr['code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($curr['code']) ?> - <?= htmlspecialchars(localizedCurrencyName($curr)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        <label for="chart_days" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                            <?= t('admin.chart_days') ?>:
                        </label>
                        <select id="chart_days" name="chart_days" style="width: 100%; padding: 0.5rem; border-radius: 4px; border: 1px solid #ddd;">
                            <option value="7" <?= $chartDefaultDaysValue === 7 ? 'selected' : '' ?>>7 <?= t('index.chart.days_unit') ?></option>
                            <option value="30" <?= $chartDefaultDaysValue === 30 ? 'selected' : '' ?>>30 <?= t('index.chart.days_unit') ?></option>
                            <option value="90" <?= $chartDefaultDaysValue === 90 ? 'selected' : '' ?>>90 <?= t('index.chart.days_unit') ?></option>
                            <option value="180" <?= $chartDefaultDaysValue === 180 ? 'selected' : '' ?>>180 <?= t('index.chart.days_unit') ?></option>
                            <option value="365" <?= $chartDefaultDaysValue === 365 ? 'selected' : '' ?>>365 <?= t('index.chart.days_unit') ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= t('admin.save') ?>
                    </button>
                </div>
            </form>
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
            <h2><?= t('admin.homepage_rates') ?></h2>
            <p><?= t('admin.homepage_rates_desc') ?></p>
            <p style="background: #f0f9ff; padding: 1rem; border-radius: 8px; border-left: 4px solid #3b82f6;">
                <strong>💡 <?= t('admin.drag_drop_hint') ?>:</strong> <?= t('admin.drag_drop_desc') ?>
            </p>
            <div class="table-responsive">
                <table class="rates-table" id="rates-sortable-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">🔀</th>
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
                        <?php foreach ($allRates as $rate): ?>
                        <tr data-rate-id="<?= (int) $rate['id'] ?>" style="cursor: move;">
                            <td class="drag-handle" style="text-align: center; cursor: grab;">⋮⋮</td>
                            <td><?= htmlspecialchars($rate['bank_name']) ?></td>
                            <td><?= htmlspecialchars(localizedCurrencyName($rate)) ?></td>
                            <td><strong><?= htmlspecialchars($rate['currency_code']) ?></strong></td>
                            <td class="text-right mono"><?= formatRate((float) $rate['buy_rate']) ?></td>
                            <td class="text-right mono"><?= formatRate((float) $rate['sell_rate']) ?></td>
                            <td>
                                <span class="<?= $rate['show_on_homepage'] ? 'text-success' : 'text-muted' ?>">
                                    <?= $rate['show_on_homepage'] ? '✓ ' . t('admin.visible') : '✗ ' . t('admin.hidden') ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_homepage">
                                    <input type="hidden" name="rate_id" value="<?= (int) $rate['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn btn-sm">
                                        <?= $rate['show_on_homepage'] ? t('admin.hide') : t('admin.show') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 1rem; text-align: right;">
                <button id="save-order-btn" class="btn btn-primary" style="display: none;">
                    💾 <?= t('admin.save_order') ?>
                </button>
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
    
    <script>
    // Drag & Drop functionality for rates ordering
    (function() {
        const tbody = document.getElementById('rates-sortable');
        const saveBtn = document.getElementById('save-order-btn');
        const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
        
        if (!tbody || !saveBtn) return;
        
        let draggedRow = null;
        let orderChanged = false;
        
        // Make rows draggable
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            row.setAttribute('draggable', 'true');
            
            row.addEventListener('dragstart', function(e) {
                draggedRow = this;
                this.style.opacity = '0.5';
                e.dataTransfer.effectAllowed = 'move';
            });
            
            row.addEventListener('dragend', function() {
                this.style.opacity = '1';
                draggedRow = null;
            });
            
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                
                if (draggedRow && draggedRow !== this) {
                    const rect = this.getBoundingClientRect();
                    const midpoint = rect.top + rect.height / 2;
                    
                    if (e.clientY < midpoint) {
                        this.parentNode.insertBefore(draggedRow, this);
                    } else {
                        this.parentNode.insertBefore(draggedRow, this.nextSibling);
                    }
                    orderChanged = true;
                    saveBtn.style.display = 'inline-block';
                }
            });
        });
        
        // Save order button
        saveBtn.addEventListener('click', function() {
            const rows = tbody.querySelectorAll('tr');
            const rateOrders = {};
            
            rows.forEach((row, index) => {
                const rateId = row.getAttribute('data-rate-id');
                if (rateId) {
                    rateOrders[rateId] = index + 1;
                }
            });
            
            // Send to server
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_rate_order';
            form.appendChild(actionInput);
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
            
            const ordersInput = document.createElement('input');
            ordersInput.type = 'hidden';
            ordersInput.name = 'rate_orders';
            ordersInput.value = JSON.stringify(rateOrders);
            form.appendChild(ordersInput);
            
            document.body.appendChild(form);
            form.submit();
        });
    })();
    </script>
</body>
</html>

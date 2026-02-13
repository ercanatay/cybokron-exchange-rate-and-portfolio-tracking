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

    if ($_POST['action'] === 'save_widget_config' && isset($_POST['widget_config'])) {
        $widgetConfig = json_decode($_POST['widget_config'], true);
        if (is_array($widgetConfig)) {
            $json = json_encode($widgetConfig, JSON_UNESCAPED_UNICODE);
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['widget_config', $json, $json]
            );
            // If AJAX request, respond JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'ok', 'message' => t('admin.widget_config_updated')]);
                exit;
            }
            $message = t('admin.widget_config_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_retention_days' && isset($_POST['retention_days'])) {
        $retDays = max(30, min(3650, (int) $_POST['retention_days']));
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['rate_history_retention_days', (string) $retDays, (string) $retDays]
        );
        $message = t('admin.retention_updated');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'toggle_noindex') {
        $currentNoindex = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['site_noindex']);
        $newValue = ($currentNoindex && ($currentNoindex['value'] ?? '0') === '1') ? '0' : '1';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['site_noindex', $newValue, $newValue]
        );
        $message = t('admin.noindex_updated');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'save_openrouter_settings') {
        $orApiKey = trim((string) ($_POST['openrouter_api_key'] ?? ''));
        $orModel = trim((string) ($_POST['openrouter_model'] ?? ''));

        if ($orApiKey !== '') {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['openrouter_api_key', $orApiKey, $orApiKey]
            );
        }

        if ($orModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $orModel)) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['openrouter_model', $orModel, $orModel]
            );
        }

        $message = t('admin.openrouter_settings_saved');
        $messageType = 'success';
    }

    if (!in_array($_POST['action'], ['update_rates', 'toggle_homepage', 'set_default_bank', 'update_rate_order', 'set_chart_defaults', 'save_widget_config', 'toggle_noindex', 'set_retention_days', 'save_openrouter_settings'], true)) {
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
$retentionDaysRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['rate_history_retention_days']);
$retentionDaysValue = (int) ($retentionDaysRow['value'] ?? (defined('RATE_HISTORY_RETENTION_DAYS') ? RATE_HISTORY_RETENTION_DAYS : 1825));
$currentLocale = getAppLocale();
$csrfToken = getCsrfToken();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));

// Widget configuration
$widgetConfigRaw = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['widget_config']);
$defaultWidgets = [
    ['id' => 'bank_selector', 'visible' => true, 'order' => 0],
    ['id' => 'converter', 'visible' => true, 'order' => 1],
    ['id' => 'widgets', 'visible' => true, 'order' => 2],
    ['id' => 'chart', 'visible' => true, 'order' => 3],
    ['id' => 'rates', 'visible' => true, 'order' => 4],
];
$widgetConfig = $defaultWidgets;
if (!empty($widgetConfigRaw['value'])) {
    $parsed = json_decode($widgetConfigRaw['value'], true);
    if (is_array($parsed)) {
        $widgetConfig = $parsed;
    }
}
usort($widgetConfig, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

$widgetLabels = [
    'bank_selector' => '🏦 ' . t('admin.widget_bank_selector'),
    'converter' => '🔄 ' . t('admin.widget_converter'),
    'widgets' => '📊 ' . t('admin.widget_summary'),
    'chart' => '📈 ' . t('admin.widget_chart'),
    'rates' => '📋 ' . t('admin.widget_rates'),
];

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
<?= renderSeoMeta([
    'title' => t('admin.title') . ' — ' . APP_NAME,
    'description' => t('seo.admin_description'),
    'page' => 'admin.php',
]) ?>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>
    <?php $activePage = 'admin';
    include __DIR__ . '/includes/header.php'; ?>

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
                                        <?= t('admin.all_banks') ?>
                                    </option>
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
                                        <?= t('index.chart.days_unit') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>

            <!-- Data Retention -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #f59e0b20, #d9770620);">🗄️</div>
                        <div>
                            <h2><?= t('admin.retention_title') ?></h2>
                            <p><?= t('admin.retention_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="action" value="set_retention_days">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="retention_days"><?= t('admin.retention_label') ?></label>
                            <select id="retention_days" name="retention_days">
                                <?php foreach ([
                                    30 => '30 ' . t('index.chart.days_unit') . ' (1 ' . t('admin.retention_month') . ')',
                                    90 => '90 ' . t('index.chart.days_unit') . ' (3 ' . t('admin.retention_month') . ')',
                                    180 => '180 ' . t('index.chart.days_unit') . ' (6 ' . t('admin.retention_month') . ')',
                                    365 => '365 ' . t('index.chart.days_unit') . ' (1 ' . t('admin.retention_year') . ')',
                                    730 => '730 ' . t('index.chart.days_unit') . ' (2 ' . t('admin.retention_year') . ')',
                                    1095 => '1095 ' . t('index.chart.days_unit') . ' (3 ' . t('admin.retention_year') . ')',
                                    1825 => '1825 ' . t('index.chart.days_unit') . ' (5 ' . t('admin.retention_year') . ')',
                                    3650 => '3650 ' . t('index.chart.days_unit') . ' (10 ' . t('admin.retention_year') . ')',
                                ] as $days => $label): ?>
                                    <option value="<?= $days ?>" <?= $retentionDaysValue === $days ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p style="font-size:0.82rem; color:var(--text-muted); margin:0 0 12px;">
                            <?= t('admin.retention_hint') ?>
                        </p>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>

            <!-- Widget Management -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #06b6d420, #0891b220);">
                            🧩</div>
                        <div>
                            <h2><?= t('admin.widget_management') ?></h2>
                            <p><?= t('admin.widget_management_desc') ?></p>
                        </div>
                    </div>
                    <div class="save-status" id="widget-save-status"></div>
                </div>
                <div class="admin-card-body">
                    <div class="hint-box">
                        <strong>💡 <?= t('admin.drag_drop_hint') ?>:</strong>
                        <?= t('admin.widget_drag_desc') ?>
                    </div>
                    <ul id="widget-sortable-list" class="widget-config-list">
                        <?php foreach ($widgetConfig as $i => $w): ?>
                            <li class="widget-config-item sortable-row" draggable="true"
                                data-widget-id="<?= htmlspecialchars($w['id']) ?>">
                                <span class="drag-handle">⠿</span>
                                <span class="order-num"><?= $i + 1 ?></span>
                                <span
                                    class="widget-config-label"><?= $widgetLabels[$w['id']] ?? htmlspecialchars($w['id']) ?></span>
                                <label class="widget-toggle">
                                    <input type="checkbox" <?= ($w['visible'] ?? true) ? 'checked' : '' ?>
                                        data-widget-id="<?= htmlspecialchars($w['id']) ?>">
                                    <span class="widget-toggle-slider"></span>
                                    <span
                                        class="widget-toggle-text"><?= ($w['visible'] ?? true) ? t('admin.visible') : t('admin.hidden') ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- SEO Settings -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #10b98120, #059e6820);">🔍</div>
                        <div>
                            <h2><?= t('admin.seo_settings') ?></h2>
                            <p><?= t('admin.seo_settings_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <?php $isNoindex = isSiteNoindex(); ?>
                    <div class="settings-form" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:200px;">
                            <strong><?= t('admin.noindex_label') ?></strong>
                            <p style="margin:4px 0 0; font-size:0.85rem; color:var(--text-muted);"><?= t('admin.noindex_desc') ?></p>
                            <p style="margin:8px 0 0;">
                                <span class="badge <?= $isNoindex ? 'badge-muted' : 'badge-success' ?>">
                                    <?= $isNoindex ? t('admin.noindex_enabled') : t('admin.noindex_disabled') ?>
                                </span>
                            </p>
                        </div>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="toggle_noindex">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="btn <?= $isNoindex ? 'btn-primary' : 'btn-action' ?>" style="white-space:nowrap;">
                                <?= $isNoindex ? '🌐 ' . t('admin.show') : '🚫 ' . t('admin.hide') ?>
                            </button>
                        </form>
                    </div>
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
                                            <?= formatDateTime($u['created_at']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- OpenRouter AI Settings -->
            <?php
            $dbApiKey = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_api_key']);
            $dbApiKeyVal = trim($dbApiKey['value'] ?? '');
            $effectiveApiKey = $dbApiKeyVal !== '' ? $dbApiKeyVal : (defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '');
            $maskedKey = $effectiveApiKey !== '' ? str_repeat('*', max(0, strlen($effectiveApiKey) - 4)) . substr($effectiveApiKey, -4) : '';
            $keySource = $dbApiKeyVal !== '' ? 'DB' : ($effectiveApiKey !== '' ? 'config.php' : '');

            $dbModel = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_model']);
            $effectiveModel = trim($dbModel['value'] ?? '');
            if ($effectiveModel === '') {
                $effectiveModel = defined('OPENROUTER_MODEL') ? trim((string) OPENROUTER_MODEL) : 'z-ai/glm-5';
            }
            ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #8b5cf620, #a78bfa20);">🤖</div>
                        <div>
                            <h2><?= t('admin.openrouter_settings') ?></h2>
                            <p><?= t('admin.openrouter_settings_desc') ?></p>
                        </div>
                    </div>
                    <?php if ($effectiveApiKey !== ''): ?>
                        <span class="badge badge-success">● <?= t('admin.config_set') ?></span>
                    <?php else: ?>
                        <span class="badge badge-muted">○ <?= t('admin.config_not_set') ?></span>
                    <?php endif; ?>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="or-settings-form">
                        <input type="hidden" name="action" value="save_openrouter_settings">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="or-field-group">
                            <div class="or-field">
                                <label for="openrouter_api_key"><?= t('admin.openrouter_api_key_label') ?></label>
                                <div class="or-input-wrapper">
                                    <input type="password" id="openrouter_api_key" name="openrouter_api_key"
                                           placeholder="<?= $maskedKey !== '' ? $maskedKey : 'sk-or-v1-...' ?>"
                                           autocomplete="off" spellcheck="false">
                                    <button type="button" class="or-toggle-vis" onclick="var i=document.getElementById('openrouter_api_key');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁️':'🙈'" title="<?= t('admin.openrouter_toggle_key') ?>">👁️</button>
                                </div>
                                <small class="or-field-hint">
                                    <?php if ($keySource === 'DB'): ?>
                                        <?= t('admin.openrouter_key_source_db') ?>
                                    <?php elseif ($keySource === 'config.php'): ?>
                                        <?= t('admin.openrouter_key_source_config') ?>
                                    <?php else: ?>
                                        <?= t('admin.openrouter_key_not_configured') ?>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="or-field">
                                <label for="openrouter_model"><?= t('admin.openrouter_model_label') ?></label>
                                <input type="text" id="openrouter_model" name="openrouter_model"
                                       value="<?= htmlspecialchars($effectiveModel) ?>"
                                       placeholder="z-ai/glm-5" spellcheck="false">
                                <small class="or-field-hint"><?= t('admin.openrouter_model_hint') ?></small>
                            </div>
                        </div>

                        <div class="or-actions">
                            <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                            <a href="openrouter.php" class="btn-action"><?= t('admin.openrouter_panel_link') ?> →</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- System Configuration (read-only) -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #6366f120, #818cf820);">&#9881;</div>
                        <div>
                            <h2><?= t('admin.system_config') ?></h2>
                            <p><?= t('admin.system_config_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <?php
                    $configSections = [
                        [
                            'title' => t('admin.config_section_security'),
                            'icon' => '🛡️',
                            'color' => '#22c55e',
                            'items' => [
                                ['label' => 'Turnstile CAPTCHA', 'value' => defined('TURNSTILE_ENABLED') ? TURNSTILE_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_security_headers'), 'value' => defined('ENABLE_SECURITY_HEADERS') ? ENABLE_SECURITY_HEADERS : false, 'type' => 'bool'],
                                ['label' => 'CSRF', 'value' => defined('API_REQUIRE_CSRF') ? API_REQUIRE_CSRF : true, 'type' => 'bool'],
                                ['label' => t('admin.cfg_cli_cron'), 'value' => defined('ENFORCE_CLI_CRON') ? ENFORCE_CLI_CRON : true, 'type' => 'bool'],
                                ['label' => t('admin.cfg_login_limit'), 'value' => defined('LOGIN_RATE_LIMIT') ? LOGIN_RATE_LIMIT . ' / ' . (defined('LOGIN_RATE_WINDOW_SECONDS') ? LOGIN_RATE_WINDOW_SECONDS : 300) . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_portfolio_auth'), 'value' => defined('AUTH_REQUIRE_PORTFOLIO') ? AUTH_REQUIRE_PORTFOLIO : false, 'type' => 'bool'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_scraping'),
                            'icon' => '🔄',
                            'color' => '#f59e0b',
                            'items' => [
                                ['label' => t('admin.cfg_scrape_timeout'), 'value' => defined('SCRAPE_TIMEOUT') ? SCRAPE_TIMEOUT . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_retry_count'), 'value' => defined('SCRAPE_RETRY_COUNT') ? (string) SCRAPE_RETRY_COUNT : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_ai_repair'), 'value' => defined('OPENROUTER_AI_REPAIR_ENABLED') ? OPENROUTER_AI_REPAIR_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_ai_model'), 'value' => defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : '—', 'type' => 'mono'],
                                ['label' => 'API Key', 'value' => defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '' ? true : false, 'type' => 'secret'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_market'),
                            'icon' => '📅',
                            'color' => '#3b82f6',
                            'items' => [
                                ['label' => t('admin.cfg_update_interval'), 'value' => defined('UPDATE_INTERVAL_MINUTES') ? UPDATE_INTERVAL_MINUTES . ' min' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_open'), 'value' => defined('MARKET_OPEN_HOUR') ? sprintf('%02d:00', MARKET_OPEN_HOUR) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_close'), 'value' => defined('MARKET_CLOSE_HOUR') ? sprintf('%02d:00', MARKET_CLOSE_HOUR) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_days'), 'value' => defined('MARKET_DAYS') ? implode(', ', array_map(fn($d) => [1 => t('admin.day_mon'), 2 => t('admin.day_tue'), 3 => t('admin.day_wed'), 4 => t('admin.day_thu'), 5 => t('admin.day_fri'), 6 => t('admin.day_sat'), 7 => t('admin.day_sun')][$d] ?? $d, MARKET_DAYS)) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_history_retention'), 'value' => defined('RATE_HISTORY_RETENTION_DAYS') ? RATE_HISTORY_RETENTION_DAYS . ' ' . t('index.chart.days_unit') : '—', 'type' => 'text'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_alerts'),
                            'icon' => '🔔',
                            'color' => '#ec4899',
                            'items' => [
                                ['label' => 'E-posta', 'value' => defined('ALERT_EMAIL_TO') && ALERT_EMAIL_TO !== '' ? true : false, 'type' => 'secret'],
                                ['label' => 'Telegram Bot', 'value' => defined('ALERT_TELEGRAM_BOT_TOKEN') && ALERT_TELEGRAM_BOT_TOKEN !== '' ? true : false, 'type' => 'secret'],
                                ['label' => 'Webhook', 'value' => defined('ALERT_WEBHOOK_URL') && ALERT_WEBHOOK_URL !== '' ? true : false, 'type' => 'secret'],
                                ['label' => t('admin.cfg_alert_cooldown'), 'value' => defined('ALERT_COOLDOWN_MINUTES') ? ALERT_COOLDOWN_MINUTES . ' min' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_rate_webhook'), 'value' => defined('RATE_UPDATE_WEBHOOK_URL') && RATE_UPDATE_WEBHOOK_URL !== '' ? true : false, 'type' => 'secret'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_api'),
                            'icon' => '⚡',
                            'color' => '#8b5cf6',
                            'items' => [
                                ['label' => 'CORS', 'value' => defined('API_ALLOW_CORS') ? API_ALLOW_CORS : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_read_limit'), 'value' => defined('API_READ_RATE_LIMIT') ? API_READ_RATE_LIMIT . ' / ' . (defined('API_READ_RATE_WINDOW_SECONDS') ? API_READ_RATE_WINDOW_SECONDS : 60) . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_write_limit'), 'value' => defined('API_WRITE_RATE_LIMIT') ? API_WRITE_RATE_LIMIT . ' / ' . (defined('API_WRITE_RATE_WINDOW_SECONDS') ? API_WRITE_RATE_WINDOW_SECONDS : 60) . 's' : '—', 'type' => 'text'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_system'),
                            'icon' => '🖥️',
                            'color' => '#64748b',
                            'items' => [
                                ['label' => 'Debug', 'value' => defined('APP_DEBUG') ? APP_DEBUG : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_timezone'), 'value' => defined('APP_TIMEZONE') ? APP_TIMEZONE : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_locale'), 'value' => defined('DEFAULT_LOCALE') ? strtoupper(DEFAULT_LOCALE) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_auto_update'), 'value' => defined('AUTO_UPDATE') ? AUTO_UPDATE : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_logging'), 'value' => defined('LOG_ENABLED') ? LOG_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_db_persistent'), 'value' => defined('DB_PERSISTENT') ? DB_PERSISTENT : false, 'type' => 'bool'],
                            ],
                        ],
                    ];
                    ?>
                    <div class="sc-grid">
                        <?php foreach ($configSections as $section): ?>
                        <div class="sc-card">
                            <div class="sc-card-header" style="--sc-accent: <?= $section['color'] ?>">
                                <span class="sc-card-icon"><?= $section['icon'] ?></span>
                                <span class="sc-card-title"><?= htmlspecialchars($section['title']) ?></span>
                            </div>
                            <div class="sc-card-body">
                                <?php foreach ($section['items'] as $item): ?>
                                <div class="sc-row">
                                    <span class="sc-label"><?= htmlspecialchars($item['label']) ?></span>
                                    <span class="sc-value">
                                        <?php if ($item['type'] === 'bool'): ?>
                                            <span class="sc-indicator <?= $item['value'] ? 'sc-on' : 'sc-off' ?>">
                                                <span class="sc-dot"></span>
                                                <?= $item['value'] ? t('admin.config_enabled') : t('admin.config_disabled') ?>
                                            </span>
                                        <?php elseif ($item['type'] === 'secret'): ?>
                                            <span class="sc-indicator <?= $item['value'] ? 'sc-on' : 'sc-off' ?>">
                                                <span class="sc-dot"></span>
                                                <?= $item['value'] ? t('admin.config_set') : t('admin.config_not_set') ?>
                                            </span>
                                        <?php elseif ($item['type'] === 'mono'): ?>
                                            <code class="sc-mono"><?= htmlspecialchars((string) $item['value']) ?></code>
                                        <?php else: ?>
                                            <span class="sc-text"><?= htmlspecialchars((string) $item['value']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /admin-grid -->
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a
                    href="observability.php"><?= t('observability.title') ?></a> | <a
                    href="openrouter.php"><?= t('nav.openrouter') ?></a></p>
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

        // ─── Widget Management ──────────────────────────────────────────────────
        (function () {
            var list = document.getElementById('widget-sortable-list');
            if (!list) return;

            var statusEl = document.getElementById('widget-save-status');
            var saveTimeout = null;
            var draggedItem = null;
            var visibleText = <?= json_encode(t('admin.visible')) ?>;
            var hiddenText = <?= json_encode(t('admin.hidden')) ?>;

            function getWidgetConfig() {
                var items = list.querySelectorAll('.widget-config-item');
                var config = [];
                items.forEach(function (item, i) {
                    config.push({
                        id: item.dataset.widgetId,
                        visible: item.querySelector('input[type="checkbox"]').checked,
                        order: i
                    });
                });
                return config;
            }

            function updateOrderNumbers() {
                list.querySelectorAll('.order-num').forEach(function (el, i) {
                    el.textContent = i + 1;
                });
            }

            function showStatus(type, text) {
                if (!statusEl) return;
                statusEl.className = 'save-status visible ' + type;
                statusEl.innerHTML = type === 'saving'
                    ? '<div class="save-spinner"></div> ' + text
                    : text;
                if (type !== 'saving') {
                    setTimeout(function () {
                        statusEl.classList.remove('visible');
                    }, 2000);
                }
            }

            function saveWidgetConfig() {
                var config = getWidgetConfig();
                showStatus('saving', <?= json_encode(t('admin.order_saving')) ?>);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status === 200) {
                        showStatus('saved', <?= json_encode(t('admin.order_saved')) ?>);
                    } else {
                        showStatus('error', <?= json_encode(t('admin.order_save_error')) ?>);
                    }
                };
                xhr.onerror = function () {
                    showStatus('error', <?= json_encode(t('admin.order_save_error')) ?>);
                };
                var params = 'action=save_widget_config&csrf_token=' + encodeURIComponent(csrfToken) +
                    '&widget_config=' + encodeURIComponent(JSON.stringify(config));
                xhr.send(params);
            }

            function scheduleSave() {
                if (saveTimeout) clearTimeout(saveTimeout);
                saveTimeout = setTimeout(saveWidgetConfig, 400);
            }

            // Toggle visibility
            list.addEventListener('change', function (e) {
                if (e.target.type === 'checkbox') {
                    var textEl = e.target.closest('.widget-toggle').querySelector('.widget-toggle-text');
                    if (textEl) {
                        textEl.textContent = e.target.checked ? visibleText : hiddenText;
                    }
                    scheduleSave();
                }
            });

            // Drag & drop
            var items = list.querySelectorAll('.widget-config-item');
            items.forEach(function (item) {
                item.addEventListener('dragstart', function (e) {
                    draggedItem = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');
                });

                item.addEventListener('dragend', function () {
                    this.classList.remove('dragging');
                    draggedItem = null;
                    list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                });

                item.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (draggedItem && draggedItem !== this) {
                        list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                        this.classList.add('drag-over');
                    }
                });

                item.addEventListener('dragleave', function () {
                    this.classList.remove('drag-over');
                });

                item.addEventListener('drop', function (e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    if (draggedItem && draggedItem !== this) {
                        var rect = this.getBoundingClientRect();
                        var mid = rect.top + rect.height / 2;
                        if (e.clientY < mid) {
                            list.insertBefore(draggedItem, this);
                        } else {
                            list.insertBefore(draggedItem, this.nextSibling);
                        }
                        updateOrderNumbers();
                        scheduleSave();
                    }
                });
            });

            // Touch support
            var touchItem = null;
            list.addEventListener('touchstart', function (e) {
                var handle = e.target.closest('.drag-handle');
                if (!handle) return;
                touchItem = handle.closest('.widget-config-item');
                if (touchItem) touchItem.classList.add('dragging');
            }, { passive: true });

            list.addEventListener('touchmove', function (e) {
                if (!touchItem) return;
                e.preventDefault();
                var y = e.touches[0].clientY;
                var allItems = Array.from(list.querySelectorAll('.widget-config-item:not(.dragging)'));
                allItems.forEach(function (r) { r.classList.remove('drag-over'); });
                for (var i = 0; i < allItems.length; i++) {
                    var rect = allItems[i].getBoundingClientRect();
                    if (y > rect.top && y < rect.bottom) {
                        allItems[i].classList.add('drag-over');
                        var mid = rect.top + rect.height / 2;
                        if (y < mid) {
                            list.insertBefore(touchItem, allItems[i]);
                        } else {
                            list.insertBefore(touchItem, allItems[i].nextSibling);
                        }
                        break;
                    }
                }
            }, { passive: false });

            list.addEventListener('touchend', function () {
                if (!touchItem) return;
                touchItem.classList.remove('dragging');
                list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                updateOrderNumbers();
                scheduleSave();
                touchItem = null;
            });
        })();
    </script>
</body>

</html>
<?php
/**
 * index.php — Main Dashboard
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

// Handle manual rate update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_rates') {
    if (!Auth::check() || !Auth::isAdmin()) {
        header('Location: index.php');
        exit;
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: index.php');
        exit;
    }
    
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
        error_log('Rate update failed: ' . implode("\n", $output));
        $message = t('admin.rates_updated_error');
        $messageType = 'error';
    }
}

// Get default bank from settings
$defaultBankSetting = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['default_bank']);
$defaultBank = $defaultBankSetting['value'] ?? 'all';

// Get selected bank from query parameter (or use default)
$selectedBank = $_GET['bank'] ?? $defaultBank;
$rates = $selectedBank === 'all' 
    ? getLatestRates(homepageOnly: true) 
    : getLatestRates(bankSlug: $selectedBank, homepageOnly: true);

$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$newTabText = t('common.opens_new_tab');

// Get all banks for dropdown
$banks = Database::query('SELECT id, name, slug FROM banks WHERE is_active = 1 ORDER BY name');

// Group rates by bank
$ratesByBank = [];
foreach ($rates as $rate) {
    $ratesByBank[$rate['bank_slug']][] = $rate;
}

// Unique currencies for chart
$chartCurrencies = array_unique(array_column($rates, 'currency_code'));
sort($chartCurrencies);

// Get chart defaults from settings
$chartDefaultCurrency = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_currency']);
$chartDefaultCurrencyValue = $chartDefaultCurrency['value'] ?? 'USD';

$chartDefaultDays = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_days']);
$chartDefaultDaysValue = (int) ($chartDefaultDays['value'] ?? 30);

// Use default if currency exists in available currencies, otherwise use first available
$defaultChartCurrency = in_array($chartDefaultCurrencyValue, $chartCurrencies) ? $chartDefaultCurrencyValue : ($chartCurrencies[0] ?? 'USD');

// Rates map for converter: { bank_slug: { currency_code: { buy, sell } } }
$converterRates = [];
$converterBankNames = [];
foreach ($rates as $r) {
    $converterRates[$r['bank_slug']][$r['currency_code']] = [
        'buy' => (float) $r['buy_rate'],
        'sell' => (float) $r['sell_rate'],
    ];
    $converterBankNames[$r['bank_slug']] = $r['bank_name'] ?? $r['bank_slug'];
}
$converterCurrencies = array_unique(array_merge(['TRY'], $chartCurrencies));
sort($converterCurrencies);

// Top movers: currencies with largest absolute change (any bank)
$changeByCurrency = [];
$currencyNameMap = [];
foreach ($rates as $r) {
    $code = $r['currency_code'];
    $chg = (float) ($r['change_percent'] ?? 0);
    if (!isset($changeByCurrency[$code]) || abs($chg) > abs($changeByCurrency[$code])) {
        $changeByCurrency[$code] = $chg;
    }
    if (!isset($currencyNameMap[$code])) {
        $currencyNameMap[$code] = localizedCurrencyName($r);
    }
}
uasort($changeByCurrency, fn($a, $b) => abs($b) <=> abs($a));
$topMovers = array_slice(array_keys($changeByCurrency), 0, 5);

$portfolioSummary = null;
if (Auth::check()) {
    $portfolioSummary = Portfolio::getSummary();
}

// Load widget configuration
$widgetConfigRaw = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['widget_config']);
$defaultWidgets = [
    ['id' => 'bank_selector', 'visible' => true, 'order' => 0],
    ['id' => 'converter',     'visible' => true, 'order' => 1],
    ['id' => 'widgets',       'visible' => true, 'order' => 2],
    ['id' => 'chart',         'visible' => true, 'order' => 3],
    ['id' => 'rates',         'visible' => true, 'order' => 4],
];
$widgetConfig = $defaultWidgets;
if (!empty($widgetConfigRaw['value'])) {
    $parsed = json_decode($widgetConfigRaw['value'], true);
    if (is_array($parsed)) {
        $widgetConfig = $parsed;
    }
}
// Sort by order
usort($widgetConfig, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
// Create a quick lookup map
$widgetVisible = [];
foreach ($widgetConfig as $w) {
    $widgetVisible[$w['id']] = $w['visible'] ?? true;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#3b82f6">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <title><?= t('index.page_title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('index.page_title') . ' — ' . APP_NAME,
    'description' => t('seo.index_description'),
    'page' => 'index.php',
]) ?>
        <link rel="icon" type="image/svg+xml" href="favicon.svg">
        <link rel="manifest" href="manifest.json">
        <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
        <link rel="stylesheet" href="assets/css/currency-icons.css">
</head>
<body>
    <a href="#main-content" class="skip-link"><?= t('common.skip_to_content') ?></a>
    <?php $activePage = 'rates'; include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        
        <?php foreach ($widgetConfig as $widget): ?>
        <?php $wid = $widget['id']; $wVisible = $widget['visible'] ?? true; ?>

        <?php if ($wid === 'bank_selector' && $wVisible): ?>
        <!-- Bank Selector -->
        <section class="bank-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem;">
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; justify-content: space-between;">
                <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <label for="bank-select" style="font-weight: 600; font-size: 1.1rem;">🏦 <?= t('converter.bank') ?>:</label>
                    <select id="bank-select" onchange="window.location.href='index.php?bank='+this.value" style="padding: 0.75rem 1rem; border-radius: 8px; border: none; font-size: 1rem; min-width: 200px; cursor: pointer;">
                        <option value="all" <?= $selectedBank === 'all' ? 'selected' : '' ?>>
                            <?= t('admin.all_banks') ?>
                        </option>
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?= htmlspecialchars($bank['slug']) ?>" <?= $selectedBank === $bank['slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bank['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="update_rates">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <button type="submit" class="btn btn-primary" style="display: flex; align-items: center; gap: 0.5rem; background: white; color: #667eea; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        🔄 <?= t('admin.update_rates_now') ?>
                    </button>
                </form>
            </div>
        </section>

        <p
            id="rates-refresh-status"
            class="sr-only"
            role="status"
            aria-live="polite"
            data-updated-template="<?= htmlspecialchars(t('index.refresh.status_updated')) ?>"
        >
            <?= t('index.refresh.status_ready') ?>
        </p>
        <?php endif; ?>

        <?php if ($wid === 'converter' && $wVisible && !empty($converterRates) && !empty($converterCurrencies)): ?>
        <section class="bank-section converter-section">
            <h2>🔄 <?= t('converter.title') ?></h2>
            <div class="converter-grid">
                <div class="converter-field">
                    <label for="converter-amount"><?= t('converter.amount') ?></label>
                    <input type="number" id="converter-amount" value="100" min="0" step="0.01" inputmode="decimal">
                </div>
                <div class="converter-field">
                    <label for="converter-from"><?= t('converter.from') ?></label>
                    <select id="converter-from">
                        <?php foreach ($converterCurrencies as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $c === 'USD' ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="converter-swap-btn" id="converter-swap" title="<?= t('converter.from') ?> ⇄ <?= t('converter.to') ?>">⇄</button>
                <div class="converter-field">
                    <label for="converter-to"><?= t('converter.to') ?></label>
                    <select id="converter-to">
                        <?php foreach ($converterCurrencies as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $c === 'TRY' ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="converter-field">
                    <label for="converter-rate-type"><?= t('converter.rate_type') ?></label>
                    <select id="converter-rate-type">
                        <option value="sell"><?= t('index.table.bank_sell') ?></option>
                        <option value="buy"><?= t('index.table.bank_buy') ?></option>
                    </select>
                </div>
                <div class="converter-field">
                    <label for="converter-bank"><?= t('converter.bank') ?></label>
                    <select id="converter-bank">
                        <?php foreach (array_keys($converterRates) as $slug): ?>
                            <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($converterBankNames[$slug] ?? $slug) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="converter-result">
                    <span class="converter-result-label"><?= t('converter.result') ?></span>
                    <span id="converter-result" class="converter-result-value">—</span>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($wid === 'widgets' && $wVisible && !empty($topMovers)): ?>
        <section class="bank-section widgets-section">
            <h2>📊 <?= t('index.widgets.title') ?></h2>
            <div class="widgets-grid">
                <!-- Top Movers Card -->
                <div class="widget-card">
                    <div class="widget-card-header">
                        <div class="widget-card-icon icon-movers">🔥</div>
                        <h3><?= t('index.widgets.top_movers') ?></h3>
                    </div>
                    <div class="widget-card-body">
                        <ul class="widget-list">
                            <?php $rank = 0; foreach ($topMovers as $code): ?>
                                <?php
                                    $chg = $changeByCurrency[$code] ?? 0;
                                    $rank++;
                                    $changeWidth = min(abs($chg) * 20, 100); // Scale: 5% change = 100% bar
                                ?>
                                <li class="<?= changeClass($chg) ?>" style="--change-width: <?= $changeWidth ?>%">
                                    <span class="mover-rank"><?= $rank ?></span>
                                    <span class="mover-currency">
                                        <span class="currency-code"><?= htmlspecialchars($code) ?></span>
                                        <?php $cName = $currencyNameMap[$code] ?? ''; if ($cName !== ''): ?>
                                            <span class="currency-label"><?= htmlspecialchars($cName) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="mover-change">
                                        <?= changeArrow($chg) ?> %<?= formatNumberLocalized(abs($chg), 2) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <?php if ($portfolioSummary && !empty($portfolioSummary['items'])): ?>
                <!-- Portfolio Summary Card -->
                <?php
                    $totalCost = (float) ($portfolioSummary['total_cost'] ?? 0);
                    $totalValue = (float) ($portfolioSummary['total_value'] ?? 0);
                    $profitPercent = (float) ($portfolioSummary['profit_percent'] ?? 0);
                    $profitAmount = $totalValue - $totalCost;
                    $isProfit = $profitPercent >= 0;
                ?>
                <div class="widget-card">
                    <div class="widget-card-header">
                        <div class="widget-card-icon icon-portfolio">💼</div>
                        <h3><?= t('index.widgets.portfolio_summary') ?></h3>
                    </div>
                    <div class="widget-card-body">
                        <div class="portfolio-metrics">
                            <div class="portfolio-metric">
                                <span class="portfolio-metric-label"><?= t('portfolio.summary.total_cost') ?></span>
                                <span class="portfolio-metric-value"><?= formatTRY($totalCost) ?></span>
                            </div>
                            <div class="portfolio-metric metric-highlight">
                                <span class="portfolio-metric-label"><?= t('portfolio.summary.current_value') ?></span>
                                <span class="portfolio-metric-value"><?= formatTRY($totalValue) ?></span>
                            </div>
                            <div class="portfolio-metric <?= $isProfit ? 'metric-profit' : 'metric-loss' ?>">
                                <span class="portfolio-metric-label"><?= t('portfolio.summary.profit_loss') ?></span>
                                <span class="portfolio-metric-value"><?= $isProfit ? '+' : '' ?><?= formatTRY($profitAmount) ?></span>
                            </div>
                        </div>
                        <div class="portfolio-profit-badge <?= $isProfit ? 'badge-profit' : 'badge-loss' ?>">
                            <span class="badge-arrow"><?= $isProfit ? '▲' : '▼' ?></span>
                            %<?= formatNumberLocalized(abs($profitPercent), 2) ?>
                        </div>
                        <a href="portfolio.php" class="btn-portfolio">
                            <?= t('nav.portfolio') ?>
                            <span class="btn-arrow">→</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($wid === 'chart' && $wVisible && !empty($chartCurrencies)): ?>
        <section class="chart-section bank-section">
            <h2>📈 <?= t('index.chart.title') ?></h2>
            <div class="chart-controls">
                <label for="chart-currency"><?= t('index.chart.currency') ?></label>
                <select id="chart-currency">
                    <?php foreach ($chartCurrencies as $code): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $code === $defaultChartCurrency ? 'selected' : '' ?>>
                            <?= htmlspecialchars($code) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="chart-days"><?= t('index.chart.days') ?></label>
                <select id="chart-days">
                    <option value="7" <?= $chartDefaultDaysValue === 7 ? 'selected' : '' ?>>7 <?= t('index.chart.days_unit') ?></option>
                    <option value="30" <?= $chartDefaultDaysValue === 30 ? 'selected' : '' ?>>30 <?= t('index.chart.days_unit') ?></option>
                    <option value="90" <?= $chartDefaultDaysValue === 90 ? 'selected' : '' ?>>90 <?= t('index.chart.days_unit') ?></option>
                    <option value="180" <?= $chartDefaultDaysValue === 180 ? 'selected' : '' ?>>180 <?= t('index.chart.days_unit') ?></option>
                    <option value="365" <?= $chartDefaultDaysValue === 365 ? 'selected' : '' ?>>365 <?= t('index.chart.days_unit') ?></option>
                </select>
            </div>
            <div class="chart-container">
                <canvas id="rate-chart" role="img" aria-label="<?= htmlspecialchars(t('index.chart.aria_label')) ?>" data-label-buy="<?= htmlspecialchars(t('chart.label.buy')) ?>" data-label-sell="<?= htmlspecialchars(t('chart.label.sell')) ?>"></canvas>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($wid === 'rates' && $wVisible): ?>
        <?php foreach ($ratesByBank as $bankSlug => $bankRates): ?>
            <?php $bankName = $bankRates[0]['bank_name'] ?? $bankSlug; ?>
            <section class="bank-section">
                <h2>🏦 <?= htmlspecialchars($bankName) ?></h2>
                <?php if (!empty($bankRates[0]['scraped_at'])): ?>
                    <p class="last-update">
                        <?= t('index.last_update', ['datetime' => formatDateTime((string) $bankRates[0]['scraped_at'])]) ?>
                    </p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="rates-table">
                        <caption class="sr-only"><?= htmlspecialchars(t('index.table.caption', ['bank' => (string) $bankName])) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= t('index.table.currency') ?></th>
                                <th scope="col"><?= t('index.table.code') ?></th>
                                <th scope="col" class="text-right"><?= t('index.table.bank_buy') ?></th>
                                <th scope="col" class="text-right"><?= t('index.table.bank_sell') ?></th>
                                <th scope="col" class="text-right"><?= t('index.table.change') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bankRates as $rate): ?>
                                <?php $change = (float) ($rate['change_percent'] ?? 0); ?>
                                <tr data-currency="<?= htmlspecialchars($rate['currency_code']) ?>" data-bank="<?= htmlspecialchars($rate['bank_slug']) ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <?php
                                            $code = $rate['currency_code'];
                                            $isPreciousMetal = in_array($code, ['XAU', 'XAG', 'XPT', 'XPD']);
                                            
                                            if ($isPreciousMetal): ?>
                                                <!-- Precious metals: gradient background -->
                                                <div class="currency-icon currency-icon-sm currency-icon-<?= htmlspecialchars($code) ?>">
                                                    <span class="currency-icon-fallback"><?= htmlspecialchars($code) ?></span>
                                                </div>
                                            <?php else:
                                                $imgPath = "assets/images/currencies/{$code}.svg";
                                                $imgPathPng = "assets/images/currencies/{$code}.png";
                                                ?>
                                                <div class="currency-icon currency-icon-sm">
                                                    <?php if (file_exists($imgPath)): ?>
                                                        <img src="<?= $imgPath ?>" alt="<?= htmlspecialchars($code) ?>" loading="lazy">
                                                    <?php elseif (file_exists($imgPathPng)): ?>
                                                        <img src="<?= $imgPathPng ?>" alt="<?= htmlspecialchars($code) ?>" loading="lazy">
                                                    <?php else: ?>
                                                        <span class="currency-icon-fallback" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                                            <?= htmlspecialchars(substr($code, 0, 2)) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <span class="currency-name"><?= htmlspecialchars(localizedCurrencyName($rate)) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="currency-code"><?= htmlspecialchars($rate['currency_code']) ?></span>
                                    </td>
                                    <td class="text-right mono rate-buy"><?= formatRate((float) $rate['buy_rate']) ?></td>
                                    <td class="text-right mono rate-sell"><?= formatRate((float) $rate['sell_rate']) ?></td>
                                    <td class="text-right rate-change <?= changeClass($change) ?>">
                                        <?= changeArrow($change) ?>
                                        % <?= formatNumberLocalized(abs($change), 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (empty($ratesByBank)): ?>
            <div class="empty-state">
                <h2><?= t('index.empty_title') ?></h2>
                <p><?= t('index.empty_desc') ?></p>
                <code>php cron/update_rates.php</code>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php endforeach; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p class="footer-links">
                Cybokron v<?= htmlspecialchars($version) ?> |
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank" rel="noopener noreferrer"><?= t('footer.github') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/blob/main/CODE_OF_CONDUCT.md" target="_blank" rel="noopener noreferrer"><?= t('footer.code_of_conduct') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="LICENSE" target="_blank" rel="noopener noreferrer"><?= t('footer.license') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a>
            </p>
        </div>
    </footer>

    <script id="cybokron-rates-data" type="application/json"><?= json_encode($converterRates ?? []) ?></script>
    <script src="assets/js/bootstrap.js" defer></script>
    <script src="assets/js/lib/chart.umd.min.js" defer></script>
    <script src="assets/js/app.js" defer></script>
    <?php if (!empty($converterRates)): ?>
    <script src="assets/js/converter.js" defer></script>
    <?php endif; ?>
    <?php if (!empty($chartCurrencies)): ?>
    <script src="assets/js/chart.js" defer></script>
    <?php endif; ?>
</body>
</html>

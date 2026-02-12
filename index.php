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

$rates = getLatestRates();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$newTabText = t('common.opens_new_tab');

// Group rates by bank
$ratesByBank = [];
foreach ($rates as $rate) {
    $ratesByBank[$rate['bank_slug']][] = $rate;
}

// Unique currencies for chart
$chartCurrencies = array_unique(array_column($rates, 'currency_code'));
sort($chartCurrencies);
$defaultChartCurrency = $chartCurrencies[0] ?? 'USD';

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
foreach ($rates as $r) {
    $code = $r['currency_code'];
    $chg = (float) ($r['change_percent'] ?? 0);
    if (!isset($changeByCurrency[$code]) || abs($chg) > abs($changeByCurrency[$code])) {
        $changeByCurrency[$code] = $chg;
    }
}
uasort($changeByCurrency, fn($a, $b) => abs($b) <=> abs($a));
$topMovers = array_slice(array_keys($changeByCurrency), 0, 5);

$portfolioSummary = null;
if (Auth::check()) {
    $portfolioSummary = Portfolio::getSummary();
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#3b82f6">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <title><?= APP_NAME ?></title>
        <link rel="manifest" href="manifest.json">
        <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <a href="#main-content" class="skip-link"><?= t('common.skip_to_content') ?></a>
    <header class="header">
        <div class="container">
            <h1>💱 <?= APP_NAME ?></h1>
            <nav class="header-nav">
                <a href="index.php" class="active" aria-current="page"><?= t('nav.rates') ?></a>
                <a href="portfolio.php"><?= t('nav.portfolio') ?></a>
                <?php if (Auth::check() && Auth::isAdmin()): ?>
                    <a href="observability.php"><?= t('observability.title') ?></a>
                    <a href="admin.php"><?= t('admin.title') ?></a>
                <?php endif; ?>
                <?php if (Auth::check()): ?>
                    <a href="logout.php" class="lang-link"><?= t('nav.logout') ?></a>
                <?php else: ?>
                    <a href="login.php" class="lang-link"><?= t('nav.login') ?></a>
                <?php endif; ?>
                <button type="button" id="theme-toggle" class="btn-theme" aria-label="<?= t('nav.theme_toggle') ?>" title="<?= t('nav.theme_toggle') ?>" data-label-light="<?= htmlspecialchars(t('theme.switch_to_light')) ?>" data-label-dark="<?= htmlspecialchars(t('theme.switch_to_dark')) ?>">🌙</button>
                <span class="lang-label"><?= t('nav.language') ?>:</span>
                <?php foreach ($availableLocales as $locale): ?>
                    <?php
                        $localeName = t('nav.language_name.' . $locale);
                        if (str_contains($localeName, 'nav.language_name.')) {
                            $localeName = strtoupper($locale);
                        }
                    ?>
                    <a
                        href="<?= htmlspecialchars(buildLocaleUrl($locale)) ?>"
                        class="lang-link <?= $currentLocale === $locale ? 'active' : '' ?>"
                        lang="<?= htmlspecialchars($locale) ?>"
                        hreflang="<?= htmlspecialchars($locale) ?>"
                        aria-label="<?= htmlspecialchars(t('nav.language_switch_to', ['language' => $localeName])) ?>"
                        title="<?= htmlspecialchars(t('nav.language_switch_to', ['language' => $localeName])) ?>"
                        <?= $currentLocale === $locale ? 'aria-current="page"' : '' ?>
                    >
                        <?= htmlspecialchars(strtoupper($locale)) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main id="main-content" class="container">
        <p
            id="rates-refresh-status"
            class="sr-only"
            role="status"
            aria-live="polite"
            data-updated-template="<?= htmlspecialchars(t('index.refresh.status_updated')) ?>"
        >
            <?= t('index.refresh.status_ready') ?>
        </p>

        <?php if (!empty($converterRates) && !empty($converterCurrencies)): ?>
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

        <?php if (!empty($topMovers)): ?>
        <section class="bank-section widgets-section">
            <h2>📊 <?= t('index.widgets.title') ?></h2>
            <div class="widgets-grid">
                <div class="widget-card">
                    <h3><?= t('index.widgets.top_movers') ?></h3>
                    <ul class="widget-list">
                        <?php foreach ($topMovers as $code): ?>
                            <?php $chg = $changeByCurrency[$code] ?? 0; ?>
                            <li class="<?= changeClass($chg) ?>">
                                <span class="currency-code"><?= htmlspecialchars($code) ?></span>
                                <?= changeArrow($chg) ?> %<?= formatNumberLocalized(abs($chg), 2) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php if ($portfolioSummary && !empty($portfolioSummary['items'])): ?>
                <div class="widget-card">
                    <h3><?= t('index.widgets.portfolio_summary') ?></h3>
                    <p class="widget-portfolio-total">
                        <?= t('portfolio.summary.total_cost') ?>: <?= formatTRY((float) ($portfolioSummary['total_cost'] ?? 0)) ?>
                    </p>
                    <p class="widget-portfolio-total">
                        <?= t('portfolio.summary.current_value') ?>: <?= formatTRY((float) ($portfolioSummary['total_value'] ?? 0)) ?>
                    </p>
                    <p class="widget-portfolio-total <?= changeClass((float) ($portfolioSummary['profit_percent'] ?? 0)) ?>">
                        <?= t('portfolio.summary.profit_loss') ?>: %<?= formatNumberLocalized((float) ($portfolioSummary['profit_percent'] ?? 0), 2) ?>
                    </p>
                    <a href="portfolio.php" class="btn btn-sm"><?= t('nav.portfolio') ?> →</a>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($chartCurrencies)): ?>
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
                    <option value="7">7 <?= t('index.chart.days_unit') ?></option>
                    <option value="30" selected>30 <?= t('index.chart.days_unit') ?></option>
                    <option value="90">90 <?= t('index.chart.days_unit') ?></option>
                </select>
            </div>
            <div class="chart-container">
                <canvas id="rate-chart" role="img" aria-label="<?= htmlspecialchars(t('index.chart.aria_label')) ?>" data-label-buy="<?= htmlspecialchars(t('chart.label.buy')) ?>" data-label-sell="<?= htmlspecialchars(t('chart.label.sell')) ?>"></canvas>
            </div>
        </section>
        <?php endif; ?>

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
                                        <span class="currency-name"><?= htmlspecialchars(localizedCurrencyName($rate)) ?></span>
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

    <script>
        window.cybokronRates = <?= json_encode($converterRates ?? []) ?>;
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(function () {});
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/app.js"></script>
    <?php if (!empty($converterRates)): ?>
    <script src="assets/js/converter.js"></script>
    <?php endif; ?>
    <?php if (!empty($chartCurrencies)): ?>
    <script src="assets/js/chart.js"></script>
    <?php endif; ?>
</body>
</html>

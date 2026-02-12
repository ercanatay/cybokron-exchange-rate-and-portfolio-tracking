<?php
/**
 * index.php — Main Dashboard
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();

$rates = getLatestRates();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();

// Group rates by bank
$ratesByBank = [];
foreach ($rates as $rate) {
    $ratesByBank[$rate['bank_slug']][] = $rate;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>💱 <?= APP_NAME ?></h1>
            <nav class="header-nav">
                <a href="index.php" class="active"><?= t('nav.rates') ?></a>
                <a href="portfolio.php"><?= t('nav.portfolio') ?></a>
                <span class="lang-label"><?= t('nav.language') ?>:</span>
                <?php foreach ($availableLocales as $locale): ?>
                    <a
                        href="<?= htmlspecialchars(buildLocaleUrl($locale)) ?>"
                        class="lang-link <?= $currentLocale === $locale ? 'active' : '' ?>"
                    >
                        <?= htmlspecialchars(strtoupper($locale)) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="container">
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
                        <thead>
                            <tr>
                                <th><?= t('index.table.currency') ?></th>
                                <th><?= t('index.table.code') ?></th>
                                <th class="text-right"><?= t('index.table.bank_buy') ?></th>
                                <th class="text-right"><?= t('index.table.bank_sell') ?></th>
                                <th class="text-right"><?= t('index.table.change') ?></th>
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
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank" rel="noopener noreferrer"><?= t('footer.github') ?></a> |
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/blob/main/CODE_OF_CONDUCT.md" target="_blank" rel="noopener noreferrer"><?= t('footer.code_of_conduct') ?></a> |
                <a href="https://www.netlify.com/" target="_blank" rel="noopener noreferrer"><?= t('footer.powered_by_netlify') ?></a>
            </p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>

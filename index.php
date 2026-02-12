<?php
/**
 * index.php — Main Dashboard
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();

$rates = getLatestRates();
$version = trim(file_get_contents(__DIR__ . '/VERSION'));

// Group rates by bank
$ratesByBank = [];
foreach ($rates as $rate) {
    $ratesByBank[$rate['bank_slug']][] = $rate;
}
?>
<!DOCTYPE html>
<html lang="tr">
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
            <nav>
                <a href="index.php" class="active">Kurlar</a>
                <a href="portfolio.php">Portföy</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php foreach ($ratesByBank as $bankSlug => $bankRates): ?>
            <?php $bankName = $bankRates[0]['bank_name'] ?? $bankSlug; ?>
            <section class="bank-section">
                <h2>🏦 <?= htmlspecialchars($bankName) ?></h2>
                <?php if (!empty($bankRates[0]['scraped_at'])): ?>
                    <p class="last-update">Son Güncelleme: <?= date('d.m.Y H:i:s', strtotime($bankRates[0]['scraped_at'])) ?></p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th>Döviz</th>
                                <th>Kod</th>
                                <th class="text-right">Banka Alış</th>
                                <th class="text-right">Banka Satış</th>
                                <th class="text-right">Değişim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bankRates as $rate): ?>
                                <tr>
                                    <td>
                                        <span class="currency-name"><?= htmlspecialchars($rate['currency_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="currency-code"><?= htmlspecialchars($rate['currency_code']) ?></span>
                                    </td>
                                    <td class="text-right mono"><?= formatRate((float)$rate['buy_rate']) ?></td>
                                    <td class="text-right mono"><?= formatRate((float)$rate['sell_rate']) ?></td>
                                    <td class="text-right <?= changeClass((float)($rate['change_percent'] ?? 0)) ?>">
                                        <?= changeArrow((float)($rate['change_percent'] ?? 0)) ?>
                                        % <?= number_format(abs((float)($rate['change_percent'] ?? 0)), 2, ',', '.') ?>
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
                <h2>Henüz kur verisi yok</h2>
                <p>Kurları çekmek için cron job'u çalıştırın:</p>
                <code>php cron/update_rates.php</code>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> &mdash;
            <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank">GitHub</a></p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>

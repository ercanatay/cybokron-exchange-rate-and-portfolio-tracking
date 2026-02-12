<?php
/**
 * Cybokron - Main Dashboard
 */
define('CYBOKRON_ROOT', __DIR__);

$config = require CYBOKRON_ROOT . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once CYBOKRON_ROOT . '/includes/Database.php';
require_once CYBOKRON_ROOT . '/includes/helpers.php';

// Fetch latest rates
$rates = Database::fetchAll("
    SELECT 
        r.buy_rate, r.sell_rate, r.change_percent, r.fetched_at,
        c.code, c.name_tr, c.type,
        b.name AS bank_name, b.slug AS bank_slug
    FROM rates r
    JOIN currencies c ON r.currency_id = c.id
    JOIN banks b ON r.bank_id = b.id
    WHERE b.is_active = 1
    ORDER BY c.type ASC, c.code ASC
");

// Group by type
$fiatRates = array_filter($rates, fn($r) => $r['type'] === 'fiat');
$metalRates = array_filter($rates, fn($r) => $r['type'] === 'precious_metal');

$lastUpdate = !empty($rates) ? $rates[0]['fetched_at'] : null;
$version = trim(file_get_contents(CYBOKRON_ROOT . '/VERSION'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>💱 <?= sanitize($config['app']['name']) ?></h1>
            <nav>
                <a href="index.php" class="active">Kurlar</a>
                <a href="portfolio.php">Portföy</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($lastUpdate): ?>
        <div class="update-info">
            Son Güncelleme: <?= date('d.m.Y H:i:s', strtotime($lastUpdate)) ?>
            <span class="badge"><?= timeAgo($lastUpdate) ?></span>
        </div>
        <?php endif; ?>

        <!-- Fiat Currencies -->
        <section class="rate-section">
            <h2>💵 Döviz Kurları</h2>
            <div class="table-responsive">
                <table class="rate-table">
                    <thead>
                        <tr>
                            <th>Döviz</th>
                            <th>Kod</th>
                            <th class="text-right">Alış</th>
                            <th class="text-right">Satış</th>
                            <th class="text-right">Değişim</th>
                            <th>Kaynak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fiatRates as $rate): ?>
                        <tr>
                            <td>
                                <span class="flag"><?= currencyFlag($rate['code']) ?></span>
                                <?= sanitize($rate['name_tr']) ?>
                            </td>
                            <td><strong><?= sanitize($rate['code']) ?></strong></td>
                            <td class="text-right mono"><?= formatRate($rate['buy_rate']) ?></td>
                            <td class="text-right mono"><?= formatRate($rate['sell_rate']) ?></td>
                            <td class="text-right <?= profitClass($rate['change_percent'] ?? 0) ?>">
                                <?php if ($rate['change_percent'] !== null): ?>
                                    <?= changeIcon($rate['change_percent']) ?>
                                    <?= formatPercent($rate['change_percent']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= sanitize($rate['bank_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fiatRates)): ?>
                        <tr><td colspan="6" class="text-center text-muted">Henüz veri yok. Cron scriptini çalıştırın.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Precious Metals -->
        <section class="rate-section">
            <h2>🥇 Değerli Metaller</h2>
            <div class="table-responsive">
                <table class="rate-table">
                    <thead>
                        <tr>
                            <th>Metal</th>
                            <th>Kod</th>
                            <th class="text-right">Alış</th>
                            <th class="text-right">Satış</th>
                            <th class="text-right">Değişim</th>
                            <th>Kaynak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metalRates as $rate): ?>
                        <tr>
                            <td>
                                <span class="flag"><?= currencyFlag($rate['code']) ?></span>
                                <?= sanitize($rate['name_tr']) ?>
                            </td>
                            <td><strong><?= sanitize($rate['code']) ?></strong></td>
                            <td class="text-right mono"><?= formatRate($rate['buy_rate']) ?></td>
                            <td class="text-right mono"><?= formatRate($rate['sell_rate']) ?></td>
                            <td class="text-right <?= profitClass($rate['change_percent'] ?? 0) ?>">
                                <?php if ($rate['change_percent'] !== null): ?>
                                    <?= changeIcon($rate['change_percent']) ?>
                                    <?= formatPercent($rate['change_percent']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= sanitize($rate['bank_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($metalRates)): ?>
                        <tr><td colspan="6" class="text-center text-muted">Henüz veri yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= sanitize($version) ?> — 
            <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank">GitHub</a></p>
        </div>
    </footer>

    <script src="assets/js/app.js"></script>
</body>
</html>

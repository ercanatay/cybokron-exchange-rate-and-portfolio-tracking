<?php
/**
 * Cybokron - Portfolio Management
 */
define('CYBOKRON_ROOT', __DIR__);

$config = require CYBOKRON_ROOT . '/config.php';
date_default_timezone_set($config['app']['timezone']);

require_once CYBOKRON_ROOT . '/includes/Database.php';
require_once CYBOKRON_ROOT . '/includes/Portfolio.php';
require_once CYBOKRON_ROOT . '/includes/helpers.php';

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        try {
            Portfolio::add(
                $_POST['currency'],
                (float) $_POST['amount'],
                (float) $_POST['buy_rate'],
                $_POST['buy_date'],
                $_POST['bank'] ?? null,
                $_POST['notes'] ?? null
            );
            $message = 'Portföye başarıyla eklendi.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Hata: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && Portfolio::delete($id)) {
            $message = 'Kayıt silindi.';
            $messageType = 'success';
        }
    }
}

// Fetch data
$summary = Portfolio::getSummary();
$currencies = Database::fetchAll("SELECT code, name_tr FROM currencies WHERE is_active = 1 ORDER BY code");
$banks = Database::fetchAll("SELECT slug, name FROM banks WHERE is_active = 1 ORDER BY name");
$version = trim(file_get_contents(CYBOKRON_ROOT . '/VERSION'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portföy — <?= sanitize($config['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>💱 <?= sanitize($config['app']['name']) ?></h1>
            <nav>
                <a href="index.php">Kurlar</a>
                <a href="portfolio.php" class="active">Portföy</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= sanitize($message) ?>
        </div>
        <?php endif; ?>

        <!-- Portfolio Summary -->
        <section class="summary-cards">
            <div class="card">
                <div class="card-label">Toplam Maliyet</div>
                <div class="card-value"><?= formatTRY($summary['total_cost_try']) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Güncel Değer</div>
                <div class="card-value"><?= formatTRY($summary['total_value_try']) ?></div>
            </div>
            <div class="card">
                <div class="card-label">Kâr / Zarar</div>
                <div class="card-value <?= profitClass($summary['profit_loss_try']) ?>">
                    <?= formatTRY($summary['profit_loss_try']) ?>
                    <small>(<?= formatPercent($summary['profit_loss_percent']) ?>)</small>
                </div>
            </div>
            <div class="card">
                <div class="card-label">Pozisyon</div>
                <div class="card-value"><?= $summary['entry_count'] ?></div>
            </div>
        </section>

        <!-- Add New Entry Form -->
        <section class="rate-section">
            <h2>➕ Yeni Pozisyon Ekle</h2>
            <form method="POST" class="portfolio-form">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="currency">Döviz / Metal</label>
                        <select name="currency" id="currency" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($currencies as $c): ?>
                            <option value="<?= sanitize($c['code']) ?>">
                                <?= currencyFlag($c['code']) ?> <?= sanitize($c['code']) ?> — <?= sanitize($c['name_tr']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="amount">Miktar</label>
                        <input type="number" name="amount" id="amount" step="0.000001" min="0" required 
                               placeholder="Ör: 1000">
                    </div>
                    <div class="form-group">
                        <label for="buy_rate">Alış Kuru (₺)</label>
                        <input type="number" name="buy_rate" id="buy_rate" step="0.000001" min="0" required 
                               placeholder="Ör: 36.50">
                    </div>
                    <div class="form-group">
                        <label for="buy_date">Alış Tarihi</label>
                        <input type="date" name="buy_date" id="buy_date" required 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label for="bank">Banka (opsiyonel)</label>
                        <select name="bank" id="bank">
                            <option value="">Belirtilmemiş</option>
                            <?php foreach ($banks as $b): ?>
                            <option value="<?= sanitize($b['slug']) ?>"><?= sanitize($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notes">Not (opsiyonel)</label>
                        <input type="text" name="notes" id="notes" placeholder="Açıklama...">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Portföye Ekle</button>
            </form>
        </section>

        <!-- Portfolio Entries -->
        <section class="rate-section">
            <h2>📋 Portföy Listesi</h2>
            <div class="table-responsive">
                <table class="rate-table">
                    <thead>
                        <tr>
                            <th>Döviz</th>
                            <th class="text-right">Miktar</th>
                            <th class="text-right">Alış Kuru</th>
                            <th class="text-right">Güncel Satış</th>
                            <th class="text-right">Maliyet (₺)</th>
                            <th class="text-right">Değer (₺)</th>
                            <th class="text-right">K/Z (₺)</th>
                            <th class="text-right">K/Z %</th>
                            <th>Tarih</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['entries'] as $entry): ?>
                        <tr>
                            <td>
                                <?= currencyFlag($entry['currency_code']) ?>
                                <strong><?= sanitize($entry['currency_code']) ?></strong>
                            </td>
                            <td class="text-right mono"><?= formatRate($entry['amount'], 2) ?></td>
                            <td class="text-right mono"><?= formatRate($entry['buy_rate']) ?></td>
                            <td class="text-right mono">
                                <?= $entry['current_sell_rate'] ? formatRate($entry['current_sell_rate']) : '—' ?>
                            </td>
                            <td class="text-right mono"><?= formatTRY($entry['cost_try'] ?? 0) ?></td>
                            <td class="text-right mono"><?= formatTRY($entry['current_value_try'] ?? 0) ?></td>
                            <td class="text-right mono <?= profitClass($entry['profit_loss_try'] ?? 0) ?>">
                                <?= formatTRY($entry['profit_loss_try'] ?? 0) ?>
                            </td>
                            <td class="text-right <?= profitClass($entry['profit_loss_percent'] ?? 0) ?>">
                                <?= formatPercent($entry['profit_loss_percent'] ?? 0) ?>
                            </td>
                            <td><?= date('d.m.Y', strtotime($entry['buy_date'])) ?></td>
                            <td>
                                <form method="POST" style="display:inline" 
                                      onsubmit="return confirm('Bu kaydı silmek istediğinize emin misiniz?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $entry['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($summary['entries'])): ?>
                        <tr><td colspan="10" class="text-center text-muted">Portföyünüzde henüz kayıt yok.</td></tr>
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

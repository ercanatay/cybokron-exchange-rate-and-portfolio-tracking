<?php
/**
 * portfolio.php — Portfolio Management Page
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        try {
            Portfolio::add([
                'currency_code' => $_POST['currency_code'] ?? '',
                'bank_slug'     => $_POST['bank_slug'] ?? '',
                'amount'        => $_POST['amount'] ?? 0,
                'buy_rate'      => $_POST['buy_rate'] ?? 0,
                'buy_date'      => $_POST['buy_date'] ?? date('Y-m-d'),
                'notes'         => $_POST['notes'] ?? '',
            ]);
            $message = 'Portföye eklendi.';
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Hata: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        if (Portfolio::delete((int) $_POST['id'])) {
            $message = 'Portföyden silindi.';
            $messageType = 'success';
        } else {
            $message = 'Silinecek kayıt bulunamadı.';
            $messageType = 'error';
        }
    }
}

$summary = Portfolio::getSummary();
$currencies = Database::query("SELECT code, name_tr FROM currencies WHERE is_active = 1 ORDER BY code");
$banks = Database::query("SELECT slug, name FROM banks WHERE is_active = 1 ORDER BY name");
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portföy — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>💱 <?= APP_NAME ?></h1>
            <nav>
                <a href="index.php">Kurlar</a>
                <a href="portfolio.php" class="active">Portföy</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Portfolio Summary -->
        <section class="summary-cards">
            <div class="card">
                <h3>Toplam Maliyet</h3>
                <p class="card-value"><?= formatTRY($summary['total_cost']) ?></p>
            </div>
            <div class="card">
                <h3>Güncel Değer</h3>
                <p class="card-value"><?= formatTRY($summary['total_value']) ?></p>
            </div>
            <div class="card <?= $summary['profit_loss'] >= 0 ? 'card-profit' : 'card-loss' ?>">
                <h3>Kâr / Zarar</h3>
                <p class="card-value">
                    <?= formatTRY($summary['profit_loss']) ?>
                    <small>(% <?= number_format($summary['profit_percent'], 2, ',', '.') ?>)</small>
                </p>
            </div>
        </section>

        <!-- Add to Portfolio Form -->
        <section class="form-section">
            <h2>➕ Portföye Ekle</h2>
            <form method="POST" class="portfolio-form">
                <input type="hidden" name="action" value="add">

                <div class="form-row">
                    <div class="form-group">
                        <label for="currency_code">Döviz</label>
                        <select name="currency_code" id="currency_code" required>
                            <option value="">Seçiniz</option>
                            <?php foreach ($currencies as $c): ?>
                                <option value="<?= htmlspecialchars($c['code']) ?>">
                                    <?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($c['name_tr']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="bank_slug">Banka</label>
                        <select name="bank_slug" id="bank_slug">
                            <option value="">Seçiniz (Opsiyonel)</option>
                            <?php foreach ($banks as $b): ?>
                                <option value="<?= htmlspecialchars($b['slug']) ?>">
                                    <?= htmlspecialchars($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="amount">Miktar</label>
                        <input type="number" name="amount" id="amount" step="0.000001" min="0" required placeholder="1000">
                    </div>

                    <div class="form-group">
                        <label for="buy_rate">Alış Kuru (₺)</label>
                        <input type="number" name="buy_rate" id="buy_rate" step="0.000001" min="0" required placeholder="43.5865">
                    </div>

                    <div class="form-group">
                        <label for="buy_date">Alış Tarihi</label>
                        <input type="date" name="buy_date" id="buy_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">Notlar</label>
                    <input type="text" name="notes" id="notes" placeholder="Opsiyonel not" maxlength="500">
                </div>

                <button type="submit" class="btn btn-primary">Ekle</button>
            </form>
        </section>

        <!-- Portfolio List -->
        <?php if (!empty($summary['items'])): ?>
            <section class="portfolio-section">
                <h2>📋 Portföy (<?= $summary['item_count'] ?> kalem)</h2>
                <div class="table-responsive">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th>Döviz</th>
                                <th class="text-right">Miktar</th>
                                <th class="text-right">Alış Kuru</th>
                                <th class="text-right">Güncel Kur</th>
                                <th class="text-right">Maliyet (₺)</th>
                                <th class="text-right">Değer (₺)</th>
                                <th class="text-right">K/Z (%)</th>
                                <th>Tarih</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary['items'] as $item): ?>
                                <?php $pl = (float) $item['profit_percent']; ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['currency_code']) ?></strong>
                                        <small><?= htmlspecialchars($item['currency_name']) ?></small>
                                    </td>
                                    <td class="text-right mono"><?= formatRate((float)$item['amount']) ?></td>
                                    <td class="text-right mono"><?= formatRate((float)$item['buy_rate']) ?></td>
                                    <td class="text-right mono">
                                        <?= $item['current_rate'] ? formatRate((float)$item['current_rate']) : '—' ?>
                                    </td>
                                    <td class="text-right mono"><?= formatTRY((float)$item['cost_try']) ?></td>
                                    <td class="text-right mono"><?= formatTRY((float)$item['value_try']) ?></td>
                                    <td class="text-right <?= changeClass($pl) ?>">
                                        <?= changeArrow($pl) ?> % <?= number_format(abs($pl), 2, ',', '.') ?>
                                    </td>
                                    <td><?= date('d.m.Y', strtotime($item['buy_date'])) ?></td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">🗑</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <div class="empty-state">
                <h2>Portföyünüz boş</h2>
                <p>Yukarıdaki formu kullanarak döviz/altın ekleyebilirsiniz.</p>
            </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> &mdash;
            <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank">GitHub</a></p>
        </div>
    </footer>
</body>
</html>

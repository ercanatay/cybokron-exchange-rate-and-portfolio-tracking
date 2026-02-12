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
            $message = t('portfolio.message.added');
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = t('portfolio.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        if (Portfolio::delete((int) $_POST['id'])) {
            $message = t('portfolio.message.deleted');
            $messageType = 'success';
        } else {
            $message = t('portfolio.message.not_found');
            $messageType = 'error';
        }
    }
}

$summary = Portfolio::getSummary();
$currencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');
$banks = Database::query('SELECT slug, name FROM banks WHERE is_active = 1 ORDER BY name');
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$deleteConfirmText = json_encode(t('portfolio.table.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('portfolio.page_title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>💱 <?= APP_NAME ?></h1>
            <nav class="header-nav">
                <a href="index.php"><?= t('nav.rates') ?></a>
                <a href="portfolio.php" class="active"><?= t('nav.portfolio') ?></a>
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
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <section class="summary-cards">
            <div class="card">
                <h3><?= t('portfolio.summary.total_cost') ?></h3>
                <p class="card-value"><?= formatTRY((float) $summary['total_cost']) ?></p>
            </div>
            <div class="card">
                <h3><?= t('portfolio.summary.current_value') ?></h3>
                <p class="card-value"><?= formatTRY((float) $summary['total_value']) ?></p>
            </div>
            <div class="card <?= $summary['profit_loss'] >= 0 ? 'card-profit' : 'card-loss' ?>">
                <h3><?= t('portfolio.summary.profit_loss') ?></h3>
                <p class="card-value">
                    <?= formatTRY((float) $summary['profit_loss']) ?>
                    <small>(% <?= formatNumberLocalized((float) $summary['profit_percent'], 2) ?>)</small>
                </p>
            </div>
        </section>

        <section class="form-section">
            <h2>➕ <?= t('portfolio.form.title') ?></h2>
            <form method="POST" class="portfolio-form">
                <input type="hidden" name="action" value="add">

                <div class="form-row">
                    <div class="form-group">
                        <label for="currency_code"><?= t('portfolio.form.currency') ?></label>
                        <select name="currency_code" id="currency_code" required>
                            <option value=""><?= t('portfolio.form.select') ?></option>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?= htmlspecialchars($currency['code']) ?>">
                                    <?= htmlspecialchars($currency['code']) ?> — <?= htmlspecialchars(localizedCurrencyName($currency)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="bank_slug"><?= t('portfolio.form.bank') ?></label>
                        <select name="bank_slug" id="bank_slug">
                            <option value=""><?= t('portfolio.form.select_optional') ?></option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank['slug']) ?>">
                                    <?= htmlspecialchars($bank['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="amount"><?= t('portfolio.form.amount') ?></label>
                        <input type="number" name="amount" id="amount" step="0.000001" min="0" required placeholder="1000">
                    </div>

                    <div class="form-group">
                        <label for="buy_rate"><?= t('portfolio.form.buy_rate') ?></label>
                        <input type="number" name="buy_rate" id="buy_rate" step="0.000001" min="0" required placeholder="43.5865">
                    </div>

                    <div class="form-group">
                        <label for="buy_date"><?= t('portfolio.form.buy_date') ?></label>
                        <input type="date" name="buy_date" id="buy_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes"><?= t('portfolio.form.notes') ?></label>
                    <input type="text" name="notes" id="notes" placeholder="<?= htmlspecialchars(t('portfolio.form.notes_placeholder')) ?>" maxlength="500">
                </div>

                <button type="submit" class="btn btn-primary"><?= t('portfolio.form.submit') ?></button>
            </form>
        </section>

        <?php if (!empty($summary['items'])): ?>
            <section class="portfolio-section">
                <h2>📋 <?= t('portfolio.table.title', ['count' => $summary['item_count']]) ?></h2>
                <div class="table-responsive">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th><?= t('portfolio.table.currency') ?></th>
                                <th class="text-right"><?= t('portfolio.table.amount') ?></th>
                                <th class="text-right"><?= t('portfolio.table.buy_rate') ?></th>
                                <th class="text-right"><?= t('portfolio.table.current_rate') ?></th>
                                <th class="text-right"><?= t('portfolio.table.cost') ?></th>
                                <th class="text-right"><?= t('portfolio.table.value') ?></th>
                                <th class="text-right"><?= t('portfolio.table.pl_percent') ?></th>
                                <th><?= t('portfolio.table.date') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary['items'] as $item): ?>
                                <?php $pl = (float) $item['profit_percent']; ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['currency_code']) ?></strong>
                                        <small><?= htmlspecialchars(localizedCurrencyName($item)) ?></small>
                                    </td>
                                    <td class="text-right mono"><?= formatRate((float) $item['amount']) ?></td>
                                    <td class="text-right mono"><?= formatRate((float) $item['buy_rate']) ?></td>
                                    <td class="text-right mono">
                                        <?= $item['current_rate'] ? formatRate((float) $item['current_rate']) : t('common.not_available') ?>
                                    </td>
                                    <td class="text-right mono"><?= formatTRY((float) $item['cost_try']) ?></td>
                                    <td class="text-right mono"><?= formatTRY((float) $item['value_try']) ?></td>
                                    <td class="text-right <?= changeClass($pl) ?>">
                                        <?= changeArrow($pl) ?> % <?= formatNumberLocalized(abs($pl), 2) ?>
                                    </td>
                                    <td><?= formatDate((string) $item['buy_date']) ?></td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm(<?= $deleteConfirmText ?>)">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
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
                <h2><?= t('portfolio.empty_title') ?></h2>
                <p><?= t('portfolio.empty_desc') ?></p>
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
</body>
</html>

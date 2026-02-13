<?php
/**
 * portfolio.php — Portfolio Management Page
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/PortfolioAnalytics.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();
requirePortfolioAccessForWeb();

// Handle form submissions
$message = '';
$messageType = '';
$fieldErrors = [];
$defaultBuyDate = date('Y-m-d');
$formValues = [
    'currency_code' => '',
    'bank_slug' => '',
    'amount' => '',
    'buy_rate' => '',
    'buy_date' => $defaultBuyDate,
    'notes' => '',
];
$editId = null;
$editItem = null;

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $summary = Portfolio::getSummary();
    foreach ($summary['items'] as $item) {
        if ((int) $item['id'] === $editId) {
            $editItem = $item;
            $formValues = [
                'currency_code' => (string) $item['currency_code'],
                'bank_slug'     => (string) ($item['bank_slug'] ?? ''),
                'amount'        => (string) $item['amount'],
                'buy_rate'      => (string) $item['buy_rate'],
                'buy_date'      => (string) $item['buy_date'],
                'notes'         => (string) ($item['notes'] ?? ''),
            ];
            break;
        }
    }
    if (!$editItem) {
        $editId = null;
    }
}

/**
 * Map portfolio validation exception to a specific form field.
 */
function portfolioFieldFromExceptionMessage(string $message): ?string
{
    $text = strtolower($message);

    if (str_contains($text, 'currency_code') || str_contains($text, 'currency')) {
        return 'currency_code';
    }
    if (str_contains($text, 'bank_slug') || str_contains($text, 'bank')) {
        return 'bank_slug';
    }
    if (str_contains($text, 'amount')) {
        return 'amount';
    }
    if (str_contains($text, 'buy_rate')) {
        return 'buy_rate';
    }
    if (str_contains($text, 'buy_date')) {
        return 'buy_date';
    }
    if (str_contains($text, 'notes')) {
        return 'notes';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $rawBuyDate = trim((string) ($_POST['buy_date'] ?? $defaultBuyDate));
        
        // Convert DD.MM.YYYY or DD/MM/YYYY to YYYY-MM-DD
        if (preg_match('/^(\d{2})[\.\/](\d{2})[\.\/](\d{4})$/', $rawBuyDate, $matches)) {
            $rawBuyDate = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        
        $formValues = [
            'currency_code' => trim((string) ($_POST['currency_code'] ?? '')),
            'bank_slug' => trim((string) ($_POST['bank_slug'] ?? '')),
            'amount' => trim((string) ($_POST['amount'] ?? '')),
            'buy_rate' => trim((string) ($_POST['buy_rate'] ?? '')),
            'buy_date' => $rawBuyDate,
            'notes' => trim((string) ($_POST['notes'] ?? '')),
        ];

        if ($formValues['buy_date'] === '') {
            $formValues['buy_date'] = $defaultBuyDate;
        }
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = t('common.invalid_request');
        $messageType = 'error';
    }

    if ($messageType === '' && $action === 'add') {
        try {
            Portfolio::add([
                'currency_code' => $formValues['currency_code'],
                'bank_slug'     => $formValues['bank_slug'],
                'amount'        => $formValues['amount'],
                'buy_rate'      => $formValues['buy_rate'],
                'buy_date'      => $formValues['buy_date'],
                'notes'         => $formValues['notes'],
            ]);
            $message = t('portfolio.message.added');
            $messageType = 'success';
            $formValues = [
                'currency_code' => '',
                'bank_slug' => '',
                'amount' => '',
                'buy_rate' => '',
                'buy_date' => $defaultBuyDate,
                'notes' => '',
            ];
        } catch (Throwable $e) {
            $isClientError = $e instanceof InvalidArgumentException;

            // Security: avoid leaking exception details into user-visible responses.
            cybokron_log(
                sprintf(
                    'Portfolio action failed action=%s code=%d detail=%s',
                    $action,
                    $isClientError ? 400 : 500,
                    $e->getMessage()
                ),
                'ERROR'
            );

            if ($isClientError) {
                $field = portfolioFieldFromExceptionMessage($e->getMessage());
                if ($field !== null) {
                    $fieldErrors[$field] = t('portfolio.form.error.' . $field);
                    $message = t('portfolio.form.error.' . $field);
                } else {
                    $message = t('common.invalid_request');
                }
            } else {
                $message = t('portfolio.message.error_generic');
            }
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'edit' && !empty($_POST['id'])) {
        try {
            $id = (int) $_POST['id'];
            $rawBuyDate = trim((string) ($_POST['buy_date'] ?? ''));
            
            // Convert DD.MM.YYYY or DD/MM/YYYY to YYYY-MM-DD
            if (preg_match('/^(\d{2})[\.\/](\d{2})[\.\/](\d{4})$/', $rawBuyDate, $matches)) {
                $rawBuyDate = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
            }
            
            $updateData = [
                'amount'   => trim((string) ($_POST['amount'] ?? '')),
                'buy_rate' => trim((string) ($_POST['buy_rate'] ?? '')),
                'buy_date' => $rawBuyDate,
                'notes'    => trim((string) ($_POST['notes'] ?? '')),
            ];
            if ($updateData['buy_date'] === '') {
                $updateData['buy_date'] = $defaultBuyDate;
            }
            if (Portfolio::update($id, $updateData)) {
                $message = t('portfolio.message.updated');
                $messageType = 'success';
                $editId = null;
                $editItem = null;
            } else {
                $message = t('portfolio.message.not_found');
                $messageType = 'error';
            }
        } catch (Throwable $e) {
            $isClientError = $e instanceof InvalidArgumentException;
            cybokron_log(sprintf('Portfolio edit failed id=%d detail=%s', (int) $_POST['id'], $e->getMessage()), 'ERROR');
            $message = $isClientError ? t('common.invalid_request') : t('portfolio.message.error_generic');
            $messageType = 'error';
            if ($e instanceof InvalidArgumentException) {
                $field = portfolioFieldFromExceptionMessage($e->getMessage());
                if ($field !== null) {
                    $fieldErrors[$field] = t('portfolio.form.error.' . $field);
                }
            }
        }
    }

    if ($messageType === '' && $action === 'delete' && !empty($_POST['id'])) {
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
$distribution = !empty($summary['items']) ? PortfolioAnalytics::getDistribution($summary['items']) : [];
$oldestDate = !empty($summary['items']) ? PortfolioAnalytics::getOldestDate($summary['items']) : null;
$annualizedReturn = ($oldestDate && $summary['total_cost'] > 0)
    ? PortfolioAnalytics::annualizedReturn(
        (float) $summary['total_cost'],
        (float) $summary['total_value'],
        $oldestDate
    )
    : null;
$currencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');
$banks = Database::query('SELECT slug, name FROM banks WHERE is_active = 1 ORDER BY name');
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$newTabText = t('common.opens_new_tab');
$deleteConfirmText = json_encode(t('portfolio.table.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfToken = getCsrfToken();
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
    <a href="#main-content" class="skip-link"><?= t('common.skip_to_content') ?></a>
    <header class="header">
        <div class="container">
            <h1>💱 <?= APP_NAME ?></h1>
            <nav class="header-nav">
                <a href="index.php"><?= t('nav.rates') ?></a>
                <a href="portfolio.php" class="active" aria-current="page"><?= t('nav.portfolio') ?></a>
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
        <?php if ($message): ?>
            <?php $isErrorMessage = $messageType === 'error'; ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $isErrorMessage ? 'alert' : 'status' ?>" aria-live="<?= $isErrorMessage ? 'assertive' : 'polite' ?>"><?= htmlspecialchars($message) ?></div>
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

        <section id="form-section" class="form-section">
            <h2><?= $editItem ? '✏️ ' . t('portfolio.form.edit_title') : '➕ ' . t('portfolio.form.title') ?></h2>
            <form method="POST" class="portfolio-form">
                <input type="hidden" name="action" value="<?= $editItem ? 'edit' : 'add' ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="id" value="<?= (int) $editItem['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <?php $currencyError = $fieldErrors['currency_code'] ?? ''; ?>
                        <label for="currency_code"><?= t('portfolio.form.currency') ?></label>
                        <select name="currency_code" id="currency_code" required <?= $currencyError !== '' ? 'aria-invalid="true" aria-describedby="currency_code-error"' : '' ?> <?= $editItem ? 'disabled' : '' ?>>
                            <option value=""><?= t('portfolio.form.select') ?></option>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?= htmlspecialchars($currency['code']) ?>" <?= $formValues['currency_code'] === (string) $currency['code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($currency['code']) ?> — <?= htmlspecialchars(localizedCurrencyName($currency)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($currencyError !== ''): ?>
                            <small id="currency_code-error" class="field-error"><?= htmlspecialchars($currencyError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $bankError = $fieldErrors['bank_slug'] ?? ''; ?>
                        <label for="bank_slug"><?= t('portfolio.form.bank') ?></label>
                        <select name="bank_slug" id="bank_slug" <?= $bankError !== '' ? 'aria-invalid="true" aria-describedby="bank_slug-error"' : '' ?> <?= $editItem ? 'disabled' : '' ?>>
                            <option value=""><?= t('portfolio.form.select_optional') ?></option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank['slug']) ?>" <?= $formValues['bank_slug'] === (string) $bank['slug'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($bank['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($bankError !== ''): ?>
                            <small id="bank_slug-error" class="field-error"><?= htmlspecialchars($bankError) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?php $amountError = $fieldErrors['amount'] ?? ''; ?>
                        <label for="amount"><?= t('portfolio.form.amount') ?></label>
                        <input type="number" name="amount" id="amount" step="0.000001" min="0" required placeholder="1000" value="<?= htmlspecialchars($formValues['amount']) ?>" <?= $amountError !== '' ? 'aria-invalid="true" aria-describedby="amount-error"' : '' ?>>
                        <?php if ($amountError !== ''): ?>
                            <small id="amount-error" class="field-error"><?= htmlspecialchars($amountError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $buyRateError = $fieldErrors['buy_rate'] ?? ''; ?>
                        <label for="buy_rate"><?= t('portfolio.form.buy_rate') ?></label>
                        <input type="number" name="buy_rate" id="buy_rate" step="0.000001" min="0" required placeholder="43.5865" value="<?= htmlspecialchars($formValues['buy_rate']) ?>" <?= $buyRateError !== '' ? 'aria-invalid="true" aria-describedby="buy_rate-error"' : '' ?>>
                        <?php if ($buyRateError !== ''): ?>
                            <small id="buy_rate-error" class="field-error"><?= htmlspecialchars($buyRateError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $buyDateError = $fieldErrors['buy_date'] ?? ''; ?>
                        <label for="buy_date"><?= t('portfolio.form.buy_date') ?></label>
                        <input type="date" name="buy_date" id="buy_date" value="<?= htmlspecialchars($formValues['buy_date']) ?>" required <?= $buyDateError !== '' ? 'aria-invalid="true" aria-describedby="buy_date-error"' : '' ?>>
                        <?php if ($buyDateError !== ''): ?>
                            <small id="buy_date-error" class="field-error"><?= htmlspecialchars($buyDateError) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <?php $notesError = $fieldErrors['notes'] ?? ''; ?>
                    <label for="notes"><?= t('portfolio.form.notes') ?></label>
                    <input type="text" name="notes" id="notes" placeholder="<?= htmlspecialchars(t('portfolio.form.notes_placeholder')) ?>" maxlength="500" value="<?= htmlspecialchars($formValues['notes']) ?>" <?= $notesError !== '' ? 'aria-invalid="true" aria-describedby="notes-error"' : '' ?>>
                    <?php if ($notesError !== ''): ?>
                        <small id="notes-error" class="field-error"><?= htmlspecialchars($notesError) ?></small>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary"><?= $editItem ? t('portfolio.form.update') : t('portfolio.form.submit') ?></button>
                <?php if ($editItem): ?>
                    <a href="portfolio.php" class="btn btn-secondary"><?= t('portfolio.form.cancel') ?></a>
                <?php endif; ?>
            </form>
        </section>

        <?php if (!empty($distribution) || $annualizedReturn !== null): ?>
        <section class="portfolio-analytics bank-section">
            <h2>📊 <?= t('portfolio.analytics.title') ?></h2>
            <div class="analytics-grid">
                <?php if (!empty($distribution)): ?>
                <div class="analytics-card">
                    <h3><?= t('portfolio.analytics.distribution') ?></h3>
                    <div class="chart-container" style="height: 220px;">
                        <canvas id="portfolio-pie-chart" role="img" aria-label="<?= htmlspecialchars(t('portfolio.analytics.distribution')) ?>"></canvas>
                    </div>
                    <script>
                        window.portfolioDistribution = <?= json_encode($distribution) ?>;
                    </script>
                </div>
                <?php endif; ?>
                <?php if ($annualizedReturn !== null): ?>
                <div class="analytics-card">
                    <h3><?= t('portfolio.analytics.annualized_return') ?></h3>
                    <p class="analytics-value <?= changeClass($annualizedReturn * 100) ?>">
                        <?= formatNumberLocalized($annualizedReturn * 100, 2) ?>% <?= t('portfolio.analytics.per_year') ?>
                    </p>
                    <p class="analytics-note"><?= t('portfolio.analytics.approximation') ?></p>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($summary['items'])): ?>
            <section class="portfolio-section">
                <h2>📋 <?= t('portfolio.table.title', ['count' => $summary['item_count']]) ?></h2>
                <p class="portfolio-export-link">
                    <a href="portfolio_export.php" class="btn btn-secondary" download><?= t('portfolio.export_csv') ?></a>
                </p>
                <div class="table-responsive">
                    <table class="rates-table">
                        <caption class="sr-only"><?= htmlspecialchars(t('portfolio.table.caption')) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= t('portfolio.table.currency') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.amount') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.buy_rate') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.current_rate') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.cost') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.value') ?></th>
                                <th scope="col" class="text-right"><?= t('portfolio.table.pl_percent') ?></th>
                                <th scope="col"><?= t('portfolio.table.date') ?></th>
                                <th scope="col"><?= t('portfolio.table.actions') ?></th>
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
                                        <a href="portfolio.php?edit=<?= (int) $item['id'] ?>#form-section" class="btn btn-sm btn-secondary" aria-label="<?= htmlspecialchars(t('portfolio.table.edit_action', ['currency' => (string) $item['currency_code']])) ?>" title="<?= htmlspecialchars(t('portfolio.table.edit_action', ['currency' => (string) $item['currency_code']])) ?>">✏️</a>
                                        <form method="POST" style="display:inline" onsubmit="return confirm(<?= $deleteConfirmText ?>)">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <?php $deleteActionLabel = t('portfolio.table.delete_action', ['currency' => (string) $item['currency_code']]); ?>
                                            <button type="submit" class="btn btn-sm btn-danger" aria-label="<?= htmlspecialchars($deleteActionLabel) ?>" title="<?= htmlspecialchars($deleteActionLabel) ?>">🗑</button>
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
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank" rel="noopener noreferrer"><?= t('footer.github') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/blob/main/CODE_OF_CONDUCT.md" target="_blank" rel="noopener noreferrer"><?= t('footer.code_of_conduct') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="LICENSE" target="_blank" rel="noopener noreferrer"><?= t('footer.license') ?><span class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a>
            </p>
        </div>
    </footer>
    <script src="assets/js/theme.js"></script>
    <?php if (!empty($distribution)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/portfolio-analytics.js"></script>
    <?php endif; ?>
</body>
</html>

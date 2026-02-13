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
    'group_id' => '',
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
                'bank_slug' => (string) ($item['bank_slug'] ?? ''),
                'amount' => (string) $item['amount'],
                'buy_rate' => (string) $item['buy_rate'],
                'buy_date' => (string) $item['buy_date'],
                'notes' => (string) ($item['notes'] ?? ''),
                'group_id' => (string) ($item['group_id'] ?? ''),
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
            'group_id' => trim((string) ($_POST['group_id'] ?? '')),
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
                'bank_slug' => $formValues['bank_slug'],
                'amount' => $formValues['amount'],
                'buy_rate' => $formValues['buy_rate'],
                'buy_date' => $formValues['buy_date'],
                'notes' => $formValues['notes'],
                'group_id' => $formValues['group_id'],
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
                'group_id' => '',
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
                'amount' => trim((string) ($_POST['amount'] ?? '')),
                'buy_rate' => trim((string) ($_POST['buy_rate'] ?? '')),
                'buy_date' => $rawBuyDate,
                'notes' => trim((string) ($_POST['notes'] ?? '')),
                'bank_slug' => trim((string) ($_POST['bank_slug'] ?? '')),
                'group_id' => trim((string) ($_POST['group_id'] ?? '')),
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

    // Group actions
    if ($messageType === '' && $action === 'add_group') {
        try {
            Portfolio::addGroup([
                'name' => trim((string) ($_POST['group_name'] ?? '')),
                'color' => trim((string) ($_POST['group_color'] ?? '#3b82f6')),
                'icon' => trim((string) ($_POST['group_icon'] ?? '')),
            ]);
            $message = t('portfolio.groups.added');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Group add failed: ' . $e->getMessage(), 'ERROR');
            $message = t('portfolio.groups.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'edit_group' && !empty($_POST['group_id'])) {
        try {
            Portfolio::updateGroup((int) $_POST['group_id'], [
                'name' => trim((string) ($_POST['group_name'] ?? '')),
                'color' => trim((string) ($_POST['group_color'] ?? '')),
                'icon' => trim((string) ($_POST['group_icon'] ?? '')),
            ]);
            $message = t('portfolio.groups.updated');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Group edit failed: ' . $e->getMessage(), 'ERROR');
            $message = t('portfolio.groups.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'delete_group' && !empty($_POST['group_id'])) {
        if (Portfolio::deleteGroup((int) $_POST['group_id'])) {
            $message = t('portfolio.groups.deleted');
            $messageType = 'success';
        } else {
            $message = t('portfolio.groups.error');
            $messageType = 'error';
        }
    }
}

$summary = Portfolio::getSummary();
$groups = Portfolio::getGroups();
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
$deleteGroupConfirmText = json_encode(t('portfolio.groups.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfToken = getCsrfToken();

// Filters
$filterGroup = $_GET['group'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$hasFilters = ($filterGroup !== '' || $filterDateFrom !== '' || $filterDateTo !== '');

// Apply filters to items
$filteredItems = $summary['items'];
if ($filterGroup !== '') {
    if ($filterGroup === 'none') {
        $filteredItems = array_filter($filteredItems, fn($item) => empty($item['group_id']));
    } else {
        $gid = (int) $filterGroup;
        $filteredItems = array_filter($filteredItems, fn($item) => (int) ($item['group_id'] ?? 0) === $gid);
    }
}
if ($filterDateFrom !== '') {
    $filteredItems = array_filter($filteredItems, fn($item) => $item['buy_date'] >= $filterDateFrom);
}
if ($filterDateTo !== '') {
    $filteredItems = array_filter($filteredItems, fn($item) => $item['buy_date'] <= $filterDateTo);
}
$filteredItems = array_values($filteredItems);

// Compute filtered totals
$filteredTotalCost = 0.0;
$filteredTotalValue = 0.0;
foreach ($filteredItems as $fi) {
    $filteredTotalCost += (float) $fi['cost_try'];
    $filteredTotalValue += (float) $fi['value_try'];
}
$filteredProfitLoss = $filteredTotalValue - $filteredTotalCost;
$filteredProfitPercent = $filteredTotalCost > 0 ? ($filteredProfitLoss / $filteredTotalCost * 100) : 0;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('portfolio.page_title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body>
    <a href="#main-content" class="skip-link"><?= t('common.skip_to_content') ?></a>
    <?php $activePage = 'portfolio';
    include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <?php $isErrorMessage = $messageType === 'error'; ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $isErrorMessage ? 'alert' : 'status' ?>"
                aria-live="<?= $isErrorMessage ? 'assertive' : 'polite' ?>"><?= htmlspecialchars($message) ?></div>
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

        <!-- Group Management Panel -->
        <section class="groups-section" id="groups-section">
            <div class="groups-header">
                <h2>🏷️ <?= t('portfolio.groups.title') ?></h2>
                <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('group-form-panel').classList.toggle('hidden')">
                    <?= t('portfolio.groups.add') ?>
                </button>
            </div>

            <div id="group-form-panel" class="group-form-panel hidden">
                <form method="POST" class="group-form">
                    <input type="hidden" name="action" value="add_group">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="group-form-row">
                        <input type="text" name="group_name" placeholder="<?= htmlspecialchars(t('portfolio.groups.name')) ?>" maxlength="100" required class="group-name-input">
                        <input type="color" name="group_color" value="#3b82f6" class="group-color-input" title="<?= htmlspecialchars(t('portfolio.groups.color')) ?>">
                        <input type="text" name="group_icon" placeholder="<?= htmlspecialchars(t('portfolio.groups.icon')) ?> (emoji)" maxlength="10" class="group-icon-input">
                        <button type="submit" class="btn btn-primary btn-sm"><?= t('portfolio.groups.add') ?></button>
                    </div>
                </form>
            </div>

            <?php if (!empty($groups)): ?>
                <div class="groups-grid">
                    <?php foreach ($groups as $group): ?>
                        <div class="group-card" style="--group-color: <?= htmlspecialchars($group['color']) ?>">
                            <div class="group-card-header">
                                <span class="group-badge" style="background: <?= htmlspecialchars($group['color']) ?>">
                                    <?php if ($group['icon']): ?>
                                        <span class="group-icon"><?= htmlspecialchars($group['icon']) ?></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($group['name']) ?>
                                </span>
                                <span class="group-count"><?= t('portfolio.groups.items', ['count' => (int) $group['item_count']]) ?></span>
                            </div>
                            <div class="group-card-actions">
                                <button type="button" class="btn btn-xs btn-secondary" onclick="toggleEditGroup(<?= (int) $group['id'] ?>)">✏️</button>
                                <form method="POST" style="display:inline" onsubmit="return confirm(<?= $deleteGroupConfirmText ?>)">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-danger">🗑</button>
                                </form>
                            </div>
                            <form method="POST" class="group-edit-form hidden" id="edit-group-<?= (int) $group['id'] ?>">
                                <input type="hidden" name="action" value="edit_group">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                <div class="group-form-row">
                                    <input type="text" name="group_name" value="<?= htmlspecialchars($group['name']) ?>" maxlength="100" required class="group-name-input">
                                    <input type="color" name="group_color" value="<?= htmlspecialchars($group['color']) ?>" class="group-color-input">
                                    <input type="text" name="group_icon" value="<?= htmlspecialchars($group['icon'] ?? '') ?>" placeholder="emoji" maxlength="10" class="group-icon-input">
                                    <button type="submit" class="btn btn-primary btn-xs">💾</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                                <option value="<?= htmlspecialchars($currency['code']) ?>"
                                    <?= $formValues['currency_code'] === (string) $currency['code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($currency['code']) ?> —
                                    <?= htmlspecialchars(localizedCurrencyName($currency)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($currencyError !== ''): ?>
                            <small id="currency_code-error"
                                class="field-error"><?= htmlspecialchars($currencyError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $bankError = $fieldErrors['bank_slug'] ?? ''; ?>
                        <label for="bank_slug"><?= t('portfolio.form.bank') ?></label>
                        <select name="bank_slug" id="bank_slug" <?= $bankError !== '' ? 'aria-invalid="true" aria-describedby="bank_slug-error"' : '' ?>>
                            <option value=""><?= t('portfolio.form.select_optional') ?></option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= htmlspecialchars($bank['slug']) ?>"
                                    <?= $formValues['bank_slug'] === (string) $bank['slug'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($bank['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($bankError !== ''): ?>
                            <small id="bank_slug-error" class="field-error"><?= htmlspecialchars($bankError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="group_id"><?= t('portfolio.form.group') ?></label>
                        <select name="group_id" id="group_id">
                            <option value=""><?= t('portfolio.form.group_optional') ?></option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= (int) $group['id'] ?>"
                                    <?= $formValues['group_id'] == (string) $group['id'] ? 'selected' : '' ?>>
                                    <?= $group['icon'] ? htmlspecialchars($group['icon']) . ' ' : '' ?><?= htmlspecialchars($group['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?php $amountError = $fieldErrors['amount'] ?? ''; ?>
                        <label for="amount"><?= t('portfolio.form.amount') ?></label>
                        <input type="number" name="amount" id="amount" step="0.000001" min="0" required
                            placeholder="1000" value="<?= htmlspecialchars($formValues['amount']) ?>" <?= $amountError !== '' ? 'aria-invalid="true" aria-describedby="amount-error"' : '' ?>>
                        <?php if ($amountError !== ''): ?>
                            <small id="amount-error" class="field-error"><?= htmlspecialchars($amountError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $buyRateError = $fieldErrors['buy_rate'] ?? ''; ?>
                        <label for="buy_rate"><?= t('portfolio.form.buy_rate') ?></label>
                        <input type="number" name="buy_rate" id="buy_rate" step="0.000001" min="0" required
                            placeholder="43.5865" value="<?= htmlspecialchars($formValues['buy_rate']) ?>"
                            <?= $buyRateError !== '' ? 'aria-invalid="true" aria-describedby="buy_rate-error"' : '' ?>>
                        <?php if ($buyRateError !== ''): ?>
                            <small id="buy_rate-error" class="field-error"><?= htmlspecialchars($buyRateError) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <?php $buyDateError = $fieldErrors['buy_date'] ?? ''; ?>
                        <label for="buy_date"><?= t('portfolio.form.buy_date') ?></label>
                        <input type="date" name="buy_date" id="buy_date"
                            value="<?= htmlspecialchars($formValues['buy_date']) ?>" required <?= $buyDateError !== '' ? 'aria-invalid="true" aria-describedby="buy_date-error"' : '' ?>>
                        <?php if ($buyDateError !== ''): ?>
                            <small id="buy_date-error" class="field-error"><?= htmlspecialchars($buyDateError) ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <?php $notesError = $fieldErrors['notes'] ?? ''; ?>
                    <label for="notes"><?= t('portfolio.form.notes') ?></label>
                    <input type="text" name="notes" id="notes"
                        placeholder="<?= htmlspecialchars(t('portfolio.form.notes_placeholder')) ?>" maxlength="500"
                        value="<?= htmlspecialchars($formValues['notes']) ?>" <?= $notesError !== '' ? 'aria-invalid="true" aria-describedby="notes-error"' : '' ?>>
                    <?php if ($notesError !== ''): ?>
                        <small id="notes-error" class="field-error"><?= htmlspecialchars($notesError) ?></small>
                    <?php endif; ?>
                </div>

                <button type="submit"
                    class="btn btn-primary"><?= $editItem ? t('portfolio.form.update') : t('portfolio.form.submit') ?></button>
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
                                <canvas id="portfolio-pie-chart" role="img"
                                    aria-label="<?= htmlspecialchars(t('portfolio.analytics.distribution')) ?>"></canvas>
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
                                <?= formatNumberLocalized($annualizedReturn * 100, 2) ?>%
                                <?= t('portfolio.analytics.per_year') ?>
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

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label><?= t('portfolio.filter.group') ?></label>
                            <div class="filter-pills">
                                <a href="?<?= http_build_query(array_diff_key($_GET, ['group' => ''])) ?>"
                                   class="filter-pill <?= $filterGroup === '' ? 'active' : '' ?>"><?= t('portfolio.groups.all') ?></a>
                                <?php foreach ($groups as $group): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['group' => $group['id']])) ?>"
                                       class="filter-pill <?= $filterGroup == (string) $group['id'] ? 'active' : '' ?>"
                                       style="--pill-color: <?= htmlspecialchars($group['color']) ?>">
                                        <?= $group['icon'] ? htmlspecialchars($group['icon']) . ' ' : '' ?><?= htmlspecialchars($group['name']) ?>
                                    </a>
                                <?php endforeach; ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['group' => 'none'])) ?>"
                                   class="filter-pill <?= $filterGroup === 'none' ? 'active' : '' ?>"><?= t('portfolio.groups.no_group') ?></a>
                            </div>
                        </div>
                        <div class="filter-dates">
                            <div class="filter-date-field">
                                <label for="date_from"><?= t('portfolio.filter.date_from') ?></label>
                                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                            </div>
                            <div class="filter-date-field">
                                <label for="date_to"><?= t('portfolio.filter.date_to') ?></label>
                                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                            </div>
                            <?php if ($filterGroup !== ''): ?>
                                <input type="hidden" name="group" value="<?= htmlspecialchars($filterGroup) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-primary"><?= t('portfolio.filter.apply') ?></button>
                            <?php if ($hasFilters): ?>
                                <a href="portfolio.php" class="btn btn-sm btn-secondary"><?= t('portfolio.filter.clear') ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($hasFilters): ?>
                        <div class="filter-summary">
                            <span class="filter-result-count"><?= count($filteredItems) ?> / <?= $summary['item_count'] ?></span>
                            <span class="filter-result-total">
                                <?= formatTRY($filteredTotalCost) ?> → <?= formatTRY($filteredTotalValue) ?>
                                <span class="<?= changeClass($filteredProfitLoss) ?>">
                                    (<?= $filteredProfitLoss >= 0 ? '+' : '' ?><?= formatTRY($filteredProfitLoss) ?>, %<?= formatNumberLocalized(abs($filteredProfitPercent), 2) ?>)
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="portfolio-export-link">
                    <a href="portfolio_export.php" class="btn btn-secondary" download><?= t('portfolio.export_csv') ?></a>
                </p>
                <div class="table-responsive">
                    <table class="rates-table">
                        <caption class="sr-only"><?= htmlspecialchars(t('portfolio.table.caption')) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= t('portfolio.table.currency') ?></th>
                                <th scope="col"><?= t('portfolio.table.group') ?></th>
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
                            <?php foreach ($filteredItems as $item): ?>
                                <?php $pl = (float) $item['profit_percent']; ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['currency_code']) ?></strong>
                                        <small><?= htmlspecialchars(localizedCurrencyName($item)) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['group_name'])): ?>
                                            <span class="group-badge-sm" style="background: <?= htmlspecialchars($item['group_color'] ?? '#666') ?>">
                                                <?php if ($item['group_icon']): ?><span class="group-icon-sm"><?= htmlspecialchars($item['group_icon']) ?></span><?php endif; ?>
                                                <?= htmlspecialchars($item['group_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
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
                                        <a href="portfolio.php?edit=<?= (int) $item['id'] ?>#form-section"
                                            class="btn btn-sm btn-secondary"
                                            aria-label="<?= htmlspecialchars(t('portfolio.table.edit_action', ['currency' => (string) $item['currency_code']])) ?>"
                                            title="<?= htmlspecialchars(t('portfolio.table.edit_action', ['currency' => (string) $item['currency_code']])) ?>">✏️</a>
                                        <form method="POST" style="display:inline"
                                            onsubmit="return confirm(<?= $deleteConfirmText ?>)">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <?php $deleteActionLabel = t('portfolio.table.delete_action', ['currency' => (string) $item['currency_code']]); ?>
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                aria-label="<?= htmlspecialchars($deleteActionLabel) ?>"
                                                title="<?= htmlspecialchars($deleteActionLabel) ?>">🗑</button>
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
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking" target="_blank"
                    rel="noopener noreferrer"><?= t('footer.github') ?><span class="sr-only">
                        <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="https://github.com/ercanatay/cybokron-exchange-rate-and-portfolio-tracking/blob/main/CODE_OF_CONDUCT.md"
                    target="_blank" rel="noopener noreferrer"><?= t('footer.code_of_conduct') ?><span class="sr-only">
                        <?= htmlspecialchars($newTabText) ?></span></a> |
                <a href="LICENSE" target="_blank" rel="noopener noreferrer"><?= t('footer.license') ?><span
                        class="sr-only"> <?= htmlspecialchars($newTabText) ?></span></a>
            </p>
        </div>
    </footer>
    <script src="assets/js/theme.js"></script>
    <script>
    function toggleEditGroup(id) {
        var el = document.getElementById('edit-group-' + id);
        if (el) el.classList.toggle('hidden');
    }
    </script>
    <?php if (!empty($distribution)): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
        <script src="assets/js/portfolio-analytics.js"></script>
    <?php endif; ?>
</body>

</html>
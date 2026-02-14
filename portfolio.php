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
            $lastInsertedId = Portfolio::add([
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

    // Bulk group assign
    if ($messageType === '' && $action === 'bulk_assign_group') {
        $selectedIds = $_POST['selected_ids'] ?? [];
        $bulkGroupId = $_POST['bulk_group_id'] ?? '';
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $gid = $bulkGroupId !== '' ? (int) $bulkGroupId : null;
            $count = Portfolio::bulkAssignGroup($selectedIds, $gid);
            $message = t('portfolio.bulk.success', ['count' => $count]);
            $messageType = 'success';
        } else {
            $message = t('portfolio.bulk.no_selection');
            $messageType = 'error';
        }
    }

    // Bulk group remove
    if ($messageType === '' && $action === 'bulk_remove_group') {
        $selectedIds = $_POST['selected_ids'] ?? [];
        if (!empty($selectedIds) && is_array($selectedIds)) {
            $count = Portfolio::bulkAssignGroup($selectedIds, null);
            $message = t('portfolio.bulk.success', ['count' => $count]);
            $messageType = 'success';
        } else {
            $message = t('portfolio.bulk.no_selection');
            $messageType = 'error';
        }
    }

    // Tag actions
    if ($messageType === '' && $action === 'add_tag') {
        try {
            Portfolio::addTag([
                'name' => trim((string) ($_POST['tag_name'] ?? '')),
                'color' => trim((string) ($_POST['tag_color'] ?? '#8b5cf6')),
            ]);
            $message = t('portfolio.tags.added');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Tag add failed: ' . $e->getMessage(), 'ERROR');
            $message = t('portfolio.tags.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'edit_tag' && !empty($_POST['tag_id'])) {
        try {
            Portfolio::updateTag((int) $_POST['tag_id'], [
                'name' => trim((string) ($_POST['tag_name'] ?? '')),
                'color' => trim((string) ($_POST['tag_color'] ?? '')),
            ]);
            $message = t('portfolio.tags.updated');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Tag edit failed: ' . $e->getMessage(), 'ERROR');
            $message = t('portfolio.tags.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'delete_tag' && !empty($_POST['tag_id'])) {
        if (Portfolio::deleteTag((int) $_POST['tag_id'])) {
            $message = t('portfolio.tags.deleted');
            $messageType = 'success';
        } else {
            $message = t('portfolio.tags.error');
            $messageType = 'error';
        }
    }

    // Inline tag assign (from table row)
    if ($messageType === '' && $action === 'inline_assign_tag') {
        $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($portfolioId > 0 && $tagId > 0 && Portfolio::assignTag($portfolioId, $tagId)) {
            $message = t('portfolio.tags.assigned');
            $messageType = 'success';
        } else {
            $message = t('portfolio.tags.error');
            $messageType = 'error';
        }
    }

    // Inline tag remove (from table row)
    if ($messageType === '' && $action === 'inline_remove_tag') {
        $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($portfolioId > 0 && $tagId > 0 && Portfolio::removeTag($portfolioId, $tagId)) {
            $message = t('portfolio.tags.removed');
            $messageType = 'success';
        } else {
            $message = t('portfolio.tags.error');
            $messageType = 'error';
        }
    }

    // Bulk tag assign
    if ($messageType === '' && $action === 'bulk_assign_tag') {
        $selectedIds = $_POST['selected_ids'] ?? [];
        $bulkTagId = (int) ($_POST['bulk_tag_id'] ?? 0);
        if (!empty($selectedIds) && is_array($selectedIds) && $bulkTagId > 0) {
            $count = Portfolio::bulkAssignTag($selectedIds, $bulkTagId);
            $message = t('portfolio.bulk.success', ['count' => $count]);
            $messageType = 'success';
        } else {
            $message = t('portfolio.bulk.no_selection');
            $messageType = 'error';
        }
    }

    // Bulk tag remove
    if ($messageType === '' && $action === 'bulk_remove_tag') {
        $selectedIds = $_POST['selected_ids'] ?? [];
        $bulkTagId = (int) ($_POST['bulk_tag_id'] ?? 0);
        if (!empty($selectedIds) && is_array($selectedIds) && $bulkTagId > 0) {
            $count = Portfolio::bulkRemoveTag($selectedIds, $bulkTagId);
            $message = t('portfolio.bulk.success', ['count' => $count]);
            $messageType = 'success';
        } else {
            $message = t('portfolio.bulk.no_selection');
            $messageType = 'error';
        }
    }

    // Assign tags during add/edit
    if ($messageType === 'success' && ($action === 'add' || $action === 'edit')) {
        $tagIds = $_POST['tag_ids'] ?? [];
        if (!empty($tagIds) && is_array($tagIds)) {
            // Get the item id (for add, use the returned ID; for edit, from POST)
            $itemId = $action === 'add'
                ? (int) ($lastInsertedId ?? 0)
                : (int) $_POST['id'];
            if ($itemId > 0) {
                foreach ($tagIds as $tid) {
                    Portfolio::assignTag($itemId, (int) $tid);
                }
            }
        }
    }

    // ── Goal Actions (all require valid CSRF) ──
    if ($messageType === '' && $action === 'add_goal') {
        try {
            $goalId = Portfolio::addGoal([
                'name' => $_POST['goal_name'] ?? '',
                'target_value' => $_POST['goal_target_value'] ?? 0,
                'target_type' => $_POST['goal_target_type'] ?? 'value',
                'target_currency' => $_POST['goal_target_currency'] ?? '',
                'bank_slug' => $_POST['goal_bank_slug'] ?? '',
                'percent_date_mode' => $_POST['goal_percent_date_mode'] ?? 'all',
                'percent_date_start' => $_POST['goal_percent_date_start'] ?? '',
                'percent_date_end' => $_POST['goal_percent_date_end'] ?? '',
                'percent_period_months' => $_POST['goal_percent_period_months'] ?? 12,
                'goal_deadline' => $_POST['goal_deadline'] ?? '',
            ]);
            // Add sources if provided
            $srcTypes = $_POST['goal_source_type'] ?? [];
            $srcIds = $_POST['goal_source_id'] ?? [];
            if (is_array($srcTypes) && is_array($srcIds)) {
                foreach ($srcTypes as $i => $st) {
                    if (isset($srcIds[$i]) && (int) $srcIds[$i] > 0) {
                        Portfolio::addGoalSource($goalId, $st, (int) $srcIds[$i]);
                    }
                }
            }
            $message = t('portfolio.goals.added');
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'edit_goal') {
        try {
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            Portfolio::updateGoal($goalId, [
                'name' => $_POST['goal_name'] ?? '',
                'target_value' => $_POST['goal_target_value'] ?? 0,
                'target_type' => $_POST['goal_target_type'] ?? 'value',
                'target_currency' => $_POST['goal_target_currency'] ?? '',
                'bank_slug' => $_POST['goal_bank_slug'] ?? '',
                'percent_date_mode' => $_POST['goal_percent_date_mode'] ?? 'all',
                'percent_date_start' => $_POST['goal_percent_date_start'] ?? '',
                'percent_date_end' => $_POST['goal_percent_date_end'] ?? '',
                'percent_period_months' => $_POST['goal_percent_period_months'] ?? 12,
                'goal_deadline' => $_POST['goal_deadline'] ?? '',
            ]);
            // Re-sync sources: remove all then add
            $existingSources = Portfolio::getGoalSources($goalId);
            foreach ($existingSources as $es) {
                Portfolio::removeGoalSource($goalId, $es['source_type'], (int) $es['source_id']);
            }
            $srcTypes = $_POST['goal_source_type'] ?? [];
            $srcIds = $_POST['goal_source_id'] ?? [];
            if (is_array($srcTypes) && is_array($srcIds)) {
                foreach ($srcTypes as $i => $st) {
                    if (isset($srcIds[$i]) && (int) $srcIds[$i] > 0) {
                        Portfolio::addGoalSource($goalId, $st, (int) $srcIds[$i]);
                    }
                }
            }
            $message = t('portfolio.goals.updated');
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'delete_goal') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        if ($goalId > 0 && Portfolio::deleteGoal($goalId)) {
            $message = t('portfolio.goals.deleted');
            $messageType = 'success';
        } else {
            $message = t('portfolio.goals.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'toggle_goal_favorite') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        if ($goalId > 0 && Portfolio::toggleGoalFavorite($goalId)) {
            $message = t('portfolio.goals.favorite_toggled');
            $messageType = 'success';
        } else {
            $message = t('portfolio.goals.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'add_goal_source') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $srcType = $_POST['source_type'] ?? '';
        $srcId = (int) ($_POST['source_id'] ?? 0);
        if ($goalId > 0 && Portfolio::addGoalSource($goalId, $srcType, $srcId)) {
            $message = t('portfolio.goals.source_added');
            $messageType = 'success';
        } else {
            $message = t('portfolio.goals.error');
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'remove_goal_source') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $srcType = $_POST['source_type'] ?? '';
        $srcId = (int) ($_POST['source_id'] ?? 0);
        if ($goalId > 0 && Portfolio::removeGoalSource($goalId, $srcType, $srcId)) {
            $message = t('portfolio.goals.source_removed');
            $messageType = 'success';
        } else {
            $message = t('portfolio.goals.error');
            $messageType = 'error';
        }
    }
}

$summary = Portfolio::getSummary();
$groups = Portfolio::getGroups();
$tags = Portfolio::getTags();
$itemTags = Portfolio::getAllItemTags();
$goals = Portfolio::getGoals();
$goalSources = Portfolio::getAllGoalSources();
// Build currency sell rates map for currency_value goals (code => sell_rate in TRY)
$currencyRatesMap = [];
$latestRates = getLatestRates(null, null, true);
foreach ($latestRates as $lr) {
    $code = strtoupper($lr['currency_code'] ?? '');
    $sell = (float) ($lr['sell_rate'] ?? 0);
    // Keep highest sell rate per currency (across banks)
    if ($code !== '' && $sell > 0 && (!isset($currencyRatesMap[$code]) || $sell > $currencyRatesMap[$code])) {
        $currencyRatesMap[$code] = $sell;
    }
}
$goalProgress = Portfolio::computeGoalProgress($goals, $summary['items'] ?? [], $itemTags, $goalSources, $currencyRatesMap);
// Distribution & annualized return will be recalculated after filters are applied
$currencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');
$banks = Database::query('SELECT slug, name FROM banks WHERE is_active = 1 ORDER BY name');
$version = trim(file_get_contents(__DIR__ . '/VERSION'));
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$newTabText = t('common.opens_new_tab');
$deleteConfirmText = json_encode(t('portfolio.table.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfToken = getCsrfToken();

// Build delete confirm messages with item counts
$groupDeleteConfirms = [];
foreach ($groups as $g) {
    $groupDeleteConfirms[(int) $g['id']] = t('portfolio.groups.delete_confirm_count', ['count' => (int) $g['item_count']]);
}
$tagDeleteConfirms = [];
foreach ($tags as $tag) {
    $tagDeleteConfirms[(int) $tag['id']] = t('portfolio.tags.delete_confirm_count', ['count' => (int) $tag['item_count']]);
}

// Filters
$filterGroup = $_GET['group'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterTag = $_GET['tag'] ?? '';
$filterCurrencyType = $_GET['currency_type'] ?? '';
$hasFilters = ($filterGroup !== '' || $filterDateFrom !== '' || $filterDateTo !== '' || $filterTag !== '' || $filterCurrencyType !== '');

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
// Currency type filter (precious_metal = gold/silver, fiat = currencies only)
if ($filterCurrencyType === 'precious_metal') {
    $filteredItems = array_filter($filteredItems, fn($item) => ($item['currency_type'] ?? '') === 'precious_metal');
} elseif ($filterCurrencyType === 'fiat') {
    $filteredItems = array_filter($filteredItems, fn($item) => ($item['currency_type'] ?? '') === 'fiat');
}

// Tag filter
if ($filterTag !== '') {
    if ($filterTag === 'none') {
        $filteredItems = array_filter($filteredItems, function ($item) use ($itemTags) {
            return empty($itemTags[(int) $item['id']]);
        });
    } else {
        $tagId = (int) $filterTag;
        $filteredItems = array_filter($filteredItems, function ($item) use ($itemTags, $tagId) {
            $tags = $itemTags[(int) $item['id']] ?? [];
            foreach ($tags as $t) {
                if ((int) $t['id'] === $tagId)
                    return true;
            }
            return false;
        });
    }
}
$filteredItems = array_values($filteredItems);

// Compute group analytics when group filter active
$groupAnalytics = null;
if ($filterGroup !== '' && $filterGroup !== 'none') {
    $gCost = 0.0;
    $gValue = 0.0;
    foreach ($filteredItems as $fi) {
        $gCost += (float) $fi['cost_try'];
        $gValue += (float) $fi['value_try'];
    }
    $gPl = $gValue - $gCost;
    $gPlPercent = $gCost > 0 ? ($gPl / $gCost * 100) : 0;
    $gName = '';
    foreach ($groups as $g) {
        if ((int) $g['id'] === (int) $filterGroup) {
            $gName = ($g['icon'] ? $g['icon'] . ' ' : '') . $g['name'];
            break;
        }
    }
    $groupAnalytics = [
        'name' => $gName,
        'cost' => $gCost,
        'value' => $gValue,
        'pl' => $gPl,
        'pl_percent' => $gPlPercent,
    ];
}

// Compute filtered totals
$filteredTotalCost = 0.0;
$filteredTotalValue = 0.0;
foreach ($filteredItems as $fi) {
    $filteredTotalCost += (float) $fi['cost_try'];
    $filteredTotalValue += (float) $fi['value_try'];
}
$filteredProfitLoss = $filteredTotalValue - $filteredTotalCost;
$filteredProfitPercent = $filteredTotalCost > 0 ? ($filteredProfitLoss / $filteredTotalCost * 100) : 0;

// Compute distribution & annualized return based on filtered items when filter active
$analyticsItems = $hasFilters ? $filteredItems : ($summary['items'] ?? []);
$distribution = !empty($analyticsItems) ? PortfolioAnalytics::getDistribution($analyticsItems) : [];
$oldestDate = !empty($analyticsItems) ? PortfolioAnalytics::getOldestDate($analyticsItems) : null;
$analyticsCost = $hasFilters ? $filteredTotalCost : (float) $summary['total_cost'];
$analyticsValue = $hasFilters ? $filteredTotalValue : (float) $summary['total_value'];
$annualizedReturn = ($oldestDate && $analyticsCost > 0)
    ? PortfolioAnalytics::annualizedReturn($analyticsCost, $analyticsValue, $oldestDate)
    : null;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('portfolio.page_title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('portfolio.page_title') . ' — ' . APP_NAME,
    'description' => t('seo.portfolio_description'),
    'page' => 'portfolio.php',
]) ?>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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

        <!-- Combined Groups & Tags Management Panel -->
        <section class="manage-panel" id="manage-panel">
            <div class="manage-panel-header" onclick="toggleManagePanel()">
                <h2>📦 <?= t('portfolio.manage.title') ?> <span class="manage-toggle-icon">▼</span></h2>
            </div>
            <div class="manage-panel-body">
                <div class="manage-tabs">
                    <button type="button" class="manage-tab active" data-tab="groups-tab"
                        onclick="switchManageTab('groups-tab')">
                        📦 <?= t('portfolio.manage.tab_groups') ?>
                        <span class="manage-tab-badge"><?= count($groups) ?></span>
                    </button>
                    <button type="button" class="manage-tab" data-tab="tags-tab" onclick="switchManageTab('tags-tab')">
                        🏷️ <?= t('portfolio.manage.tab_tags') ?>
                        <span class="manage-tab-badge"><?= count($tags) ?></span>
                    </button>
                    <button type="button" class="manage-tab" data-tab="goals-tab" onclick="switchManageTab('goals-tab')">
                        🎯 <?= t('portfolio.manage.tab_goals') ?>
                        <span class="manage-tab-badge"><?= count($goals) ?></span>
                    </button>
                </div>

                <!-- === Groups Tab === -->
                <div class="manage-tab-content active" id="groups-tab">
                    <div class="manage-tab-actions">
                        <span></span>
                        <button type="button" class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('group-form-panel').classList.toggle('hidden')">
                            <?= t('portfolio.groups.add') ?>
                        </button>
                    </div>
                    <div id="group-form-panel" class="group-form-panel hidden">
                        <form method="POST" class="group-form">
                            <input type="hidden" name="action" value="add_group">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="group-form-row">
                                <input type="text" name="group_name"
                                    placeholder="<?= htmlspecialchars(t('portfolio.groups.name')) ?>" maxlength="100"
                                    required class="group-name-input">
                                <input type="color" name="group_color" value="#3b82f6" class="group-color-input"
                                    title="<?= htmlspecialchars(t('portfolio.groups.color')) ?>">
                                <input type="text" name="group_icon"
                                    placeholder="<?= htmlspecialchars(t('portfolio.groups.icon_placeholder')) ?>"
                                    maxlength="10" class="group-icon-input">
                                <button type="submit"
                                    class="btn btn-primary btn-sm"><?= t('portfolio.groups.add') ?></button>
                            </div>
                        </form>
                    </div>
                    <?php if (!empty($groups)): ?>
                        <div class="groups-grid">
                            <?php foreach ($groups as $group): ?>
                                <div class="group-card" style="--group-color: <?= htmlspecialchars($group['color']) ?>">
                                    <div class="group-card-header">
                                        <div class="group-card-info">
                                            <span class="group-badge" style="background: <?= htmlspecialchars($group['color']) ?>">
                                                <?php if ($group['icon']): ?>
                                                    <span class="group-icon"><?= htmlspecialchars($group['icon']) ?></span>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($group['name']) ?>
                                            </span>
                                            <a href="?group=<?= (int) $group['id'] ?>"
                                                class="group-count-link"><?= t('portfolio.groups.items', ['count' => (int) $group['item_count']]) ?></a>
                                        </div>
                                        <div class="group-card-actions">
                                            <button type="button" class="btn btn-xs btn-secondary"
                                                onclick="toggleEditGroup(<?= (int) $group['id'] ?>)">✏️</button>
                                            <form method="POST" style="display:inline" data-confirm-type="group"
                                                data-item-count="<?= (int) $group['item_count'] ?>"
                                                data-item-name="<?= htmlspecialchars($group['name']) ?>"
                                                onsubmit="return confirmDeleteWithCount(this, 'group')">
                                                <input type="hidden" name="action" value="delete_group">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-danger" aria-label="<?= t('common.delete') ?>">🗑</button>
                                            </form>
                                        </div>
                                    </div>
                                    <form method="POST" class="group-edit-form hidden"
                                        id="edit-group-<?= (int) $group['id'] ?>">
                                        <input type="hidden" name="action" value="edit_group">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                        <div class="group-form-row">
                                            <input type="text" name="group_name" value="<?= htmlspecialchars($group['name']) ?>"
                                                maxlength="100" required class="group-name-input">
                                            <input type="color" name="group_color"
                                                value="<?= htmlspecialchars($group['color']) ?>" class="group-color-input">
                                            <input type="text" name="group_icon"
                                                value="<?= htmlspecialchars($group['icon'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('portfolio.groups.icon_placeholder')) ?>"
                                                maxlength="10" class="group-icon-input">
                                            <button type="submit" class="btn btn-primary btn-xs" aria-label="<?= t('common.save') ?>">💾</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="manage-empty">
                            <span class="manage-empty-icon">📦</span>
                            <?= t('portfolio.groups.empty') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- === Tags Tab === -->
                <div class="manage-tab-content" id="tags-tab">
                    <div class="manage-tab-actions">
                        <span></span>
                        <button type="button" class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('tag-form-panel').classList.toggle('hidden')">
                            <?= t('portfolio.tags.add') ?>
                        </button>
                    </div>
                    <div id="tag-form-panel" class="group-form-panel hidden">
                        <form method="POST" class="group-form">
                            <input type="hidden" name="action" value="add_tag">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="group-form-row">
                                <input type="text" name="tag_name"
                                    placeholder="<?= htmlspecialchars(t('portfolio.tags.name')) ?>" maxlength="50"
                                    required class="group-name-input">
                                <input type="color" name="tag_color" value="#8b5cf6" class="group-color-input"
                                    title="<?= htmlspecialchars(t('portfolio.tags.color')) ?>">
                                <button type="submit"
                                    class="btn btn-primary btn-sm"><?= t('portfolio.tags.add') ?></button>
                            </div>
                        </form>
                    </div>
                    <?php if (!empty($tags)): ?>
                        <div class="tags-pills-list">
                            <?php foreach ($tags as $tag): ?>
                                <div class="tag-card" style="--tag-color: <?= htmlspecialchars($tag['color']) ?>">
                                    <span class="tag-pill" style="background: <?= htmlspecialchars($tag['color']) ?>">
                                        <?= htmlspecialchars($tag['name']) ?>
                                    </span>
                                    <a href="?tag=<?= (int) $tag['id'] ?>"
                                        class="tag-count-link"><?= t('portfolio.tags.items', ['count' => (int) $tag['item_count']]) ?></a>
                                    <div class="tag-card-actions" role="group">
                                        <button type="button" class="btn btn-xs btn-secondary"
                                            onclick="toggleEditTag(<?= (int) $tag['id'] ?>)">✏️</button>
                                        <form method="POST" style="display:inline" data-confirm-type="tag"
                                            data-item-count="<?= (int) $tag['item_count'] ?>"
                                            data-item-name="<?= htmlspecialchars($tag['name']) ?>"
                                            onsubmit="return confirmDeleteWithCount(this, 'tag')">
                                            <input type="hidden" name="action" value="delete_tag">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-danger" aria-label="<?= t('common.delete') ?>">🗑</button>
                                        </form>
                                    </div>
                                    <form method="POST" class="group-edit-form hidden" id="edit-tag-<?= (int) $tag['id'] ?>">
                                        <input type="hidden" name="action" value="edit_tag">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                                        <div class="group-form-row">
                                            <input type="text" name="tag_name" value="<?= htmlspecialchars($tag['name']) ?>"
                                                maxlength="50" required class="group-name-input">
                                            <input type="color" name="tag_color" value="<?= htmlspecialchars($tag['color']) ?>"
                                                class="group-color-input">
                                            <button type="submit" class="btn btn-primary btn-xs" aria-label="<?= t('common.save') ?>">💾</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="manage-empty">
                            <span class="manage-empty-icon">🏷️</span>
                            <?= t('portfolio.tags.empty') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- === Goals Tab === -->
                <div class="manage-tab-content" id="goals-tab">
                    <div class="manage-tab-actions">
                        <span></span>
                        <button type="button" class="btn btn-sm btn-secondary"
                            onclick="document.getElementById('goal-form-panel').classList.toggle('hidden')">
                            <?= t('portfolio.goals.add') ?>
                        </button>
                    </div>
                    <div id="goal-form-panel" class="group-form-panel hidden">
                        <form method="POST" class="goal-form" id="goal-add-form">
                            <input type="hidden" name="action" value="add_goal">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="goal-form-grid goal-form-grid-4">
                                <div class="goal-form-field">
                                    <label><?= t('portfolio.goals.name') ?></label>
                                    <input type="text" name="goal_name" placeholder="<?= htmlspecialchars(t('portfolio.goals.name_placeholder')) ?>" maxlength="100" required>
                                </div>
                                <div class="goal-form-field">
                                    <label><?= t('portfolio.goals.target_type') ?></label>
                                    <select name="goal_target_type" onchange="goalTypeChanged(this, 'add')">
                                        <option value="value"><?= t('portfolio.goals.type_value') ?></option>
                                        <option value="cost"><?= t('portfolio.goals.type_cost') ?></option>
                                        <option value="amount"><?= t('portfolio.goals.type_amount') ?></option>
                                        <option value="currency_value"><?= t('portfolio.goals.type_currency_value') ?></option>
                                        <option value="percent"><?= t('portfolio.goals.type_percent') ?></option>
                                        <option value="cagr"><?= t('portfolio.goals.type_cagr') ?></option>
                                        <option value="drawdown"><?= t('portfolio.goals.type_drawdown') ?></option>
                                    </select>
                                </div>
                                <div class="goal-form-field goal-currency-field" id="goal-currency-add" style="display:none">
                                    <label><?= t('portfolio.goals.currency') ?></label>
                                    <select name="goal_target_currency">
                                        <option value=""><?= t('portfolio.goals.select_currency') ?></option>
                                        <?php foreach ($currencies as $c): ?>
                                            <option value="<?= htmlspecialchars($c['code']) ?>"><?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars(localizedCurrencyName($c)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="goal-form-field goal-percent-fields" id="goal-percent-add" style="display:none">
                                    <label><?= t('portfolio.goals.percent_date_mode') ?></label>
                                    <select name="goal_percent_date_mode" onchange="percentModeChanged(this, 'add')">
                                        <option value="all"><?= t('portfolio.goals.percent_mode_all') ?></option>
                                        <option value="range"><?= t('portfolio.goals.percent_mode_range') ?></option>
                                        <option value="since_first"><?= t('portfolio.goals.percent_mode_since_first') ?></option>
                                        <option value="weighted"><?= t('portfolio.goals.percent_mode_weighted') ?></option>
                                    </select>
                                </div>
                                <div class="goal-form-field goal-percent-range" id="goal-percent-range-add" style="display:none">
                                    <label><?= t('portfolio.goals.percent_date_start') ?></label>
                                    <input type="date" name="goal_percent_date_start">
                                </div>
                                <div class="goal-form-field goal-percent-range" id="goal-percent-range-end-add" style="display:none">
                                    <label><?= t('portfolio.goals.percent_date_end') ?></label>
                                    <input type="date" name="goal_percent_date_end">
                                </div>
                                <div class="goal-form-field goal-percent-period" id="goal-percent-period-add" style="display:none">
                                    <label><?= t('portfolio.goals.percent_period') ?></label>
                                    <input type="number" name="goal_percent_period_months" value="12" min="1" max="120">
                                </div>
                                <div class="goal-form-field">
                                    <label><?= t('portfolio.goals.bank') ?></label>
                                    <select name="goal_bank_slug">
                                        <option value=""><?= t('portfolio.goals.all_banks') ?></option>
                                        <?php foreach ($banks as $bank): ?>
                                            <option value="<?= htmlspecialchars($bank['slug']) ?>"><?= htmlspecialchars($bank['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="goal-form-field">
                                    <label id="goal-target-label-add"><?= t('portfolio.goals.target_value') ?></label>
                                    <input type="number" name="goal_target_value" step="0.000001" min="0.000001" required placeholder="500000">
                                </div>
                                <div class="goal-form-field goal-deadline-field" id="goal-deadline-add" style="display:none">
                                    <label><?= t('portfolio.goals.deadline') ?></label>
                                    <select name="goal_deadline_preset" onchange="deadlinePresetChanged(this, 'add')">
                                        <option value=""><?= t('portfolio.goals.deadline_none') ?></option>
                                        <option value="1m"><?= t('portfolio.goals.deadline_1m') ?></option>
                                        <option value="3m"><?= t('portfolio.goals.deadline_3m') ?></option>
                                        <option value="6m"><?= t('portfolio.goals.deadline_6m') ?></option>
                                        <option value="9m"><?= t('portfolio.goals.deadline_9m') ?></option>
                                        <option value="1y"><?= t('portfolio.goals.deadline_1y') ?></option>
                                        <option value="custom"><?= t('portfolio.goals.deadline_custom') ?></option>
                                    </select>
                                    <input type="date" name="goal_deadline" id="goal-deadline-date-add" style="display:none; margin-top:4px">
                                </div>
                            </div>
                            <div class="goal-sources-section">
                                <label><?= t('portfolio.goals.sources') ?></label>
                                <div id="goal-sources-list"></div>
                                <div class="goal-source-add">
                                    <select id="goal-source-type-select" class="goal-source-select">
                                        <option value="group">📦 <?= t('portfolio.manage.tab_groups') ?></option>
                                        <option value="tag">🏷️ <?= t('portfolio.manage.tab_tags') ?></option>
                                        <option value="item">📋 <?= t('portfolio.goals.source_item') ?></option>
                                    </select>
                                    <select id="goal-source-id-select" class="goal-source-select">
                                        <?php foreach ($groups as $g): ?>
                                            <option value="<?= (int)$g['id'] ?>" data-type="group"><?= htmlspecialchars($g['icon'] ? $g['icon'] . ' ' : '') ?><?= htmlspecialchars($g['name']) ?></option>
                                        <?php endforeach; ?>
                                        <?php foreach ($tags as $tag): ?>
                                            <option value="<?= (int)$tag['id'] ?>" data-type="tag" style="display:none"><?= htmlspecialchars($tag['name']) ?></option>
                                        <?php endforeach; ?>
                                        <?php foreach ($summary['items'] ?? [] as $pItem): ?>
                                            <option value="<?= (int)$pItem['id'] ?>" data-type="item" style="display:none">
                                                <?= htmlspecialchars($pItem['currency_code']) ?> — <?= formatNumberLocalized((float)$pItem['amount'], 4) ?> (<?= $pItem['buy_date'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addGoalSource()">
                                        ➕ <?= t('common.add') ?>
                                    </button>
                                </div>
                            </div>
                            <div class="goal-form-actions">
                                <button type="submit" class="btn btn-primary btn-sm"><?= t('portfolio.goals.add') ?></button>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($goals)):
                        // Collect unique currencies used in goals for the filter dropdown
                        $goalCurrenciesUsed = [];
                        foreach ($goals as $g) {
                            $tc = $g['target_currency'] ?? '';
                            if ($tc !== '') $goalCurrenciesUsed[$tc] = true;
                        }
                        ksort($goalCurrenciesUsed);
                    ?>
                        <div class="goal-filter-bar">
                            <button type="button" class="goal-filter-btn" data-filter="favorites" onclick="toggleGoalFilter('favorites')">
                                ⭐ <?= t('portfolio.goals.favorites') ?>
                            </button>
                            <button type="button" class="goal-filter-btn" data-filter="group" onclick="toggleGoalFilter('group')">
                                📦 <?= t('portfolio.goals.filter_group') ?>
                            </button>
                            <button type="button" class="goal-filter-btn" data-filter="tag" onclick="toggleGoalFilter('tag')">
                                🏷️ <?= t('portfolio.goals.filter_tag') ?>
                            </button>
                            <select class="goal-filter-select" onchange="filterGoalsByCurrency(this.value)">
                                <option value=""><?= t('portfolio.goals.filter_all_currencies') ?></option>
                                <?php foreach ($goalCurrenciesUsed as $cur => $_): ?>
                                    <option value="<?= htmlspecialchars($cur) ?>"><?= htmlspecialchars($cur) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="goal-filter-btn goal-filter-clear hidden" onclick="clearGoalFilters()">
                                ✕ <?= t('portfolio.goals.filter_clear') ?>
                            </button>
                        </div>

                        <div class="goals-list">
                            <?php foreach ($goals as $goal):
                                $gp = $goalProgress[(int)$goal['id']] ?? ['current' => 0, 'target' => 0, 'percent' => 0, 'item_count' => 0, 'unit' => '₺', 'target_type' => 'value', 'target_currency' => null];
                                $pct = $gp['percent'];
                                $barColor = $pct >= 100 ? '#22c55e' : ($pct >= 50 ? '#eab308' : '#9ca3af');
                                $gSources = $goalSources[(int)$goal['id']] ?? [];
                                $goalTargetType = $goal['target_type'] ?? 'value';
                                $isAmountGoal = $goalTargetType === 'amount';
                                $isCurrencyValueGoal = $goalTargetType === 'currency_value';
                                $isPercentGoal = $goalTargetType === 'percent';
                                $isCagrGoal = $goalTargetType === 'cagr';
                                $isDrawdownGoal = $goalTargetType === 'drawdown';
                                $hasCurrencyUnit = ($isAmountGoal || $isCurrencyValueGoal);
                                $goalCurrency = $goal['target_currency'] ?? '';
                                $goalBankSlug = $goal['bank_slug'] ?? '';
                                $goalBankName = '';
                                if ($goalBankSlug) {
                                    foreach ($banks as $bk) {
                                        if ($bk['slug'] === $goalBankSlug) {
                                            $goalBankName = $bk['name'];
                                            break;
                                        }
                                    }
                                }
                            ?>
                                <div class="goal-card<?= $isDrawdownGoal ? ' goal-card-drawdown' : '' ?>"
                                     data-favorite="<?= !empty($goal['is_favorite']) ? '1' : '0' ?>"
                                     data-source-types="<?= htmlspecialchars(implode(',', array_unique(array_column($gSources, 'source_type')))) ?>"
                                     data-currencies="<?= htmlspecialchars($goal['target_currency'] ?? '') ?>"
                                     data-goal-type="<?= htmlspecialchars($goal['target_type'] ?? 'value') ?>">
                                    <div class="goal-card-body">
                                        <div class="goal-card-header">
                                            <div class="goal-card-info">
                                                <div class="goal-name-row">
                                                    <form method="POST" style="display:inline">
                                                        <input type="hidden" name="action" value="toggle_goal_favorite">
                                                        <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <button type="submit" class="goal-favorite-btn<?= !empty($goal['is_favorite']) ? ' active' : '' ?>"
                                                            aria-label="<?= !empty($goal['is_favorite']) ? t('portfolio.goals.favorite_remove') : t('portfolio.goals.favorite_add') ?>" title="<?= !empty($goal['is_favorite']) ? t('portfolio.goals.favorite_remove') : t('portfolio.goals.favorite_add') ?>">
                                                            <?= !empty($goal['is_favorite']) ? '★' : '☆' ?>
                                                        </button>
                                                    </form>
                                                    <span class="goal-name"><?= htmlspecialchars($goal['name']) ?></span>
                                                </div>
                                                <span class="goal-meta">
                                                    <?= t('portfolio.goals.type_' . ($goal['target_type'] ?? 'value')) ?>
                                                    <?php if ($isPercentGoal && !empty($goal['percent_date_mode'])): ?>
                                                        <span class="goal-percent-mode-badge"><?= t('portfolio.goals.percent_mode_' . $goal['percent_date_mode']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($hasCurrencyUnit && $goalCurrency): ?>
                                                        <span class="goal-currency-badge"><?= htmlspecialchars($goalCurrency) ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($goalBankName): ?>
                                                        <span class="goal-bank-badge">🏦 <?= htmlspecialchars($goalBankName) ?></span>
                                                    <?php endif; ?>
                                                    · <?= $gp['item_count'] ?> <?= t('portfolio.goals.items') ?>
                                                </span>
                                            </div>
                                            <div class="goal-card-actions">
                                                <button type="button" class="btn btn-xs btn-secondary"
                                                    onclick="toggleEditGoal(<?= (int)$goal['id'] ?>)">✏️</button>
                                                <form method="POST" style="display:inline"
                                                    onsubmit="return confirm('<?= htmlspecialchars(t('portfolio.goals.delete_confirm'), ENT_QUOTES) ?>')">
                                                    <input type="hidden" name="action" value="delete_goal">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-danger" aria-label="<?= t('common.delete') ?>">🗑</button>
                                                </form>
                                            </div>
                                        </div>
                                        <div class="goal-progress-section">
                                            <?php
                                                // Show period dropdown for non-date goals
                                                $showPeriodDropdown = !($isPercentGoal && in_array($goal['percent_date_mode'] ?? 'all', ['range', 'since_first']));
                                                $goalDeadline = $goal['goal_deadline'] ?? null;
                                                $deadlineMonths = $gp['deadline_months'] ?? null;
                                            ?>
                                            <?php if ($showPeriodDropdown || $goalDeadline): ?>
                                                <div class="goal-card-extras">
                                                    <?php if ($showPeriodDropdown): ?>
                                                        <select class="goal-period-select" data-goal-id="<?= (int)$goal['id'] ?>" onchange="goalPeriodChanged(this)">
                                                            <option value=""><?= t('portfolio.goals.period_all') ?></option>
                                                            <option value="7d"><?= t('portfolio.goals.period_7d') ?></option>
                                                            <option value="14d"><?= t('portfolio.goals.period_14d') ?></option>
                                                            <option value="1m"><?= t('portfolio.goals.period_1m') ?></option>
                                                            <option value="3m"><?= t('portfolio.goals.period_3m') ?></option>
                                                            <option value="6m"><?= t('portfolio.goals.period_6m') ?></option>
                                                            <option value="9m"><?= t('portfolio.goals.period_9m') ?></option>
                                                            <option value="1y"><?= t('portfolio.goals.period_1y') ?></option>
                                                        </select>
                                                    <?php endif; ?>
                                                    <?php if ($goalDeadline): ?>
                                                        <span class="goal-deadline-badge<?= $deadlineMonths === 0 ? ' goal-deadline-expired' : '' ?>">
                                                            <?php if ($deadlineMonths === 0): ?>
                                                                ⚠️ <?= t('portfolio.goals.deadline_expired') ?>
                                                            <?php else: ?>
                                                                ⏳ <?= str_replace(':months', (string)$deadlineMonths, t('portfolio.goals.deadline_remaining')) ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="goal-progress" id="goal-progress-<?= (int)$goal['id'] ?>">
                                                <div class="goal-progress-bar" style="width: <?= $pct ?>%;"></div>
                                            </div>
                                            <div class="goal-progress-stats" id="goal-stats-<?= (int)$goal['id'] ?>">
                                                <span class="goal-current<?= $pct >= 100 ? ' goal-complete' : '' ?><?= $isDrawdownGoal && $gp['current'] > 0 ? ' goal-danger' : '' ?>">
                                                    <?php if ($isPercentGoal || $isCagrGoal): ?>
                                                        %<?= formatNumberLocalized($gp['current'], 2) ?>
                                                    <?php elseif ($isDrawdownGoal): ?>
                                                        <?= $gp['current'] > 0 ? '▼ ' : '' ?>%<?= formatNumberLocalized($gp['current'], 2) ?>
                                                    <?php elseif ($isAmountGoal): ?>
                                                        <?= formatNumberLocalized($gp['current'], 4) ?> <?= htmlspecialchars($goalCurrency) ?>
                                                    <?php elseif ($isCurrencyValueGoal): ?>
                                                        <?= formatNumberLocalized($gp['current'], 2) ?> <?= htmlspecialchars($goalCurrency) ?>
                                                    <?php else: ?>
                                                        <?= formatTRY($gp['current']) ?>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="goal-percent<?= $pct >= 100 ? ' goal-complete' : '' ?>">
                                                    <?= formatNumberLocalized($pct, 1) ?>%
                                                </span>
                                                <span class="goal-target">
                                                    <?php if ($isPercentGoal || $isCagrGoal): ?>
                                                        %<?= formatNumberLocalized($gp['target'], 2) ?>
                                                    <?php elseif ($isDrawdownGoal): ?>
                                                        <?= t('portfolio.goals.drawdown_limit') ?> %<?= formatNumberLocalized($gp['target'], 2) ?>
                                                    <?php elseif ($isAmountGoal): ?>
                                                        <?= formatNumberLocalized($gp['target'], 4) ?> <?= htmlspecialchars($goalCurrency) ?>
                                                    <?php elseif ($isCurrencyValueGoal): ?>
                                                        <?= formatNumberLocalized($gp['target'], 2) ?> <?= htmlspecialchars($goalCurrency) ?>
                                                    <?php else: ?>
                                                        <?= formatTRY($gp['target']) ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($gSources)): ?>
                                        <div class="goal-card-footer">
                                            <div class="goal-sources-display">
                                                <?php foreach ($gSources as $src): ?>
                                                    <span class="goal-source-pill goal-source-<?= htmlspecialchars($src['source_type']) ?>">
                                                        <?php if ($src['source_type'] === 'group'): ?>
                                                            <?php
                                                            $srcName = '';
                                                            foreach ($groups as $g) {
                                                                if ((int)$g['id'] === (int)$src['source_id']) {
                                                                    $srcName = ($g['icon'] ? $g['icon'] . ' ' : '📦 ') . $g['name'];
                                                                    break;
                                                                }
                                                            }
                                                            echo htmlspecialchars($srcName);
                                                            ?>
                                                        <?php elseif ($src['source_type'] === 'tag'): ?>
                                                            <?php
                                                            $srcName = '';
                                                            foreach ($tags as $t) {
                                                                if ((int)$t['id'] === (int)$src['source_id']) {
                                                                    $srcName = '🏷️ ' . $t['name'];
                                                                    break;
                                                                }
                                                            }
                                                            echo htmlspecialchars($srcName);
                                                            ?>
                                                        <?php else: ?>
                                                            <?php
                                                            $srcName = '📋 #' . $src['source_id'];
                                                            foreach ($summary['items'] ?? [] as $si) {
                                                                if ((int)$si['id'] === (int)$src['source_id']) {
                                                                    $srcName = '📋 ' . $si['currency_code'] . ' ' . formatNumberLocalized((float)$si['amount'], 4);
                                                                    break;
                                                                }
                                                            }
                                                            echo htmlspecialchars($srcName);
                                                            ?>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Edit form (hidden) -->
                                    <form method="POST" class="goal-edit-form hidden" id="edit-goal-<?= (int)$goal['id'] ?>">
                                        <input type="hidden" name="action" value="edit_goal">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                                        <div class="goal-form-grid goal-form-grid-4">
                                            <div class="goal-form-field">
                                                <label><?= t('portfolio.goals.name') ?></label>
                                                <input type="text" name="goal_name" value="<?= htmlspecialchars($goal['name']) ?>" maxlength="100" required>
                                            </div>
                                            <div class="goal-form-field">
                                                <label><?= t('portfolio.goals.target_type') ?></label>
                                                <select name="goal_target_type" onchange="goalTypeChanged(this, 'edit-<?= (int)$goal['id'] ?>')">
                                                    <option value="value" <?= ($goal['target_type'] ?? 'value') === 'value' ? 'selected' : '' ?>><?= t('portfolio.goals.type_value') ?></option>
                                                    <option value="cost" <?= ($goal['target_type'] ?? 'value') === 'cost' ? 'selected' : '' ?>><?= t('portfolio.goals.type_cost') ?></option>
                                                    <option value="amount" <?= ($goal['target_type'] ?? 'value') === 'amount' ? 'selected' : '' ?>><?= t('portfolio.goals.type_amount') ?></option>
                                                    <option value="currency_value" <?= ($goal['target_type'] ?? 'value') === 'currency_value' ? 'selected' : '' ?>><?= t('portfolio.goals.type_currency_value') ?></option>
                                                    <option value="percent" <?= ($goal['target_type'] ?? 'value') === 'percent' ? 'selected' : '' ?>><?= t('portfolio.goals.type_percent') ?></option>
                                                    <option value="cagr" <?= ($goal['target_type'] ?? 'value') === 'cagr' ? 'selected' : '' ?>><?= t('portfolio.goals.type_cagr') ?></option>
                                                    <option value="drawdown" <?= ($goal['target_type'] ?? 'value') === 'drawdown' ? 'selected' : '' ?>><?= t('portfolio.goals.type_drawdown') ?></option>
                                                </select>
                                            </div>
                                            <div class="goal-form-field goal-currency-field" id="goal-currency-edit-<?= (int)$goal['id'] ?>" style="<?= $hasCurrencyUnit ? '' : 'display:none' ?>">
                                                <label><?= t('portfolio.goals.currency') ?></label>
                                                <select name="goal_target_currency">
                                                    <option value=""><?= t('portfolio.goals.select_currency') ?></option>
                                                    <?php foreach ($currencies as $c): ?>
                                                        <option value="<?= htmlspecialchars($c['code']) ?>" <?= $goalCurrency === $c['code'] ? 'selected' : '' ?>><?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars(localizedCurrencyName($c)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="goal-form-field">
                                                <label><?= t('portfolio.goals.bank') ?></label>
                                                <select name="goal_bank_slug">
                                                    <option value=""><?= t('portfolio.goals.all_banks') ?></option>
                                                    <?php foreach ($banks as $bk): ?>
                                                        <option value="<?= htmlspecialchars($bk['slug']) ?>" <?= $goalBankSlug === $bk['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($bk['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="goal-form-field">
                                                <label id="goal-target-label-edit-<?= (int)$goal['id'] ?>"><?= $isPercentGoal ? t('portfolio.goals.target_percent') : ($isCagrGoal ? t('portfolio.goals.target_cagr') : ($isDrawdownGoal ? t('portfolio.goals.target_drawdown') : ($isAmountGoal ? t('portfolio.goals.target_amount') : ($isCurrencyValueGoal ? t('portfolio.goals.target_currency_value_label') : t('portfolio.goals.target_value'))))) ?></label>
                                                <input type="number" name="goal_target_value" step="0.000001" value="<?= (float)$goal['target_value'] ?>" required>
                                            </div>
                                            <?php
                                                $editPercentMode = $goal['percent_date_mode'] ?? 'all';
                                                $editPercentStart = $goal['percent_date_start'] ?? '';
                                                $editPercentEnd = $goal['percent_date_end'] ?? '';
                                                $editPercentPeriod = (int) ($goal['percent_period_months'] ?? 12);
                                            ?>
                                            <div class="goal-form-field goal-percent-fields" id="goal-percent-edit-<?= (int)$goal['id'] ?>" style="<?= $isPercentGoal ? '' : 'display:none' ?>">
                                                <label><?= t('portfolio.goals.percent_date_mode') ?></label>
                                                <select name="goal_percent_date_mode" onchange="percentModeChanged(this, 'edit-<?= (int)$goal['id'] ?>')">
                                                    <option value="all" <?= $editPercentMode === 'all' ? 'selected' : '' ?>><?= t('portfolio.goals.percent_mode_all') ?></option>
                                                    <option value="range" <?= $editPercentMode === 'range' ? 'selected' : '' ?>><?= t('portfolio.goals.percent_mode_range') ?></option>
                                                    <option value="since_first" <?= $editPercentMode === 'since_first' ? 'selected' : '' ?>><?= t('portfolio.goals.percent_mode_since_first') ?></option>
                                                    <option value="weighted" <?= $editPercentMode === 'weighted' ? 'selected' : '' ?>><?= t('portfolio.goals.percent_mode_weighted') ?></option>
                                                </select>
                                            </div>
                                            <div class="goal-form-field goal-percent-range" id="goal-percent-range-edit-<?= (int)$goal['id'] ?>" style="<?= ($isPercentGoal && $editPercentMode === 'range') ? '' : 'display:none' ?>">
                                                <label><?= t('portfolio.goals.percent_date_start') ?></label>
                                                <input type="date" name="goal_percent_date_start" value="<?= htmlspecialchars($editPercentStart) ?>">
                                            </div>
                                            <div class="goal-form-field goal-percent-range" id="goal-percent-range-end-edit-<?= (int)$goal['id'] ?>" style="<?= ($isPercentGoal && $editPercentMode === 'range') ? '' : 'display:none' ?>">
                                                <label><?= t('portfolio.goals.percent_date_end') ?></label>
                                                <input type="date" name="goal_percent_date_end" value="<?= htmlspecialchars($editPercentEnd) ?>">
                                            </div>
                                            <div class="goal-form-field goal-percent-period" id="goal-percent-period-edit-<?= (int)$goal['id'] ?>" style="<?= ($isPercentGoal && $editPercentMode === 'since_first') ? '' : 'display:none' ?>">
                                                <label><?= t('portfolio.goals.percent_period') ?></label>
                                                <input type="number" name="goal_percent_period_months" value="<?= $editPercentPeriod ?>" min="1" max="120">
                                            </div>
                                            <?php $editGoalDeadline = $goal['goal_deadline'] ?? ''; ?>
                                            <div class="goal-form-field goal-deadline-field" id="goal-deadline-edit-<?= (int)$goal['id'] ?>" style="<?= $isPercentGoal ? 'display:none' : '' ?>">
                                                <label><?= t('portfolio.goals.deadline') ?></label>
                                                <select name="goal_deadline_preset" onchange="deadlinePresetChanged(this, 'edit-<?= (int)$goal['id'] ?>')">
                                                    <option value="" <?= $editGoalDeadline === '' ? 'selected' : '' ?>><?= t('portfolio.goals.deadline_none') ?></option>
                                                    <option value="custom" <?= $editGoalDeadline !== '' ? 'selected' : '' ?>><?= t('portfolio.goals.deadline_custom') ?></option>
                                                </select>
                                                <input type="date" name="goal_deadline" id="goal-deadline-date-edit-<?= (int)$goal['id'] ?>" value="<?= htmlspecialchars($editGoalDeadline) ?>" style="<?= $editGoalDeadline !== '' ? '' : 'display:none' ?>; margin-top:4px">
                                            </div>
                                        </div>
                                        <!-- Sources re-sync for edit -->
                                        <div class="goal-sources-section">
                                            <label><?= t('portfolio.goals.sources') ?></label>
                                            <div id="goal-sources-list-edit-<?= (int)$goal['id'] ?>">
                                                <?php foreach ($gSources as $src):
                                                    $srcLabel = '';
                                                    $srcIcon = '';
                                                    if ($src['source_type'] === 'group') {
                                                        $srcIcon = '📦';
                                                        foreach ($groups as $g) {
                                                            if ((int)$g['id'] === (int)$src['source_id']) {
                                                                $srcLabel = ($g['icon'] ? $g['icon'] . ' ' : '') . $g['name'];
                                                                break;
                                                            }
                                                        }
                                                    } elseif ($src['source_type'] === 'tag') {
                                                        $srcIcon = '🏷️';
                                                        foreach ($tags as $t2) {
                                                            if ((int)$t2['id'] === (int)$src['source_id']) {
                                                                $srcLabel = $t2['name'];
                                                                break;
                                                            }
                                                        }
                                                    } else {
                                                        $srcIcon = '📋';
                                                        foreach ($summary['items'] ?? [] as $si) {
                                                            if ((int)$si['id'] === (int)$src['source_id']) {
                                                                $srcLabel = $si['currency_code'] . ' ' . formatNumberLocalized((float)$si['amount'], 4);
                                                                break;
                                                            }
                                                        }
                                                        if (!$srcLabel) $srcLabel = '#' . $src['source_id'];
                                                    }
                                                ?>
                                                    <div class="goal-source-row">
                                                        <input type="hidden" name="goal_source_type[]" value="<?= htmlspecialchars($src['source_type']) ?>">
                                                        <input type="hidden" name="goal_source_id[]" value="<?= (int)$src['source_id'] ?>">
                                                        <span class="goal-source-pill goal-source-<?= htmlspecialchars($src['source_type']) ?>"><?= $srcIcon ?> <?= htmlspecialchars($srcLabel) ?></span>
                                                        <button type="button" class="btn btn-xs btn-danger" aria-label="<?= t('common.remove') ?> <?= htmlspecialchars($srcLabel) ?>" onclick="removeGoalSourceRow(this)">×</button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="goal-source-add">
                                                <select class="goal-source-select goal-edit-source-type" data-edit-id="<?= (int)$goal['id'] ?>">
                                                    <option value="group">📦 <?= t('portfolio.manage.tab_groups') ?></option>
                                                    <option value="tag">🏷️ <?= t('portfolio.manage.tab_tags') ?></option>
                                                    <option value="item">📋 <?= t('portfolio.goals.source_item') ?></option>
                                                </select>
                                                <select class="goal-source-select goal-edit-source-id" data-edit-id="<?= (int)$goal['id'] ?>">
                                                    <?php foreach ($groups as $g): ?>
                                                        <option value="<?= (int)$g['id'] ?>" data-type="group"><?= htmlspecialchars($g['icon'] ? $g['icon'] . ' ' : '') ?><?= htmlspecialchars($g['name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($tags as $tag): ?>
                                                        <option value="<?= (int)$tag['id'] ?>" data-type="tag" style="display:none"><?= htmlspecialchars($tag['name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($summary['items'] ?? [] as $pItem): ?>
                                                        <option value="<?= (int)$pItem['id'] ?>" data-type="item" style="display:none">
                                                            <?= htmlspecialchars($pItem['currency_code']) ?> — <?= formatNumberLocalized((float)$pItem['amount'], 4) ?> (<?= $pItem['buy_date'] ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-sm btn-primary" onclick="addGoalSourceEdit(<?= (int)$goal['id'] ?>)">➕</button>
                                            </div>
                                        </div>
                                        <div class="goal-edit-actions">
                                            <button type="submit" class="btn btn-primary btn-xs">💾 <?= t('portfolio.form.update') ?></button>
                                            <button type="button" class="btn btn-secondary btn-xs" onclick="toggleEditGoal(<?= (int)$goal['id'] ?>)" aria-label="<?= t('common.cancel') ?>">❌</button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="manage-empty">
                            <span class="manage-empty-icon">🎯</span>
                            <?= t('portfolio.goals.empty') ?>
                        </div>
                    <?php endif; ?>
                </div>
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
                                <option value="<?= (int) $group['id'] ?>" <?= $formValues['group_id'] == (string) $group['id'] ? 'selected' : '' ?>>
                                    <?= $group['icon'] ? htmlspecialchars($group['icon']) . ' ' : '' ?>
                                    <?= htmlspecialchars($group['name']) ?>
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

                <?php if (!empty($tags)): ?>
                    <div class="form-group">
                        <label><?= t('portfolio.form.tags') ?></label>
                        <div class="form-tag-selector">
                            <?php foreach ($tags as $tag): ?>
                                <input type="checkbox" class="form-tag-checkbox" id="form-tag-<?= (int) $tag['id'] ?>"
                                    name="tag_ids[]" value="<?= (int) $tag['id'] ?>">
                                <label for="form-tag-<?= (int) $tag['id'] ?>" class="form-tag-label"
                                    style="--tag-color: <?= htmlspecialchars($tag['color']) ?>">
                                    <span class="form-tag-dot"
                                        style="background: <?= htmlspecialchars($tag['color']) ?>"></span>
                                    <?= htmlspecialchars($tag['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

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
                                        <?= $group['icon'] ? htmlspecialchars($group['icon']) . ' ' : '' ?>
                                        <?= htmlspecialchars($group['name']) ?>
                                    </a>
                                <?php endforeach; ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['group' => 'none'])) ?>"
                                    class="filter-pill <?= $filterGroup === 'none' ? 'active' : '' ?>"><?= t('portfolio.groups.no_group') ?></a>
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><?= t('portfolio.filter.currency_type') ?></label>
                            <div class="filter-pills">
                                <a href="?<?= http_build_query(array_diff_key($_GET, ['currency_type' => ''])) ?>"
                                    class="filter-pill <?= $filterCurrencyType === '' ? 'active' : '' ?>"><?= t('portfolio.filter.all_types') ?></a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['currency_type' => 'precious_metal'])) ?>"
                                    class="filter-pill filter-pill-gold <?= $filterCurrencyType === 'precious_metal' ? 'active' : '' ?>">🥇 <?= t('portfolio.filter.precious_metals') ?></a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['currency_type' => 'fiat'])) ?>"
                                    class="filter-pill <?= $filterCurrencyType === 'fiat' ? 'active' : '' ?>">💱 <?= t('portfolio.filter.fiat_only') ?></a>
                            </div>
                        </div>
                        <?php if (!empty($tags)): ?>
                            <div class="filter-group">
                                <label><?= t('portfolio.filter.tag') ?></label>
                                <div class="filter-pills">
                                    <a href="?<?= http_build_query(array_diff_key($_GET, ['tag' => ''])) ?>"
                                        class="filter-pill filter-pill-tag <?= $filterTag === '' ? 'active' : '' ?>"><?= t('portfolio.tags.all') ?></a>
                                    <?php foreach ($tags as $tag): ?>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['tag' => $tag['id']])) ?>"
                                            class="filter-pill filter-pill-tag <?= $filterTag == (string) $tag['id'] ? 'active' : '' ?>"
                                            style="--pill-color: <?= htmlspecialchars($tag['color']) ?>">
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['tag' => 'none'])) ?>"
                                        class="filter-pill filter-pill-tag <?= $filterTag === 'none' ? 'active' : '' ?>"><?= t('portfolio.tags.no_tag') ?></a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="filter-dates">
                            <div class="filter-date-field">
                                <label for="date_from"><?= t('portfolio.filter.date_from') ?></label>
                                <input type="date" name="date_from" id="date_from"
                                    value="<?= htmlspecialchars($filterDateFrom) ?>">
                            </div>
                            <div class="filter-date-field">
                                <label for="date_to"><?= t('portfolio.filter.date_to') ?></label>
                                <input type="date" name="date_to" id="date_to"
                                    value="<?= htmlspecialchars($filterDateTo) ?>">
                            </div>
                            <?php if ($filterGroup !== ''): ?>
                                <input type="hidden" name="group" value="<?= htmlspecialchars($filterGroup) ?>">
                            <?php endif; ?>
                            <?php if ($filterCurrencyType !== ''): ?>
                                <input type="hidden" name="currency_type" value="<?= htmlspecialchars($filterCurrencyType) ?>">
                            <?php endif; ?>
                            <?php if ($filterTag !== ''): ?>
                                <input type="hidden" name="tag" value="<?= htmlspecialchars($filterTag) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-primary"><?= t('portfolio.filter.apply') ?></button>
                            <?php if ($hasFilters): ?>
                                <a href="portfolio.php" class="btn btn-sm btn-secondary"><?= t('portfolio.filter.clear') ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($hasFilters): ?>
                        <div class="filter-summary">
                            <span class="filter-result-count"><?= count($filteredItems) ?> /
                                <?= $summary['item_count'] ?></span>
                            <span class="filter-result-total">
                                <?= formatTRY($filteredTotalCost) ?> → <?= formatTRY($filteredTotalValue) ?>
                                <span class="<?= changeClass($filteredProfitLoss) ?>">
                                    (<?= $filteredProfitLoss >= 0 ? '+' : '' ?><?= formatTRY($filteredProfitLoss) ?>,
                                    %<?= formatNumberLocalized(abs($filteredProfitPercent), 2) ?>)
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="portfolio-export-link">
                    <a href="portfolio_export.php" class="btn btn-secondary" download><?= t('portfolio.export_csv') ?></a>
                </p>

                <?php if ($groupAnalytics): ?>
                    <div class="group-analytics-strip">
                        <div class="group-stat">
                            <span class="group-stat-label">📦 <?= htmlspecialchars($groupAnalytics['name']) ?></span>
                        </div>
                        <div class="group-stat">
                            <span class="group-stat-label"><?= t('portfolio.analytics.group_cost') ?></span>
                            <span class="group-stat-value"><?= formatTRY($groupAnalytics['cost']) ?></span>
                        </div>
                        <div class="group-stat">
                            <span class="group-stat-label"><?= t('portfolio.analytics.group_value') ?></span>
                            <span class="group-stat-value"><?= formatTRY($groupAnalytics['value']) ?></span>
                        </div>
                        <div class="group-stat">
                            <span class="group-stat-label"><?= t('portfolio.analytics.group_pl') ?></span>
                            <span class="group-stat-value <?= changeClass($groupAnalytics['pl']) ?>">
                                <?= $groupAnalytics['pl'] >= 0 ? '+' : '' ?>         <?= formatTRY($groupAnalytics['pl']) ?>
                                (% <?= formatNumberLocalized(abs($groupAnalytics['pl_percent']), 2) ?>)
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Bulk Actions Toolbar (Sticky Bottom) -->
                <div id="bulk-actions-bar" class="bulk-actions-bar hidden">
                    <div class="bulk-info">
                        <span id="bulk-count">0</span> <?= t('portfolio.bulk.selected', ['count' => '']) ?>
                    </div>
                    <div class="bulk-section">
                        <span class="bulk-section-label"><?= t('portfolio.bulk.group_actions') ?></span>
                        <div class="bulk-buttons">
                            <form method="POST" class="bulk-form" id="bulk-assign-group-form">
                                <input type="hidden" name="action" value="bulk_assign_group">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <div id="bulk-assign-group-ids"></div>
                                <select name="bulk_group_id" class="bulk-select" required>
                                    <option value=""><?= t('portfolio.bulk.select_group') ?></option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?= (int) $group['id'] ?>">
                                            <?= $group['icon'] ? htmlspecialchars($group['icon']) . ' ' : '' ?>
                                            <?= htmlspecialchars($group['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit"
                                    class="btn btn-xs btn-primary"><?= t('portfolio.bulk.assign_group') ?></button>
                            </form>
                            <form method="POST" class="bulk-form" id="bulk-remove-group-form">
                                <input type="hidden" name="action" value="bulk_remove_group">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <div id="bulk-remove-group-ids"></div>
                                <button type="submit"
                                    class="btn btn-xs btn-danger"><?= t('portfolio.bulk.remove_group') ?></button>
                            </form>
                        </div>
                    </div>
                    <?php if (!empty($tags)): ?>
                        <div class="bulk-section">
                            <span class="bulk-section-label"><?= t('portfolio.bulk.tag_actions') ?></span>
                            <div class="bulk-buttons">
                                <form method="POST" class="bulk-form" id="bulk-assign-tag-form">
                                    <input type="hidden" name="action" value="bulk_assign_tag">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <div id="bulk-assign-tag-ids"></div>
                                    <select name="bulk_tag_id" class="bulk-select" required>
                                        <option value=""><?= t('portfolio.bulk.select_tag') ?></option>
                                        <?php foreach ($tags as $tag): ?>
                                            <option value="<?= (int) $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"
                                        class="btn btn-xs btn-primary"><?= t('portfolio.bulk.assign_tag') ?></button>
                                </form>
                                <form method="POST" class="bulk-form" id="bulk-remove-tag-form">
                                    <input type="hidden" name="action" value="bulk_remove_tag">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <div id="bulk-remove-tag-ids"></div>
                                    <select name="bulk_tag_id" class="bulk-select" required>
                                        <option value=""><?= t('portfolio.bulk.select_tag') ?></option>
                                        <?php foreach ($tags as $tag): ?>
                                            <option value="<?= (int) $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"
                                        class="btn btn-xs btn-danger"><?= t('portfolio.bulk.remove_tag') ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="rates-table" id="portfolio-table">
                        <caption class="sr-only"><?= htmlspecialchars(t('portfolio.table.caption')) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col" class="col-checkbox"><input type="checkbox" id="select-all"
                                        title="<?= htmlspecialchars(t('common.select_all')) ?>"
                                        aria-label="<?= htmlspecialchars(t('common.select_all')) ?>"></th>
                                <th scope="col"><?= t('portfolio.table.currency') ?></th>
                                <th scope="col"><?= t('portfolio.table.group') ?></th>
                                <th scope="col"><?= t('portfolio.table.tags') ?></th>
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
                                <tr data-id="<?= (int) $item['id'] ?>">
                                    <td class="col-checkbox">
                                        <input type="checkbox" class="row-checkbox" value="<?= (int) $item['id'] ?>">
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($item['currency_code']) ?></strong>
                                        <small><?= htmlspecialchars(localizedCurrencyName($item)) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['group_name'])): ?>
                                            <span class="group-badge-sm"
                                                style="background: <?= htmlspecialchars($item['group_color'] ?? '#666') ?>">
                                                <?php if ($item['group_icon']): ?><span
                                                        class="group-icon-sm"><?= htmlspecialchars($item['group_icon']) ?></span><?php endif; ?>
                                                <?= htmlspecialchars($item['group_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-tags">
                                        <div class="inline-tag-wrapper" data-portfolio-id="<?= (int) $item['id'] ?>">
                                            <?php
                                            $thisTags = $itemTags[(int) $item['id']] ?? [];
                                            if (!empty($thisTags)):
                                                foreach ($thisTags as $t): ?>
                                                    <span class="tag-pill-sm" style="background: <?= htmlspecialchars($t['color']) ?>">
                                                        <?= htmlspecialchars($t['name']) ?>
                                                        <form method="POST" style="display:inline;margin:0;padding:0">
                                                            <input type="hidden" name="action" value="inline_remove_tag">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="portfolio_id" value="<?= (int) $item['id'] ?>">
                                                            <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>">
                                                            <button type="submit" class="tag-remove"
                                                                title="<?= htmlspecialchars(t('portfolio.inline.remove_tag')) ?>">✕</button>
                                                        </form>
                                                    </span>
                                                <?php endforeach;
                                            endif; ?>
                                            <?php if (!empty($tags)): ?>
                                                <button type="button" class="inline-tag-add"
                                                    onclick="toggleInlineTagDropdown(this)"><?= t('portfolio.tags.inline_add') ?></button>
                                                <div class="inline-tag-dropdown">
                                                    <?php foreach ($tags as $availTag): ?>
                                                        <?php
                                                        // Check if already assigned
                                                        $alreadyAssigned = false;
                                                        foreach ($thisTags as $at) {
                                                            if ((int) $at['id'] === (int) $availTag['id']) {
                                                                $alreadyAssigned = true;
                                                                break;
                                                            }
                                                        }
                                                        if ($alreadyAssigned)
                                                            continue;
                                                        ?>
                                                        <form method="POST" style="margin:0">
                                                            <input type="hidden" name="action" value="inline_assign_tag">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="portfolio_id" value="<?= (int) $item['id'] ?>">
                                                            <input type="hidden" name="tag_id" value="<?= (int) $availTag['id'] ?>">
                                                            <button type="submit" class="inline-tag-option">
                                                                <span class="tag-dot"
                                                                    style="background: <?= htmlspecialchars($availTag['color']) ?>"></span>
                                                                <?= htmlspecialchars($availTag['name']) ?>
                                                            </button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
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
    <script>
        /* ─── Panel Toggle (Collapse/Expand) ─────────────────────────────────── */
        function toggleManagePanel() {
            var panel = document.getElementById('manage-panel');
            if (panel) panel.classList.toggle('collapsed');
        }

        /* ─── Tab Switching ──────────────────────────────────────────────────── */
        function switchManageTab(tabId) {
            document.querySelectorAll('.manage-tab').forEach(function (t) { t.classList.remove('active'); });
            document.querySelectorAll('.manage-tab-content').forEach(function (c) { c.classList.remove('active'); });
            var tab = document.querySelector('.manage-tab[data-tab="' + tabId + '"]');
            var content = document.getElementById(tabId);
            if (tab) tab.classList.add('active');
            if (content) content.classList.add('active');
        }

        /* ─── Edit Toggle ───────────────────────────────────────────────────── */
        function toggleEditGroup(id) {
            var el = document.getElementById('edit-group-' + id);
            if (el) el.classList.toggle('hidden');
        }
        function toggleEditTag(id) {
            var el = document.getElementById('edit-tag-' + id);
            if (el) el.classList.toggle('hidden');
        }
        function toggleEditGoal(id) {
            var el = document.getElementById('edit-goal-' + id);
            if (el) el.classList.toggle('hidden');
        }

        /* ─── Goal Filtering ──────────────────────────────────────────────── */
        var activeGoalFilters = { favorites: false, group: false, tag: false, currency: '' };

        function toggleGoalFilter(filterName) {
            activeGoalFilters[filterName] = !activeGoalFilters[filterName];
            applyGoalFilters();
        }

        function filterGoalsByCurrency(currency) {
            activeGoalFilters.currency = currency;
            applyGoalFilters();
        }

        function applyGoalFilters() {
            var cards = document.querySelectorAll('.goal-card');
            var anyFilter = activeGoalFilters.favorites || activeGoalFilters.group || activeGoalFilters.tag || activeGoalFilters.currency !== '';

            cards.forEach(function(card) {
                var show = true;
                if (activeGoalFilters.favorites && card.dataset.favorite !== '1') show = false;
                if (activeGoalFilters.group && (card.dataset.sourceTypes || '').indexOf('group') === -1) show = false;
                if (activeGoalFilters.tag && (card.dataset.sourceTypes || '').indexOf('tag') === -1) show = false;
                if (activeGoalFilters.currency && card.dataset.currencies !== activeGoalFilters.currency) show = false;
                card.style.display = show ? '' : 'none';
            });

            var clearBtn = document.querySelector('.goal-filter-clear');
            if (clearBtn) clearBtn.classList.toggle('hidden', !anyFilter);
            updateFilterButtons();
        }

        function updateFilterButtons() {
            document.querySelectorAll('.goal-filter-btn[data-filter]').forEach(function(btn) {
                var f = btn.dataset.filter;
                if (f && f in activeGoalFilters) {
                    btn.classList.toggle('active', !!activeGoalFilters[f]);
                }
            });
        }

        function clearGoalFilters() {
            activeGoalFilters = { favorites: false, group: false, tag: false, currency: '' };
            var sel = document.querySelector('.goal-filter-select');
            if (sel) sel.value = '';
            applyGoalFilters();
        }

        /* ─── Goal Type → Currency Field Toggle ────────────────────────────── */
        function goalTypeChanged(select, formId) {
            var val = select.value;
            var needsCurrency = (val === 'amount' || val === 'currency_value');
            var isPercent = (val === 'percent');
            var isCagr = (val === 'cagr');
            var isDrawdown = (val === 'drawdown');
            var currField = document.getElementById('goal-currency-' + formId);
            var percentField = document.getElementById('goal-percent-' + formId);
            var label = document.getElementById('goal-target-label-' + formId);
            if (currField) currField.style.display = needsCurrency ? '' : 'none';
            if (percentField) percentField.style.display = isPercent ? '' : 'none';
            // Hide percent sub-fields when switching away from percent
            if (!isPercent) {
                var rangeStart = document.getElementById('goal-percent-range-' + formId);
                var rangeEnd = document.getElementById('goal-percent-range-end-' + formId);
                var period = document.getElementById('goal-percent-period-' + formId);
                if (rangeStart) rangeStart.style.display = 'none';
                if (rangeEnd) rangeEnd.style.display = 'none';
                if (period) period.style.display = 'none';
            }
            if (label) {
                if (val === 'amount') {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_amount'), JSON_UNESCAPED_UNICODE) ?>;
                } else if (val === 'currency_value') {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_currency_value_label'), JSON_UNESCAPED_UNICODE) ?>;
                } else if (val === 'percent') {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_percent'), JSON_UNESCAPED_UNICODE) ?>;
                } else if (val === 'cagr') {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_cagr'), JSON_UNESCAPED_UNICODE) ?>;
                } else if (val === 'drawdown') {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_drawdown'), JSON_UNESCAPED_UNICODE) ?>;
                } else {
                    label.textContent = <?= json_encode(t('portfolio.goals.target_value'), JSON_UNESCAPED_UNICODE) ?>;
                }
            }
            // Toggle required on currency select
            if (currField) {
                var sel = currField.querySelector('select');
                if (sel) sel.required = needsCurrency;
            }
            // Toggle deadline field (hide for percent goals)
            var deadlineField = document.getElementById('goal-deadline-' + formId);
            if (deadlineField) deadlineField.style.display = isPercent ? 'none' : '';
        }

        /* ─── Deadline Preset Changed ──────────────────────────────────────── */
        function deadlinePresetChanged(select, formId) {
            var val = select.value;
            var dateInput = document.getElementById('goal-deadline-date-' + formId);
            if (!dateInput) return;
            if (val === 'custom') {
                dateInput.style.display = '';
            } else if (val === '') {
                dateInput.style.display = 'none';
                dateInput.value = '';
            } else {
                // Calculate date from today + preset
                var d = new Date();
                var map = {'1m': 1, '3m': 3, '6m': 6, '9m': 9, '1y': 12};
                var months = map[val] || 0;
                d.setMonth(d.getMonth() + months);
                dateInput.value = d.toISOString().split('T')[0];
                dateInput.style.display = '';
            }
        }

        /* ─── Goal Period Changed (AJAX) ───────────────────────────────────── */
        function goalPeriodChanged(select) {
            var goalId = select.getAttribute('data-goal-id');
            var period = select.value;
            var url = 'api.php?action=goal_progress&goal_id=' + goalId;
            if (period) url += '&period=' + encodeURIComponent(period);
            var card = select.closest('.goal-card');
            if (card) card.style.opacity = '0.6';
            fetch(url, {credentials: 'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'ok' && data.progress) {
                        var gp = data.progress;
                        var pct = gp.percent || 0;
                        var progressBar = document.querySelector('#goal-progress-' + goalId + ' .goal-progress-bar');
                        if (progressBar) progressBar.style.width = pct + '%';
                        var statsEl = document.getElementById('goal-stats-' + goalId);
                        if (statsEl) {
                            var currentEl = statsEl.querySelector('.goal-current');
                            var percentEl = statsEl.querySelector('.goal-percent');
                            if (currentEl) {
                                var unit = gp.unit || '₺';
                                if (unit === '%') {
                                    currentEl.textContent = '%' + parseFloat(gp.current).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                                } else if (unit === '₺') {
                                    currentEl.textContent = parseFloat(gp.current).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' ₺';
                                } else {
                                    currentEl.textContent = parseFloat(gp.current).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:4}) + ' ' + unit;
                                }
                            }
                            if (percentEl) percentEl.textContent = parseFloat(pct).toLocaleString(undefined, {minimumFractionDigits:1, maximumFractionDigits:1}) + '%';
                        }
                    }
                })
                .catch(function() {})
                .finally(function() {
                    if (card) card.style.opacity = '';
                });
        }

        /* ─── Percent Date Mode Toggle ─────────────────────────────────────── */
        function percentModeChanged(select, formId) {
            var mode = select.value;
            var rangeStart = document.getElementById('goal-percent-range-' + formId);
            var rangeEnd = document.getElementById('goal-percent-range-end-' + formId);
            var period = document.getElementById('goal-percent-period-' + formId);
            if (rangeStart) rangeStart.style.display = (mode === 'range') ? '' : 'none';
            if (rangeEnd) rangeEnd.style.display = (mode === 'range') ? '' : 'none';
            if (period) period.style.display = (mode === 'since_first') ? '' : 'none';
        }

        /* ─── Goal Source Management ────────────────────────────────────────── */
        var goalSourceCounter = 0;

        // Generic source filter function
        function filterSourceOptions(typeSelect, idSelect) {
            var type = typeSelect.value;
            var opts = idSelect.querySelectorAll('option');
            var firstVisible = null;
            opts.forEach(function(opt) {
                if (opt.getAttribute('data-type') === type) {
                    opt.style.display = '';
                    if (!firstVisible) firstVisible = opt;
                } else {
                    opt.style.display = 'none';
                    opt.selected = false;
                }
            });
            if (firstVisible) firstVisible.selected = true;
        }

        // Init add form source filter
        (function() {
            var typeSelect = document.getElementById('goal-source-type-select');
            var idSelect = document.getElementById('goal-source-id-select');
            if (!typeSelect || !idSelect) return;
            typeSelect.addEventListener('change', function() { filterSourceOptions(typeSelect, idSelect); });
            filterSourceOptions(typeSelect, idSelect);
        })();

        // Init edit form source filters
        document.querySelectorAll('.goal-edit-source-type').forEach(function(ts) {
            var editId = ts.getAttribute('data-edit-id');
            var is = document.querySelector('.goal-edit-source-id[data-edit-id="' + editId + '"]');
            if (!is) return;
            ts.addEventListener('change', function() { filterSourceOptions(ts, is); });
            filterSourceOptions(ts, is);
        });

        function addGoalSource() {
            var typeSelect = document.getElementById('goal-source-type-select');
            var idSelect = document.getElementById('goal-source-id-select');
            if (!typeSelect || !idSelect) return;
            _addGoalSourceToList(typeSelect, idSelect, 'goal-sources-list');
        }

        function addGoalSourceEdit(goalId) {
            var typeSelect = document.querySelector('.goal-edit-source-type[data-edit-id="' + goalId + '"]');
            var idSelect = document.querySelector('.goal-edit-source-id[data-edit-id="' + goalId + '"]');
            if (!typeSelect || !idSelect) return;
            _addGoalSourceToList(typeSelect, idSelect, 'goal-sources-list-edit-' + goalId);
        }

        function _addGoalSourceToList(typeSelect, idSelect, listId) {
            var type = typeSelect.value;
            var id = idSelect.value;
            var label = idSelect.options[idSelect.selectedIndex]?.text?.trim() || '';
            if (!id) return;
            // Check for duplicates
            var existing = document.querySelectorAll('#' + listId + ' .goal-source-row');
            for (var i = 0; i < existing.length; i++) {
                var et = existing[i].querySelector('input[name="goal_source_type[]"]');
                var ei = existing[i].querySelector('input[name="goal_source_id[]"]');
                if (et && ei && et.value === type && ei.value === id) return;
            }
            goalSourceCounter++;
            var removeLabel = <?= json_encode(t('common.remove')) ?>;
            var icons = {group: '📦', tag: '🏷️', item: '📋'};
            var row = document.createElement('div');
            row.className = 'goal-source-row';
            var hiddenType = document.createElement('input');
            hiddenType.type = 'hidden'; hiddenType.name = 'goal_source_type[]'; hiddenType.value = type;
            var hiddenId = document.createElement('input');
            hiddenId.type = 'hidden'; hiddenId.name = 'goal_source_id[]'; hiddenId.value = id;
            var pill = document.createElement('span');
            pill.className = 'goal-source-pill goal-source-' + type;
            pill.textContent = (icons[type] || '') + ' ' + label;
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button'; removeBtn.className = 'btn btn-xs btn-danger';
            removeBtn.setAttribute('aria-label', removeLabel + ' ' + label);
            removeBtn.textContent = '×';
            removeBtn.onclick = function() { removeGoalSourceRow(this); };
            row.appendChild(hiddenType); row.appendChild(hiddenId); row.appendChild(pill); row.appendChild(removeBtn);
            document.getElementById(listId).appendChild(row);
        }

        function removeGoalSourceRow(btn) {
            btn.parentElement.remove();
        }

        /* ─── Enhanced Delete Confirmation with Item Count ──────────────────── */
        function confirmDeleteWithCount(form, type) {
            var count = parseInt(form.getAttribute('data-item-count') || '0', 10);
            var name = form.getAttribute('data-item-name') || '';
            var icon = type === 'group' ? '📦' : '🏷️';
            var msg;
            if (count > 0) {
                if (type === 'group') {
                    msg = <?= json_encode(t('portfolio.groups.delete_confirm_count', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('__COUNT__', count);
                } else {
                    msg = <?= json_encode(t('portfolio.tags.delete_confirm_count', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('__COUNT__', count);
                }
            } else {
                msg = type === 'group'
                    ? <?= json_encode(t('portfolio.groups.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                    : <?= json_encode(t('portfolio.tags.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            }
            return confirm(icon + ' ' + name + '\n\n' + msg);
        }

        /* ─── Inline Tag Dropdown ───────────────────────────────────────────── */
        function toggleInlineTagDropdown(btn) {
            // Close any other open dropdowns
            document.querySelectorAll('.inline-tag-dropdown.open').forEach(function (d) {
                if (d !== btn.nextElementSibling) d.classList.remove('open');
            });
            var dd = btn.nextElementSibling;
            if (dd) dd.classList.toggle('open');
        }
        // Close inline dropdowns when clicking outside
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.inline-tag-wrapper')) {
                document.querySelectorAll('.inline-tag-dropdown.open').forEach(function (d) {
                    d.classList.remove('open');
                });
            }
        });

        /* ─── Checkbox / Bulk Actions ───────────────────────────────────────── */
        (function () {
            var selectAll = document.getElementById('select-all');
            var bulkBar = document.getElementById('bulk-actions-bar');
            var bulkCount = document.getElementById('bulk-count');
            if (!selectAll || !bulkBar) return;

            function getCheckboxes() {
                return document.querySelectorAll('.row-checkbox');
            }

            function getSelected() {
                var selected = [];
                getCheckboxes().forEach(function (cb) {
                    if (cb.checked) selected.push(cb.value);
                });
                return selected;
            }

            function updateBulkBar() {
                var selected = getSelected();
                var count = selected.length;
                bulkCount.textContent = count;
                if (count > 0) {
                    bulkBar.classList.remove('hidden');
                    bulkBar.classList.add('visible');
                } else {
                    bulkBar.classList.add('hidden');
                    bulkBar.classList.remove('visible');
                }
            }

            selectAll.addEventListener('change', function () {
                var checked = this.checked;
                getCheckboxes().forEach(function (cb) {
                    cb.checked = checked;
                });
                updateBulkBar();
            });

            document.addEventListener('change', function (e) {
                if (e.target.classList.contains('row-checkbox')) {
                    updateBulkBar();
                    var cbs = getCheckboxes();
                    var allChecked = true;
                    cbs.forEach(function (cb) {
                        if (!cb.checked) allChecked = false;
                    });
                    selectAll.checked = allChecked && cbs.length > 0;
                }
            });

            // Inject selected IDs into bulk forms on submit
            var bulkForms = document.querySelectorAll('.bulk-form');
            bulkForms.forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    var selected = getSelected();
                    if (selected.length === 0) {
                        e.preventDefault();
                        return;
                    }
                    var container = form.querySelector('[id^="bulk-"]');
                    if (!container) return;
                    container.innerHTML = '';
                    selected.forEach(function (id) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'selected_ids[]';
                        inp.value = id;
                        container.appendChild(inp);
                    });
                });
            });
        })();
    </script>
    <?php if (!empty($distribution)): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
        <script src="assets/js/portfolio-analytics.js"></script>
    <?php endif; ?>
</body>

</html>
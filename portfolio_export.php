<?php
/**
 * portfolio_export.php — CSV Export
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
requirePortfolioAccessForWeb();

$summary = Portfolio::getSummary();
$items = $summary['items'] ?? [];
$groups = Portfolio::getGroups();
$tags = Portfolio::getTags();
$itemTags = Portfolio::getAllItemTags();
$locale = getAppLocale();
$nameField = $locale === 'en' ? 'currency_name_en' : 'currency_name_tr';

// Build group lookup
$groupMap = [];
foreach ($groups as $g) {
    $groupMap[(int) $g['id']] = $g['name'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="portfolio-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

// BOM for Excel UTF-8
fwrite($out, "\xEF\xBB\xBF");

// Header — now includes Group and Tags columns
fputcsv($out, [
    t('csv.currency'),
    t('csv.group'),
    t('csv.tags'),
    t('csv.amount'),
    t('csv.buy_rate'),
    t('csv.buy_date'),
    t('csv.current_rate'),
    t('csv.cost_try'),
    t('csv.value_try'),
    t('csv.pl_percent'),
    t('csv.notes'),
]);

foreach ($items as $item) {
    $groupName = '';
    $gid = (int) ($item['group_id'] ?? 0);
    if ($gid > 0 && isset($groupMap[$gid])) {
        $groupName = $groupMap[$gid];
    }

    $tagNames = '';
    $pid = (int) ($item['id'] ?? 0);
    if (!empty($itemTags[$pid])) {
        $names = array_map(fn($t) => $t['name'], $itemTags[$pid]);
        $tagNames = implode(', ', $names);
    }

    fputcsv($out, [
        $item['currency_code'] ?? '',
        $groupName,
        $tagNames,
        $item['amount'] ?? '',
        $item['buy_rate'] ?? '',
        $item['buy_date'] ?? '',
        $item['current_rate'] ?? '',
        $item['cost_try'] ?? '',
        $item['value_try'] ?? '',
        $item['profit_percent'] ?? '',
        $item['notes'] ?? '',
    ]);
}

fclose($out);
exit;

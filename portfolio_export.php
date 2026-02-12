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
$locale = getAppLocale();
$nameField = $locale === 'en' ? 'currency_name_en' : 'currency_name_tr';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="portfolio-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

// BOM for Excel UTF-8
fwrite($out, "\xEF\xBB\xBF");

// Header
fputcsv($out, [
    $locale === 'en' ? 'Currency' : 'Döviz',
    $locale === 'en' ? 'Amount' : 'Miktar',
    $locale === 'en' ? 'Buy Rate' : 'Alış Kuru',
    $locale === 'en' ? 'Buy Date' : 'Alış Tarihi',
    $locale === 'en' ? 'Current Rate' : 'Güncel Kur',
    $locale === 'en' ? 'Cost (TRY)' : 'Maliyet (₺)',
    $locale === 'en' ? 'Value (TRY)' : 'Değer (₺)',
    $locale === 'en' ? 'P/L (%)' : 'K/Z (%)',
    $locale === 'en' ? 'Notes' : 'Notlar',
]);

foreach ($items as $item) {
    fputcsv($out, [
        $item['currency_code'] ?? '',
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

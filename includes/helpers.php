<?php
/**
 * Helper functions
 */

/**
 * Format a number as currency (Turkish locale)
 */
function formatRate(float $value, int $decimals = 4): string
{
    return number_format($value, $decimals, ',', '.');
}

/**
 * Format money in TRY
 */
function formatTRY(float $value): string
{
    return number_format($value, 2, ',', '.') . ' ₺';
}

/**
 * Format percentage
 */
function formatPercent(float $value): string
{
    $prefix = $value >= 0 ? '+' : '';
    return $prefix . number_format($value, 2, ',', '.') . '%';
}

/**
 * Get CSS class for profit/loss
 */
function profitClass(float $value): string
{
    if ($value > 0) return 'text-success';
    if ($value < 0) return 'text-danger';
    return 'text-muted';
}

/**
 * Get arrow icon for change
 */
function changeIcon(float $value): string
{
    if ($value > 0) return '▲';
    if ($value < 0) return '▼';
    return '—';
}

/**
 * Sanitize input
 */
function sanitize(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * JSON response helper
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Get time ago string
 */
function timeAgo(string $datetime): string
{
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' yıl önce';
    if ($diff->m > 0) return $diff->m . ' ay önce';
    if ($diff->d > 0) return $diff->d . ' gün önce';
    if ($diff->h > 0) return $diff->h . ' saat önce';
    if ($diff->i > 0) return $diff->i . ' dakika önce';
    return 'Az önce';
}

/**
 * Get currency flag emoji
 */
function currencyFlag(string $code): string
{
    $flags = [
        'USD' => '🇺🇸', 'EUR' => '🇪🇺', 'GBP' => '🇬🇧', 'CHF' => '🇨🇭',
        'AUD' => '🇦🇺', 'CAD' => '🇨🇦', 'CNY' => '🇨🇳', 'JPY' => '🇯🇵',
        'SAR' => '🇸🇦', 'AED' => '🇦🇪',
        'XAU' => '🥇', 'XAG' => '🥈', 'XPT' => '⚪', 'XPD' => '🔘',
    ];
    return $flags[$code] ?? '💱';
}

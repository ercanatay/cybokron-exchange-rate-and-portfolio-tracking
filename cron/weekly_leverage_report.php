<?php
/**
 * Weekly Leverage Report — sends weekly summary email of leverage signals
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Run via cron: 0 8 * * 1 php /path/to/cron/weekly_leverage_report.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/SendGridMailer.php';
require_once __DIR__ . '/../includes/LeverageEngine.php';

// Initialize
cybokron_init();
ensureCliExecution();

// Check if weekly report is enabled (settings > config > default false)
$dbEnabled = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_weekly_report_enabled']);
$reportEnabled = $dbEnabled
    ? ($dbEnabled['value'] === '1')
    : (defined('LEVERAGE_WEEKLY_REPORT_ENABLED') && LEVERAGE_WEEKLY_REPORT_ENABLED);

if (!$reportEnabled) {
    echo "Weekly leverage report is disabled.\n";
    exit(0);
}

// Check if today is the correct day (settings > config > default 'monday')
$dbDay = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_weekly_report_day']);
if ($dbDay && trim($dbDay['value']) !== '') {
    $reportDay = strtolower(trim($dbDay['value']));
} elseif (defined('LEVERAGE_WEEKLY_REPORT_DAY') && trim((string) LEVERAGE_WEEKLY_REPORT_DAY) !== '') {
    $reportDay = strtolower(trim((string) LEVERAGE_WEEKLY_REPORT_DAY));
} else {
    $reportDay = 'monday';
}

$today = strtolower(date('l')); // e.g., 'monday'
if ($today !== $reportDay) {
    echo "Not report day (today={$today}, configured={$reportDay}).\n";
    exit(0);
}

// Get recipients
$recipients = SendGridMailer::getNotifyEmails();
if (empty($recipients)) {
    echo "No recipients configured.\n";
    exit(0);
}

// Fetch last 7 days of signals
$pdo = Database::getInstance();
$dateFrom = date('Y-m-d', strtotime('-7 days'));
$dateTo = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT lh.*, lr.name AS rule_name, lr.currency_code
    FROM leverage_history lh
    JOIN leverage_rules lr ON lh.rule_id = lr.id
    WHERE lh.created_at >= ?
    AND lh.event_type IN ('buy_signal','sell_signal','weak_buy_signal','weak_sell_signal','trailing_stop_signal')
    ORDER BY lh.created_at DESC
");
$stmt->execute([$dateFrom . ' 00:00:00']);
$signals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count active/paused rules
$activeRow = Database::queryOne("SELECT COUNT(*) AS cnt FROM leverage_rules WHERE status = 'active'");
$activeRules = $activeRow ? (int) $activeRow['cnt'] : 0;

$pausedRow = Database::queryOne("SELECT COUNT(*) AS cnt FROM leverage_rules WHERE status = 'paused'");
$pausedRules = $pausedRow ? (int) $pausedRow['cnt'] : 0;

// Build email
$subject = t('leverage.report.subject');
// If t() returns the key itself (no translation), use a default
if ($subject === 'leverage.report.subject') {
    $subject = '[Cybokron] Haftalık Kaldıraç Raporu';
}

// Build HTML and text email bodies
$htmlBody = buildReportHtml($signals, $activeRules, $pausedRules, $dateFrom, $dateTo);
$textBody = buildReportText($signals, $activeRules, $pausedRules, $dateFrom, $dateTo);

// Send
$result = SendGridMailer::send($recipients, $subject, $htmlBody, $textBody);

if ($result['success']) {
    $count = count($signals);
    echo "Weekly report sent: {$count} signals, {$activeRules} active rules.\n";
    cybokron_log("Weekly leverage report sent to " . count($recipients) . " recipients ({$count} signals)");
    exit(0);
} else {
    echo "Failed to send weekly report: {$result['error']}\n";
    cybokron_log("Weekly leverage report failed: {$result['error']}", 'ERROR');
    exit(1);
}

// ─── Helper functions ──────────────────────────────────────────────────────

/**
 * Build HTML email body for weekly report.
 * Uses the same inline CSS styling as LeverageEngine::buildEmailHtml().
 */
function buildReportHtml(array $signals, int $activeRules, int $pausedRules, string $dateFrom, string $dateTo): string
{
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $th = '<td style="padding:8px;border:1px solid #ddd;font-weight:bold">';
    $td = '<td style="padding:8px;border:1px solid #ddd">';
    $totalSignals = count($signals);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    $html .= '<div style="font-family:Arial,sans-serif;max-width:700px;margin:0 auto;padding:20px">';

    // Title
    $title = t('leverage.report.title');
    if ($title === 'leverage.report.title') {
        $title = 'Haftalık Kaldıraç Raporu';
    }
    $html .= '<h2 style="color:#2c3e50;margin-bottom:4px">' . $e($title) . '</h2>';

    // Period
    $periodLabel = t('leverage.report.period');
    if ($periodLabel === 'leverage.report.period') {
        $periodLabel = 'Dönem';
    }
    $html .= '<p style="color:#7f8c8d;margin-top:0">' . $e($periodLabel) . ': ' . $e($dateFrom) . ' &mdash; ' . $e($dateTo) . '</p>';

    // Summary cards
    $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
    $html .= '<tr>';

    $signalsLabel = t('leverage.report.total_signals');
    if ($signalsLabel === 'leverage.report.total_signals') {
        $signalsLabel = 'Toplam Sinyal';
    }
    $html .= '<td style="padding:12px;text-align:center;background:#3498db;color:#fff;border-radius:6px 0 0 6px">';
    $html .= '<div style="font-size:24px;font-weight:bold">' . $totalSignals . '</div>';
    $html .= '<div style="font-size:12px">' . $e($signalsLabel) . '</div></td>';

    $activeLabel = t('leverage.report.active_rules');
    if ($activeLabel === 'leverage.report.active_rules') {
        $activeLabel = 'Aktif Kural';
    }
    $html .= '<td style="padding:12px;text-align:center;background:#27ae60;color:#fff">';
    $html .= '<div style="font-size:24px;font-weight:bold">' . $activeRules . '</div>';
    $html .= '<div style="font-size:12px">' . $e($activeLabel) . '</div></td>';

    $pausedLabel = t('leverage.report.paused_rules');
    if ($pausedLabel === 'leverage.report.paused_rules') {
        $pausedLabel = 'Duraklatılmış Kural';
    }
    $html .= '<td style="padding:12px;text-align:center;background:#f39c12;color:#fff;border-radius:0 6px 6px 0">';
    $html .= '<div style="font-size:24px;font-weight:bold">' . $pausedRules . '</div>';
    $html .= '<div style="font-size:12px">' . $e($pausedLabel) . '</div></td>';

    $html .= '</tr></table>';

    // Signal table
    if ($totalSignals > 0) {
        $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';

        // Header row
        $dateHeader = t('leverage.report.col_date');
        if ($dateHeader === 'leverage.report.col_date') {
            $dateHeader = 'Tarih';
        }
        $ruleHeader = t('leverage.report.col_rule');
        if ($ruleHeader === 'leverage.report.col_rule') {
            $ruleHeader = 'Kural';
        }
        $signalHeader = t('leverage.report.col_signal');
        if ($signalHeader === 'leverage.report.col_signal') {
            $signalHeader = 'Sinyal';
        }
        $currencyHeader = t('leverage.report.col_currency');
        if ($currencyHeader === 'leverage.report.col_currency') {
            $currencyHeader = 'Döviz';
        }
        $changeHeader = t('leverage.report.col_change');
        if ($changeHeader === 'leverage.report.col_change') {
            $changeHeader = 'Değişim %';
        }
        $aiHeader = t('leverage.report.col_ai');
        if ($aiHeader === 'leverage.report.col_ai') {
            $aiHeader = 'AI Önerisi';
        }

        $thHeader = '<th style="padding:8px;border:1px solid #ddd;background:#f8f9fa;text-align:left">';
        $html .= '<tr>';
        $html .= $thHeader . $e($dateHeader) . '</th>';
        $html .= $thHeader . $e($ruleHeader) . '</th>';
        $html .= $thHeader . $e($signalHeader) . '</th>';
        $html .= $thHeader . $e($currencyHeader) . '</th>';
        $html .= $thHeader . $e($changeHeader) . '</th>';
        $html .= $thHeader . $e($aiHeader) . '</th>';
        $html .= '</tr>';

        foreach ($signals as $signal) {
            $eventType = $signal['event_type'] ?? '';
            $signalLabel = formatSignalLabel($eventType);
            $signalColor = str_contains($eventType, 'buy') ? '#e74c3c' : '#27ae60';
            $changePct = isset($signal['change_percent']) ? number_format((float) $signal['change_percent'], 2) . '%' : '-';
            $aiRec = !empty($signal['ai_recommendation']) ? $e((string) $signal['ai_recommendation']) : '-';
            $createdAt = isset($signal['created_at']) ? date('d.m.Y H:i', strtotime($signal['created_at'])) : '-';
            $currencyCode = strtoupper(trim($signal['currency_code'] ?? ''));

            $html .= '<tr>';
            $html .= $td . $e($createdAt) . '</td>';
            $html .= $td . $e($signal['rule_name'] ?? '-') . '</td>';
            $html .= '<td style="padding:8px;border:1px solid #ddd;color:' . $signalColor . ';font-weight:bold">' . $e($signalLabel) . '</td>';
            $html .= $td . $e($currencyCode) . '</td>';
            $html .= $td . $e($changePct) . '</td>';
            $html .= $td . $aiRec . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
    } else {
        $noSignals = t('leverage.report.no_signals');
        if ($noSignals === 'leverage.report.no_signals') {
            $noSignals = 'Bu hafta sinyal üretilmedi.';
        }
        $html .= '<p style="background:#f8f9fa;padding:16px;border-radius:6px;text-align:center;color:#7f8c8d">' . $e($noSignals) . '</p>';
    }

    // Footer
    $html .= '<hr style="margin:20px 0;border:none;border-top:1px solid #ddd">';
    $html .= '<p style="color:#666;font-size:12px">';
    $html .= $e(date('d.m.Y H:i'));

    $appUrl = defined('APP_URL') ? APP_URL : '';
    if ($appUrl !== '') {
        $linkText = t('leverage.email.panel_link');
        if ($linkText === 'leverage.email.panel_link') {
            $linkText = 'Kaldıraç Paneli';
        }
        $html .= ' | <a href="' . $e($appUrl) . '/leverage.php">' . $e($linkText) . '</a>';
    }

    $html .= '<br>Cybokron Exchange Rate &amp; Portfolio Tracking';
    $html .= '</p></div></body></html>';

    return $html;
}

/**
 * Build text email body for weekly report.
 */
function buildReportText(array $signals, int $activeRules, int $pausedRules, string $dateFrom, string $dateTo): string
{
    $totalSignals = count($signals);

    $title = t('leverage.report.title');
    if ($title === 'leverage.report.title') {
        $title = 'Haftalık Kaldıraç Raporu';
    }

    $text = strtoupper($title) . "\n";
    $text .= str_repeat('=', 40) . "\n\n";
    $text .= "Dönem: {$dateFrom} — {$dateTo}\n\n";

    $text .= "Toplam Sinyal: {$totalSignals}\n";
    $text .= "Aktif Kural: {$activeRules}\n";
    $text .= "Duraklatılmış Kural: {$pausedRules}\n\n";

    if ($totalSignals > 0) {
        $text .= str_repeat('-', 60) . "\n";
        foreach ($signals as $signal) {
            $eventType = $signal['event_type'] ?? '';
            $signalLabel = formatSignalLabel($eventType);
            $changePct = isset($signal['change_percent']) ? number_format((float) $signal['change_percent'], 2) . '%' : '-';
            $aiRec = !empty($signal['ai_recommendation']) ? (string) $signal['ai_recommendation'] : '-';
            $createdAt = isset($signal['created_at']) ? date('d.m.Y H:i', strtotime($signal['created_at'])) : '-';
            $currencyCode = strtoupper(trim($signal['currency_code'] ?? ''));

            $text .= "[{$createdAt}] {$signalLabel} | {$currencyCode} | {$changePct}";
            $text .= " | Kural: " . ($signal['rule_name'] ?? '-');
            $text .= " | AI: {$aiRec}\n";
        }
        $text .= str_repeat('-', 60) . "\n";
    } else {
        $text .= "Bu hafta sinyal üretilmedi.\n";
    }

    $text .= "\n---\n";
    $text .= date('d.m.Y H:i') . "\n";
    $text .= "Cybokron Exchange Rate & Portfolio Tracking\n";

    return $text;
}

/**
 * Format signal event type into a human-readable label.
 */
function formatSignalLabel(string $eventType): string
{
    $labels = [
        'buy_signal'           => 'AL',
        'sell_signal'          => 'SAT',
        'weak_buy_signal'      => 'Zayıf AL',
        'weak_sell_signal'     => 'Zayıf SAT',
        'trailing_stop_signal' => 'Trailing Stop',
    ];
    return $labels[$eventType] ?? $eventType;
}

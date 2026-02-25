<?php
/**
 * leverage.php — Leverage Rule Management Page
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/LeverageEngine.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'leverage.php'));
    exit;
}

// ─── POST Handlers ──────────────────────────────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $message = t('common.invalid_request');
        $messageType = 'error';
    }

    $action = $_POST['action'] ?? '';

    if ($messageType === '' && $action === 'create_rule') {
        try {
            LeverageEngine::create([
                'name' => trim((string) ($_POST['name'] ?? '')),
                'source_type' => trim((string) ($_POST['source_type'] ?? 'currency')),
                'source_id' => $_POST['source_id'] ?? null,
                'currency_code' => trim((string) ($_POST['currency_code'] ?? '')),
                'buy_threshold' => (float) ($_POST['buy_threshold'] ?? -15.00),
                'sell_threshold' => (float) ($_POST['sell_threshold'] ?? 30.00),
                'reference_price' => (float) ($_POST['reference_price'] ?? 0),
                'ai_enabled' => isset($_POST['ai_enabled']) ? 1 : 0,
                'strategy_context' => trim((string) ($_POST['strategy_context'] ?? '')),
            ]);
            $message = t('leverage.message.created');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage create failed: ' . $e->getMessage(), 'ERROR');
            $message = ($e instanceof InvalidArgumentException)
                ? $e->getMessage()
                : t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'update_rule' && !empty($_POST['rule_id'])) {
        try {
            LeverageEngine::update((int) $_POST['rule_id'], [
                'name' => trim((string) ($_POST['name'] ?? '')),
                'buy_threshold' => (float) ($_POST['buy_threshold'] ?? -15.00),
                'sell_threshold' => (float) ($_POST['sell_threshold'] ?? 30.00),
                'reference_price' => (float) ($_POST['reference_price'] ?? 0),
                'ai_enabled' => isset($_POST['ai_enabled']) ? 1 : 0,
                'strategy_context' => trim((string) ($_POST['strategy_context'] ?? '')),
            ]);
            $message = t('leverage.message.updated');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage update failed: ' . $e->getMessage(), 'ERROR');
            $message = ($e instanceof InvalidArgumentException)
                ? $e->getMessage()
                : t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'delete_rule' && !empty($_POST['rule_id'])) {
        try {
            LeverageEngine::delete((int) $_POST['rule_id']);
            $message = t('leverage.message.deleted');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage delete failed: ' . $e->getMessage(), 'ERROR');
            $message = t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'pause_rule' && !empty($_POST['rule_id'])) {
        try {
            LeverageEngine::pause((int) $_POST['rule_id']);
            $message = t('leverage.message.paused');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage pause failed: ' . $e->getMessage(), 'ERROR');
            $message = t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'resume_rule' && !empty($_POST['rule_id'])) {
        try {
            LeverageEngine::resume((int) $_POST['rule_id']);
            $message = t('leverage.message.resumed');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage resume failed: ' . $e->getMessage(), 'ERROR');
            $message = t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    if ($messageType === '' && $action === 'update_reference' && !empty($_POST['rule_id'])) {
        try {
            LeverageEngine::updateReference((int) $_POST['rule_id']);
            $message = t('leverage.message.reference_updated');
            $messageType = 'success';
        } catch (Throwable $e) {
            cybokron_log('Leverage update reference failed: ' . $e->getMessage(), 'ERROR');
            $message = t('leverage.message.error', ['error' => $e->getMessage()]);
            $messageType = 'error';
        }
    }

    // PRG: redirect after POST
    if ($messageType !== '') {
        $_SESSION['leverage_flash'] = ['message' => $message, 'type' => $messageType];
        header('Location: leverage.php');
        exit;
    }
}

// Check for flash message from redirect
if (isset($_SESSION['leverage_flash'])) {
    $message = $_SESSION['leverage_flash']['message'] ?? '';
    $messageType = $_SESSION['leverage_flash']['type'] ?? '';
    unset($_SESSION['leverage_flash']);
}

// ─── Data Fetch ─────────────────────────────────────────────────────────────
$rules = LeverageEngine::getAllRules();
$history = LeverageEngine::getHistory(50);
$stats = LeverageEngine::getSummaryStats();

// For modal dropdowns
$groups = Portfolio::getGroups();
$tags = Portfolio::getTags();
$currencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');

// Get current rates for each rule's currency
$currentRates = [];
$latestRates = getLatestRates(null, null, true);
foreach ($latestRates as $lr) {
    $code = strtoupper($lr['currency_code'] ?? '');
    $sell = (float) ($lr['sell_rate'] ?? 0);
    if ($code !== '' && $sell > 0 && (!isset($currentRates[$code]) || $sell > $currentRates[$code])) {
        $currentRates[$code] = $sell;
    }
}

$csrfToken = getCsrfToken();
$currentLocale = getAppLocale();
$availableLocales = getAvailableLocales();
$version = getAppVersion();
$newTabText = t('common.opens_new_tab');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>" data-layout-default="<?= isFullwidthDefault() ? 'fullwidth' : 'normal' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('leverage.page_title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('leverage.page_title') . ' — ' . APP_NAME,
    'description' => t('leverage.page_title'),
    'page' => 'leverage.php',
]) ?>
    <script nonce="<?= getCspNonce() ?>">(function(){try{var t=localStorage.getItem('cybokron_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}else if(window.matchMedia('(prefers-color-scheme:light)').matches){document.documentElement.setAttribute('data-theme','light')}}catch(e){}})();</script>
    <script nonce="<?= getCspNonce() ?>">(function(){try{var l=localStorage.getItem('cybokron_layout');if(l!=='fullwidth'&&l!=='normal'){l=document.documentElement.getAttribute('data-layout-default')||'normal'}document.documentElement.setAttribute('data-layout',l)}catch(e){}})();</script>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style nonce="<?= getCspNonce() ?>">
        /* ─── Leverage Page Styles ──────────────────────────────────────────── */
        .leverage-rules-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 24px 0 16px;
        }
        .leverage-rules-header h2 {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .leverage-rule-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 16px;
            position: relative;
        }
        .leverage-rule-card.status-paused {
            opacity: 0.7;
        }
        .leverage-rule-card.status-completed {
            opacity: 0.5;
        }

        .rule-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .rule-card-title {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .rule-card-title .currency-code {
            font-family: var(--mono);
            color: var(--primary);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-active {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }
        .badge-paused {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }
        .badge-completed {
            background: rgba(139, 143, 163, 0.15);
            color: var(--text-muted);
        }
        .badge-group {
            background: rgba(96, 165, 250, 0.15);
            color: var(--primary);
        }
        .badge-tag {
            background: rgba(139, 92, 246, 0.15);
            color: #8b5cf6;
        }
        .badge-currency {
            background: rgba(6, 182, 212, 0.15);
            color: #06b6d4;
        }
        .badge-ai-on {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }
        .badge-ai-off {
            background: rgba(139, 143, 163, 0.1);
            color: var(--text-muted);
        }

        .rule-prices {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            font-family: var(--mono);
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        .rule-prices .price-label {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-family: var(--font);
            text-transform: uppercase;
        }
        .rule-prices .price-value {
            font-weight: 600;
        }
        .rule-prices .price-arrow {
            color: var(--text-muted);
        }
        .rule-prices .change-positive {
            color: var(--success);
        }
        .rule-prices .change-negative {
            color: var(--danger);
        }

        /* Progress bar */
        .leverage-progress {
            position: relative;
            height: 28px;
            background: var(--surface-hover);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 12px;
            border: 1px solid var(--border);
        }
        .leverage-progress-buy {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: rgba(239, 68, 68, 0.15);
            border-right: 1px dashed rgba(239, 68, 68, 0.4);
        }
        .leverage-progress-sell {
            position: absolute;
            top: 0;
            right: 0;
            height: 100%;
            background: rgba(34, 197, 94, 0.15);
            border-left: 1px dashed rgba(34, 197, 94, 0.4);
        }
        .leverage-progress-marker {
            position: absolute;
            top: 2px;
            bottom: 2px;
            width: 3px;
            background: var(--primary);
            border-radius: 2px;
            z-index: 2;
            transform: translateX(-50%);
        }
        .leverage-progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .leverage-progress-labels .label-buy {
            color: var(--danger);
        }
        .leverage-progress-labels .label-sell {
            color: var(--success);
        }

        .rule-card-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .rule-card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .rule-card-actions .btn {
            padding: 6px 12px;
            font-size: 0.78rem;
        }

        /* ─── Modal ────────────────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            justify-content: center;
            align-items: flex-start;
            padding-top: 60px;
            overflow-y: auto;
        }
        .modal-overlay.open {
            display: flex;
        }
        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            width: 100%;
            max-width: 540px;
            padding: 28px;
            position: relative;
            margin-bottom: 40px;
        }
        .modal-content h2 {
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 4px 8px;
        }
        .modal-close:hover {
            color: var(--text);
        }

        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: var(--font);
            font-size: 0.9rem;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-group .form-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-radio-group {
            display: flex;
            gap: 16px;
            margin-top: 6px;
        }
        .form-radio-group label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 400;
            cursor: pointer;
        }
        .form-radio-group input[type="radio"] {
            width: auto;
        }

        .form-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 6px;
        }
        .form-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }
        .form-toggle label {
            font-weight: 400;
            margin-bottom: 0;
        }

        .form-inline-btn {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .form-inline-btn input {
            flex: 1;
        }
        .form-inline-btn .btn {
            white-space: nowrap;
            height: 42px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* ─── History Table ────────────────────────────────────────────────── */
        .leverage-history-section {
            margin-top: 32px;
        }
        .leverage-history-section h2 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .leverage-history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .leverage-history-table th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid var(--border);
            color: var(--text-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .leverage-history-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .leverage-history-table tbody tr:hover {
            background: var(--surface-hover);
        }

        .event-buy {
            color: var(--danger);
            font-weight: 600;
        }
        .event-sell {
            color: var(--success);
            font-weight: 600;
        }
        .notification-sent {
            color: var(--success);
        }
        .notification-pending {
            color: var(--text-muted);
        }

        .ai-rec {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .ai-rec-strong_buy, .ai-rec-buy {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success);
        }
        .ai-rec-hold {
            background: rgba(245, 158, 11, 0.15);
            color: var(--warning);
        }
        .ai-rec-sell, .ai-rec-strong_sell {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger);
        }

        /* ─── Empty State ──────────────────────────────────────────────────── */
        .leverage-empty {
            text-align: center;
            padding: 48px 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
        }
        .leverage-empty h3 {
            font-size: 1rem;
            margin-bottom: 8px;
        }
        .leverage-empty p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* ─── Responsive ───────────────────────────────────────────────────── */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .leverage-history-table {
                display: block;
                overflow-x: auto;
            }
            .rule-card-actions {
                width: 100%;
            }
            .rule-card-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <a href="#main-content" class="skip-link"><?= t('common.skip_to_content') ?></a>
    <?php $activePage = 'leverage';
    include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <?php $isErrorMessage = $messageType === 'error'; ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $isErrorMessage ? 'alert' : 'status' ?>"
                aria-live="<?= $isErrorMessage ? 'assertive' : 'polite' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- ─── Summary Cards ─────────────────────────────────────────────── -->
        <section class="summary-cards">
            <div class="card">
                <h3><?= t('leverage.summary.active_rules') ?></h3>
                <p class="card-value"><?= (int) $stats['active_rules'] ?></p>
            </div>
            <div class="card">
                <h3><?= t('leverage.summary.triggered_signals') ?></h3>
                <p class="card-value"><?= (int) $stats['triggered_today'] ?></p>
            </div>
            <div class="card">
                <h3><?= t('leverage.summary.today_checks') ?></h3>
                <p class="card-value"><?= (int) $stats['checks_today'] ?></p>
            </div>
            <div class="card">
                <h3><?= t('leverage.summary.ai_analyses') ?></h3>
                <p class="card-value"><?= (int) $stats['ai_today'] ?></p>
            </div>
        </section>

        <!-- ─── Rules Section ─────────────────────────────────────────────── -->
        <div class="leverage-rules-header">
            <h2><?= t('leverage.rules.title') ?></h2>
            <button type="button" class="btn btn-primary btn-sm" id="btn-new-rule">
                <?= t('leverage.rules.new') ?>
            </button>
        </div>

        <?php if (empty($rules)): ?>
            <div class="leverage-empty">
                <h3><?= t('leverage.rules.empty_title') ?></h3>
                <p><?= t('leverage.rules.empty_desc') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($rules as $rule):
                $ruleId = (int) $rule['id'];
                $status = $rule['status'] ?? 'active';
                $currencyCode = strtoupper(trim($rule['currency_code']));
                $referencePrice = (float) $rule['reference_price'];
                $currentPrice = $currentRates[$currencyCode] ?? 0;
                $changePercent = ($referencePrice > 0 && $currentPrice > 0)
                    ? (($currentPrice - $referencePrice) / $referencePrice) * 100
                    : 0;
                $buyThreshold = (float) $rule['buy_threshold'];
                $sellThreshold = (float) $rule['sell_threshold'];
                $sourceType = $rule['source_type'] ?? 'currency';
                $aiEnabled = (int) ($rule['ai_enabled'] ?? 1);
                $lastChecked = $rule['last_checked_at'] ?? null;

                // Progress bar calculation
                $totalRange = $sellThreshold - $buyThreshold;
                $buyZoneWidth = ($totalRange > 0) ? (abs($buyThreshold) / $totalRange) * 100 : 33;
                $sellZoneWidth = ($totalRange > 0) ? ($sellThreshold / $totalRange) * 100 : 33;
                $markerPosition = ($totalRange > 0)
                    ? (($changePercent - $buyThreshold) / $totalRange) * 100
                    : 50;
                $markerPosition = max(0, min(100, $markerPosition));
            ?>
                <div class="leverage-rule-card status-<?= htmlspecialchars($status) ?>"
                    data-rule-id="<?= $ruleId ?>"
                    data-name="<?= htmlspecialchars($rule['name'] ?? '') ?>"
                    data-source-type="<?= htmlspecialchars($sourceType) ?>"
                    data-source-id="<?= htmlspecialchars($rule['source_id'] ?? '') ?>"
                    data-currency-code="<?= htmlspecialchars($currencyCode) ?>"
                    data-buy-threshold="<?= htmlspecialchars((string) $buyThreshold) ?>"
                    data-sell-threshold="<?= htmlspecialchars((string) $sellThreshold) ?>"
                    data-reference-price="<?= htmlspecialchars((string) $referencePrice) ?>"
                    data-ai-enabled="<?= $aiEnabled ?>"
                    data-strategy-context="<?= htmlspecialchars($rule['strategy_context'] ?? '') ?>">

                    <div class="rule-card-header">
                        <div class="rule-card-title">
                            <span><?= htmlspecialchars($rule['name'] ?? '') ?></span>
                            <span class="currency-code"><?= htmlspecialchars($currencyCode) ?></span>
                            <span class="badge badge-<?= htmlspecialchars($sourceType) ?>"><?= htmlspecialchars(t('leverage.form.source_' . $sourceType)) ?></span>
                        </div>
                        <div>
                            <?php if ($status === 'active'): ?>
                                <span class="badge badge-active"><?= t('leverage.rules.status.active') ?></span>
                            <?php elseif ($status === 'paused'): ?>
                                <span class="badge badge-paused"><?= t('leverage.rules.status.paused') ?></span>
                            <?php else: ?>
                                <span class="badge badge-completed"><?= t('leverage.rules.status.completed') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rule-prices">
                        <div>
                            <span class="price-label"><?= t('leverage.rules.reference') ?></span><br>
                            <span class="price-value"><?= number_format($referencePrice, 2, ',', '.') ?> &#8378;</span>
                        </div>
                        <span class="price-arrow">&rarr;</span>
                        <div>
                            <span class="price-label"><?= t('leverage.rules.current') ?></span><br>
                            <span class="price-value"><?= $currentPrice > 0 ? number_format($currentPrice, 2, ',', '.') . ' &#8378;' : '—' ?></span>
                        </div>
                        <?php if ($currentPrice > 0): ?>
                            <div>
                                <span class="price-label"><?= t('leverage.rules.change') ?></span><br>
                                <span class="price-value <?= $changePercent >= 0 ? 'change-positive' : 'change-negative' ?>">
                                    <?= ($changePercent >= 0 ? '+' : '') . number_format($changePercent, 2, ',', '.') ?>%
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Progress Bar -->
                    <div class="leverage-progress">
                        <div class="leverage-progress-buy" style="width: <?= number_format($buyZoneWidth, 1) ?>%"></div>
                        <div class="leverage-progress-sell" style="width: <?= number_format($sellZoneWidth, 1) ?>%"></div>
                        <?php if ($currentPrice > 0): ?>
                            <div class="leverage-progress-marker" style="left: <?= number_format($markerPosition, 1) ?>%"></div>
                        <?php endif; ?>
                    </div>
                    <div class="leverage-progress-labels">
                        <span class="label-buy"><?= t('leverage.rules.buy_zone') ?> <?= number_format($buyThreshold, 1) ?>%</span>
                        <span><?= t('leverage.rules.waiting') ?></span>
                        <span class="label-sell"><?= t('leverage.rules.sell_zone') ?> +<?= number_format($sellThreshold, 1) ?>%</span>
                    </div>

                    <div class="rule-card-meta">
                        <?php if ($lastChecked): ?>
                            <span><?= t('leverage.rules.last_check') ?>: <?= htmlspecialchars($lastChecked) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= $aiEnabled ? 'badge-ai-on' : 'badge-ai-off' ?>">
                            <?= t('leverage.rules.ai_status') ?>: <?= $aiEnabled ? 'ON' : 'OFF' ?>
                        </span>
                    </div>

                    <div class="rule-card-actions">
                        <button type="button" class="btn btn-secondary btn-edit-rule"><?= t('portfolio.form.update') ?></button>

                        <?php if ($status === 'active'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="pause_rule">
                                <input type="hidden" name="rule_id" value="<?= $ruleId ?>">
                                <button type="submit" class="btn btn-secondary"><?= t('leverage.rules.pause') ?></button>
                            </form>
                        <?php elseif ($status === 'paused'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="resume_rule">
                                <input type="hidden" name="rule_id" value="<?= $ruleId ?>">
                                <button type="submit" class="btn btn-secondary"><?= t('leverage.rules.resume') ?></button>
                            </form>
                        <?php endif; ?>

                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_reference">
                            <input type="hidden" name="rule_id" value="<?= $ruleId ?>">
                            <button type="submit" class="btn btn-secondary"><?= t('leverage.rules.update_reference') ?></button>
                        </form>

                        <form method="POST" style="display:inline" class="form-delete-rule">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_rule">
                            <input type="hidden" name="rule_id" value="<?= $ruleId ?>">
                            <button type="submit" class="btn btn-danger"><?= t('common.delete') ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- ─── Signal History Table ──────────────────────────────────────── -->
        <section class="leverage-history-section">
            <h2><?= t('leverage.history.title') ?></h2>

            <?php if (empty($history)): ?>
                <div class="leverage-empty">
                    <p><?= t('leverage.history.empty') ?></p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="leverage-history-table">
                        <thead>
                            <tr>
                                <th><?= t('leverage.history.date') ?></th>
                                <th><?= t('leverage.history.rule') ?></th>
                                <th><?= t('leverage.history.event') ?></th>
                                <th><?= t('leverage.history.price') ?></th>
                                <th><?= t('leverage.history.change') ?></th>
                                <th><?= t('leverage.history.ai_recommendation') ?></th>
                                <th><?= t('leverage.history.notification') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h):
                                $eventType = $h['event_type'] ?? '';
                                $aiRec = $h['ai_recommendation'] ?? null;
                                $changeP = (float) ($h['change_percent'] ?? 0);
                                $notifSent = (int) ($h['notification_sent'] ?? 0);
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($h['created_at'] ?? '') ?></td>
                                    <td>
                                        <?= htmlspecialchars($h['rule_name'] ?? '') ?>
                                        <small style="color:var(--text-muted)">(<?= htmlspecialchars($h['currency_code'] ?? '') ?>)</small>
                                    </td>
                                    <td>
                                        <?php if ($eventType === 'buy_signal'): ?>
                                            <span class="event-buy"><?= t('leverage.history.event.buy_signal') ?></span>
                                        <?php elseif ($eventType === 'sell_signal'): ?>
                                            <span class="event-sell"><?= t('leverage.history.event.sell_signal') ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars(t('leverage.history.event.' . $eventType) ?: $eventType) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family:var(--mono)">
                                        <?= number_format((float) ($h['price_at_event'] ?? 0), 2, ',', '.') ?> &#8378;
                                    </td>
                                    <td class="<?= $changeP >= 0 ? 'change-positive' : 'change-negative' ?>" style="font-family:var(--mono)">
                                        <?= ($changeP >= 0 ? '+' : '') . number_format($changeP, 2, ',', '.') ?>%
                                    </td>
                                    <td>
                                        <?php if ($aiRec): ?>
                                            <span class="ai-rec ai-rec-<?= htmlspecialchars($aiRec) ?>">
                                                <?= htmlspecialchars(t('leverage.ai.' . $aiRec) ?: $aiRec) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)"><?= t('leverage.ai.no_analysis') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($notifSent): ?>
                                            <span class="notification-sent" title="Email sent">&#10003;</span>
                                        <?php else: ?>
                                            <span class="notification-pending">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- ─── Create/Edit Modal ──────────────────────────────────────────────── -->
    <div class="modal-overlay" id="rule-modal">
        <div class="modal-content">
            <button type="button" class="modal-close" id="modal-close">&times;</button>
            <h2 id="modal-title"><?= t('leverage.form.title') ?></h2>

            <form method="POST" id="rule-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" id="form-action" value="create_rule">
                <input type="hidden" name="rule_id" id="form-rule-id" value="">

                <div class="form-group">
                    <label for="rule-name"><?= t('leverage.form.name') ?></label>
                    <input type="text" id="rule-name" name="name" maxlength="100" required
                        placeholder="<?= htmlspecialchars(t('leverage.form.name_placeholder')) ?>">
                </div>

                <div class="form-group">
                    <label><?= t('leverage.form.source_type') ?></label>
                    <div class="form-radio-group">
                        <label>
                            <input type="radio" name="source_type" value="currency" checked>
                            <?= t('leverage.form.source_currency') ?>
                        </label>
                        <label>
                            <input type="radio" name="source_type" value="group">
                            <?= t('leverage.form.source_group') ?>
                        </label>
                        <label>
                            <input type="radio" name="source_type" value="tag">
                            <?= t('leverage.form.source_tag') ?>
                        </label>
                    </div>
                </div>

                <div class="form-group" id="group-select-group" style="display:none">
                    <label for="source-group"><?= t('leverage.form.group') ?></label>
                    <select id="source-group" name="source_id">
                        <option value=""><?= t('portfolio.form.select') ?></option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="tag-select-group" style="display:none">
                    <label for="source-tag"><?= t('leverage.form.tag') ?></label>
                    <select id="source-tag" name="source_id">
                        <option value=""><?= t('portfolio.form.select') ?></option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>"><?= htmlspecialchars($tag['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="rule-currency"><?= t('leverage.form.currency') ?></label>
                    <select id="rule-currency" name="currency_code" required>
                        <option value=""><?= t('portfolio.form.select') ?></option>
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= htmlspecialchars($c['code']) ?>">
                                <?= htmlspecialchars($c['code']) ?> — <?= htmlspecialchars($currentLocale === 'tr' ? ($c['name_tr'] ?? $c['code']) : ($c['name_en'] ?? $c['code'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="rule-reference-price"><?= t('leverage.form.reference_price') ?></label>
                    <div class="form-inline-btn">
                        <input type="number" id="rule-reference-price" name="reference_price" step="0.01" min="0.01" required>
                        <button type="button" class="btn btn-secondary" id="btn-get-current-price"><?= t('leverage.form.get_current') ?></button>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rule-buy-threshold"><?= t('leverage.form.buy_threshold') ?></label>
                        <input type="number" id="rule-buy-threshold" name="buy_threshold" step="0.01" value="-15.00" required>
                    </div>
                    <div class="form-group">
                        <label for="rule-sell-threshold"><?= t('leverage.form.sell_threshold') ?></label>
                        <input type="number" id="rule-sell-threshold" name="sell_threshold" step="0.01" value="30.00" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-toggle">
                        <input type="checkbox" id="rule-ai-enabled" name="ai_enabled" value="1" checked>
                        <label for="rule-ai-enabled"><?= t('leverage.form.ai_enabled') ?></label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="rule-strategy"><?= t('leverage.form.strategy_context') ?></label>
                    <textarea id="rule-strategy" name="strategy_context" maxlength="500"
                        placeholder="<?= htmlspecialchars(t('leverage.form.strategy_placeholder')) ?>"></textarea>
                    <div class="form-hint"><span id="strategy-chars">0</span>/500</div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="form-submit-btn"><?= t('leverage.form.submit') ?></button>
                    <button type="button" class="btn btn-secondary" id="form-cancel-btn"><?= t('leverage.form.cancel') ?></button>
                </div>
            </form>
        </div>
    </div>

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

    <script nonce="<?= getCspNonce() ?>">
        var leverageCurrentRates = <?= json_encode($currentRates, JSON_UNESCAPED_UNICODE) ?>;
        var leverageDeleteConfirmText = <?= json_encode(t('leverage.rules.delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var leverageFormTitleCreate = <?= json_encode(t('leverage.form.title'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var leverageFormTitleEdit = <?= json_encode(t('leverage.form.edit_title'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var leverageFormSubmitCreate = <?= json_encode(t('leverage.form.submit'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var leverageFormSubmitUpdate = <?= json_encode(t('leverage.form.update'), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/js/leverage.js?v=<?= time() ?>" defer></script>
</body>

</html>

<?php
/**
 * admin.php — Admin Dashboard: Banks, Currencies, Users
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'admin.php'));
    exit;
}

// Handle POST actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: admin.php');
        exit;
    }

    if ($_POST['action'] === 'update_rates') {
        $result = executeRateUpdate();
        if ($result['success']) {
            $message = t('admin.rates_updated_success');
            $messageType = 'success';
        } else {
            $message = t('admin.rates_updated_error') . ': ' . $result['message'];
            $messageType = 'error';
        }
    }

    if ($_POST['action'] === 'toggle_bank' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $bank = Database::queryOne('SELECT is_active, name FROM banks WHERE id = ?', [$id]);
        if ($bank) {
            $new = $bank['is_active'] ? 0 : 1;
            Database::update('banks', ['is_active' => $new], 'id = ?', [$id]);
            $message = htmlspecialchars($bank['name']) . ': ' . ($new ? t('admin.active') : t('admin.inactive'));
            $messageType = 'success';
        }
    }
    if ($_POST['action'] === 'toggle_currency' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $cur = Database::queryOne('SELECT is_active, code FROM currencies WHERE id = ?', [$id]);
        if ($cur) {
            $new = $cur['is_active'] ? 0 : 1;
            Database::update('currencies', ['is_active' => $new], 'id = ?', [$id]);
            $message = htmlspecialchars($cur['code']) . ': ' . ($new ? t('admin.active') : t('admin.inactive'));
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'toggle_homepage' && isset($_POST['rate_id'])) {
        $rateId = (int) $_POST['rate_id'];
        $rate = Database::queryOne('SELECT show_on_homepage FROM rates WHERE id = ?', [$rateId]);
        if ($rate) {
            $new = $rate['show_on_homepage'] ? 0 : 1;
            Database::update('rates', ['show_on_homepage' => $new], 'id = ?', [$rateId]);
            $message = $new ? t('admin.rate_shown_on_homepage') : t('admin.rate_hidden_from_homepage');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_default_bank' && isset($_POST['default_bank'])) {
        $rawBank = trim((string) $_POST['default_bank']);
        $defaultBank = ($rawBank === 'all') ? 'all' : normalizeBankSlug($rawBank);
        if ($defaultBank !== null && ($defaultBank === 'all' || Database::queryOne('SELECT id FROM banks WHERE slug = ? AND is_active = 1', [$defaultBank]))) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['default_bank', $defaultBank, $defaultBank]
            );
            $message = t('admin.default_bank_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'update_rate_order' && isset($_POST['rate_orders'])) {
        $rateOrders = json_decode($_POST['rate_orders'], true);
        if (is_array($rateOrders)) {
            foreach ($rateOrders as $rateId => $order) {
                Database::update('rates', ['display_order' => (int) $order], 'id = ?', [(int) $rateId]);
            }
            // If AJAX request, respond JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'ok', 'message' => t('admin.rate_order_updated')]);
                exit;
            }
            $message = t('admin.rate_order_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_chart_defaults' && isset($_POST['chart_currency']) && isset($_POST['chart_days'])) {
        $chartCurrency = normalizeCurrencyCode(trim($_POST['chart_currency']));
        $chartDays = max(1, min(3650, (int) $_POST['chart_days']));
        if ($chartCurrency !== null && Database::queryOne('SELECT id FROM currencies WHERE code = ? AND is_active = 1', [$chartCurrency])) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['chart_default_currency', $chartCurrency, $chartCurrency]
            );
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['chart_default_days', (string) $chartDays, (string) $chartDays]
            );
            $message = t('admin.chart_defaults_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'save_widget_config' && isset($_POST['widget_config'])) {
        $widgetConfig = json_decode($_POST['widget_config'], true);
        if (is_array($widgetConfig)) {
            $json = json_encode($widgetConfig, JSON_UNESCAPED_UNICODE);
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['widget_config', $json, $json]
            );
            // If AJAX request, respond JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'ok', 'message' => t('admin.widget_config_updated')]);
                exit;
            }
            $message = t('admin.widget_config_updated');
            $messageType = 'success';
        }
    }

    if ($_POST['action'] === 'set_retention_days' && isset($_POST['retention_days'])) {
        $retDays = max(30, min(3650, (int) $_POST['retention_days']));
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['rate_history_retention_days', (string) $retDays, (string) $retDays]
        );
        $message = t('admin.retention_updated');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'toggle_noindex') {
        $currentNoindex = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['site_noindex']);
        $newValue = ($currentNoindex && ($currentNoindex['value'] ?? '0') === '1') ? '0' : '1';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['site_noindex', $newValue, $newValue]
        );
        $message = t('admin.noindex_updated');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'save_deposit_rate' && isset($_POST['deposit_interest_rate'])) {
        $depositRate = max(0, min(200, (float) $_POST['deposit_interest_rate']));
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['deposit_interest_rate', (string) $depositRate, (string) $depositRate]
        );
        $message = t('admin.deposit_rate_updated');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'toggle_deposit_comparison') {
        $currentValue = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_comparison_enabled']);
        $newValue = ($currentValue && ($currentValue['value'] ?? '1') === '1') ? '0' : '1';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['deposit_comparison_enabled', $newValue, $newValue]
        );
        $message = t('admin.deposit_comparison_toggled');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'toggle_layout_default') {
        $currentLayout = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['layout_fullwidth_default']);
        $newValue = ($currentLayout && ($currentLayout['value'] ?? '0') === '1') ? '0' : '1';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['layout_fullwidth_default', $newValue, $newValue]
        );
        $message = t('admin.layout_default_toggled');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'save_openrouter_settings') {
        $orApiKey = trim((string) ($_POST['openrouter_api_key'] ?? ''));
        $orModel = trim((string) ($_POST['openrouter_model'] ?? ''));

        if ($orApiKey !== '' && strlen($orApiKey) <= 500 && preg_match('/^[a-zA-Z0-9_\-:.]+$/', $orApiKey)) {
            $encryptedKey = encryptSettingValue($orApiKey);
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['openrouter_api_key', $encryptedKey, $encryptedKey]
            );
        }

        if ($orModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $orModel)) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['openrouter_model', $orModel, $orModel]
            );
        }

        $message = t('admin.openrouter_settings_saved');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'save_leverage_settings') {
        $leverageEnabled = isset($_POST['leverage_enabled']) ? '1' : '0';
        $leverageAiEnabled = isset($_POST['leverage_ai_enabled']) ? '1' : '0';
        $leverageAiModel = trim((string) ($_POST['leverage_ai_model'] ?? ''));
        $leverageCheckInterval = max(5, (int) ($_POST['leverage_check_interval_minutes'] ?? 15));
        $leverageCooldown = max(15, (int) ($_POST['leverage_cooldown_minutes'] ?? 60));
        $sendgridEnabled = isset($_POST['sendgrid_enabled']) ? '1' : '0';
        $sendgridApiKey = trim((string) ($_POST['sendgrid_api_key'] ?? ''));
        $sendgridFromEmail = trim((string) ($_POST['sendgrid_from_email'] ?? ''));
        $sendgridFromName = trim((string) ($_POST['sendgrid_from_name'] ?? ''));
        $leverageNotifyEmailsRaw = trim((string) ($_POST['leverage_notify_emails'] ?? ''));

        $upsertSettings = [
            'leverage_enabled' => $leverageEnabled,
            'leverage_ai_enabled' => $leverageAiEnabled,
            'leverage_check_interval_minutes' => (string) $leverageCheckInterval,
            'leverage_cooldown_minutes' => (string) $leverageCooldown,
            'sendgrid_enabled' => $sendgridEnabled,
        ];

        // AI model
        if ($leverageAiModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $leverageAiModel)) {
            $upsertSettings['leverage_ai_model'] = $leverageAiModel;
        }

        // SendGrid API key — encrypt if non-empty, skip if empty (don't overwrite)
        if ($sendgridApiKey !== '' && strlen($sendgridApiKey) <= 500) {
            $upsertSettings['sendgrid_api_key'] = encryptSettingValue($sendgridApiKey);
        }

        // SendGrid from email — validate
        if ($sendgridFromEmail !== '' && filter_var($sendgridFromEmail, FILTER_VALIDATE_EMAIL)) {
            $upsertSettings['sendgrid_from_email'] = $sendgridFromEmail;
        }

        // SendGrid from name
        if ($sendgridFromName !== '') {
            $upsertSettings['sendgrid_from_name'] = substr($sendgridFromName, 0, 100);
        }

        // Notification emails — comma-separated → JSON array, validate each, max 10
        if ($leverageNotifyEmailsRaw !== '') {
            $emailParts = array_map('trim', explode(',', $leverageNotifyEmailsRaw));
            $validEmails = [];
            foreach ($emailParts as $emailPart) {
                if ($emailPart !== '' && filter_var($emailPart, FILTER_VALIDATE_EMAIL) && count($validEmails) < 10) {
                    $validEmails[] = $emailPart;
                }
            }
            $upsertSettings['leverage_notify_emails'] = json_encode(array_values($validEmails), JSON_UNESCAPED_UNICODE);
        } else {
            $upsertSettings['leverage_notify_emails'] = json_encode([], JSON_UNESCAPED_UNICODE);
        }

        foreach ($upsertSettings as $settKey => $settVal) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                [$settKey, $settVal, $settVal]
            );
        }

        // Telegram
        $telegramEnabledPost = isset($_POST['telegram_enabled']) ? '1' : '0';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['telegram_enabled', $telegramEnabledPost, $telegramEnabledPost]
        );

        if (!empty($_POST['telegram_bot_token'])) {
            $token = trim($_POST['telegram_bot_token']);
            // Only update if not the masked placeholder
            if ($token !== '••••••••' && strpos($token, '•') === false) {
                $encToken = encryptSettingValue($token);
                Database::query(
                    'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                    ['telegram_bot_token', $encToken, $encToken]
                );
            }
        }

        if (isset($_POST['telegram_chat_id'])) {
            $telegramChatIdPost = trim($_POST['telegram_chat_id']);
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['telegram_chat_id', $telegramChatIdPost, $telegramChatIdPost]
            );
        }

        // Webhook
        $webhookEnabledPost = isset($_POST['webhook_enabled']) ? '1' : '0';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['webhook_enabled', $webhookEnabledPost, $webhookEnabledPost]
        );

        // Backtesting
        $backtestingEnabledPost = isset($_POST['backtesting_enabled']) ? '1' : '0';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['backtesting_enabled', $backtestingEnabledPost, $backtestingEnabledPost]
        );

        $backtestingDefaultSourcePost = $_POST['backtesting_default_source'] ?? 'rate_history';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['backtesting_default_source', $backtestingDefaultSourcePost, $backtestingDefaultSourcePost]
        );

        if (!empty($_POST['backtesting_metals_dev_api_key'])) {
            $key = trim($_POST['backtesting_metals_dev_api_key']);
            if ($key !== '••••••••' && strpos($key, '•') === false) {
                $encKey = encryptSettingValue($key);
                Database::query(
                    'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                    ['backtesting_metals_dev_api_key', $encKey, $encKey]
                );
            }
        }

        if (!empty($_POST['backtesting_exchangerate_host_api_key'])) {
            $key = trim($_POST['backtesting_exchangerate_host_api_key']);
            if ($key !== '••••••••' && strpos($key, '•') === false) {
                $encKey = encryptSettingValue($key);
                Database::query(
                    'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                    ['backtesting_exchangerate_host_api_key', $encKey, $encKey]
                );
            }
        }

        // Weekly Report
        $weeklyReportEnabledPost = isset($_POST['weekly_report_enabled']) ? '1' : '0';
        Database::query(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            ['leverage_weekly_report_enabled', $weeklyReportEnabledPost, $weeklyReportEnabledPost]
        );

        $allowedDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $weeklyReportDayPost = strtolower(trim($_POST['weekly_report_day'] ?? 'monday'));
        if (in_array($weeklyReportDayPost, $allowedDays, true)) {
            Database::query(
                'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
                ['leverage_weekly_report_day', $weeklyReportDayPost, $weeklyReportDayPost]
            );
        }

        $message = t('admin.leverage.saved');
        $messageType = 'success';
    }

    if ($_POST['action'] === 'test_leverage_email') {
        require_once __DIR__ . '/includes/SendGridMailer.php';
        $notifyEmails = SendGridMailer::getNotifyEmails();
        if (empty($notifyEmails)) {
            $message = t('admin.leverage.test_email_error', ['error' => 'No notification recipients configured']);
            $messageType = 'error';
        } else {
            $testSubject = '[Cybokron] Test Email';
            $testTimestamp = date('Y-m-d H:i:s');
            $testHtml = '<p>This is a test email from Cybokron Leverage system.</p><p>Timestamp: ' . htmlspecialchars($testTimestamp) . '</p>';
            $testText = 'This is a test email from Cybokron Leverage system. Timestamp: ' . $testTimestamp;
            $result = SendGridMailer::send($notifyEmails, $testSubject, $testHtml, $testText);
            if ($result['success']) {
                $message = t('admin.leverage.test_email_success');
                $messageType = 'success';
            } else {
                $message = t('admin.leverage.test_email_error', ['error' => $result['error']]);
                $messageType = 'error';
            }
        }
    }

    if ($_POST['action'] === 'test_telegram') {
        require_once __DIR__ . '/includes/TelegramNotifier.php';
        $telegram = new TelegramNotifier(Database::getInstance());
        $result = $telegram->sendTestMessage();
        if ($result['success']) {
            $message = t('admin.leverage.telegram_test_success');
            $messageType = 'success';
        } else {
            $message = t('admin.leverage.telegram_test_fail') . ': ' . $result['error'];
            $messageType = 'error';
        }
    }

    if ($_POST['action'] === 'test_leverage_signal_buy' || $_POST['action'] === 'test_leverage_signal_sell') {
        require_once __DIR__ . '/includes/LeverageEngine.php';
        $dir = $_POST['action'] === 'test_leverage_signal_buy' ? 'buy' : 'sell';
        $result = LeverageEngine::sendTestSignal($dir);
        if ($result['success']) {
            $message = t('admin.leverage.test_signal_success', ['direction' => $dir === 'buy' ? t('leverage.email.signal_buy') : t('leverage.email.signal_sell')]);
            $messageType = 'success';
        } else {
            $message = t('admin.leverage.test_email_error', ['error' => $result['error'] ?? 'Unknown error']);
            $messageType = 'error';
        }
    }

    if (!in_array($_POST['action'], ['update_rates', 'toggle_bank', 'toggle_currency', 'toggle_homepage', 'set_default_bank', 'update_rate_order', 'set_chart_defaults', 'save_widget_config', 'toggle_noindex', 'set_retention_days', 'save_deposit_rate', 'toggle_deposit_comparison', 'save_openrouter_settings', 'toggle_layout_default', 'save_leverage_settings', 'test_leverage_email', 'test_leverage_signal_buy', 'test_leverage_signal_sell', 'test_telegram'], true)) {
        header('Location: admin.php');
        exit;
    }
}

$banks = Database::query('SELECT id, name, slug, is_active, last_scraped_at FROM banks ORDER BY name');
$currencies = Database::query('SELECT id, code, name_tr, name_en, is_active, type FROM currencies ORDER BY code');
$users = Database::query('SELECT id, username, role, is_active, created_at FROM users ORDER BY username');

$allRates = Database::query('
    SELECT 
        r.id,
        r.show_on_homepage,
        r.display_order,
        r.buy_rate,
        r.sell_rate,
        r.scraped_at,
        c.code AS currency_code,
        c.name_tr AS currency_name_tr,
        c.name_en AS currency_name_en,
        b.name AS bank_name,
        b.slug AS bank_slug
    FROM rates r
    JOIN currencies c ON c.id = r.currency_id
    JOIN banks b ON b.id = r.bank_id
    WHERE b.is_active = 1 AND c.is_active = 1
    ORDER BY r.display_order ASC, b.name, c.code
');

$activeCurrencies = Database::query('SELECT code, name_tr, name_en FROM currencies WHERE is_active = 1 ORDER BY code');
$lastRateUpdate = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['last_rate_update']);
$defaultBank = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['default_bank']);
$defaultBankValue = $defaultBank['value'] ?? 'all';
$chartDefaultCurrency = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_currency']);
$chartDefaultCurrencyValue = $chartDefaultCurrency['value'] ?? 'USD';
$chartDefaultDays = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['chart_default_days']);
$chartDefaultDaysValue = (int) ($chartDefaultDays['value'] ?? 30);
$retentionDaysRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['rate_history_retention_days']);
$retentionDaysValue = (int) ($retentionDaysRow['value'] ?? (defined('RATE_HISTORY_RETENTION_DAYS') ? RATE_HISTORY_RETENTION_DAYS : 1825));
$depositRateRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_interest_rate']);
$depositRateValue = $depositRateRow ? (float) $depositRateRow['value'] : 40.0;
$depositComparisonRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['deposit_comparison_enabled']);
$isDepositComparisonEnabled = !$depositComparisonRow || ($depositComparisonRow['value'] ?? '1') === '1';
$currentLocale = getAppLocale();
$csrfToken = getCsrfToken();
$version = getAppVersion();

// Widget configuration
$widgetConfigRaw = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['widget_config']);
$defaultWidgets = [
    ['id' => 'bank_selector', 'visible' => true, 'order' => 0],
    ['id' => 'converter', 'visible' => true, 'order' => 1],
    ['id' => 'widgets', 'visible' => true, 'order' => 2],
    ['id' => 'chart', 'visible' => true, 'order' => 3],
    ['id' => 'rates', 'visible' => true, 'order' => 4],
];
$widgetConfig = $defaultWidgets;
if (!empty($widgetConfigRaw['value'])) {
    $parsed = json_decode($widgetConfigRaw['value'], true);
    if (is_array($parsed)) {
        $widgetConfig = $parsed;
    }
}
usort($widgetConfig, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

// Leverage settings
$leverageEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_enabled']);
$leverageEnabledVal = $leverageEnabledRow ? $leverageEnabledRow['value'] : (defined('LEVERAGE_ENABLED') ? (LEVERAGE_ENABLED ? '1' : '0') : '0');
$leverageAiEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_ai_enabled']);
$leverageAiEnabledVal = $leverageAiEnabledRow ? $leverageAiEnabledRow['value'] : (defined('LEVERAGE_AI_ENABLED') ? (LEVERAGE_AI_ENABLED ? '1' : '0') : '0');
$leverageAiModelRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_ai_model']);
$leverageAiModelVal = trim($leverageAiModelRow['value'] ?? '');
if ($leverageAiModelVal === '') {
    $leverageAiModelVal = defined('LEVERAGE_AI_MODEL') ? trim((string) LEVERAGE_AI_MODEL) : 'google/gemini-3.1-pro-preview';
}
$leverageCheckIntervalRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_check_interval_minutes']);
$leverageCheckIntervalVal = (int) ($leverageCheckIntervalRow['value'] ?? (defined('LEVERAGE_CHECK_INTERVAL_MINUTES') ? LEVERAGE_CHECK_INTERVAL_MINUTES : 15));
$leverageCooldownRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_cooldown_minutes']);
$leverageCooldownVal = (int) ($leverageCooldownRow['value'] ?? (defined('LEVERAGE_COOLDOWN_MINUTES') ? LEVERAGE_COOLDOWN_MINUTES : 60));
$sendgridEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['sendgrid_enabled']);
$sendgridEnabledVal = $sendgridEnabledRow ? $sendgridEnabledRow['value'] : (defined('SENDGRID_ENABLED') ? (SENDGRID_ENABLED ? '1' : '0') : '0');
$sendgridApiKeyRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['sendgrid_api_key']);
$sendgridApiKeyDbVal = trim($sendgridApiKeyRow['value'] ?? '');
$sendgridApiKeyExists = false;
$sendgridApiKeyMasked = '';
if ($sendgridApiKeyDbVal !== '') {
    $decrypted = decryptSettingValue($sendgridApiKeyDbVal);
    if ($decrypted !== '') {
        $sendgridApiKeyExists = true;
        $sendgridApiKeyMasked = str_repeat('•', 8) . substr($decrypted, -4);
    }
} elseif (defined('SENDGRID_API_KEY') && trim((string) SENDGRID_API_KEY) !== '') {
    $sendgridApiKeyExists = true;
    $configKey = trim((string) SENDGRID_API_KEY);
    $sendgridApiKeyMasked = str_repeat('•', 8) . substr($configKey, -4);
}
$sendgridFromEmailRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['sendgrid_from_email']);
$sendgridFromEmailVal = trim($sendgridFromEmailRow['value'] ?? '');
if ($sendgridFromEmailVal === '') {
    $sendgridFromEmailVal = defined('SENDGRID_FROM_EMAIL') ? trim((string) SENDGRID_FROM_EMAIL) : '';
}
$sendgridFromNameRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['sendgrid_from_name']);
$sendgridFromNameVal = trim($sendgridFromNameRow['value'] ?? '');
if ($sendgridFromNameVal === '') {
    $sendgridFromNameVal = defined('SENDGRID_FROM_NAME') ? trim((string) SENDGRID_FROM_NAME) : 'Cybokron';
}
$leverageNotifyEmailsRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_notify_emails']);
$leverageNotifyEmailsJson = trim($leverageNotifyEmailsRow['value'] ?? '');
$leverageNotifyEmailsList = [];
if ($leverageNotifyEmailsJson !== '') {
    $decoded = json_decode($leverageNotifyEmailsJson, true);
    if (is_array($decoded)) {
        $leverageNotifyEmailsList = array_filter($decoded, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
    }
}
$leverageNotifyEmailsDisplay = implode(', ', $leverageNotifyEmailsList);

// Telegram
$telegramEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['telegram_enabled']);
$telegramEnabled = $telegramEnabledRow ? $telegramEnabledRow['value'] : (defined('LEVERAGE_TELEGRAM_ENABLED') ? (LEVERAGE_TELEGRAM_ENABLED ? '1' : '0') : '0');
$telegramBotTokenRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['telegram_bot_token']);
$telegramBotToken = trim($telegramBotTokenRow['value'] ?? '');
$telegramBotTokenMasked = !empty($telegramBotToken) ? '••••••••' : '';
$telegramChatIdRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['telegram_chat_id']);
$telegramChatId = trim($telegramChatIdRow['value'] ?? '');
if ($telegramChatId === '' && defined('ALERT_TELEGRAM_CHAT_ID')) {
    $telegramChatId = (string) ALERT_TELEGRAM_CHAT_ID;
}

// Webhook
$webhookEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['webhook_enabled']);
$webhookEnabled = $webhookEnabledRow ? $webhookEnabledRow['value'] : (defined('LEVERAGE_WEBHOOK_ENABLED') ? (LEVERAGE_WEBHOOK_ENABLED ? '1' : '0') : '0');

// Backtesting
$backtestingEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['backtesting_enabled']);
$backtestingEnabled = $backtestingEnabledRow ? $backtestingEnabledRow['value'] : (defined('BACKTESTING_ENABLED') ? (BACKTESTING_ENABLED ? '1' : '0') : '1');
$backtestingDefaultSourceRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['backtesting_default_source']);
$backtestingDefaultSource = trim($backtestingDefaultSourceRow['value'] ?? '');
if ($backtestingDefaultSource === '') {
    $backtestingDefaultSource = defined('BACKTESTING_DEFAULT_SOURCE') ? BACKTESTING_DEFAULT_SOURCE : 'rate_history';
}
$backtestingMetalsDevKeyRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['backtesting_metals_dev_api_key']);
$backtestingMetalsDevKey = trim($backtestingMetalsDevKeyRow['value'] ?? '');
$backtestingMetalsDevKeyMasked = !empty($backtestingMetalsDevKey) ? '••••••••' : '';
$backtestingExchangeRateHostKeyRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['backtesting_exchangerate_host_api_key']);
$backtestingExchangeRateHostKey = trim($backtestingExchangeRateHostKeyRow['value'] ?? '');
$backtestingExchangeRateHostKeyMasked = !empty($backtestingExchangeRateHostKey) ? '••••••••' : '';

// Weekly Report
$weeklyReportEnabledRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_weekly_report_enabled']);
$weeklyReportEnabled = $weeklyReportEnabledRow ? $weeklyReportEnabledRow['value'] : (defined('LEVERAGE_WEEKLY_REPORT_ENABLED') ? (LEVERAGE_WEEKLY_REPORT_ENABLED ? '1' : '0') : '0');
$weeklyReportDayRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['leverage_weekly_report_day']);
$weeklyReportDay = trim($weeklyReportDayRow['value'] ?? '');
if ($weeklyReportDay === '') {
    $weeklyReportDay = defined('LEVERAGE_WEEKLY_REPORT_DAY') ? LEVERAGE_WEEKLY_REPORT_DAY : 'monday';
}

$widgetLabels = [
    'bank_selector' => '🏦 ' . t('admin.widget_bank_selector'),
    'converter' => '🔄 ' . t('admin.widget_converter'),
    'widgets' => '📊 ' . t('admin.widget_summary'),
    'chart' => '📈 ' . t('admin.widget_chart'),
    'rates' => '📋 ' . t('admin.widget_rates'),
];

// Collect unique bank names for filter
$bankNames = [];
foreach ($allRates as $r) {
    $bankNames[$r['bank_slug']] = $r['bank_name'];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>" data-layout-default="<?= isFullwidthDefault() ? 'fullwidth' : 'normal' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('admin.title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('admin.title') . ' — ' . APP_NAME,
    'description' => t('seo.admin_description'),
    'page' => 'admin.php',
]) ?>
    <script nonce="<?= htmlspecialchars(getCspNonce()) ?>">(function(){try{var t=localStorage.getItem('cybokron_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}else if(window.matchMedia('(prefers-color-scheme:light)').matches){document.documentElement.setAttribute('data-theme','light')}}catch(e){}})();</script>
    <script nonce="<?= htmlspecialchars(getCspNonce()) ?>">(function(){try{var l=localStorage.getItem('cybokron_layout');if(l!=='fullwidth'&&l!=='normal'){l=document.documentElement.getAttribute('data-layout-default')||'normal'}document.documentElement.setAttribute('data-layout',l)}catch(e){}})();</script>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>

<body>
    <?php $activePage = 'admin';
    include __DIR__ . '/includes/header.php'; ?>

    <div id="toast-container" class="toast-container"></div>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="admin-grid">

            <!-- Row 1: Health + Default Bank -->
            <div class="row-2">
                <!-- System Health -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon health">💚</div>
                            <div>
                                <h2><?= t('admin.health') ?></h2>
                                <p><?= t('admin.last_rate_update') ?>:
                                    <?= $lastRateUpdate && $lastRateUpdate['value'] ? formatDateTime($lastRateUpdate['value']) : t('common.not_available') ?>
                                </p>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center">
                            <button type="button" id="clear-cache-btn" class="btn btn-sm" style="white-space:nowrap"
                                onclick="clearServiceWorkerCache(this)">🧹 <?= t('admin.clear_cache') ?></button>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="action" value="update_rates">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <button type="submit" class="btn btn-primary" style="white-space:nowrap">🔄
                                    <?= t('admin.update_rates_now') ?></button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Default Bank -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon bank">🏦</div>
                            <div>
                                <h2><?= t('admin.default_bank_setting') ?></h2>
                                <p><?= t('admin.default_bank_desc') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="admin-card-body">
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="action" value="set_default_bank">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="form-field">
                                <label for="default_bank"><?= t('admin.default_bank') ?></label>
                                <select id="default_bank" name="default_bank">
                                    <option value="all" <?= $defaultBankValue === 'all' ? 'selected' : '' ?>>
                                        <?= t('admin.all_banks') ?>
                                    </option>
                                    <?php foreach ($banks as $bank): ?>
                                        <?php if ($bank['is_active']): ?>
                                            <option value="<?= htmlspecialchars($bank['slug']) ?>"
                                                <?= $defaultBankValue === $bank['slug'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($bank['name']) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Row 2: Chart Defaults -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon chart">📊</div>
                        <div>
                            <h2><?= t('admin.chart_defaults') ?></h2>
                            <p><?= t('admin.chart_defaults_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="action" value="set_chart_defaults">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="chart_currency"><?= t('admin.chart_currency') ?></label>
                            <select id="chart_currency" name="chart_currency">
                                <?php foreach ($activeCurrencies as $curr): ?>
                                    <option value="<?= htmlspecialchars($curr['code']) ?>"
                                        <?= $chartDefaultCurrencyValue === $curr['code'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($curr['code']) ?> —
                                        <?= htmlspecialchars(localizedCurrencyName($curr)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="chart_days"><?= t('admin.chart_days') ?></label>
                            <select id="chart_days" name="chart_days">
                                <?php foreach ([7, 30, 90, 180, 365] as $d): ?>
                                    <option value="<?= $d ?>" <?= $chartDefaultDaysValue === $d ? 'selected' : '' ?>><?= $d ?>
                                        <?= t('index.chart.days_unit') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>

            <!-- Data Retention -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #f59e0b20, #d9770620);">🗄️</div>
                        <div>
                            <h2><?= t('admin.retention_title') ?></h2>
                            <p><?= t('admin.retention_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="settings-form">
                        <input type="hidden" name="action" value="set_retention_days">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="retention_days"><?= t('admin.retention_label') ?></label>
                            <select id="retention_days" name="retention_days">
                                <?php foreach ([
                                    30 => '30 ' . t('index.chart.days_unit') . ' (1 ' . t('admin.retention_month') . ')',
                                    90 => '90 ' . t('index.chart.days_unit') . ' (3 ' . t('admin.retention_month') . ')',
                                    180 => '180 ' . t('index.chart.days_unit') . ' (6 ' . t('admin.retention_month') . ')',
                                    365 => '365 ' . t('index.chart.days_unit') . ' (1 ' . t('admin.retention_year') . ')',
                                    730 => '730 ' . t('index.chart.days_unit') . ' (2 ' . t('admin.retention_year') . ')',
                                    1095 => '1095 ' . t('index.chart.days_unit') . ' (3 ' . t('admin.retention_year') . ')',
                                    1825 => '1825 ' . t('index.chart.days_unit') . ' (5 ' . t('admin.retention_year') . ')',
                                    3650 => '3650 ' . t('index.chart.days_unit') . ' (10 ' . t('admin.retention_year') . ')',
                                ] as $days => $label): ?>
                                    <option value="<?= $days ?>" <?= $retentionDaysValue === $days ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p style="font-size:0.82rem; color:var(--text-muted); margin:0 0 12px;">
                            <?= t('admin.retention_hint') ?>
                        </p>
                        <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                    </form>
                </div>
            </div>

            <!-- Deposit Interest Rate -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #10b98120, #059e6020);">🏦</div>
                        <div>
                            <h2><?= t('admin.deposit_rate_title') ?></h2>
                            <p><?= t('admin.deposit_rate_desc') ?></p>
                        </div>
                    </div>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="toggle_deposit_comparison">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button type="submit" class="btn <?= $isDepositComparisonEnabled ? 'btn-action' : 'btn-primary' ?>" style="white-space:nowrap;">
                            <?= $isDepositComparisonEnabled ? '🔴 ' . t('admin.deposit_disable') : '🟢 ' . t('admin.deposit_enable') ?>
                        </button>
                    </form>
                </div>
                <div class="admin-card-body">
                    <p style="margin-bottom:8px;">
                        <span class="badge <?= $isDepositComparisonEnabled ? 'badge-success' : 'badge-muted' ?>">
                            <?= $isDepositComparisonEnabled ? t('admin.deposit_status_on') : t('admin.deposit_status_off') ?>
                        </span>
                    </p>
                    <form method="POST" class="settings-form" style="margin-bottom:16px;">
                        <input type="hidden" name="action" value="save_deposit_rate">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="form-field">
                            <label for="deposit_interest_rate"><?= t('admin.deposit_rate_label') ?></label>
                            <input type="number" id="deposit_interest_rate" name="deposit_interest_rate"
                                   value="<?= $depositRateValue ?>" step="0.1" min="0" max="200"
                                   style="max-width: 120px" <?= $isDepositComparisonEnabled ? '' : 'disabled' ?>>
                        </div>
                        <button type="submit" class="btn btn-primary" <?= $isDepositComparisonEnabled ? '' : 'disabled' ?>><?= t('admin.save') ?></button>
                    </form>
                    <div style="background: var(--bg-tertiary, #f0f4f8); border-radius: 8px; padding: 12px 16px; font-size: 0.85em; line-height: 1.6; color: var(--text-secondary, #64748b);">
                        <strong>ℹ️ <?= t('admin.deposit_info_title') ?></strong><br>
                        <?= t('admin.deposit_info_body') ?>
                    </div>
                </div>
            </div>

            <!-- Widget Management -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #06b6d420, #0891b220);">
                            🧩</div>
                        <div>
                            <h2><?= t('admin.widget_management') ?></h2>
                            <p><?= t('admin.widget_management_desc') ?></p>
                        </div>
                    </div>
                    <div class="save-status" id="widget-save-status"></div>
                </div>
                <div class="admin-card-body">
                    <div class="hint-box">
                        <strong>💡 <?= t('admin.drag_drop_hint') ?>:</strong>
                        <?= t('admin.widget_drag_desc') ?>
                    </div>
                    <ul id="widget-sortable-list" class="widget-config-list">
                        <?php foreach ($widgetConfig as $i => $w): ?>
                            <li class="widget-config-item sortable-row" draggable="true"
                                data-widget-id="<?= htmlspecialchars($w['id']) ?>">
                                <span class="drag-handle">⠿</span>
                                <span class="order-num"><?= $i + 1 ?></span>
                                <span
                                    class="widget-config-label"><?= $widgetLabels[$w['id']] ?? htmlspecialchars($w['id']) ?></span>
                                <label class="widget-toggle">
                                    <input type="checkbox" <?= ($w['visible'] ?? true) ? 'checked' : '' ?>
                                        data-widget-id="<?= htmlspecialchars($w['id']) ?>">
                                    <span class="widget-toggle-slider"></span>
                                    <span
                                        class="widget-toggle-text"><?= ($w['visible'] ?? true) ? t('admin.visible') : t('admin.hidden') ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- SEO Settings -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #10b98120, #059e6820);">🔍</div>
                        <div>
                            <h2><?= t('admin.seo_settings') ?></h2>
                            <p><?= t('admin.seo_settings_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <?php $isNoindex = isSiteNoindex(); ?>
                    <div class="settings-form" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:200px;">
                            <strong><?= t('admin.noindex_label') ?></strong>
                            <p style="margin:4px 0 0; font-size:0.85rem; color:var(--text-muted);"><?= t('admin.noindex_desc') ?></p>
                            <p style="margin:8px 0 0;">
                                <span class="badge <?= $isNoindex ? 'badge-muted' : 'badge-success' ?>">
                                    <?= $isNoindex ? t('admin.noindex_enabled') : t('admin.noindex_disabled') ?>
                                </span>
                            </p>
                        </div>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="toggle_noindex">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="btn <?= $isNoindex ? 'btn-primary' : 'btn-action' ?>" style="white-space:nowrap;">
                                <?= $isNoindex ? '🌐 ' . t('admin.show') : '🚫 ' . t('admin.hide') ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Layout Settings -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #8b5cf620, #6d28d920);">&#x229E;</div>
                        <div>
                            <h2><?= t('admin.layout_default_title') ?></h2>
                            <p><?= t('admin.layout_default_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <?php $isFullwidthDefault = isFullwidthDefault(); ?>
                    <div class="settings-form" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:200px;">
                            <strong><?= t('admin.layout_default_title') ?></strong>
                            <p style="margin:4px 0 0; font-size:0.85rem; color:var(--text-muted);"><?= t('admin.layout_default_desc') ?></p>
                            <p style="margin:8px 0 0;">
                                <span class="badge <?= $isFullwidthDefault ? 'badge-success' : 'badge-muted' ?>">
                                    <?= $isFullwidthDefault ? t('admin.layout_default_status_on') : t('admin.layout_default_status_off') ?>
                                </span>
                            </p>
                        </div>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="toggle_layout_default">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="btn <?= $isFullwidthDefault ? 'btn-action' : 'btn-primary' ?>" style="white-space:nowrap;">
                                <?= $isFullwidthDefault ? t('admin.layout_default_disable') : t('admin.layout_default_enable') ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Row 3: Banks -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon bank">🏛️</div>
                        <h2><?= t('admin.banks') ?></h2>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th><?= t('observability.bank') ?></th>
                                <th><?= t('admin.slug') ?></th>
                                <th><?= t('observability.last_scrape') ?></th>
                                <th><?= t('admin.status') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($banks as $b): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                                    <td><code
                                            style="font-size: 0.78rem; background: var(--surface-hover); padding: 2px 8px; border-radius: 4px;"><?= htmlspecialchars($b['slug']) ?></code>
                                    </td>
                                    <td><?= $b['last_scraped_at'] ? formatDateTime($b['last_scraped_at']) : '—' ?></td>
                                    <td><span
                                            class="badge <?= $b['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $b['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_bank">
                                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                            <button type="submit"
                                                class="btn-action"><?= $b['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Row 4: Homepage Rates (Drag & Drop) -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon rates">📋</div>
                        <div>
                            <h2><?= t('admin.homepage_rates') ?></h2>
                            <p><?= t('admin.homepage_rates_desc') ?></p>
                        </div>
                    </div>
                    <div id="save-status" class="save-status" aria-live="polite"></div>
                </div>
                <div class="admin-card-body" style="padding-top: 8px;">
                    <div class="hint-box">
                        <span>💡</span>
                        <span><strong><?= t('admin.drag_drop_hint') ?>:</strong> <?= t('admin.drag_drop_desc') ?></span>
                    </div>
                    <?php if (count($bankNames) > 1): ?>
                        <div class="filter-tabs" style="margin-bottom: 12px;">
                            <button class="filter-tab active" data-bank="all"><?= t('admin.all') ?></button>
                            <?php foreach ($bankNames as $slug => $name): ?>
                                <button class="filter-tab"
                                    data-bank="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($name) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="admin-table" id="rates-sortable-table">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th style="width:36px"></th>
                                <th><?= t('observability.bank') ?></th>
                                <th><?= t('index.table.currency') ?></th>
                                <th><?= t('index.table.code') ?></th>
                                <th class="text-right"><?= t('index.table.bank_buy') ?></th>
                                <th class="text-right"><?= t('index.table.bank_sell') ?></th>
                                <th><?= t('admin.homepage_visibility') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rates-sortable">
                            <?php $idx = 0;
                            foreach ($allRates as $rate):
                                $idx++; ?>
                                <tr class="sortable-row" data-rate-id="<?= (int) $rate['id'] ?>"
                                    data-bank="<?= htmlspecialchars($rate['bank_slug']) ?>" draggable="true">
                                    <td><span class="order-num"><?= $idx ?></span></td>
                                    <td class="drag-handle" title="<?= t('admin.drag_drop_hint') ?>">⋮⋮</td>
                                    <td><?= htmlspecialchars($rate['bank_name']) ?></td>
                                    <td><?= htmlspecialchars(localizedCurrencyName($rate)) ?></td>
                                    <td><strong><?= htmlspecialchars($rate['currency_code']) ?></strong></td>
                                    <td class="text-right mono"><?= formatRate((float) $rate['buy_rate']) ?></td>
                                    <td class="text-right mono"><?= formatRate((float) $rate['sell_rate']) ?></td>
                                    <td>
                                        <span
                                            class="badge <?= $rate['show_on_homepage'] ? 'badge-success' : 'badge-muted' ?>">
                                            <?= $rate['show_on_homepage'] ? '✓ ' . t('admin.visible') : '✗ ' . t('admin.hidden') ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_homepage">
                                            <input type="hidden" name="rate_id" value="<?= (int) $rate['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars($csrfToken) ?>">
                                            <button type="submit"
                                                class="btn-action"><?= $rate['show_on_homepage'] ? t('admin.hide') : t('admin.show') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Row 5: Currencies + Users -->
            <div class="row-2">
                <!-- Currencies -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon currency">💱</div>
                            <h2><?= t('admin.currencies') ?></h2>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th><?= t('admin.code') ?></th>
                                    <th><?= t('index.table.currency') ?></th>
                                    <th><?= t('admin.type') ?></th>
                                    <th><?= t('admin.status') ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currencies as $c): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
                                        <td><?= htmlspecialchars(localizedCurrencyName($c)) ?></td>
                                        <td><span
                                                class="badge badge-muted"><?= htmlspecialchars($c['type'] ?? 'fiat') ?></span>
                                        </td>
                                        <td><span
                                                class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $c['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                        </td>
                                        <td style="text-align:right">
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="action" value="toggle_currency">
                                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                <input type="hidden" name="csrf_token"
                                                    value="<?= htmlspecialchars($csrfToken) ?>">
                                                <button type="submit"
                                                    class="btn-action"><?= $c['is_active'] ? t('admin.deactivate') : t('admin.activate') ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Users -->
                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="admin-card-header-left">
                            <div class="admin-card-icon users">👥</div>
                            <h2><?= t('admin.users') ?></h2>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th><?= t('admin.id') ?></th>
                                    <th><?= t('auth.username') ?></th>
                                    <th><?= t('admin.role') ?></th>
                                    <th><?= t('admin.status') ?></th>
                                    <th><?= t('admin.created') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?= (int) $u['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                        <td><span
                                                class="badge badge-muted"><?= htmlspecialchars($u['role'] ?? 'user') ?></span>
                                        </td>
                                        <td><span
                                                class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-muted' ?>"><?= $u['is_active'] ? '● ' . t('admin.active') : '○ ' . t('admin.inactive') ?></span>
                                        </td>
                                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                                            <?= formatDateTime($u['created_at']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- OpenRouter AI Settings -->
            <?php
            $dbApiKey = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_api_key']);
            $dbApiKeyVal = trim($dbApiKey['value'] ?? '');
            if ($dbApiKeyVal !== '') {
                $dbApiKeyVal = decryptSettingValue($dbApiKeyVal);
            }
            $effectiveApiKey = $dbApiKeyVal !== '' ? $dbApiKeyVal : (defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '');
            $maskedKey = $effectiveApiKey !== '' ? str_repeat('*', max(0, strlen($effectiveApiKey) - 4)) . substr($effectiveApiKey, -4) : '';
            $keySource = $dbApiKeyVal !== '' ? 'DB' : ($effectiveApiKey !== '' ? 'config.php' : '');

            $dbModel = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_model']);
            $effectiveModel = trim($dbModel['value'] ?? '');
            if ($effectiveModel === '') {
                $effectiveModel = defined('OPENROUTER_MODEL') ? trim((string) OPENROUTER_MODEL) : 'z-ai/glm-5';
            }
            ?>
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #8b5cf620, #a78bfa20);">🤖</div>
                        <div>
                            <h2><?= t('admin.openrouter_settings') ?></h2>
                            <p><?= t('admin.openrouter_settings_desc') ?></p>
                        </div>
                    </div>
                    <?php if ($effectiveApiKey !== ''): ?>
                        <span class="badge badge-success">● <?= t('admin.config_set') ?></span>
                    <?php else: ?>
                        <span class="badge badge-muted">○ <?= t('admin.config_not_set') ?></span>
                    <?php endif; ?>
                </div>
                <div class="admin-card-body">
                    <form method="POST" class="or-settings-form">
                        <input type="hidden" name="action" value="save_openrouter_settings">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="or-field-group">
                            <div class="or-field">
                                <label for="openrouter_api_key"><?= t('admin.openrouter_api_key_label') ?></label>
                                <div class="or-input-wrapper">
                                    <input type="password" id="openrouter_api_key" name="openrouter_api_key"
                                           placeholder="<?= $maskedKey !== '' ? $maskedKey : 'sk-or-v1-...' ?>"
                                           autocomplete="off" spellcheck="false">
                                    <button type="button" class="or-toggle-vis" onclick="var i=document.getElementById('openrouter_api_key');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁️':'🙈'" title="<?= t('admin.openrouter_toggle_key') ?>">👁️</button>
                                </div>
                                <small class="or-field-hint">
                                    <?php if ($keySource === 'DB'): ?>
                                        <?= t('admin.openrouter_key_source_db') ?>
                                    <?php elseif ($keySource === 'config.php'): ?>
                                        <?= t('admin.openrouter_key_source_config') ?>
                                    <?php else: ?>
                                        <?= t('admin.openrouter_key_not_configured') ?>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="or-field">
                                <label for="openrouter_model"><?= t('admin.openrouter_model_label') ?></label>
                                <input type="text" id="openrouter_model" name="openrouter_model"
                                       value="<?= htmlspecialchars($effectiveModel) ?>"
                                       placeholder="z-ai/glm-5" spellcheck="false">
                                <small class="or-field-hint"><?= t('admin.openrouter_model_hint') ?></small>
                            </div>
                        </div>

                        <div class="or-actions">
                            <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                            <a href="openrouter.php" class="btn-action"><?= t('admin.openrouter_panel_link') ?> →</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Leverage Settings -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #f59e0b20, #ef444420);">&#9889;</div>
                        <div>
                            <h2><?= t('admin.leverage.title') ?></h2>
                            <p><?= t('admin.leverage.desc') ?></p>
                        </div>
                    </div>
                    <?php if ($leverageEnabledVal === '1'): ?>
                        <span class="badge badge-success">● <?= t('admin.config_set') ?></span>
                    <?php else: ?>
                        <span class="badge badge-muted">○ <?= t('admin.config_not_set') ?></span>
                    <?php endif; ?>
                </div>
                <div class="admin-card-body">
                    <form method="POST" action="admin.php" class="or-settings-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_leverage_settings">

                        <!-- General -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#9881;</span>
                                <span class="leverage-section-title"><?= t('admin.leverage.section_general') ?></span>
                            </div>
                            <div class="or-field-group" style="grid-template-columns: auto 1fr 1fr;">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="leverage_enabled" value="1" <?= $leverageEnabledVal === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="leverage_check_interval_minutes"><?= t('admin.leverage.check_interval') ?></label>
                                    <input type="number" id="leverage_check_interval_minutes" name="leverage_check_interval_minutes"
                                           value="<?= $leverageCheckIntervalVal ?>" min="5" step="1">
                                </div>
                                <div class="or-field">
                                    <label for="leverage_cooldown_minutes"><?= t('admin.leverage.cooldown') ?></label>
                                    <input type="number" id="leverage_cooldown_minutes" name="leverage_cooldown_minutes"
                                           value="<?= $leverageCooldownVal ?>" min="15" step="1">
                                </div>
                            </div>
                        </div>

                        <!-- AI -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#129302;</span>
                                <span class="leverage-section-title">AI <?= t('admin.leverage.section_analysis') ?></span>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="leverage_ai_enabled" value="1" <?= $leverageAiEnabledVal === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.ai_enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="leverage_ai_model"><?= t('admin.leverage.ai_model') ?></label>
                                    <input type="text" id="leverage_ai_model" name="leverage_ai_model"
                                           value="<?= htmlspecialchars($leverageAiModelVal) ?>"
                                           placeholder="google/gemini-3.1-pro-preview" spellcheck="false">
                                    <small class="or-field-hint"><?= t('admin.leverage.ai_model_desc') ?></small>
                                </div>
                            </div>
                        </div>

                        <!-- SendGrid -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#9993;</span>
                                <span class="leverage-section-title">SendGrid</span>
                                <?php if ($sendgridApiKeyExists): ?>
                                    <span class="badge badge-success" style="margin-left:8px; font-size:0.7rem;">● API Key <?= t('admin.config_set') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="sendgrid_enabled" value="1" <?= $sendgridEnabledVal === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.sendgrid_enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="sendgrid_api_key"><?= t('admin.leverage.sendgrid_key') ?></label>
                                    <div class="or-input-wrapper">
                                        <input type="password" id="sendgrid_api_key" name="sendgrid_api_key"
                                               placeholder="<?= $sendgridApiKeyExists ? $sendgridApiKeyMasked : 'SG.xxx...' ?>"
                                               autocomplete="off" spellcheck="false">
                                        <button type="button" class="or-toggle-vis" onclick="var i=document.getElementById('sendgrid_api_key');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'&#x1F441;':'&#x1F648;'" title="<?= t('admin.openrouter_toggle_key') ?>">&#x1F441;</button>
                                    </div>
                                </div>
                            </div>
                            <div class="or-field-group" style="margin-top:12px;">
                                <div class="or-field">
                                    <label for="sendgrid_from_email"><?= t('admin.leverage.sendgrid_from_email') ?></label>
                                    <input type="email" id="sendgrid_from_email" name="sendgrid_from_email"
                                           value="<?= htmlspecialchars($sendgridFromEmailVal) ?>"
                                           placeholder="noreply@example.com">
                                </div>
                                <div class="or-field">
                                    <label for="sendgrid_from_name"><?= t('admin.leverage.sendgrid_from_name') ?></label>
                                    <input type="text" id="sendgrid_from_name" name="sendgrid_from_name"
                                           value="<?= htmlspecialchars($sendgridFromNameVal) ?>"
                                           placeholder="Cybokron Leverage">
                                </div>
                            </div>
                            <div class="or-field" style="margin-top:12px;">
                                <label for="leverage_notify_emails"><?= t('admin.leverage.notify_emails') ?></label>
                                <input type="text" id="leverage_notify_emails" name="leverage_notify_emails"
                                       value="<?= htmlspecialchars($leverageNotifyEmailsDisplay) ?>"
                                       placeholder="user1@example.com, user2@example.com">
                                <small class="or-field-hint"><?= t('admin.leverage.notify_emails_desc') ?></small>
                            </div>
                        </div>

                        <!-- Telegram -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#128241;</span>
                                <span class="leverage-section-title"><?= t('admin.leverage.section_telegram') ?></span>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="telegram_enabled" value="1" <?= $telegramEnabled === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.telegram_enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="telegram_bot_token"><?= t('admin.leverage.telegram_bot_token') ?></label>
                                    <input type="password" id="telegram_bot_token" name="telegram_bot_token"
                                           value="<?= htmlspecialchars($telegramBotTokenMasked) ?>"
                                           class="form-control" placeholder="123456:ABC-DEF..." autocomplete="off">
                                </div>
                                <div class="or-field">
                                    <label for="telegram_chat_id"><?= t('admin.leverage.telegram_chat_id') ?></label>
                                    <input type="text" id="telegram_chat_id" name="telegram_chat_id"
                                           value="<?= htmlspecialchars($telegramChatId) ?>"
                                           class="form-control" placeholder="-1001234567890">
                                </div>
                            </div>
                            <small class="or-field-hint" style="margin-top:8px;"><?= t('admin.leverage.telegram_setup_guide') ?></small>
                        </div>

                        <!-- Webhook -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#128279;</span>
                                <span class="leverage-section-title"><?= t('admin.leverage.section_webhook') ?></span>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="webhook_enabled" value="1" <?= $webhookEnabled === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.webhook_enabled') ?></span>
                                    </label>
                                </div>
                            </div>
                            <small class="or-field-hint" style="margin-top:8px;"><?= t('admin.leverage.webhook_manage_note') ?></small>
                        </div>

                        <!-- Backtesting -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#128202;</span>
                                <span class="leverage-section-title"><?= t('admin.leverage.section_backtesting') ?></span>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="backtesting_enabled" value="1" <?= $backtestingEnabled === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.backtesting_enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="backtesting_default_source"><?= t('admin.leverage.backtesting_default_source') ?></label>
                                    <select id="backtesting_default_source" name="backtesting_default_source">
                                        <option value="rate_history" <?= $backtestingDefaultSource === 'rate_history' ? 'selected' : '' ?>><?= t('admin.leverage.backtesting_source_rate_history') ?></option>
                                        <option value="metals_dev" <?= $backtestingDefaultSource === 'metals_dev' ? 'selected' : '' ?>><?= t('admin.leverage.backtesting_source_metals_dev') ?></option>
                                        <option value="exchangerate_host" <?= $backtestingDefaultSource === 'exchangerate_host' ? 'selected' : '' ?>><?= t('admin.leverage.backtesting_source_exchangerate_host') ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="or-field-group" style="margin-top:12px;">
                                <div class="or-field">
                                    <label for="backtesting_metals_dev_api_key"><?= t('admin.leverage.backtesting_metals_dev_key') ?></label>
                                    <input type="password" id="backtesting_metals_dev_api_key" name="backtesting_metals_dev_api_key"
                                           value="<?= htmlspecialchars($backtestingMetalsDevKeyMasked) ?>"
                                           class="form-control" autocomplete="off">
                                </div>
                                <div class="or-field">
                                    <label for="backtesting_exchangerate_host_api_key"><?= t('admin.leverage.backtesting_exchangerate_host_key') ?></label>
                                    <input type="password" id="backtesting_exchangerate_host_api_key" name="backtesting_exchangerate_host_api_key"
                                           value="<?= htmlspecialchars($backtestingExchangeRateHostKeyMasked) ?>"
                                           class="form-control" autocomplete="off">
                                </div>
                            </div>
                        </div>

                        <!-- Weekly Report -->
                        <div class="leverage-section">
                            <div class="leverage-section-header">
                                <span class="leverage-section-icon">&#128197;</span>
                                <span class="leverage-section-title"><?= t('admin.leverage.section_weekly_report') ?></span>
                            </div>
                            <div class="or-field-group">
                                <div class="or-field">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding-top:20px;">
                                        <input type="checkbox" name="weekly_report_enabled" value="1" <?= $weeklyReportEnabled === '1' ? 'checked' : '' ?>
                                               style="width:18px; height:18px; accent-color:var(--primary,#3b82f6);">
                                        <span style="font-weight:600;"><?= t('admin.leverage.weekly_report_enabled') ?></span>
                                    </label>
                                </div>
                                <div class="or-field">
                                    <label for="weekly_report_day"><?= t('admin.leverage.weekly_report_day') ?></label>
                                    <select id="weekly_report_day" name="weekly_report_day">
                                        <?php
                                        $weekDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                                        foreach ($weekDays as $dayOption):
                                        ?>
                                        <option value="<?= $dayOption ?>" <?= $weeklyReportDay === $dayOption ? 'selected' : '' ?>><?= t('leverage.day.' . $dayOption) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="or-actions">
                            <button type="submit" class="btn btn-primary"><?= t('admin.save') ?></button>
                        </div>
                    </form>

                    <!-- Telegram Test Button (separate form) -->
                    <form method="POST" action="admin.php" style="margin-bottom: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="test_telegram">
                        <button type="submit" class="btn btn-sm"><?= t('admin.leverage.telegram_test') ?></button>
                    </form>

                    <!-- Test Emails -->
                    <div class="leverage-section" style="margin-top:20px; padding-top:20px; border-top:1px solid var(--border-color,#e2e8f0);">
                        <div class="leverage-section-header">
                            <span class="leverage-section-icon">&#128233;</span>
                            <span class="leverage-section-title"><?= t('admin.leverage.section_test') ?></span>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                            <form method="POST" action="admin.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="test_leverage_email">
                                <button type="submit" class="btn btn-sm"><?= t('admin.leverage.test_email') ?></button>
                            </form>
                            <form method="POST" action="admin.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="test_leverage_signal_buy">
                                <button type="submit" class="btn btn-sm" style="background:#fee2e2; color:#dc2626; border-color:#fca5a5;">
                                    &#9660; <?= t('admin.leverage.test_signal_buy') ?>
                                </button>
                            </form>
                            <form method="POST" action="admin.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="test_leverage_signal_sell">
                                <button type="submit" class="btn btn-sm" style="background:#dcfce7; color:#16a34a; border-color:#86efac;">
                                    &#9650; <?= t('admin.leverage.test_signal_sell') ?>
                                </button>
                            </form>
                        </div>
                        <small class="or-field-hint" style="margin-top:8px;"><?= t('admin.leverage.test_signal_desc') ?></small>
                    </div>
                </div>
            </div>

            <!-- System Configuration (read-only) -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-left">
                        <div class="admin-card-icon" style="background: linear-gradient(135deg, #6366f120, #818cf820);">&#9881;</div>
                        <div>
                            <h2><?= t('admin.system_config') ?></h2>
                            <p><?= t('admin.system_config_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="admin-card-body">
                    <?php
                    $configSections = [
                        [
                            'title' => t('admin.config_section_security'),
                            'icon' => '🛡️',
                            'color' => '#22c55e',
                            'items' => [
                                ['label' => 'Turnstile CAPTCHA', 'value' => defined('TURNSTILE_ENABLED') ? TURNSTILE_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_security_headers'), 'value' => defined('ENABLE_SECURITY_HEADERS') ? ENABLE_SECURITY_HEADERS : false, 'type' => 'bool'],
                                ['label' => 'CSRF', 'value' => defined('API_REQUIRE_CSRF') ? API_REQUIRE_CSRF : true, 'type' => 'bool'],
                                ['label' => t('admin.cfg_cli_cron'), 'value' => defined('ENFORCE_CLI_CRON') ? ENFORCE_CLI_CRON : true, 'type' => 'bool'],
                                ['label' => t('admin.cfg_login_limit'), 'value' => defined('LOGIN_RATE_LIMIT') ? LOGIN_RATE_LIMIT . ' / ' . (defined('LOGIN_RATE_WINDOW_SECONDS') ? LOGIN_RATE_WINDOW_SECONDS : 300) . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_portfolio_auth'), 'value' => defined('AUTH_REQUIRE_PORTFOLIO') ? AUTH_REQUIRE_PORTFOLIO : false, 'type' => 'bool'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_scraping'),
                            'icon' => '🔄',
                            'color' => '#f59e0b',
                            'items' => [
                                ['label' => t('admin.cfg_scrape_timeout'), 'value' => defined('SCRAPE_TIMEOUT') ? SCRAPE_TIMEOUT . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_retry_count'), 'value' => defined('SCRAPE_RETRY_COUNT') ? (string) SCRAPE_RETRY_COUNT : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_ai_repair'), 'value' => defined('OPENROUTER_AI_REPAIR_ENABLED') ? OPENROUTER_AI_REPAIR_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_ai_model'), 'value' => defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : '—', 'type' => 'mono'],
                                ['label' => 'API Key', 'value' => defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '' ? true : false, 'type' => 'secret'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_market'),
                            'icon' => '📅',
                            'color' => '#3b82f6',
                            'items' => [
                                ['label' => t('admin.cfg_update_interval'), 'value' => defined('UPDATE_INTERVAL_MINUTES') ? UPDATE_INTERVAL_MINUTES . ' min' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_open'), 'value' => defined('MARKET_OPEN_HOUR') ? sprintf('%02d:00', MARKET_OPEN_HOUR) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_close'), 'value' => defined('MARKET_CLOSE_HOUR') ? sprintf('%02d:00', MARKET_CLOSE_HOUR) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_market_days'), 'value' => defined('MARKET_DAYS') ? implode(', ', array_map(fn($d) => [1 => t('admin.day_mon'), 2 => t('admin.day_tue'), 3 => t('admin.day_wed'), 4 => t('admin.day_thu'), 5 => t('admin.day_fri'), 6 => t('admin.day_sat'), 7 => t('admin.day_sun')][$d] ?? $d, MARKET_DAYS)) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_history_retention'), 'value' => defined('RATE_HISTORY_RETENTION_DAYS') ? RATE_HISTORY_RETENTION_DAYS . ' ' . t('index.chart.days_unit') : '—', 'type' => 'text'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_alerts'),
                            'icon' => '🔔',
                            'color' => '#ec4899',
                            'items' => [
                                ['label' => 'E-posta', 'value' => defined('ALERT_EMAIL_TO') && ALERT_EMAIL_TO !== '' ? true : false, 'type' => 'secret'],
                                ['label' => 'Telegram Bot', 'value' => defined('ALERT_TELEGRAM_BOT_TOKEN') && ALERT_TELEGRAM_BOT_TOKEN !== '' ? true : false, 'type' => 'secret'],
                                ['label' => 'Webhook', 'value' => defined('ALERT_WEBHOOK_URL') && ALERT_WEBHOOK_URL !== '' ? true : false, 'type' => 'secret'],
                                ['label' => t('admin.cfg_alert_cooldown'), 'value' => defined('ALERT_COOLDOWN_MINUTES') ? ALERT_COOLDOWN_MINUTES . ' min' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_rate_webhook'), 'value' => defined('RATE_UPDATE_WEBHOOK_URL') && RATE_UPDATE_WEBHOOK_URL !== '' ? true : false, 'type' => 'secret'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_api'),
                            'icon' => '⚡',
                            'color' => '#8b5cf6',
                            'items' => [
                                ['label' => 'CORS', 'value' => defined('API_ALLOW_CORS') ? API_ALLOW_CORS : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_read_limit'), 'value' => defined('API_READ_RATE_LIMIT') ? API_READ_RATE_LIMIT . ' / ' . (defined('API_READ_RATE_WINDOW_SECONDS') ? API_READ_RATE_WINDOW_SECONDS : 60) . 's' : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_write_limit'), 'value' => defined('API_WRITE_RATE_LIMIT') ? API_WRITE_RATE_LIMIT . ' / ' . (defined('API_WRITE_RATE_WINDOW_SECONDS') ? API_WRITE_RATE_WINDOW_SECONDS : 60) . 's' : '—', 'type' => 'text'],
                            ],
                        ],
                        [
                            'title' => t('admin.config_section_system'),
                            'icon' => '🖥️',
                            'color' => '#64748b',
                            'items' => [
                                ['label' => 'Debug', 'value' => defined('APP_DEBUG') ? APP_DEBUG : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_timezone'), 'value' => defined('APP_TIMEZONE') ? APP_TIMEZONE : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_locale'), 'value' => defined('DEFAULT_LOCALE') ? strtoupper(DEFAULT_LOCALE) : '—', 'type' => 'text'],
                                ['label' => t('admin.cfg_auto_update'), 'value' => defined('AUTO_UPDATE') ? AUTO_UPDATE : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_logging'), 'value' => defined('LOG_ENABLED') ? LOG_ENABLED : false, 'type' => 'bool'],
                                ['label' => t('admin.cfg_db_persistent'), 'value' => defined('DB_PERSISTENT') ? DB_PERSISTENT : false, 'type' => 'bool'],
                            ],
                        ],
                    ];
                    ?>
                    <div class="sc-grid">
                        <?php foreach ($configSections as $section): ?>
                        <div class="sc-card">
                            <div class="sc-card-header" style="--sc-accent: <?= $section['color'] ?>">
                                <span class="sc-card-icon"><?= $section['icon'] ?></span>
                                <span class="sc-card-title"><?= htmlspecialchars($section['title']) ?></span>
                            </div>
                            <div class="sc-card-body">
                                <?php foreach ($section['items'] as $item): ?>
                                <div class="sc-row">
                                    <span class="sc-label"><?= htmlspecialchars($item['label']) ?></span>
                                    <span class="sc-value">
                                        <?php if ($item['type'] === 'bool'): ?>
                                            <span class="sc-indicator <?= $item['value'] ? 'sc-on' : 'sc-off' ?>">
                                                <span class="sc-dot"></span>
                                                <?= $item['value'] ? t('admin.config_enabled') : t('admin.config_disabled') ?>
                                            </span>
                                        <?php elseif ($item['type'] === 'secret'): ?>
                                            <span class="sc-indicator <?= $item['value'] ? 'sc-on' : 'sc-off' ?>">
                                                <span class="sc-dot"></span>
                                                <?= $item['value'] ? t('admin.config_set') : t('admin.config_not_set') ?>
                                            </span>
                                        <?php elseif ($item['type'] === 'mono'): ?>
                                            <code class="sc-mono"><?= htmlspecialchars((string) $item['value']) ?></code>
                                        <?php else: ?>
                                            <span class="sc-text"><?= htmlspecialchars((string) $item['value']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /admin-grid -->
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a
                    href="observability.php"><?= t('observability.title') ?></a> | <a
                    href="openrouter.php"><?= t('nav.openrouter') ?></a></p>
        </div>
    </footer>

    <script nonce="<?= getCspNonce() ?>">
        // ── Toast system ──
        function showToast(msg, type) {
            var c = document.getElementById('toast-container');
            if (!c) return;
            var t = document.createElement('div');
            t.className = 'toast toast-' + (type || 'info');
            t.textContent = msg;
            c.appendChild(t);
            setTimeout(function () {
                t.classList.add('hiding');
                setTimeout(function () { t.remove(); }, 300);
            }, 3000);
        }

        // ── Bank filter tabs ──
        (function () {
            var tabs = document.querySelectorAll('.filter-tab');
            var rows = document.querySelectorAll('#rates-sortable .sortable-row');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    var bank = tab.getAttribute('data-bank');
                    rows.forEach(function (row) {
                        if (bank === 'all' || row.getAttribute('data-bank') === bank) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            });
        })();

        // ── Drag & Drop with AJAX auto-save ──
        (function () {
            var tbody = document.getElementById('rates-sortable');
            var statusEl = document.getElementById('save-status');
            var csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
            if (!tbody) return;

            var draggedRow = null;
            var saveTimeout = null;

            function updateOrderNumbers() {
                var rows = tbody.querySelectorAll('.sortable-row');
                var i = 0;
                rows.forEach(function (r) {
                    i++;
                    var num = r.querySelector('.order-num');
                    if (num) num.textContent = i;
                });
            }

            function showStatus(type, text) {
                if (!statusEl) return;
                statusEl.className = 'save-status visible ' + type;
                statusEl.innerHTML = (type === 'saving' ? '<span class="save-spinner"></span>' : '') + text;
                if (type !== 'saving') {
                    setTimeout(function () { statusEl.className = 'save-status'; }, 2500);
                }
            }

            function saveOrder() {
                var rows = tbody.querySelectorAll('.sortable-row');
                var orders = {};
                var i = 0;
                rows.forEach(function (r) {
                    i++;
                    var id = r.getAttribute('data-rate-id');
                    if (id) orders[id] = i;
                });

                showStatus('saving', <?= json_encode(t('admin.order_saving')) ?>);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.status === 'ok') {
                                showStatus('saved', '✓ ' + <?= json_encode(t('admin.order_saved')) ?>);
                                showToast(<?= json_encode(t('admin.order_saved')) ?>, 'success');
                            } else {
                                showStatus('error', '✗ ' + <?= json_encode(t('admin.order_save_error')) ?>);
                                showToast(<?= json_encode(t('admin.order_save_error')) ?>, 'error');
                            }
                        } catch (e) {
                            showStatus('error', '✗ ' + <?= json_encode(t('admin.order_save_error')) ?>);
                        }
                    } else {
                        showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                    }
                };
                xhr.onerror = function () {
                    showStatus('error', '✗ <?= t('admin.order_save_error') ?>');
                };
                var params = 'action=update_rate_order&csrf_token=' + encodeURIComponent(csrfToken) +
                    '&rate_orders=' + encodeURIComponent(JSON.stringify(orders));
                xhr.send(params);
            }

            function scheduleSave() {
                if (saveTimeout) clearTimeout(saveTimeout);
                saveTimeout = setTimeout(saveOrder, 400);
            }

            // Set up drag events on each row
            var allRows = tbody.querySelectorAll('.sortable-row');
            allRows.forEach(function (row) {
                row.addEventListener('dragstart', function (e) {
                    draggedRow = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');
                });

                row.addEventListener('dragend', function () {
                    this.classList.remove('dragging');
                    draggedRow = null;
                    tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                });

                row.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (draggedRow && draggedRow !== this) {
                        tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                        this.classList.add('drag-over');
                    }
                });

                row.addEventListener('dragleave', function () {
                    this.classList.remove('drag-over');
                });

                row.addEventListener('drop', function (e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    if (draggedRow && draggedRow !== this) {
                        var rect = this.getBoundingClientRect();
                        var mid = rect.top + rect.height / 2;
                        if (e.clientY < mid) {
                            this.parentNode.insertBefore(draggedRow, this);
                        } else {
                            this.parentNode.insertBefore(draggedRow, this.nextSibling);
                        }
                        updateOrderNumbers();
                        scheduleSave();
                    }
                });
            });

            // Touch support for mobile
            var touchRow = null, touchClone = null, touchStartY = 0;
            tbody.addEventListener('touchstart', function (e) {
                var handle = e.target.closest('.drag-handle');
                if (!handle) return;
                touchRow = handle.closest('.sortable-row');
                if (!touchRow) return;
                touchStartY = e.touches[0].clientY;
                touchRow.classList.add('dragging');
            }, { passive: true });

            tbody.addEventListener('touchmove', function (e) {
                if (!touchRow) return;
                e.preventDefault();
                var y = e.touches[0].clientY;
                var rows = Array.from(tbody.querySelectorAll('.sortable-row:not(.dragging)'));
                rows.forEach(function (r) { r.classList.remove('drag-over'); });
                for (var i = 0; i < rows.length; i++) {
                    var rect = rows[i].getBoundingClientRect();
                    if (y > rect.top && y < rect.bottom) {
                        rows[i].classList.add('drag-over');
                        var mid = rect.top + rect.height / 2;
                        if (y < mid) {
                            tbody.insertBefore(touchRow, rows[i]);
                        } else {
                            tbody.insertBefore(touchRow, rows[i].nextSibling);
                        }
                        break;
                    }
                }
            }, { passive: false });

            tbody.addEventListener('touchend', function () {
                if (!touchRow) return;
                touchRow.classList.remove('dragging');
                tbody.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                updateOrderNumbers();
                scheduleSave();
                touchRow = null;
            });
        })();

        // ─── Widget Management ──────────────────────────────────────────────────
        (function () {
            var list = document.getElementById('widget-sortable-list');
            if (!list) return;

            var csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
            var statusEl = document.getElementById('widget-save-status');
            var saveTimeout = null;
            var draggedItem = null;
            var visibleText = <?= json_encode(t('admin.visible')) ?>;
            var hiddenText = <?= json_encode(t('admin.hidden')) ?>;

            function getWidgetConfig() {
                var items = list.querySelectorAll('.widget-config-item');
                var config = [];
                items.forEach(function (item, i) {
                    config.push({
                        id: item.dataset.widgetId,
                        visible: item.querySelector('input[type="checkbox"]').checked,
                        order: i
                    });
                });
                return config;
            }

            function updateOrderNumbers() {
                list.querySelectorAll('.order-num').forEach(function (el, i) {
                    el.textContent = i + 1;
                });
            }

            function showStatus(type, text) {
                if (!statusEl) return;
                statusEl.className = 'save-status visible ' + type;
                statusEl.innerHTML = type === 'saving'
                    ? '<div class="save-spinner"></div> ' + text
                    : text;
                if (type !== 'saving') {
                    setTimeout(function () {
                        statusEl.classList.remove('visible');
                    }, 2000);
                }
            }

            function saveWidgetConfig() {
                var config = getWidgetConfig();
                showStatus('saving', <?= json_encode(t('admin.order_saving')) ?>);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status === 200) {
                        showStatus('saved', <?= json_encode(t('admin.order_saved')) ?>);
                    } else {
                        showStatus('error', <?= json_encode(t('admin.order_save_error')) ?>);
                    }
                };
                xhr.onerror = function () {
                    showStatus('error', <?= json_encode(t('admin.order_save_error')) ?>);
                };
                var params = 'action=save_widget_config&csrf_token=' + encodeURIComponent(csrfToken) +
                    '&widget_config=' + encodeURIComponent(JSON.stringify(config));
                xhr.send(params);
            }

            function scheduleSave() {
                if (saveTimeout) clearTimeout(saveTimeout);
                saveTimeout = setTimeout(saveWidgetConfig, 400);
            }

            // Toggle visibility
            list.addEventListener('change', function (e) {
                if (e.target.type === 'checkbox') {
                    var textEl = e.target.closest('.widget-toggle').querySelector('.widget-toggle-text');
                    if (textEl) {
                        textEl.textContent = e.target.checked ? visibleText : hiddenText;
                    }
                    scheduleSave();
                }
            });

            // Drag & drop
            var items = list.querySelectorAll('.widget-config-item');
            items.forEach(function (item) {
                item.addEventListener('dragstart', function (e) {
                    draggedItem = this;
                    this.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', '');
                });

                item.addEventListener('dragend', function () {
                    this.classList.remove('dragging');
                    draggedItem = null;
                    list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                });

                item.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (draggedItem && draggedItem !== this) {
                        list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                        this.classList.add('drag-over');
                    }
                });

                item.addEventListener('dragleave', function () {
                    this.classList.remove('drag-over');
                });

                item.addEventListener('drop', function (e) {
                    e.preventDefault();
                    this.classList.remove('drag-over');
                    if (draggedItem && draggedItem !== this) {
                        var rect = this.getBoundingClientRect();
                        var mid = rect.top + rect.height / 2;
                        if (e.clientY < mid) {
                            list.insertBefore(draggedItem, this);
                        } else {
                            list.insertBefore(draggedItem, this.nextSibling);
                        }
                        updateOrderNumbers();
                        scheduleSave();
                    }
                });
            });

            // Touch support
            var touchItem = null;
            list.addEventListener('touchstart', function (e) {
                var handle = e.target.closest('.drag-handle');
                if (!handle) return;
                touchItem = handle.closest('.widget-config-item');
                if (touchItem) touchItem.classList.add('dragging');
            }, { passive: true });

            list.addEventListener('touchmove', function (e) {
                if (!touchItem) return;
                e.preventDefault();
                var y = e.touches[0].clientY;
                var allItems = Array.from(list.querySelectorAll('.widget-config-item:not(.dragging)'));
                allItems.forEach(function (r) { r.classList.remove('drag-over'); });
                for (var i = 0; i < allItems.length; i++) {
                    var rect = allItems[i].getBoundingClientRect();
                    if (y > rect.top && y < rect.bottom) {
                        allItems[i].classList.add('drag-over');
                        var mid = rect.top + rect.height / 2;
                        if (y < mid) {
                            list.insertBefore(touchItem, allItems[i]);
                        } else {
                            list.insertBefore(touchItem, allItems[i].nextSibling);
                        }
                        break;
                    }
                }
            }, { passive: false });

            list.addEventListener('touchend', function () {
                if (!touchItem) return;
                touchItem.classList.remove('dragging');
                list.querySelectorAll('.drag-over').forEach(function (r) { r.classList.remove('drag-over'); });
                updateOrderNumbers();
                scheduleSave();
                touchItem = null;
            });
        })();

        function clearServiceWorkerCache(btn) {
            btn.disabled = true;
            btn.textContent = '⏳ ...';
            var done = function() {
                btn.textContent = '✅ ' + <?= json_encode(t('admin.cache_cleared')) ?>;
                setTimeout(function() {
                    btn.disabled = false;
                    btn.textContent = '🧹 ' + <?= json_encode(t('admin.clear_cache')) ?>;
                }, 2000);
            };
            // Clear all caches directly (works with or without SW)
            if ('caches' in window) {
                caches.keys().then(function(keys) {
                    return Promise.all(keys.map(function(k) { return caches.delete(k); }));
                }).then(done).catch(done);
            } else {
                done();
            }
        }
    </script>
</body>

</html>
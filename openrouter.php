<?php
/**
 * openrouter.php — OpenRouter AI Management Panel
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'openrouter.php'));
    exit;
}

$message = '';
$messageType = '';
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: openrouter.php');
        exit;
    }

    if ($_POST['action'] === 'test_connection') {
        $dbKey = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_api_key']);
        $apiKey = trim($dbKey['value'] ?? '');
        if ($apiKey !== '') {
            $apiKey = decryptSettingValue($apiKey);
        }
        if ($apiKey === '') {
            $apiKey = defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '';
        }
        if ($apiKey === '') {
            $testResult = ['success' => false, 'message' => t('openrouter.key_not_set'), 'time' => 0];
        } else {
            $startTime = microtime(true);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://openrouter.ai/api/v1/models',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($raw !== false && $httpCode >= 200 && $httpCode < 300) {
                $testResult = ['success' => true, 'message' => t('openrouter.test_success'), 'time' => $elapsed];
            } else {
                $errMsg = $curlError ?: ('HTTP ' . $httpCode);
                $testResult = ['success' => false, 'message' => t('openrouter.test_error') . ': ' . $errMsg, 'time' => $elapsed];
            }
        }
    }

    if ($_POST['action'] === 'change_model') {
        $newModel = trim((string) ($_POST['model'] ?? ''));
        if ($newModel !== '' && preg_match('/^[a-zA-Z0-9._\/-]{3,120}$/', $newModel)) {
            Database::upsert('settings', [
                'key' => 'openrouter_model',
                'value' => $newModel,
            ], ['value']);
            $message = t('openrouter.model_updated');
            $messageType = 'success';
        } else {
            $message = 'Invalid model ID format.';
            $messageType = 'error';
        }
    }
}

// ─── Data Gathering ─────────────────────────────────────────────────────

$dbApiKeyRow = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_api_key']);
$apiKey = trim($dbApiKeyRow['value'] ?? '');
if ($apiKey !== '') {
    $apiKey = decryptSettingValue($apiKey);
}
if ($apiKey === '') {
    $apiKey = defined('OPENROUTER_API_KEY') ? trim((string) OPENROUTER_API_KEY) : '';
}
$apiKeySet = $apiKey !== '';
$apiKeyLast4 = $apiKeySet ? '...' . substr($apiKey, -4) : '';

$configModel = defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : 'z-ai/glm-5';
$dbModel = Database::queryOne('SELECT value FROM settings WHERE `key` = ?', ['openrouter_model']);
$activeModel = ($dbModel && !empty($dbModel['value'])) ? $dbModel['value'] : $configModel;

$repairEnabled = defined('OPENROUTER_AI_REPAIR_ENABLED') && OPENROUTER_AI_REPAIR_ENABLED === true;
$cooldownSeconds = defined('OPENROUTER_AI_COOLDOWN_SECONDS') ? (int) OPENROUTER_AI_COOLDOWN_SECONDS : 21600;

// Bank AI stats from settings table
$banks = Database::query('SELECT id, name, slug FROM banks WHERE is_active = 1 ORDER BY name');
$bankStats = [];

// Build all setting keys for a single batch query
$settingKeys = [];
$bankCacheKeys = [];
foreach ($banks as $bank) {
    $cacheKey = substr(hash('sha1', $bank['slug']), 0, 12);
    $bankCacheKeys[] = $cacheKey;
    $settingKeys[] = 'or_ai_t_' . $cacheKey;
    $settingKeys[] = 'or_ai_h_' . $cacheKey;
    $settingKeys[] = 'or_ai_c_' . $cacheKey;
}

// Fetch all AI settings in a single query
$aiSettings = [];
if (!empty($settingKeys)) {
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $rows = Database::query("SELECT `key`, value FROM settings WHERE `key` IN ({$placeholders})", $settingKeys);
    foreach ($rows as $row) {
        $aiSettings[$row['key']] = $row['value'];
    }
}

foreach ($banks as $i => $bank) {
    $cacheKey = $bankCacheKeys[$i];
    $ts = (int) ($aiSettings['or_ai_t_' . $cacheKey] ?? 0);
    $cooldownActive = false;
    if ($ts > 0 && $cooldownSeconds > 0) {
        $cooldownActive = (time() - $ts) < $cooldownSeconds;
    }

    $bankStats[] = [
        'name' => $bank['name'],
        'slug' => $bank['slug'],
        'last_ai_ts' => $ts,
        'last_ai_count' => (int) ($aiSettings['or_ai_c_' . $cacheKey] ?? 0),
        'cooldown_active' => $cooldownActive,
        'has_data' => $ts > 0,
    ];
}

// Table change logs (last 30)
$changeLogs = Database::query("
    SELECT
        sl.id,
        sl.status,
        sl.message,
        sl.rates_count,
        sl.duration_ms,
        sl.table_changed,
        sl.created_at,
        b.name AS bank_name
    FROM scrape_logs sl
    JOIN banks b ON b.id = sl.bank_id
    ORDER BY sl.created_at DESC
    LIMIT 30
");

$currentLocale = getAppLocale();
$csrfToken = getCsrfToken();
$version = getAppVersion();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('openrouter.title') ?> — <?= APP_NAME ?></title>
<?= renderSeoMeta([
    'title' => t('openrouter.title') . ' — ' . APP_NAME,
    'description' => t('seo.openrouter_description'),
    'page' => 'openrouter.php',
]) ?>
    <script>(function(){try{var t=localStorage.getItem('cybokron_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t)}else if(window.matchMedia('(prefers-color-scheme:light)').matches){document.documentElement.setAttribute('data-theme','light')}}catch(e){}})();</script>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/openrouter.css?v=<?= filemtime(__DIR__ . '/assets/css/openrouter.css') ?>">
</head>

<body>
    <?php $activePage = 'openrouter';
    include __DIR__ . '/includes/header.php'; ?>

    <main id="main-content" class="container">
        <?php if ($message): ?>
            <div class="or-alert or-alert-<?= $messageType ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="or-grid">

            <!-- Row 1: Connection Status + Model Management -->
            <div class="row-2">
                <!-- Connection Status -->
                <div class="or-card">
                    <div class="or-card-header">
                        <div class="or-card-header-left">
                            <div class="or-card-icon connection">🔗</div>
                            <div>
                                <h2><?= t('openrouter.connection_status') ?></h2>
                                <p><?= t('openrouter.config_summary') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="or-card-body">
                        <div class="or-status-row">
                            <span class="or-status-label"><?= t('openrouter.key_status') ?></span>
                            <span class="or-status-value">
                                <span class="or-dot <?= $apiKeySet ? 'green' : 'red' ?>"></span>
                                <?php if ($apiKeySet): ?>
                                    <?= t('openrouter.key_set') ?>
                                    <code style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($apiKeyLast4) ?></code>
                                <?php else: ?>
                                    <?= t('openrouter.key_not_set') ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="or-status-row">
                            <span class="or-status-label"><?= t('openrouter.model_active') ?></span>
                            <span class="or-status-value">
                                <code style="font-size: 0.8rem;"><?= htmlspecialchars($activeModel) ?></code>
                            </span>
                        </div>
                        <div class="or-status-row">
                            <span class="or-status-label">AI Repair</span>
                            <span class="or-status-value">
                                <span class="or-badge <?= $repairEnabled ? 'or-badge-success' : 'or-badge-danger' ?>">
                                    <?= $repairEnabled ? t('openrouter.enabled') : t('openrouter.disabled') ?>
                                </span>
                            </span>
                        </div>
                        <div class="or-status-row">
                            <span class="or-status-label">Cooldown</span>
                            <span class="or-status-value"><?= gmdate('H:i:s', $cooldownSeconds) ?></span>
                        </div>

                        <!-- Test Connection -->
                        <form method="POST" style="margin-top: 12px;">
                            <input type="hidden" name="action" value="test_connection">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                🧪 <?= t('openrouter.test_connection') ?>
                            </button>
                        </form>

                        <?php if ($testResult !== null): ?>
                            <div class="or-test-result <?= $testResult['success'] ? 'success' : 'error' ?>">
                                <?= htmlspecialchars($testResult['message']) ?>
                                <?php if ($testResult['time'] > 0): ?>
                                    — <?= t('openrouter.response_time') ?>: <?= (int) $testResult['time'] ?> ms
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Model Management -->
                <div class="or-card">
                    <div class="or-card-header">
                        <div class="or-card-header-left">
                            <div class="or-card-icon model">🧠</div>
                            <div>
                                <h2><?= t('openrouter.model_change') ?></h2>
                                <p><?= t('openrouter.model_default') ?>: <code><?= htmlspecialchars($configModel) ?></code></p>
                            </div>
                        </div>
                    </div>
                    <div class="or-card-body">
                        <div class="or-status-row" style="margin-bottom: 16px;">
                            <span class="or-status-label"><?= t('openrouter.model_active') ?></span>
                            <span class="or-status-value">
                                <code style="font-size: 0.85rem; font-weight: 700;"><?= htmlspecialchars($activeModel) ?></code>
                            </span>
                        </div>
                        <form method="POST" class="or-model-form">
                            <input type="hidden" name="action" value="change_model">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="form-field">
                                <label for="or-model"><?= t('openrouter.model_change') ?></label>
                                <input type="text" id="or-model" name="model"
                                    value="<?= htmlspecialchars($activeModel) ?>"
                                    placeholder="<?= t('openrouter.model_placeholder') ?>"
                                    pattern="[a-zA-Z0-9._\/\-]{3,120}" required>
                            </div>
                            <button type="submit" class="btn btn-primary"><?= t('openrouter.save') ?></button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- AI Repair Statistics -->
            <div class="or-card">
                <div class="or-card-header">
                    <div class="or-card-header-left">
                        <div class="or-card-icon stats">📊</div>
                        <div>
                            <h2><?= t('openrouter.ai_repair_stats') ?></h2>
                            <p><?= t('openrouter.config_summary') ?></p>
                        </div>
                    </div>
                </div>
                <div class="or-card-body">
                    <?php if (empty($bankStats)): ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem;"><?= t('openrouter.no_logs') ?></p>
                    <?php else: ?>
                        <div class="or-stats-grid">
                            <?php foreach ($bankStats as $bs): ?>
                                <div class="or-stat-card">
                                    <h3>🏦 <?= htmlspecialchars($bs['name']) ?></h3>
                                    <div class="or-stat-row">
                                        <span class="label"><?= t('openrouter.last_ai_call') ?></span>
                                        <span class="value">
                                            <?php if ($bs['has_data']): ?>
                                                <?= formatDateTime(date('Y-m-d H:i:s', $bs['last_ai_ts'])) ?>
                                            <?php else: ?>
                                                <?= t('openrouter.never') ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="or-stat-row">
                                        <span class="label"><?= t('openrouter.rates_extracted') ?></span>
                                        <span class="value"><?= $bs['last_ai_count'] ?></span>
                                    </div>
                                    <div class="or-stat-row">
                                        <span class="label">Cooldown</span>
                                        <span class="value">
                                            <?php if ($bs['cooldown_active']): ?>
                                                <span class="or-badge or-badge-warning"><?= t('openrouter.cooldown_active') ?></span>
                                            <?php else: ?>
                                                <span class="or-badge or-badge-muted"><?= t('openrouter.cooldown_inactive') ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table Change Logs -->
            <div class="or-card">
                <div class="or-card-header">
                    <div class="or-card-header-left">
                        <div class="or-card-icon logs">📋</div>
                        <div>
                            <h2><?= t('openrouter.table_change_logs') ?></h2>
                            <p>Son 30 scrape log kaydı</p>
                        </div>
                    </div>
                </div>
                <?php if (empty($changeLogs)): ?>
                    <div class="or-card-body">
                        <p style="color: var(--text-muted); font-size: 0.85rem;"><?= t('openrouter.no_logs') ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="or-log-table">
                            <thead>
                                <tr>
                                    <th><?= t('openrouter.time') ?></th>
                                    <th><?= t('openrouter.bank') ?></th>
                                    <th><?= t('openrouter.status') ?></th>
                                    <th><?= t('openrouter.rates_count') ?></th>
                                    <th><?= t('openrouter.duration') ?></th>
                                    <th><?= t('openrouter.message') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($changeLogs as $log): ?>
                                    <tr class="<?= $log['table_changed'] ? 'highlight-row' : '' ?>">
                                        <td style="white-space: nowrap;"><?= formatDateTime($log['created_at']) ?></td>
                                        <td><?= htmlspecialchars($log['bank_name']) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = match ($log['status']) {
                                                'success' => 'or-badge-success',
                                                'warning' => 'or-badge-warning',
                                                default => 'or-badge-danger',
                                            };
                                            ?>
                                            <span class="or-badge <?= $statusClass ?>">
                                                <?= htmlspecialchars($log['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= (int) $log['rates_count'] ?></td>
                                        <td><?= $log['duration_ms'] !== null ? (int) $log['duration_ms'] . ' ms' : '—' ?></td>
                                        <td class="message-cell">
                                            <?= htmlspecialchars($log['message'] ?? '') ?>
                                            <?= $log['table_changed'] ? ' ' . t('openrouter.table_changed') : '' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /or-grid -->
    </main>

    <footer class="footer">
        <div class="container">
            <p>Cybokron v<?= htmlspecialchars($version) ?> | <a href="admin.php"><?= t('admin.title') ?></a> | <a href="observability.php"><?= t('observability.title') ?></a></p>
        </div>
    </footer>
</body>

</html>

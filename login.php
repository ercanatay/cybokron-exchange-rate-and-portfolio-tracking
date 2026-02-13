<?php
/**
 * login.php — User login
 * Cybokron Exchange Rate & Portfolio Tracking
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
applySecurityHeaders();
ensureWebSessionStarted();
Auth::init();

// If already logged in, redirect
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // TEMPORARY DEBUG - remove after testing
    $debugLog = dirname(__DIR__) . '/cybokron-logs/login_debug.log';
    $debugData = date('Y-m-d H:i:s') . " POST login attempt\n";
    $debugData .= "  POST username: " . ($_POST['username'] ?? '(missing)') . "\n";
    $debugData .= "  POST password length: " . strlen($_POST['password'] ?? '') . "\n";
    $debugData .= "  POST password hex: " . bin2hex($_POST['password'] ?? '') . "\n";

    if (!enforceLoginRateLimit()) {
        $error = t('auth.error_rate_limit');
        $debugData .= "  RESULT: rate limited\n";
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $debugData .= "  trimmed username: '{$username}'\n";
        $debugData .= "  password length after cast: " . strlen($password) . "\n";

        // Check DB directly
        $dbUser = Database::queryOne('SELECT id, username, password_hash, is_active FROM users WHERE username = ? AND is_active = 1', [trim($username)]);
        $debugData .= "  DB user found: " . ($dbUser ? "yes, id={$dbUser['id']}" : "NO") . "\n";
        if ($dbUser) {
            $debugData .= "  DB hash length: " . strlen($dbUser['password_hash']) . "\n";
            $debugData .= "  DB hash prefix: " . substr($dbUser['password_hash'], 0, 7) . "\n";
            $debugData .= "  password_verify result: " . (password_verify($password, (string)$dbUser['password_hash']) ? 'PASS' : 'FAIL') . "\n";
        }

        if ($username === '' || $password === '') {
            $error = t('auth.error_empty');
            $debugData .= "  RESULT: empty fields\n";
        } elseif (Auth::login($username, $password)) {
            $redirect = trim((string) ($_GET['redirect'] ?? 'index.php'));
            if ($redirect === '' || str_starts_with($redirect, '//') || str_contains($redirect, '://') || str_contains($redirect, '..')) {
                $redirect = 'index.php';
            }
            $debugData .= "  RESULT: login SUCCESS\n";
            @file_put_contents($debugLog, $debugData, FILE_APPEND);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = t('auth.error_invalid');
            $debugData .= "  RESULT: Auth::login returned false\n";
        }
    }
    @file_put_contents($debugLog, $debugData, FILE_APPEND);
}

$currentLocale = getAppLocale();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLocale) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('auth.login_title') ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>

<body>
    <main class="container login-container">
        <h1><?= t('auth.login_title') ?></h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" class="portfolio-form">
            <div class="form-group">
                <label for="username"><?= t('auth.username') ?></label>
                <input type="text" name="username" id="username" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password"><?= t('auth.password') ?></label>
                <input type="password" name="password" id="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary"><?= t('auth.login') ?></button>
        </form>
        <p class="login-back-link"><a href="index.php"><?= t('auth.back') ?></a></p>
    </main>
</body>

</html>
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
    if (!enforceLoginRateLimit()) {
        $error = t('auth.error_rate_limit');
    } elseif (defined('TURNSTILE_ENABLED') && TURNSTILE_ENABLED && !verifyTurnstile($_POST['cf-turnstile-response'] ?? '')) {
        $error = t('auth.error_captcha');
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = t('auth.error_empty');
        } elseif (Auth::login($username, $password)) {
            $redirect = trim((string) ($_GET['redirect'] ?? 'index.php'));
            if ($redirect === '' || str_starts_with($redirect, '//') || str_contains($redirect, '://') || str_contains($redirect, '..')) {
                $redirect = 'index.php';
            }
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = t('auth.error_invalid');
        }
    }
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
    <?php if (defined('TURNSTILE_ENABLED') && TURNSTILE_ENABLED): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
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
            <?php if (defined('TURNSTILE_ENABLED') && TURNSTILE_ENABLED): ?>
            <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY) ?>" data-theme="auto"></div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary"><?= t('auth.login') ?></button>
        </form>
        <p class="login-back-link"><a href="index.php"><?= t('auth.back') ?></a></p>
    </main>
</body>

</html>

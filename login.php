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
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = t('auth.error_empty');
        } elseif (Auth::login($username, $password)) {
            header('Location: ' . ($_GET['redirect'] ?? 'index.php'));
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="container" style="max-width: 400px; margin: 80px auto;">
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
        <p style="margin-top: 16px;"><a href="index.php"><?= t('auth.back') ?></a></p>
    </main>
</body>
</html>

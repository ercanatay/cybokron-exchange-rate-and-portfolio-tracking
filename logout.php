<?php
/**
 * logout.php — User logout
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
ensureWebSessionStarted();
Auth::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: index.php');
    exit;
}

Auth::logout();
header('Location: index.php');
exit;

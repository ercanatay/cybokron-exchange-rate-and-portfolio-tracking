<?php
/**
 * logout.php — User logout
 */

require_once __DIR__ . '/includes/helpers.php';
cybokron_init();
ensureWebSessionStarted();
Auth::init();
Auth::logout();
header('Location: index.php');
exit;

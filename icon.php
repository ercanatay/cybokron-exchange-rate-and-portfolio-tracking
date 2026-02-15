<?php
/**
 * Dynamic PWA icon generator
 * Usage: icon.php?size=192 or icon.php?size=512
 */
$size = isset($_GET['size']) ? (int) $_GET['size'] : 192;
$size = in_array($size, [192, 512]) ? $size : 192;

if (!extension_loaded('gd')) {
    http_response_code(500);
    exit('GD not available');
}

$img = imagecreatetruecolor($size, $size);
$bg = imagecolorallocate($img, 15, 17, 23);
$accent = imagecolorallocate($img, 59, 130, 246);
imagefill($img, 0, 0, $bg);
imagefilledellipse($img, $size / 2, $size / 2, $size * 0.6, $size * 0.6, $accent);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
imagepng($img);
imagedestroy($img);

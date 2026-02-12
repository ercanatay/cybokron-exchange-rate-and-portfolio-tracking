<?php
/**
 * Generate detached signature for update package
 * Usage: php scripts/generate_signature.php path/to/cybokron-update.zip
 *
 * Requires OPENSSL_CONF or private key path in env:
 *   UPDATE_PRIVATE_KEY_PATH=/path/to/update-private.pem
 */

$zipPath = $argv[1] ?? '';
if ($zipPath === '' || !file_exists($zipPath)) {
    fwrite(STDERR, "Usage: php generate_signature.php <zip-file>\n");
    exit(1);
}

$keyPath = getenv('UPDATE_PRIVATE_KEY_PATH') ?: __DIR__ . '/../update-private.pem';
if (!file_exists($keyPath)) {
    fwrite(STDERR, "Private key not found. Set UPDATE_PRIVATE_KEY_PATH or place update-private.pem in project root.\n");
    exit(1);
}

$key = openssl_pkey_get_private('file://' . $keyPath);
if ($key === false) {
    fwrite(STDERR, "Failed to load private key.\n");
    exit(1);
}

$data = file_get_contents($zipPath);
if ($data === false) {
    fwrite(STDERR, "Failed to read zip file.\n");
    exit(1);
}

$signature = '';
if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "Signing failed.\n");
    exit(1);
}

$sigPath = $zipPath . '.sig';
file_put_contents($sigPath, base64_encode($signature));
echo "Signature written to: {$sigPath}\n";

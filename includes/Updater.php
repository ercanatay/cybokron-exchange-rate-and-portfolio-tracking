<?php
/**
 * Updater.php — GitHub Release Self-Updater
 * Cybokron Exchange Rate & Portfolio Tracking
 */

class Updater
{
    private string $repo;
    private string $currentVersion;
    private string $basePath;

    public function __construct()
    {
        $this->repo = defined('GITHUB_REPO') ? GITHUB_REPO : 'ercanatay/cybokron-exchange-rate-and-portfolio-tracking';
        $this->basePath = dirname(__DIR__);
        $this->currentVersion = function_exists('getAppVersion') ? getAppVersion() : trim((string) @file_get_contents($this->basePath . '/VERSION'));
    }

    /**
     * Check GitHub for latest release.
     */
    public function checkForUpdate(): ?array
    {
        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $allowedHosts = $this->getAllowedUpdateHosts();
        try {
            $this->assertAllowedUpdateHost($url, $allowedHosts, 'update metadata URL');
        } catch (Throwable $e) {
            cybokron_log('Update metadata host policy error: ' . $e->getMessage(), 'ERROR');
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Cybokron-Updater/1.0',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($effectiveUrl !== '') {
            try {
                $this->assertAllowedUpdateHost($effectiveUrl, $allowedHosts, 'update metadata redirect target');
            } catch (Throwable $e) {
                cybokron_log('Update metadata redirect blocked: ' . $e->getMessage(), 'ERROR');
                return null;
            }
        }

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $release = json_decode($response, true);

        if (!$release || empty($release['tag_name'])) {
            return null;
        }

        $latestVersion = ltrim((string) $release['tag_name'], 'v');

        if (version_compare($latestVersion, $this->currentVersion, '>')) {
            $downloadUrl = $this->findReleasePackageUrl($release);
            if ($downloadUrl === null || $downloadUrl === '') {
                cybokron_log('Update available but no package URL was resolved.', 'ERROR');
                return null;
            }

            $signatureUrl = $this->findReleaseSignatureUrl($release);
            if ($this->isSignatureRequired()) {
                if ($signatureUrl === null || $signatureUrl === '') {
                    cybokron_log('Signed update required but signature asset is missing.', 'ERROR');
                    return null;
                }
                if ($this->getSigningPublicKeyPem() === '' || !function_exists('openssl_verify')) {
                    cybokron_log('Signed update required but UPDATE_SIGNING_PUBLIC_KEY_PEM or OpenSSL is unavailable.', 'ERROR');
                    return null;
                }
            }

            return [
                'current_version' => $this->currentVersion,
                'latest_version'  => $latestVersion,
                'tag_name'        => (string) $release['tag_name'],
                'download_url'    => $downloadUrl,
                'signature_url'   => $signatureUrl,
                'release_notes'   => $release['body'] ?? '',
                'published_at'    => $release['published_at'] ?? '',
            ];
        }

        return null;
    }

    /**
     * Download and apply the update.
     */
    public function applyUpdate(array $updateInfo): array
    {
        if (empty($updateInfo['download_url']) || !is_string($updateInfo['download_url'])) {
            return ['status' => 'error', 'message' => 'No download URL available.'];
        }

        $downloadUrl = trim((string) $updateInfo['download_url']);
        $signatureUrl = isset($updateInfo['signature_url']) && is_string($updateInfo['signature_url'])
            ? trim((string) $updateInfo['signature_url'])
            : '';
        $allowedHosts = $this->getAllowedUpdateHosts();

        $tempDir = sys_get_temp_dir() . '/cybokron_update_' . time();
        $zipFile = $tempDir . '.zip';

        try {
            $zipData = $this->downloadSecureBytes($downloadUrl, 120, $allowedHosts, 'update package');

            if ($this->isSignatureRequired()) {
                if ($signatureUrl === '') {
                    throw new RuntimeException('Signature URL missing for required signed update.');
                }

                $signatureData = $this->downloadSecureBytes($signatureUrl, 30, $allowedHosts, 'update signature');
                $this->verifyDetachedSignature($zipData, $signatureData);
            }

            if (!$zipData) {
                throw new RuntimeException('Failed to download update.');
            }

            if (file_put_contents($zipFile, $zipData) === false) {
                throw new RuntimeException('Failed to write update ZIP file.');
            }

            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Failed to open ZIP archive.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string) $zip->getNameIndex($i);
                if (!$this->isSafeZipEntryPath($entryName)) {
                    $zip->close();
                    throw new RuntimeException('Unsafe path found in update archive.');
                }
            }

            mkdir($tempDir, 0755, true);
            $zip->extractTo($tempDir);
            $zip->close();

            $dirs = glob($tempDir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                throw new RuntimeException('No directory found in ZIP.');
            }
            $extractedDir = $dirs[0];

            $backupRoot = defined('BACKUP_DIR')
                ? rtrim((string) BACKUP_DIR, "/\\")
                : dirname($this->basePath) . '/cybokron-backups';
            $backupDir = $backupRoot . '/' . $this->currentVersion . '_' . date('Ymd_His');
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $this->copyDirectory($extractedDir, $this->basePath, ['config.php', '.git', 'backups']);

            file_put_contents(
                $this->basePath . '/VERSION',
                $updateInfo['latest_version'] . "\n"
            );

            Database::update(
                'settings',
                ['value' => $updateInfo['latest_version']],
                '`key` = ?',
                ['app_version']
            );

            Database::update(
                'settings',
                ['value' => date('Y-m-d H:i:s')],
                '`key` = ?',
                ['last_update_check']
            );

            @unlink($zipFile);
            $this->removeDirectory($tempDir);

            return [
                'status'           => 'success',
                'previous_version' => $this->currentVersion,
                'new_version'      => $updateInfo['latest_version'],
                'message'          => "Updated from {$this->currentVersion} to {$updateInfo['latest_version']}",
            ];
        } catch (Throwable $e) {
            @unlink($zipFile);
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate ZIP entry path to mitigate zip-slip style path traversal.
     */
    private function isSafeZipEntryPath(string $entryPath): bool
    {
        if ($entryPath === '') {
            return false;
        }

        if (str_contains($entryPath, '..')) {
            return false;
        }

        if (str_starts_with($entryPath, '/') || str_starts_with($entryPath, '\\')) {
            return false;
        }

        if (preg_match('/^[a-zA-Z]:[\\\/]/', $entryPath)) {
            return false;
        }

        return true;
    }

    /**
     * Recursively copy directory, skipping specified items.
     */
    private function copyDirectory(string $src, string $dst, array $skip = []): void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (in_array($file, $skip, true)) {
                continue;
            }

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_link($srcPath)) {
                continue;
            }

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath, $skip);
            } else {
                copy($srcPath, $dstPath);
            }
        }

        closedir($dir);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * @return string[]
     */
    private function getAllowedUpdateHosts(): array
    {
        $configured = (defined('UPDATE_ALLOWED_HOSTS') && is_array(UPDATE_ALLOWED_HOSTS))
            ? UPDATE_ALLOWED_HOSTS
            : ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];

        $normalized = [];
        foreach ($configured as $host) {
            $candidate = strtolower(trim((string) $host));
            $candidate = rtrim($candidate, '.');
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param string[] $allowedHosts
     */
    private function assertAllowedUpdateHost(string $url, array $allowedHosts, string $context): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException("Blocked non-HTTPS URL for {$context}.");
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        $host = rtrim($host, '.');
        if ($host === '') {
            throw new RuntimeException("Missing host for {$context}.");
        }

        if (empty($allowedHosts)) {
            return;
        }

        foreach ($allowedHosts as $allowedHost) {
            $candidate = strtolower(trim((string) $allowedHost));
            $candidate = rtrim($candidate, '.');
            if ($candidate !== '' && ($host === $candidate || str_ends_with($host, '.' . $candidate))) {
                return;
            }
        }

        throw new RuntimeException("Blocked outbound host '{$host}' for {$context}.");
    }

    /**
     * Resolve package download URL from release assets/config.
     */
    private function findReleasePackageUrl(array $release): ?string
    {
        $assetName = defined('UPDATE_PACKAGE_ASSET_NAME')
            ? trim((string) UPDATE_PACKAGE_ASSET_NAME)
            : '';

        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        if ($assetName !== '') {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                if (($asset['name'] ?? '') !== $assetName) {
                    continue;
                }

                $assetUrl = $asset['browser_download_url'] ?? null;
                return is_string($assetUrl) && $assetUrl !== '' ? $assetUrl : null;
            }

            return null;
        }

        $zipball = $release['zipball_url'] ?? null;
        return is_string($zipball) && $zipball !== '' ? $zipball : null;
    }

    /**
     * Resolve signature URL from release assets.
     */
    private function findReleaseSignatureUrl(array $release): ?string
    {
        $signatureAssetName = defined('UPDATE_SIGNATURE_ASSET_NAME')
            ? trim((string) UPDATE_SIGNATURE_ASSET_NAME)
            : 'cybokron-update.zip.sig';

        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = (string) ($asset['name'] ?? '');
            if ($name !== $signatureAssetName) {
                continue;
            }

            $assetUrl = $asset['browser_download_url'] ?? null;
            return is_string($assetUrl) && $assetUrl !== '' ? $assetUrl : null;
        }

        return null;
    }

    /**
     * Download bytes while enforcing protocol and allowlisted redirect hosts.
     *
     * @param string[] $allowedHosts
     */
    private function downloadSecureBytes(string $url, int $timeout, array $allowedHosts, string $context): string
    {
        $this->assertAllowedUpdateHost($url, $allowedHosts, $context);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Cybokron-Updater/1.0',
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($effectiveUrl !== '') {
            $this->assertAllowedUpdateHost($effectiveUrl, $allowedHosts, $context . ' redirect target');
        }

        if (!is_string($raw) || $raw === '' || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Failed to download {$context}: {$curlError}");
        }

        return $raw;
    }

    /**
     * Require detached signature verification for update payload.
     */
    private function verifyDetachedSignature(string $payload, string $signatureData): void
    {
        if (!function_exists('openssl_verify')) {
            throw new RuntimeException('OpenSSL extension is required for signature verification.');
        }

        $publicKeyPem = $this->getSigningPublicKeyPem();
        if ($publicKeyPem === '') {
            throw new RuntimeException('UPDATE_SIGNING_PUBLIC_KEY_PEM is required for signature verification.');
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new RuntimeException('Invalid UPDATE_SIGNING_PUBLIC_KEY_PEM value.');
        }

        $trimmedSig = trim($signatureData);
        $decodedSig = base64_decode($trimmedSig, true);
        $signature = is_string($decodedSig) ? $decodedSig : $signatureData;

        $verified = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if (function_exists('openssl_free_key')) {
            openssl_free_key($publicKey);
        }

        if ($verified !== 1) {
            throw new RuntimeException('Update signature verification failed.');
        }
    }

    /**
     * Signed updates are required by default.
     */
    private function isSignatureRequired(): bool
    {
        return !defined('UPDATE_REQUIRE_SIGNATURE') || UPDATE_REQUIRE_SIGNATURE === true;
    }

    /**
     * Read PEM-encoded public key used for update signature validation.
     */
    private function getSigningPublicKeyPem(): string
    {
        return defined('UPDATE_SIGNING_PUBLIC_KEY_PEM')
            ? trim((string) UPDATE_SIGNING_PUBLIC_KEY_PEM)
            : '';
    }

    /**
     * Get current version.
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }
}

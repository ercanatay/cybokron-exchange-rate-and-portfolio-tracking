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
        $this->currentVersion = trim((string) file_get_contents($this->basePath . '/VERSION'));
    }

    /**
     * Check GitHub for latest release.
     */
    public function checkForUpdate(): ?array
    {
        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
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
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $release = json_decode($response, true);

        if (!$release || empty($release['tag_name'])) {
            return null;
        }

        $latestVersion = ltrim((string) $release['tag_name'], 'v');

        if (version_compare($latestVersion, $this->currentVersion, '>')) {
            return [
                'current_version' => $this->currentVersion,
                'latest_version'  => $latestVersion,
                'tag_name'        => (string) $release['tag_name'],
                'download_url'    => $release['zipball_url'] ?? null,
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

        $tempDir = sys_get_temp_dir() . '/cybokron_update_' . time();
        $zipFile = $tempDir . '.zip';

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $updateInfo['download_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Cybokron-Updater/1.0',
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if (defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
            }

            $zipData = curl_exec($ch);
            curl_close($ch);

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

            $backupDir = $this->basePath . '/backups/' . $this->currentVersion . '_' . date('Ymd_His');
            if (!is_dir(dirname($backupDir))) {
                mkdir(dirname($backupDir), 0755, true);
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
     * Get current version.
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }
}

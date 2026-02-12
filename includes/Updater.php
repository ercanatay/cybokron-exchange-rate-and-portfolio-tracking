<?php
/**
 * Self-updater via GitHub Releases
 */
class Updater
{
    private string $owner;
    private string $repo;
    private string $currentVersion;
    private string $basePath;

    public function __construct()
    {
        $config = require __DIR__ . '/../config.php';
        $this->owner = $config['github']['owner'];
        $this->repo = $config['github']['repo'];
        $this->currentVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
        $this->basePath = realpath(__DIR__ . '/..');
    }

    /**
     * Check if a newer version is available
     */
    public function checkForUpdate(): ?array
    {
        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Cybokron-Updater/' . $this->currentVersion,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $release = json_decode($response, true);
        if (!$release || !isset($release['tag_name'])) {
            return null;
        }

        $latestVersion = ltrim($release['tag_name'], 'v');

        if (version_compare($latestVersion, $this->currentVersion, '>')) {
            return [
                'current_version' => $this->currentVersion,
                'latest_version'  => $latestVersion,
                'tag_name'        => $release['tag_name'],
                'download_url'    => $release['zipball_url'] ?? null,
                'release_notes'   => $release['body'] ?? '',
                'published_at'    => $release['published_at'] ?? '',
            ];
        }

        return null; // Already up to date
    }

    /**
     * Download and apply update
     */
    public function applyUpdate(array $updateInfo): bool
    {
        if (!$updateInfo['download_url']) {
            throw new RuntimeException('No download URL available');
        }

        $tmpDir = sys_get_temp_dir() . '/cybokron_update_' . time();
        $zipFile = $tmpDir . '/update.zip';

        try {
            // Create temp directory
            if (!mkdir($tmpDir, 0755, true)) {
                throw new RuntimeException("Cannot create temp directory: $tmpDir");
            }

            // Download ZIP
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $updateInfo['download_url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Cybokron-Updater/' . $this->currentVersion,
                CURLOPT_TIMEOUT        => 120,
            ]);
            $zipData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$zipData) {
                throw new RuntimeException("Failed to download update (HTTP $httpCode)");
            }

            file_put_contents($zipFile, $zipData);

            // Extract ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Failed to open ZIP file');
            }

            $zip->extractTo($tmpDir . '/extracted');
            $zip->close();

            // Find the extracted directory (GitHub adds a prefix)
            $extracted = glob($tmpDir . '/extracted/*', GLOB_ONLYDIR);
            if (empty($extracted)) {
                throw new RuntimeException('No directory found in ZIP');
            }
            $sourceDir = $extracted[0];

            // Files to preserve (not overwrite)
            $preserve = ['config.php', '.git'];

            // Copy files
            $this->copyDirectory($sourceDir, $this->basePath, $preserve);

            // Update VERSION file
            file_put_contents($this->basePath . '/VERSION', $updateInfo['latest_version']);

            return true;

        } finally {
            // Cleanup temp files
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * Recursively copy directory, preserving specified files
     */
    private function copyDirectory(string $source, string $dest, array $preserve = []): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($source) + 1);

            // Skip preserved files
            foreach ($preserve as $skip) {
                if (strpos($relativePath, $skip) === 0) {
                    continue 2;
                }
            }

            $targetPath = $dest . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }
}

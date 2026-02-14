<?php
/**
 * GitHubIntegration.php — GitHub API Integration
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * Commits repair config files and creates issues via GitHub Contents & Issues API.
 * Token is read from GITHUB_API_TOKEN constant (defined in gitignored config.php).
 */

class GitHubIntegration
{
    private string $repo;
    private string $token;
    private string $branch;

    /** @var string[] Allowed API hosts for outbound requests. */
    private const ALLOWED_HOSTS = ['api.github.com'];

    public function __construct()
    {
        $this->repo = defined('GITHUB_REPO') ? trim((string) GITHUB_REPO) : '';
        $this->token = defined('GITHUB_API_TOKEN') ? trim((string) GITHUB_API_TOKEN) : '';
        $this->branch = defined('GITHUB_BRANCH') ? trim((string) GITHUB_BRANCH) : 'main';
    }

    /**
     * Check if GitHub integration is configured.
     */
    public function isConfigured(): bool
    {
        return $this->repo !== '' && $this->token !== '';
    }

    /**
     * Commit a file via GitHub Contents API (PUT).
     *
     * @return array{sha: string, html_url: string}|null
     */
    public function commitFile(string $path, string $content, string $message): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $existingSha = $this->getFileSha($path);

        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $this->branch,
        ];

        if ($existingSha !== null) {
            $payload['sha'] = $existingSha;
        }

        $url = "https://api.github.com/repos/{$this->repo}/contents/{$path}";
        $response = $this->apiRequest('PUT', $url, $payload);

        if ($response === null || !isset($response['content']['sha'])) {
            return null;
        }

        return [
            'sha'      => (string) $response['content']['sha'],
            'html_url' => (string) ($response['content']['html_url'] ?? ''),
        ];
    }

    /**
     * Create a GitHub issue via Issues API (POST).
     *
     * @param string[] $labels
     * @return array{number: int, html_url: string}|null
     */
    public function createIssue(string $title, string $body, array $labels = []): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = "https://api.github.com/repos/{$this->repo}/issues";
        $payload = [
            'title'  => $title,
            'body'   => $body,
            'labels' => $labels,
        ];

        $response = $this->apiRequest('POST', $url, $payload);

        if ($response === null || !isset($response['number'])) {
            return null;
        }

        return [
            'number'   => (int) $response['number'],
            'html_url' => (string) ($response['html_url'] ?? ''),
        ];
    }

    /**
     * Get the SHA of an existing file (needed for updates).
     */
    public function getFileSha(string $path): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = "https://api.github.com/repos/{$this->repo}/contents/{$path}?ref={$this->branch}";
        $response = $this->apiRequest('GET', $url);

        if ($response === null || !isset($response['sha'])) {
            return null;
        }

        return (string) $response['sha'];
    }

    /**
     * Make a GitHub API request with security validations.
     */
    private function apiRequest(string $method, string $url, ?array $payload = null): ?array
    {
        $this->assertHttps($url);
        $this->assertAllowedHost($url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Cybokron/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
            ],
        ]);

        if (defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($effectiveUrl !== '') {
            $this->assertAllowedHost($effectiveUrl);
        }

        if ($raw === false || $raw === '') {
            cybokron_log("GitHub API request failed: {$curlError}", 'ERROR');
            return null;
        }

        if ($httpCode === 404 && $method === 'GET') {
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            cybokron_log("GitHub API HTTP {$httpCode}: " . substr((string) $raw, 0, 500), 'ERROR');
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Ensure URL uses HTTPS.
     */
    private function assertHttps(string $url): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException("GitHub API URL must use HTTPS: {$url}");
        }
    }

    /**
     * Ensure URL host is in the allowlist.
     */
    private function assertAllowedHost(string $url): void
    {
        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? '')));
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new RuntimeException('Missing host in GitHub API URL');
        }

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        throw new RuntimeException("Blocked outbound host '{$host}' for GitHub API");
    }
}

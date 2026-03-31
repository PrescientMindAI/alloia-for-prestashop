<?php
/**
 * AlloIA for PrestaShop — Update checker
 *
 * Checks GitHub releases once per day and displays a notice in the module
 * admin page when a newer version is available.
 *
 * @author    AlloIA Team
 * @copyright 2025 AlloIA
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AlloiaUpdater
{
    const GITHUB_REPO      = 'PrescientMindAI/alloia-for-prestashop';
    const GITHUB_API_URL   = 'https://api.github.com/repos/PrescientMindAI/alloia-for-prestashop/releases/latest';
    const CACHE_KEY        = 'ALLOIA_UPDATE_CACHE';
    const CACHE_EXPIRY_KEY = 'ALLOIA_UPDATE_CACHE_EXPIRY';
    const CACHE_TTL        = 86400; // 24 hours in seconds

    /** @var string|null */
    private $latestVersion = null;

    /** @var string|null */
    private $downloadUrl = null;

    /** @var string|null */
    private $releaseUrl = null;

    public function __construct()
    {
        $this->loadFromCache();
    }

    /**
     * Returns true when $currentVersion is older than the latest GitHub release.
     *
     * @param string $currentVersion e.g. '1.0.8'
     * @return bool
     */
    public function isUpdateAvailable($currentVersion)
    {
        if ($this->latestVersion === null) {
            return false;
        }
        return version_compare($currentVersion, $this->latestVersion, '<');
    }

    /** @return string|null */
    public function getLatestVersion()
    {
        return $this->latestVersion;
    }

    /** @return string|null Direct browser download URL for the zip asset */
    public function getDownloadUrl()
    {
        return $this->downloadUrl;
    }

    /** @return string|null GitHub release page URL */
    public function getReleaseUrl()
    {
        return $this->releaseUrl;
    }

    /**
     * Load release info from PrestaShop config cache.
     * Refreshes from GitHub if the cache is stale or missing.
     */
    private function loadFromCache()
    {
        $expiry = (int) Configuration::get(self::CACHE_EXPIRY_KEY);

        if ($expiry > time()) {
            $cached = Configuration::get(self::CACHE_KEY);
            if ($cached) {
                $data = json_decode($cached, true);
                if (isset($data['version'])) {
                    $this->latestVersion = $data['version'];
                    $this->downloadUrl   = isset($data['download_url']) ? $data['download_url'] : null;
                    $this->releaseUrl    = isset($data['release_url'])  ? $data['release_url']  : null;
                    return;
                }
            }
        }

        $this->fetchFromGitHub();
    }

    /**
     * Hit the GitHub releases API and persist the result.
     */
    private function fetchFromGitHub()
    {
        $ch = curl_init(self::GITHUB_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'User-Agent: AlloIA-PrestaShop-Updater/' . _PS_VERSION_,
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            // Cache a short retry window on failure (1 hour)
            Configuration::updateValue(self::CACHE_EXPIRY_KEY, time() + 3600);
            return;
        }

        $release = json_decode($body, true);
        if (!isset($release['tag_name'])) {
            Configuration::updateValue(self::CACHE_EXPIRY_KEY, time() + 3600);
            return;
        }

        // Tag format: prestashop-v1.0.8 → strip prefix to get "1.0.8"
        $version = ltrim(str_replace('prestashop-v', '', $release['tag_name']), 'v');

        // Prefer the explicit zip asset; fall back to zipball
        $downloadUrl = isset($release['zipball_url']) ? $release['zipball_url'] : null;
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
                    $downloadUrl = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $this->latestVersion = $version;
        $this->downloadUrl   = $downloadUrl;
        $this->releaseUrl    = isset($release['html_url']) ? $release['html_url'] : null;

        Configuration::updateValue(self::CACHE_KEY, json_encode([
            'version'      => $version,
            'download_url' => $downloadUrl,
            'release_url'  => $this->releaseUrl,
        ]));
        Configuration::updateValue(self::CACHE_EXPIRY_KEY, time() + self::CACHE_TTL);
    }
}

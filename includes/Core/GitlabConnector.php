<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;
use RRZE\Updater\Utility;

/**
 * Class GitlabConnector
 *
 * Represents a connector for GitLab repositories.
 */
class GitlabConnector extends Connector
{
    /**
     * GitLab host name.
     *
     * @var string
     */
    public $host;

    /**
     * GitLab projects API URI.
     *
     * @var string
     */
    public $apiUri;

    /**
     * Constructor for the GitlabConnector class.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Add a GitlabConnector object from an array of data.
     *
     * @static
     * @param array $array An associative array of data for creating the connector.
     * @return object The created GitlabConnector object.
     */
    public static function createFromArray(array $array): object
    {
        // Add a new GitlabConnector object.
        // Populate properties from the provided array.
        // Set default values if necessary.
        // Return the created object.

        $connector = new GitlabConnector();

        if (isset($array['token'])) {
            $connector->token = sanitize_text_field($array['token']);
        }
        if (isset($array['id'])) {
            $connector->id = sanitize_text_field($array['id']);
        } else {
            $connector->id = Utility::uniqid();
        }
        $connector->updateServerSettings($array);
        $connector->owner = sanitize_text_field($array['owner']);

        return $connector;
    }

    /**
     * Convert the connector's properties to an associative array.
     *
     * @return array An array containing the connector's properties.
     */
    public function asArray()
    {
        // Return an associative array containing the connector's properties.        
        return [
            'type' => 'gitlab',
            'id' => $this->id,
            'display' => $this->display,
            'owner' => $this->owner,
            'host' => $this->host,
            'apiUri' => $this->apiUri,
            'token' => $this->token
        ];
    }

    /**
     * Get the type of the connector, which is 'gitlab'.
     *
     * @return string The connector type.
     */
    public function getType(): string
    {
        return 'gitlab';
    }

    /**
     * Updates configurable GitLab server settings.
     *
     * @param array $array An associative array of connector settings.
     */
    public function updateServerSettings(array $array)
    {
        $rrzeSettings = self::getGitlabRrzeSettings();
        $customSettings = self::getGitlabCustomSettings();
        $config = new Config();
        $defaultHost = $config->getGitlabDefaultHost();
        $defaultApiUri = $config->getGitlabDefaultApiUri();
        $defaultDisplay = (string) ($rrzeSettings['default_display'] ?? 'GitLab RRZE');
        $customDisplayFormat = (string) ($customSettings['custom_display_format'] ?? 'GitLab (%s)');

        $this->host = self::sanitizeHost((string) ($array['host'] ?? $defaultHost));
        $this->apiUri = self::sanitizeApiUri((string) ($array['apiUri'] ?? $defaultApiUri));

        if ($this->host === $defaultHost && $this->apiUri === $defaultApiUri) {
            $this->display = $defaultDisplay;
            return;
        }

        $this->display = sprintf($customDisplayFormat, $this->host);
    }

    /**
     * Get the URL of a GitLab repository based on the owner and repository name.
     *
     * @param string $repository The name of the repository.
     * @return string The URL of the GitLab repository.
     */
    public function getUrl(string $repository): string
    {
        // Construct and return the URL of the GitLab repository.
        return $this->getBaseUrl() . '/' . $this->owner . '/' . $repository;
    }

    /**
     * Get the remote commit (ID) of a specific branch of a GitLab repository.
     *
     * @param string $repository The name of the repository.
     * @param string $branch     The branch name.
     * @return string|boolean The remote commit ID or false on failure.
     */
    public function getRemoteCommit(string $repository, string $branch = 'main'): string|bool
    {
        // Query the GitLab API to get the remote commit ID.
        // Return the ID or false on failure.

        $url = sprintf(
            '%1$s/%2$s/repository/commits?ref_name=%3$s',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository),
            rawurlencode($branch)
        );

        $response = $this->api(
            $url,
            $this->requestArgs(),
            [
                'logContext' => $this->getRepositoryLogContext($repository, 'commits', $branch)
            ]
        );

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->id ?? false;
        }
        return $ret;
    }

    public function remoteBranchExists(string $repository, string $branch): bool
    {
        $url = sprintf(
            '%1$s/%2$s/repository/branches/%3$s',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository),
            rawurlencode($branch)
        );

        $response = $this->api(
            $url,
            $this->requestArgs(),
            [
                'logErrors' => false,
                'storeError' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'branches', $branch)
            ]
        );

        return is_object($response) && isset($response->name);
    }

    public function getRemoteBranches(string $repository): array|false
    {
        $url = sprintf(
            '%1$s/%2$s/repository/branches?per_page=100',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository)
        );

        $response = $this->api(
            $url,
            $this->requestArgs(),
            [
                'logErrors' => false,
                'storeError' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'branches')
            ]
        );

        if (!is_array($response)) {
            return false;
        }

        return $this->extractBranchNames($response);
    }

    /**
     * Get the latest remote tag of a GitLab repository.
     *
     * @param string $repository The name of the repository.
     * @return string|boolean The latest remote tag name or false on failure.
     */
    public function getRemoteTag(string $repository): string|bool
    {
        // Query the GitLab API to get the latest remote tag.
        // Return the tag name or false on failure.

        $url = sprintf(
            '%1$s/%2$s/repository/tags',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository)
        );

        $response = $this->api(
            $url,
            $this->requestArgs(),
            [
                'logContext' => $this->getRepositoryLogContext($repository, 'tags')
            ]
        );

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->name ?? false;
        }
        return $ret;
    }

    /**
     * Return the tag of the newest published GitLab release.
     *
     * @param string $repository The repository name.
     * @return string|false A published release tag, or false if none is available.
     */
    public function getRemoteRelease(string $repository): string|false
    {
        // GitLab has upcoming releases, but no GitHub-style prerelease flag.
        // Read newest released_at first and skip entries not published yet.
        for ($page = 1; $page <= 100; $page++) {
            $url = sprintf('%s/%s/releases?order_by=released_at&sort=desc&per_page=100&page=%d',
                $this->getApiBaseUrl(), rawurlencode($this->owner . '/' . $repository), $page);
            $response = $this->api($url, $this->requestArgs(), [
                'logContext' => $this->getRepositoryLogContext($repository, 'releases'),
            ]);
            if (!is_array($response)) {
                return false;
            }
            foreach ($response as $release) {
                if (!is_object($release) || !empty($release->upcoming_release)
                    || empty($release->tag_name) || !is_string($release->tag_name)) {
                    continue;
                }
                $releasedAt = strtotime($release->released_at ?? '');
                if ($releasedAt !== false && $releasedAt <= time()) {
                    return $release->tag_name;
                }
            }
            if (count($response) < 100) {
                return false;
            }
        }
        $this->error = __('Release lookup exceeded the pagination limit.', 'rrze-updater');
        return false;
    }

    /** A credential-free identifier safe to store in WordPress update transients. */
    public function downloadRepoZip(string $repository, string $branch = 'main'): string
    {
        return sprintf('%s/%s/repository/archive.zip?sha=%s',
            $this->getApiBaseUrl(), rawurlencode($this->owner . '/' . $repository), rawurlencode($branch));
    }

    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $destination = wp_tempnam($repository . '.zip');
        if (!$destination) {
            $this->error = __('Could not create temporary file.', 'rrze-updater');
            return false;
        }
        $complete = false;
        try {
            $response = $this->api($this->downloadRepoZip($repository, $branch), array_merge($this->requestArgs(), [
                'stream' => true, 'filename' => $destination, 'timeout' => 300,
            ]), [
                'jsonDecodeBody' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'archive.zip', $branch),
            ]);
            if (!$response || !is_file($destination) || filesize($destination) === 0) {
                $this->error = $this->error ?: __('Could not download ZIP archive.', 'rrze-updater');
                return false;
            }
            $complete = true;
            return $destination;
        } finally {
            if (!$complete && is_file($destination)) {
                wp_delete_file($destination);
            }
        }
    }

    public function getRemoteFile(string $repository, string $ref, string $filePath): string|bool
    {
        $url = sprintf(
            '%1$s/%2$s/repository/files/%3$s/raw?ref=%4$s',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository),
            urlencode($filePath),
            rawurlencode($ref)
        );

        $response = $this->api(
            $url,
            $this->requestArgs(),
            [
                'jsonDecodeBody' => false,
                'logErrors' => false,
                'storeError' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'repository-file', $ref, $filePath)
            ]
        );

        if (!$response || !isset($response['body'])) {
            return false;
        }

        return is_string($response['body']) ? $response['body'] : false;
    }

    private static function sanitizeHost(string $host): string
    {
        $defaultHost = (new Config())->getGitlabDefaultHost();
        $host = trim(sanitize_text_field($host));
        $parsedHost = parse_url($host, PHP_URL_HOST);

        if ($parsedHost) {
            $host = $parsedHost;
        }

        $host = trim($host, " \t\n\r\0\x0B/");

        return $host ?: $defaultHost;
    }

    private function extractBranchNames(array $branches): array
    {
        $names = [];

        foreach ($branches as $branch) {
            if (!is_object($branch) || empty($branch->name)) {
                continue;
            }

            $names[] = sanitize_text_field((string) $branch->name);
        }

        return array_values(array_unique(array_filter($names)));
    }

    private static function sanitizeApiUri(string $apiUri): string
    {
        $defaultApiUri = (new Config())->getGitlabDefaultApiUri();
        $apiUri = trim(sanitize_text_field($apiUri));

        if ($apiUri === '') {
            $apiUri = $defaultApiUri;
        }

        return '/' . trim($apiUri, '/') . '/';
    }

    private function getBaseUrl(): string
    {
        return 'https://' . $this->host;
    }

    private function getApiBaseUrl(): string
    {
        return rtrim($this->getBaseUrl() . $this->apiUri, '/');
    }

    private function requestArgs(): array
    {
        // Never forward the connector credential to a redirected host.
        return ['headers' => $this->token ? ['PRIVATE-TOKEN' => $this->token] : [], 'redirection' => 0];
    }

    private static function getGitlabRrzeSettings(): array
    {
        return (new Config())->getConnectorSettings('gitlab-rrze');
    }

    private static function getGitlabCustomSettings(): array
    {
        return (new Config())->getConnectorSettings('gitlab-custom');
    }
}

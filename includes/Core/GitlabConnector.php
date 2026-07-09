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

        if ($this->token) {
            $url = $this->addPrivateToken($url);
        }

        $response = $this->api($url);

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->id ?? false;
        }
        return $ret;
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

        if ($this->token) {
            $url = $this->addPrivateToken($url);
        }

        $response = $this->api($url);

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->name ?? false;
        }
        return $ret;
    }

    /**
     * Get the latest remote tag of a GitLab repository.
     *
     * @param string $repository The name of the repository.
     * @return string|boolean The latest remote tag or false on failure.
     */
    public function downloadRepoZip(string $repository, string $branch = 'main'): string|bool
    {
        // Construct and return the ZIP archive download URL.
        // Return false on failure or rate limit reached.

        $url = sprintf(
            '%1$s/%2$s/repository/archive.zip?sha=%3$s',
            $this->getApiBaseUrl(),
            urlencode($this->owner . '/' . $repository),
            rawurlencode($branch)
        );

        if ($this->token) {
            $url = $this->addPrivateToken($url);
        }

        $response = $this->api($url, [], ['jsonDecodeBody' => false]);
        if (!$response) {
            return false;
        }

        return $url;
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

        if ($this->token) {
            $url = $this->addPrivateToken($url);
        }

        $response = $this->api(
            $url,
            [],
            [
                'jsonDecodeBody' => false,
                'logErrors' => false,
                'storeError' => false
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

    private function addPrivateToken(string $url): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . 'private_token=' . rawurlencode($this->token);
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

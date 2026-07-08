<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Utility;

/**
 * Class GithubConnector
 *
 * Represents a connector for GitHub repositories.
 */
class GithubConnector extends Connector
{
    /**
     * Constructor for the GithubConnector class.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Add a GithubConnector object from an array of data.
     *
     * @static
     * @param array $array An associative array of data for creating the connector.
     * @return object The created GithubConnector object.
     */
    public static function createFromArray(array $array): object
    {
        // Add a new GithubConnector object.
        // Populate properties from the provided array.
        // Set default values if necessary.
        // Return the created object.

        $connector = new GithubConnector();

        if (isset($array['token'])) {
            $connector->token = sanitize_text_field($array['token']);
        }
        if (isset($array['id'])) {
            $connector->id = sanitize_text_field($array['id']);
        } else {
            $connector->id = Utility::uniqid();
        }
        $connector->display = 'GitHub.com';
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
            'type' => 'github',
            'id' => $this->id,
            'display' => $this->display,
            'owner' => $this->owner,
            'token' => $this->token
        ];
    }

    /**
     * Get the type of the connector, which is 'github'.
     *
     * @return string The connector type.
     */
    public function getType(): string
    {
        return 'github';
    }

    /**
     * Get the URL of a GitHub repository based on the owner and repository name.
     *
     * @param string $repository The name of the repository.
     * @return string The URL of the GitHub repository.
     */
    public function getUrl(string $repository): string
    {
        // Construct and return the URL of the GitHub repository.
        $ret = 'https://github.com/' . $this->owner . '/'  . $repository;
        return $ret;
    }

    /**
     * Get the remote commit (SHA) of a specific branch of a GitHub repository.
     *
     * @param string $repository The name of the repository.
     * @param string $branch     The branch name.
     * @return string|boolean The remote commit SHA or false on failure.
     */
    public function getRemoteCommit(string $repository, string $branch = 'main'): string|bool
    {
        // Query the GitHub API to get the remote commit SHA.
        // Return the SHA or false on failure.

        $url = sprintf(
            'https://api.github.com/repos/%1$s/%2$s/commits?sha=%3$s',
            $this->owner,
            $repository,
            $branch
        );

        $getArgs = [
            'headers' => $this->getHeaders()
        ];

        $response = $this->api($url, $getArgs);

        $ret = false;
        if (is_array($response) && count($response) > 0 && !$this->isRateLimitReached()) {
            $ret = $response[0]->sha;
        }
        return $ret;
    }

    /**
     * Get the latest remote tag of a GitHub repository.
     *
     * @param string $repository The name of the repository.
     * @return string|boolean The latest remote tag name or false on failure.
     */
    public function getRemoteTag(string $repository): string|bool
    {
        // Query the GitHub API to get the latest remote tag.
        // Return the tag name or false on failure.

        $url = sprintf(
            'https://api.github.com/repos/%1$s/%2$s/tags',
            $this->owner,
            $repository
        );

        $getArgs = [
            'headers' => $this->getHeaders()
        ];

        $response = $this->api($url, $getArgs);

        $ret = false;
        if (is_array($response) && count($response) > 0 && !$this->isRateLimitReached()) {
            $ret = $response[0]->name;
        }
        return $ret;
    }

    public function downloadRepoZip(string $repository, string $branch = 'main'): string
    {
        return $this->getRepoZipUrl($repository, $branch);
    }

    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool
    {
        $url = $this->getRepoZipUrl($repository, $branch);

        $response = $this->api(
            $url,
            [
                'headers' => $this->getHeaders()
            ],
            [
                'jsonDecodeBody' => false
            ]
        );

        if (!$response || $this->isRateLimitReached()) {
            return false;
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $dest = wp_tempnam($repository . '.zip');
        if (!$dest) {
            $this->error = __('Could not create temporary file.', 'rrze-updater');
            return false;
        }

        if (false === file_put_contents($dest, $response['body'])) {
            @unlink($dest);
            $this->error = __('Could not write ZIP archive to temporary file.', 'rrze-updater');
            return false;
        }

        return $dest;
    }

    private function getRepoZipUrl(string $repository, string $branch = 'main'): string
    {
        return sprintf(
            'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s',
            $this->owner,
            $repository,
            $branch
        );
    }

    /**
     * Get HTTP headers for GitHub API requests, including authentication headers if a token is available.
     *
     * @return array An array of HTTP headers.
     */
    protected function getHeaders(): array
    {
        // Construct and return the HTTP headers for GitHub API requests.
        // Include authentication headers if a token is available.

        $headers['Accept'] = 'application/vnd.github.v3.full+json';
        if ($this->token) {
            $headers['Authorization'] = 'token ' . $this->token;
        }
        return $headers;
    }

    /**
     * Check if the rate limit for GitHub API requests is reached.
     *
     * @return boolean True if the rate limit is reached, otherwise false.
     */
    public function isRateLimitReached(): bool
    {
        // Query the GitHub API to check the rate limit status.
        // Set warning and error messages based on the rate limit status.
        // Return true if rate limit is reached, otherwise false.

        $getArgs = [
            'headers' => $this->getHeaders()
        ];
        $response = $this->api('https://api.github.com/rate_limit', $getArgs);
        if (isset($response->resources->core->remaining) && $response->resources->core->remaining > 1) {
            $this->warning = sprintf(
                /* translators: 1: API rate limit, 2: API rate left, 3: API rate reset */
                __('GitHub API Rate Limit: %1$s (%2$s left). It\'ll be reset %3$s.', 'rrze-updater'),
                $response->resources->core->limit,
                $response->resources->core->remaining,
                sprintf(
                    /* translators: %s: Human time difference */
                    esc_html_x('in %s', 'Human time difference', 'rrze-updater'),
                    human_time_diff(
                        $response->resources->core->reset,
                        get_the_time('U')
                    )
                )
            );
            return false;
        } elseif (isset($response->resources->core->reset)) {
            $this->error = sprintf(
                /* translators: %s: API rate limit time availabelity */
                __('GitHub API Rate Limit is reached! It\'ll be available %s.', 'rrze-updater'),
                sprintf(
                    /* translators: %s: Human time difference */
                    esc_html_x('in %s', 'Human time difference', 'rrze-updater'),
                    human_time_diff(
                        $response->resources->core->reset,
                        get_the_time('U')
                    )
                )
            );
            return true;
        }
        return false;
    }
}

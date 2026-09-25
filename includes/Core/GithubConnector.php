<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;
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
        $settings = (new Config())->getConnectorSettings('github');
        $connector->display = (string) ($settings['display'] ?? 'GitHub.com');
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
        $webHost = (new Config())->getGithubWebHost();
        $ret = 'https://' . $webHost . '/' . $this->owner . '/'  . $repository;
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
            'https://%1$s/repos/%2$s/%3$s/commits?sha=%4$s',
            $this->getApiHost(),
            rawurlencode($this->owner),
            rawurlencode($repository),
            rawurlencode($branch)
        );

        $getArgs = [
            'headers' => $this->getHeaders()
        ];

        $response = $this->api(
            $url,
            $getArgs,
            [
                'logContext' => $this->getRepositoryLogContext($repository, 'commits', $branch)
            ]
        );

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->sha;
        }
        return $ret;
    }

    public function remoteBranchExists(string $repository, string $branch): bool
    {
        $url = sprintf(
            'https://%1$s/repos/%2$s/%3$s/branches/%4$s',
            $this->getApiHost(),
            rawurlencode($this->owner),
            rawurlencode($repository),
            rawurlencode($branch)
        );

        $response = $this->api(
            $url,
            [
                'headers' => $this->getHeaders()
            ],
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
            'https://%1$s/repos/%2$s/%3$s/branches?per_page=100',
            $this->getApiHost(),
            rawurlencode($this->owner),
            rawurlencode($repository)
        );

        $response = $this->api(
            $url,
            [
                'headers' => $this->getHeaders()
            ],
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
            'https://%1$s/repos/%2$s/%3$s/tags',
            $this->getApiHost(),
            $this->owner,
            $repository
        );

        $getArgs = [
            'headers' => $this->getHeaders()
        ];

        $response = $this->api(
            $url,
            $getArgs,
            [
                'logContext' => $this->getRepositoryLogContext($repository, 'tags')
            ]
        );

        $ret = false;
        if (is_array($response) && count($response) > 0) {
            $ret = $response[0]->name;
        }
        return $ret;
    }

    public function getRemoteRelease(string $repository): string|false
    {
        $url = sprintf('https://%s/repos/%s/%s/releases/latest',
            $this->getApiHost(), rawurlencode($this->owner), rawurlencode($repository));
        $response = $this->api($url, ['headers' => $this->getHeaders()], [
            'logContext' => $this->getRepositoryLogContext($repository, 'releases/latest'),
        ]);
        if (!is_object($response)
            || !empty($response->draft) || !empty($response->prerelease)) {
            return false;
        }
        return isset($response->tag_name) && is_string($response->tag_name) && $response->tag_name !== ''
            ? $response->tag_name : false;
    }

    public function downloadRepoZip(string $repository, string $branch = 'main'): string
    {
        return $this->getRepoZipUrl($repository, $branch);
    }

    public function getRemoteFile(string $repository, string $ref, string $filePath): string|bool
    {
        $url = sprintf(
            'https://%1$s/repos/%2$s/%3$s/contents/%4$s?ref=%5$s',
            $this->getApiHost(),
            rawurlencode($this->owner),
            rawurlencode($repository),
            $this->encodePath($filePath),
            rawurlencode($ref)
        );

        $response = $this->api(
            $url,
            [
                'headers' => $this->getHeaders()
            ],
            [
                'logErrors' => false,
                'storeError' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'contents', $ref, $filePath)
            ]
        );

        if (!is_object($response) || empty($response->content)) {
            return false;
        }

        if (($response->encoding ?? '') !== 'base64') {
            return false;
        }

        $content = base64_decode(str_replace(["\n", "\r"], '', $response->content), true);
        return is_string($content) ? $content : false;
    }

    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $dest = wp_tempnam($repository . '.zip');
        if (!$dest) {
            $this->error = __('Could not create temporary file.', 'rrze-updater');
            $this->logError(
                'Could not create temporary ZIP file for {repository}.',
                [
                    'repository' => $repository,
                    'ref' => $branch,
                    'error' => $this->error
                ]
            );
            return false;
        }

        // GitHub redirects zipballs to codeload, using a temporary URL for private
        // repositories. Scope this hook to our file and never forward the API token.
        $apiHost = $this->getApiHost();
        $redirect = static function ($location, &$headers, $data, $options) use ($dest, $apiHost): void {
            if (($options['filename'] ?? null) !== $dest) {
                return;
            }
            $parts = wp_parse_url($location);
            $host = strtolower($parts['host'] ?? '');
            if (strtolower($parts['scheme'] ?? '') !== 'https'
                || !in_array($host, [$apiHost, 'codeload.github.com'], true)
                || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new \WpOrg\Requests\Exception(
                    __('GitHub archive redirected to an unsupported or insecure URL.', 'rrze-updater'),
                    'rrze_updater_unsafe_archive_redirect'
                );
            }
            if ($host !== $apiHost) {
                foreach (array_keys($headers) as $name) {
                    if (strcasecmp($name, 'Authorization') === 0) {
                        unset($headers[$name]);
                    }
                }
            }
        };
        add_action('requests-requests.before_redirect', $redirect, 10, 4);

        $complete = false;
        try {
            $response = $this->api($this->getRepoZipUrl($repository, $branch), [
                'headers' => $this->getHeaders(),
                'stream' => true,
                'filename' => $dest,
                'timeout' => 300,
                'sslverify' => true,
                'reject_unsafe_urls' => true,
                'redirection' => 5,
            ], [
                'jsonDecodeBody' => false,
                'logContext' => $this->getRepositoryLogContext($repository, 'zipball', $branch),
            ]);
            clearstatcache(true, $dest);
            // A successful response remains usable even if it consumed the last API request.
            if (!$response || !is_file($dest) || filesize($dest) === 0) {
                $this->error = $this->error ?: __('Could not download ZIP archive.', 'rrze-updater');
                return false;
            }
            $complete = true;
            return $dest;
        } finally {
            remove_action('requests-requests.before_redirect', $redirect, 10);
            if (!$complete && is_file($dest)) {
                wp_delete_file($dest);
            }
        }
    }

    private function getRepoZipUrl(string $repository, string $branch = 'main'): string
    {
        return sprintf(
            'https://%1$s/repos/%2$s/%3$s/zipball/%4$s',
            $this->getApiHost(),
            rawurlencode($this->owner),
            rawurlencode($repository),
            rawurlencode($branch)
        );
    }

    private function encodePath(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        $encoded = array_map('rawurlencode', $parts);

        return implode('/', $encoded);
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

        $settings = $this->getGithubSettings();
        $headers['Accept'] = (string) ($settings['api_accept_header'] ?? 'application/vnd.github.v3.full+json');
        if ($this->token) {
            $headers['Authorization'] = 'token ' . $this->token;
        }
        return $headers;
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
        $response = $this->api('https://' . $this->getApiHost() . '/rate_limit', $getArgs);
        $core = $response->resources->core ?? null;
        if (!isset($core->remaining, $core->limit, $core->reset)) {
            return false;
        }
        if ($core->remaining > 0) {
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
                        time()
                    )
                )
            );
            return false;
        } else {
            $this->error = sprintf(
                /* translators: %s: API rate limit time availabelity */
                __('GitHub API Rate Limit is reached! It\'ll be available %s.', 'rrze-updater'),
                sprintf(
                    /* translators: %s: Human time difference */
                    esc_html_x('in %s', 'Human time difference', 'rrze-updater'),
                    human_time_diff(
                        $response->resources->core->reset,
                        time()
                    )
                )
            );
            $this->logError(
                'GitHub API rate limit reached for {owner}.',
                [
                    'owner' => $this->owner,
                    'error' => $this->error
                ]
            );
            return true;
        }
    }

    /** GitHub also reports primary and secondary throttling with HTTP 403. */
    protected function isRateLimitResponse($response, array $getArgs): bool
    {
        if (parent::isRateLimitResponse($response, $getArgs)) {
            return true;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 403) {
            return false;
        }
        if ((string) wp_remote_retrieve_header($response, 'x-ratelimit-remaining') === '0'
            || wp_remote_retrieve_header($response, 'retry-after') !== '') {
            return true;
        }
        $body = wp_remote_retrieve_body($response);
        // WordPress streams error responses too. Inspect only a small error body,
        // never a successful archive, when secondary throttling has no headers.
        if ($body === '' && !empty($getArgs['stream']) && !empty($getArgs['filename'])
            && is_readable($getArgs['filename'])) {
            $body = file_get_contents($getArgs['filename'], false, null, 0, 4096);
        }
        $decoded = json_decode((string) $body, true);
        $message = is_string($decoded['message'] ?? null) ? strtolower($decoded['message']) : '';
        return str_contains($message, 'api rate limit exceeded')
            || str_contains($message, 'secondary rate limit');
    }

    private function getGithubSettings(): array
    {
        return (new Config())->getConnectorSettings('github');
    }

    private function getApiHost(): string
    {
        return (new Config())->getGithubApiHost();
    }
}

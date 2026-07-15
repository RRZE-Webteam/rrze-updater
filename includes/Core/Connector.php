<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;

/**
 * Abstract base class for different repository connectors.
 *
 * This class defines a common interface for classes that connect to various repository hosting services.
 * It includes methods for retrieving information about repositories and making API requests.
 *
 * @abstract
 */
abstract class Connector
{
    /**
     * Unique identifier for the connector.
     *
     * @var string
     */
    public $id;

    /**
     * Display name for the connector.
     *
     * @var string
     */
    public $display;

    /**
     * Owner or organization associated with the repository.
     *
     * @var string
     */
    public $owner;

    /**
     * Personal access token for API authentication.
     *
     * @var string
     */
    public $token;

    /**
     * Warning message generated during operations.
     *
     * @var string
     */
    public $warning;

    /**
     * Error message generated during operations.
     *
     * @var string
     */
    public $error;

    /**
     * Additional error data from the last failed operation.
     *
     * @var array
     */
    public $errorData;

    /**
     * Constructor for the Connector class.
     *
     * Initializes warning and error properties as empty strings.
     */
    public function __construct()
    {
        $this->warning = '';
        $this->error = '';
        $this->errorData = [];
    }

    /**
     * Abstract method to get the type of the connector (e.g., 'github', 'gitlab').
     *
     * @abstract
     * @return string
     */
    abstract public function getType(): string;

    /**
     * Abstract method to get the URL of the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @return string
     */
    abstract public function getUrl(string $repository): mixed;

    /**
     * Abstract method to get the remote commit for a specific branch of the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @param string $branch     The branch name.
     * @return mixed
     */
    abstract public function getRemoteCommit(string $repository, string $branch): mixed;

    /**
     * Abstract method to check whether a branch exists in the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @param string $branch     The branch name.
     * @return bool True if the branch exists, false otherwise.
     */
    abstract public function remoteBranchExists(string $repository, string $branch): bool;

    /**
     * Abstract method to get the remote tag for the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @return mixed
     */
    abstract public function getRemoteTag(string $repository): mixed;

    /**
     * Abstract method to get a file from the repository at a specific ref.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @param string $ref        The branch, tag, or commit.
     * @param string $filePath   The file path inside the repository.
     * @return string|boolean The file contents or false on failure.
     */
    abstract public function getRemoteFile(string $repository, string $ref, string $filePath): string|bool;

    /**
     * Abstract method to get the URL for downloading a ZIP archive of a specific branch of the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @param string $branch     The branch name.
     * @return mixed
     */
    abstract public function downloadRepoZip(string $repository, string $branch): mixed;

    /**
     * Factory method to create a Connector object from an array of data.
     *
     * @static
     * @param array $array An associative array of data for creating the connector.
     * @return object|boolean A Connector object or false on failure.
     */
    public static function createFromArray(array $array): object|bool
    {
        $connectorObj = false;

        $connectorType = $array['type'];
        if (isset($connectorType) && is_string($connectorType)) {
            switch ($connectorType) {
                case 'github':
                    $connectorObj = GithubConnector::createFromArray($array);
                    break;
                case 'gitlab':
                    $connectorObj = GitlabConnector::createFromArray($array);
                    break;
            }
        }

        return $connectorObj;
    }

    /**
     * Protected method for making HTTP API requests.
     *
     * @param string $url     The URL for the API request.
     * @param array  $getArgs An array of GET request parameters.
     * @param array  $args    An array of additional request parameters.
     * @return mixed Returns the response data or false on failure.
     */
    protected function api(string $url, array $getArgs = [], array $args = []): mixed
    {
        $this->errorData = [];

        $httpErrors = [
            '401' => __('Unauthorized (use valid authentication)', 'rrze-updater'),
            '403' => __('Forbidden (use valid authentication)', 'rrze-updater'),
            '404' => __('Resource not found (check repository name, branch/tag/commit name)', 'rrze-updater'),
            '429' => __('Too many requests', 'rrze-updater')
        ];

        $defaultGetArgs = [
            'method' => 'GET',
            'timeout' => 10,
            'headers' => []
        ];

        $defaultArgs = [
            'jsonDecodeBody' => true,
            'logErrors' => true,
            'storeError' => true
        ];

        $getArgs = wp_parse_args($getArgs, $defaultGetArgs);
        $args = wp_parse_args($args, $defaultArgs);

        $response = wp_remote_get($url, $getArgs);
        $code = wp_remote_retrieve_response_code($response);

        $allowedCodes = [200];

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $errorData = $response->get_error_data();
            $this->errorData = [
                'error-data' => $this->formatLogValue($errorData),
                'url' => $this->redactLogUrl($url)
            ];
            if ($args['storeError']) {
                $this->error = $error;
            }
            if ($args['logErrors']) {
                $this->logError(
                    'Repository API request failed: {error}. Error data: {error-data}',
                    [
                        'error' => $error,
                        'error-data' => $this->errorData['error-data'],
                        'url' => $this->redactLogUrl($url)
                    ]
                );
            }
            return false;
        }
        if (!in_array($code, $allowedCodes, false)) {
            $error = isset($httpErrors[$code]) ? $httpErrors[$code] : 'HTTP error ' . $code;
            $this->errorData = array_merge(
                $this->getResponseErrorContext($response),
                [
                    'http-code' => $code,
                    'url' => $this->redactLogUrl($url)
                ]
            );
            if ($args['storeError']) {
                $this->error = $error;
            }
            if ($args['logErrors']) {
                $this->logError(
                    'Repository API request returned HTTP {http-code}: {error}. Response: {response-body}',
                    array_merge(
                        $this->errorData,
                        [
                            'error' => $error
                        ]
                    )
                );
            }
            return false;
        }

        if ($args['jsonDecodeBody']) {
            $response = json_decode(wp_remote_retrieve_body($response));
        }

        return $response;
    }

    /**
     * Logs an error through the RRZE logging action.
     *
     * @param string $message The log message.
     * @param array  $context Additional log context.
     */
    protected function logError(string $message, array $context = [])
    {
        $context = wp_parse_args(
            $context,
            [
                'plugin' => (new Config())->getLogPlugin(),
                'connector' => static::class,
                'service' => $this->display ?? '',
                'owner' => $this->owner ?? ''
            ]
        );

        do_action('rrze.log.error', $message, $context);
    }

    /**
     * Returns additional error context from the last failed request.
     *
     * @return array Error context.
     */
    public function getLastErrorContext(): array {
        return $this->errorData;
    }

    protected function getResponseErrorContext($response): array {
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }

        $decodedBody = json_decode((string) $body, true);
        $context = [
            'http-message' => wp_remote_retrieve_response_message($response),
            'response-body' => $this->formatLogValue($body),
            'response-headers' => $this->formatLogValue($headers)
        ];

        if (is_array($decodedBody)) {
            $context['response-json'] = $this->formatLogValue($decodedBody);
            foreach (['message', 'error', 'error_description', 'documentation_url'] as $key) {
                if (!empty($decodedBody[$key]) && is_scalar($decodedBody[$key])) {
                    $context['response-' . str_replace('_', '-', $key)] = (string) $decodedBody[$key];
                }
            }
        }

        return $context;
    }

    protected function formatLogValue($value): string {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        $encoded = wp_json_encode($value);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Redacts secrets from URLs before logging.
     *
     * @param string $url The URL to redact.
     * @return string The redacted URL.
     */
    protected function redactLogUrl(string $url): string
    {
        return preg_replace('/([?&]private_token=)[^&]+/', '$1[redacted]', $url);
    }
}

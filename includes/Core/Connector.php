<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

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
     * Constructor for the Connector class.
     *
     * Initializes warning and error properties as empty strings.
     */
    public function __construct()
    {
        $this->warning = '';
        $this->error = '';
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
     * Abstract method to get the remote tag for the repository.
     *
     * @abstract
     * @param string $repository The name of the repository.
     * @return mixed
     */
    abstract public function getRemoteTag(string $repository): mixed;

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
            'jsonDecodeBody' => true
        ];

        $getArgs = wp_parse_args($getArgs, $defaultGetArgs);
        $args = wp_parse_args($args, $defaultArgs);

        $response = wp_remote_get($url, $getArgs);
        $code = wp_remote_retrieve_response_code($response);

        $allowedCodes = [200];

        if (is_wp_error($response)) {
            $this->error = $response->get_error_message();
            return false;
        }
        if (!in_array($code, $allowedCodes, false)) {
            $this->error = isset($httpErrors[$code]) ? $httpErrors[$code] : 'HTTP error ' . $code;
            return false;
        }

        if ($args['jsonDecodeBody']) {
            $response = json_decode(wp_remote_retrieve_body($response));
        }

        return $response;
    }
}

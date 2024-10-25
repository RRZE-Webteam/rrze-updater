<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Utility;

/**
 * Class GitlabConnector
 *
 * Represents a connector for GitLab repositories.
 */
class GitlabConnector extends Connector
{
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
        $connector->display = 'RRZE Gitlab';
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
     * Get the URL of a GitLab repository based on the owner and repository name.
     *
     * @param string $repository The name of the repository.
     * @return string The URL of the GitLab repository.
     */
    public function getUrl(string $repository): string
    {
        // Construct and return the URL of the GitLab repository.
        return 'https://gitlab.rrze.fau.de/' . $this->owner . '/' . $repository;
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
            'https://gitlab.rrze.fau.de/api/v4/projects/%1$s/repository/commits?ref_name=%2$s',
            urlencode($this->owner . '/' . $repository),
            $branch
        );

        if ($this->token) {
            $url .= "&private_token=" . $this->token;
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
            'https://gitlab.rrze.fau.de/api/v4/projects/%s/repository/tags',
            urlencode($this->owner . '/' . $repository)
        );

        if ($this->token) {
            $url .= "?private_token=" . $this->token;
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
    public function getZipUrl(string $repository, string $branch = 'main'): string|bool
    {
        // Construct and return the ZIP archive download URL.
        // Return false on failure or rate limit reached.

        $url = sprintf(
            'https://gitlab.rrze.fau.de/api/v4/projects/%1$s/repository/archive.zip?sha=%2$s',
            urlencode($this->owner . '/' . $repository),
            $branch
        );

        if ($this->token) {
            $url .= "&private_token=" . $this->token;
        }

        $response = $this->api($url, [], ['jsonDecodeBody' => false]);
        if (!$response) {
            return false;
        }

        return $url;
    }
}

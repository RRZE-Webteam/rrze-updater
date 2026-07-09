<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;
use RRZE\Updater\Utility;

/**
 * Class Extension
 *
 * Represents an extension that can be updated from a remote source (e.g., GitHub, GitLab).
 */
class Extension
{
    /**
     * Unique identifier for the extension.
     *
     * @var string
     */
    public $id;

    /**
     * Identifier of the connector associated with this extension.
     *
     * @var string
     */
    public $connectorId;

    /**
     * Connector object associated with this extension.
     *
     * @var Connector|null
     */
    public $connector;

    /**
     * Name of the repository from which the extension is updated.
     *
     * @var string
     */
    public $repository;

    /**
     * Branch of the repository to track for updates.
     *
     * @var string
     */
    public $branch;

    /**
     * Folder where the extension is installed.
     *
     * @var string
     */
    public $installationFolder;

    /**
     * Local version of the extension.
     *
     * @var string
     */
    public $localVersion;

    /**
     * Remote version of the extension.
     *
     * @var string
     */
    public $remoteVersion;

    /**
     * Human-readable remote version read from readme.txt or extension headers.
     *
     * @var string
     */
    public $remoteReadableVersion;

    /**
     * Type of updates to check ('tags' or 'commits').
     *
     * @var string
     */
    public $updates;

    /**
     * Timestamp of the last update check.
     *
     * @var int
     */
    public $lastChecked;

    /**
     * Serialized last warning message.
     *
     * @var string
     */
    public $lastWarning;

    /**
     * Serialized last error message, which can be a \WP_Error object or plain text.
     *
     * @var string
     */
    public $lastError;

    /**
     * Update extension properties from an array of data.
     *
     * @param array $array An associative array of data to update the extension's properties.
     */
    public function updateFromArray(array $array)
    {
        // Populate properties from the provided array, if available.
        // If not available, set default values or leave them uninitialized.
        // Use unserialize for 'lastWarning' and 'lastError' properties.

        $this->id = !empty($array['id']) ? sanitize_text_field($array['id']) : Utility::uniqid();
        $this->connectorId = !empty($array['connectorId']) ? sanitize_text_field($array['connectorId']) : '';
        $this->repository = !empty($array['repository']) ? sanitize_text_field($array['repository']) : '';
        $this->branch = !empty($array['branch']) ? sanitize_text_field($array['branch']) : 'main';
        $this->installationFolder = !empty($array['installationFolder']) ? sanitize_text_field($array['installationFolder']) : '';
        $this->localVersion = $array['localVersion'] ?? '';
        $this->remoteVersion = $array['remoteVersion'] ?? '';
        $this->remoteReadableVersion = $array['remoteReadableVersion'] ?? '';
        $this->updates = $array['updates'] ?? '';
        $this->lastChecked = $array['lastChecked'] ?? 0;
        $this->lastWarning = isset($array['lastWarning']) ? unserialize($array['lastWarning']) : '';
        $this->lastError = isset($array['lastError']) ? unserialize($array['lastError']) : '';
    }

    /**
     * Convert the extension's properties to an associative array.
     *
     * @return array An array containing the extension's properties.
     */
    public function asArray(): array
    {
        // Return an associative array containing the extension's properties.
        // Serialize 'lastWarning' and 'lastError' properties.

        return [
            'id' => $this->id,
            'connectorId' => $this->connectorId,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'installationFolder' => $this->installationFolder,
            'localVersion' => $this->localVersion,
            'remoteVersion' => $this->remoteVersion,
            'remoteReadableVersion' => $this->remoteReadableVersion,
            'updates' => $this->updates,
            'lastChecked' => $this->lastChecked,
            'lastWarning' => serialize($this->lastWarning),
            'lastError' => serialize($this->lastError)
        ];
    }

    /**
     * Check for updates for the extension.
     *
     * This method queries the remote source (e.g., GitHub, GitLab) for updates based on the specified update type.
     */
    public function checkForUpdates()
    {
        // Check the type of updates ('tags' or 'commits').
        // Query the connector object to get the remote version based on the update type.
        // Update 'lastChecked', 'lastWarning', 'lastError', and 'remoteVersion' properties accordingly.

        if ($this->updates != 'tags' && $this->updates != 'commits') {
            return;
        }

        $this->lastChecked = time();

        $remoteVersion = false;
        switch ($this->updates) {
            case 'tags':
                $remoteVersion = $this->connector->getRemoteTag($this->repository);
                break;
            case 'commits':
                $remoteVersion = $this->connector->getRemoteCommit($this->repository, $this->branch);
                break;
        }

        $this->lastWarning = $this->connector->warning;

        $this->lastError = $this->connector->error;
        if ($this->lastError != "") {
            $remoteVersion = "";
        }

        $this->remoteVersion = $remoteVersion;
        $this->remoteReadableVersion = '';

        if ($remoteVersion) {
            $this->remoteReadableVersion = $this->getRemoteReadableVersion((string) $remoteVersion);
        }
    }

    public function getReadableRemoteVersion(): string
    {
        return (string) ($this->remoteReadableVersion ?: $this->remoteVersion);
    }

    public function getRemoteVersionLabel(): string
    {
        $readableVersion = trim((string) $this->remoteReadableVersion);
        if ($readableVersion !== '') {
            return $readableVersion;
        }

        if (!$this->remoteVersion) {
            return '';
        }

        if ($this->updates == 'commits') {
            return substr((string) $this->remoteVersion, 0, 6) . '&hellip; (commit)';
        }

        return (string) $this->remoteVersion;
    }

    public function getRemoteVersionDetailLabel(): string
    {
        $versionLabel = $this->getRemoteVersionLabel();
        if (!$this->remoteReadableVersion || !$this->remoteVersion) {
            return $versionLabel;
        }

        $gitRef = $this->updates == 'commits' ? substr((string) $this->remoteVersion, 0, 6) . '&hellip;' : (string) $this->remoteVersion;

        return sprintf('%1$s (%2$s)', $versionLabel, $gitRef);
    }

    protected function getRemoteReadableVersion(string $ref): string
    {
        if (!$this->connector) {
            return '';
        }

        foreach ($this->getVersionFileCandidates() as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (!is_string($content)) {
                continue;
            }

            $version = $this->extractReadableVersion($content, $filePath);
            if ($version !== '') {
                return $version;
            }
        }

        return '';
    }

    protected function getVersionFileCandidates(): array
    {
        $config = new Config();

        return array_merge(
            $config->getReadmeFiles(),
            [
                $config->getPackageFile()
            ]
        );
    }

    protected function extractReadableVersion(string $content, string $filePath = ''): string
    {
        $content = str_replace("\xc2\xa0", ' ', $content);

        if (basename($filePath) == 'package.json') {
            $package = json_decode($content, true);
            if (is_array($package) && !empty($package['version']) && is_string($package['version'])) {
                return sanitize_text_field(trim($package['version']));
            }
        }

        if (preg_match('/^[\s\/\*#@]*Version\s*:\s*(.+)$/mi', $content, $matches)) {
            return sanitize_text_field(trim($matches[1]));
        }

        if (preg_match('/^[\s\/\*#@]*Stable tag\s*:\s*(.+)$/mi', $content, $matches)) {
            $version = sanitize_text_field(trim($matches[1]));
            if ($version !== '' && strtolower($version) !== 'trunk') {
                return $version;
            }
        }

        return '';
    }
}

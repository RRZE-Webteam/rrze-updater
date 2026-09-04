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

    /**
     * Returns an access-token error for an unavailable GitLab branch.
     *
     * GitLab intentionally returns HTTP 404 for private projects that are
     * requested without sufficient permission.
     *
     * @param string $branch Branch that could not be verified.
     * @return \WP_Error|false Access-token error or false.
     */
    protected function getMissingGitlabTokenBranchError(string $branch): \WP_Error|false
    {
        if (!$this->connector instanceof GitlabConnector || !empty($this->connector->token)) {
            return false;
        }

        $errorContext = $this->connector->getLastErrorContext();
        $httpCode = absint($errorContext['http-code'] ?? 0);
        if (!in_array($httpCode, [401, 403, 404], true)) {
            return false;
        }

        return new \WP_Error(
            'rrze_updater_missing_gitlab_token',
            sprintf(
                /* translators: 1: Branch name, 2: Repository name, 3: HTTP response code */
                __('The GitLab repository branch "%1$s" could not be verified for "%2$s" because no access token is configured. GitLab returned HTTP %3$d; private repositories can be hidden this way. Add a valid token in the service settings and try again.', 'rrze-updater'),
                $branch,
                $this->repository,
                $httpCode
            )
        );
    }

    /**
     * Returns a detailed repository access error after branch lookup failed.
     *
     * @return \WP_Error|false Repository access error or false.
     */
    protected function getRemoteRepositoryLookupError(): \WP_Error|false
    {
        $errorContext = $this->connector->getLastErrorContext();
        $httpCode = absint($errorContext['http-code'] ?? 0);
        $service = (string) ($this->connector->display ?? '');

        if (in_array($httpCode, [401, 403], true)) {
            return new \WP_Error(
                'rrze_updater_repository_access_denied',
                sprintf(
                    /* translators: 1: Repository name, 2: Service name, 3: HTTP response code */
                    __('Access to repository "%1$s" was denied by %2$s (HTTP %3$d). Check whether the configured token is valid and authorized to access this repository.', 'rrze-updater'),
                    $this->repository,
                    $service,
                    $httpCode
                )
            );
        }

        if ($httpCode === 404) {
            return new \WP_Error(
                'rrze_updater_repository_not_found',
                sprintf(
                    /* translators: 1: Repository name, 2: Service name */
                    __('Repository "%1$s" could not be found on %2$s or is not accessible with the configured token. Check the user/group and repository name. If both are correct, grant the token access to this repository.', 'rrze-updater'),
                    $this->repository,
                    $service
                )
            );
        }

        return false;
    }

    /**
     * Returns a detailed error for a missing branch.
     *
     * @param string $branch    Requested branch name.
     * @param array  $branches  Available branch names.
     * @return \WP_Error Branch error.
     */
    protected function getMissingBranchError(string $branch, array $branches): \WP_Error
    {
        $branches = array_values(array_unique(array_filter($branches, 'is_string')));

        if (empty($branches)) {
            return new \WP_Error(
                'rrze_updater_missing_branch',
                sprintf(
                    /* translators: 1: Branch name, 2: Repository name */
                    __('The repository branch "%1$s" could not be found for "%2$s". The repository does not contain any branches.', 'rrze-updater'),
                    $branch,
                    $this->repository
                )
            );
        }

        $suggestion = $this->getBranchSuggestion($branch, $branches);

        return new \WP_Error(
            'rrze_updater_missing_branch',
            sprintf(
                /* translators: 1: Requested branch, 2: Repository, 3: Available branches, 4: Suggested branch */
                __('The repository branch "%1$s" could not be found for "%2$s". Available branches: %3$s. Suggested branch: %4$s.', 'rrze-updater'),
                $branch,
                $this->repository,
                implode(', ', $branches),
                $suggestion
            )
        );
    }

    private function getBranchSuggestion(string $branch, array $branches): string
    {
        if (in_array('main', $branches, true)) {
            return 'main';
        }

        $suggestion = $branches[0];
        $shortestDistance = PHP_INT_MAX;

        foreach ($branches as $candidate) {
            $distance = levenshtein(strtolower($branch), strtolower($candidate));
            if ($distance < $shortestDistance) {
                $suggestion = $candidate;
                $shortestDistance = $distance;
            }
        }

        return $suggestion;
    }

    protected function getRemoteReadableVersion(string $ref): string
    {
        if (!$this->connector) {
            return '';
        }

        $readableVersion = '';

        foreach ($this->getVersionFileCandidates() as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (!is_string($content)) {
                continue;
            }

            $version = $this->extractReadableVersion($content, $filePath);
            if ($version !== '') {
                $readableVersion = $this->getHigherReadableVersion($readableVersion, $version);
            }
        }

        return $readableVersion;
    }

    protected function getHigherReadableVersion(string $currentVersion, string $candidateVersion): string {
        if ($currentVersion === '') {
            return $candidateVersion;
        }

        $currentComparable = $this->getComparableVersion($currentVersion);
        $candidateComparable = $this->getComparableVersion($candidateVersion);

        if (version_compare($candidateComparable, $currentComparable, '>')) {
            return $candidateVersion;
        }

        return $currentVersion;
    }

    protected function getComparableVersion(string $version): string {
        return ltrim(trim($version), 'vV');
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

<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Settings;
use RRZE\Updater\Config;
use RRZE\Updater\FilesystemLock;
use RRZE\Updater\Upgrader\RepositoryInstaller;
use WP_Error;

/** Manage associations independently of the admin UI and WP-CLI. */
class RepositoryManager
{
    private array $warnings = [];

    /** Warnings from the most recent repository operation. */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function __construct(
        private Settings $settings,
        private RepositoryInstaller $installer = new RepositoryInstaller()
    ) {}

    public function listConnectors(): array
    {
        return array_map(static fn(Connector $connector) => [
            'id' => $connector->id, 'type' => $connector->getType(),
            'host' => wp_parse_url($connector->getUrl(''), PHP_URL_HOST),
            'owner' => $connector->owner,
            'authenticated' => !empty($connector->token) ? 'yes' : 'no',
        ], $this->settings->connectors);
    }

    public function listRepositories(string $type): array
    {
        return array_values(array_map(static fn(Extension $extension) => [
            'repository' => $extension->repository, 'connector' => $extension->connectorId,
            'folder' => $extension->installationFolder, 'branch' => $extension->branch,
            'updates' => $extension->updates, 'local_ref' => $extension->localVersion,
            'remote_ref' => $extension->remoteVersion,
        ], $this->extensions($type)));
    }

    /**
     * Associate existing files under the shared filesystem lock.
     *
     * @param string $type Plugin or theme.
     * @param string $repository Repository name.
     * @param array<string, mixed> $options Source and destination options.
     * @return string|WP_Error Success notice or failure.
     */
    public function register(string $type, string $repository, array $options): string|WP_Error
    {
        return FilesystemLock::withLock(fn() => $this->add($type, $repository, $options, false));
    }

    /**
     * Install and save an association without concurrent managed filesystem writes.
     *
     * @param string $type Plugin or theme.
     * @param string $repository Repository name.
     * @param array<string, mixed> $options Source and destination options.
     * @return string|WP_Error Success notice or failure.
     */
    public function install(string $type, string $repository, array $options): string|WP_Error
    {
        return FilesystemLock::withLock(fn() => $this->add($type, $repository, $options, true));
    }

    /**
     * Validate a plugin source without applying destination-conflict rules.
     *
     * Used by source changes and explicit repairs, which keep an existing folder.
     * Remote requests operate on a private connector so rejected candidates do
     * not modify the live registry's warnings, versions or source configuration.
     *
     * @param string $repository Repository name within the connector owner.
     * @param array<string, mixed> $options Connector, folder, branch and update policy.
     * @return Plugin|WP_Error Checked plugin definition or validation failure.
     */
    public function preparePluginDefinition(string $repository, array $options): Plugin|WP_Error
    {
        $this->warnings = [];
        $candidate = $this->definition('plugin', $repository, $options);
        if (is_wp_error($candidate)) {
            return $candidate;
        }
        $candidate->connector = clone $candidate->connector;
        // A saved branch must remain valid even when tags or releases currently
        // determine the target version; switching policies must not hide a typo.
        $validation = $candidate->validateRemotePluginBranch($candidate->branch);
        if (is_wp_error($validation)) {
            return $validation;
        }
        $validation = $this->checkRemote($candidate);
        return is_wp_error($validation) ? $validation : $candidate;
    }

    /**
     * Validate and save a new source while preserving installed files and identity.
     *
     * @param string $id Existing plugin association ID.
     * @param string $repository Proposed repository name.
     * @param array<string, mixed> $options Proposed connector, branch and update policy.
     * @return string|WP_Error Success notice or an error leaving the association intact.
     */
    public function changePluginSource(string $id, string $repository, array $options): string|WP_Error
    {
        $previous = $this->settings->getPluginById($id);
        if (!$previous) {
            $this->settings->reload();
            return $this->error('unknown_plugin', __('The managed plugin no longer exists. Reload the plugin overview.', 'rrze-updater'));
        }
        $before = RepositorySource::fingerprint($previous);
        $candidate = $this->preparePluginDefinition($repository, array_merge($options, ['folder' => $previous->installationFolder]));
        if (is_wp_error($candidate)) {
            $this->settings->reload();
            return $candidate;
        }
        $candidate->id = $previous->id;
        $candidate->localVersion = RepositorySource::installedFingerprint($candidate) === RepositorySource::installedFingerprint($previous)
            ? $previous->localVersion : '';

        $result = FilesystemLock::withLock(function () use ($id, $before, $candidate): string|WP_Error {
            // HTTP ran outside the filesystem lock. Re-read before committing so
            // an update or another source edit cannot invalidate the candidate.
            $current = new Settings();
            $current->reload();
            $persisted = $current->getPluginById($id);
            $checkedSource = RepositorySource::fingerprint($candidate);
            $effectiveCandidate = clone $candidate;
            $effectiveCandidate->connector = $current->getConnectorById($candidate->connectorId);
            if (!$persisted || RepositorySource::fingerprint($persisted) !== $before
                || RepositorySource::fingerprint($effectiveCandidate) !== $checkedSource) {
                return $this->error('source_changed', __('The repository source changed during validation. Reload and check the source again.', 'rrze-updater'));
            }
            foreach ($current->plugins as $other) {
                if ($other->id === $id) {
                    continue;
                }
                $sameRepository = $other->connectorId === $candidate->connectorId
                    && ($candidate->connector->getType() === 'github'
                        ? strcasecmp($other->repository, $candidate->repository) === 0
                        : $other->repository === $candidate->repository);
                if ($sameRepository || strcasecmp($other->installationFolder, $candidate->installationFolder) === 0) {
                    return $this->error('conflicting_repository', __('The repository or installation folder already belongs to another managed plugin.', 'rrze-updater'));
                }
            }
            // An update may have completed during validation without changing
            // the source. Preserve that installed ref when this is only a check.
            if (RepositorySource::installedFingerprint($candidate) === RepositorySource::installedFingerprint($persisted)) {
                $candidate->localVersion = $persisted->localVersion;
            }
            $extensions = $this->settings->plugins;
            foreach ($extensions as $key => $extension) {
                if ($extension->id === $id) {
                    $extensions[$key] = $candidate;
                }
            }
            $this->settings->markSourceValidated($candidate);
            $saved = $this->save('plugin', $extensions);
            if (is_wp_error($saved)) {
                return $saved;
            }
            return __('Repository source saved. Installed plugin files are unchanged.', 'rrze-updater');
        });
        if (is_wp_error($result)) {
            $this->settings->reload();
        }
        return $result;
    }

    /**
     * Build a read-only plan containing the selected ref and its release details.
     *
     * @param string $type Plugin or theme.
     * @param string $repository Repository name.
     * @param array $options Source and update policy options.
     * @return array|WP_Error Checked installation plan or validation error.
     */
    public function prepare(string $type, string $repository, array $options): array|WP_Error
    {
        $this->warnings = [];
        $extension = $this->definition($type, $repository, $options);
        if (is_wp_error($extension)) {
            return $extension;
        }
        $state = $this->localState($type, $extension);
        if (is_wp_error($state)) {
            return $state;
        }
        $checked = $this->checkRemote($extension);
        if (is_wp_error($checked)) {
            return $checked;
        }
        $parent = $extension instanceof Theme ? $extension->getRemoteParentTheme($extension->remoteVersion) : '';
        if (is_wp_error($parent)) {
            return $parent;
        }
        return [
            'action' => $state['installed'] ? ($state['existing'] ? 'skip' : 'register') : 'install',
            'ref' => $extension->remoteVersion, 'readable' => $extension->remoteReadableVersion,
            'release' => $extension->remoteRelease,
            'checked_at' => $extension->lastChecked, 'warning' => $extension->lastWarning,
            'parent' => $parent,
        ];
    }

    /**
     * Execute a server-side preflight plan without resolving a newer ref.
     *
     * @param string $type Plugin or theme.
     * @param string $repository Repository name.
     * @param array<string, mixed> $options Reviewed source and destination options.
     * @param array<string, mixed> $plan Server-side preflight result.
     * @return string|WP_Error Success notice or failure.
     */
    public function applyPrepared(string $type, string $repository, array $options, array $plan): string|WP_Error
    {
        // An interrupted install may have saved its association already. The
        // normal association checks recognize that case without reinstalling.
        return FilesystemLock::withLock(fn() => $this->add($type, $repository, $options, $plan['action'] === 'install', $plan));
    }

    /** Build a plan from server-side inspection of an immutable discovered commit. */
    public function prepareDiscovered(string $repository, array $options, array $inspection): array|WP_Error
    {
        $extension = $this->definition($inspection['type'], $repository, $options);
        if (is_wp_error($extension)) {
            return $extension;
        }
        $state = $this->localState($inspection['type'], $extension);
        if (is_wp_error($state)) {
            return $state;
        }
        return $inspection + [
            'action' => $state['installed'] ? ($state['existing'] ? 'skip' : 'register') : 'install',
            'checked_at' => time(),
        ];
    }

    public function unregister(string $type, string $repository, string $connectorId = ''): string|WP_Error
    {
        $this->warnings = [];
        if ($connectorId !== '' && !$this->settings->getConnectorById($connectorId)) {
            return $this->error('unknown_connector', 'Unknown connector ID. Run wp rrze-updater connector list.');
        }
        $matches = array_filter($this->extensions($type), static fn(Extension $extension) =>
            $extension->repository === $repository
            && ($connectorId === '' || $extension->connectorId === $connectorId));
        if (count($matches) > 1) {
            return $this->error('ambiguous_repository', 'More than one association matches. Specify --connector.');
        }
        if (!$matches) {
            return "$repository is already unregistered.";
        }
        $extensions = $this->extensions($type);
        unset($extensions[array_key_first($matches)]);
        $saved = $this->save($type, $extensions);
        return is_wp_error($saved) ? $saved : "$repository unregistered. Installed files were kept.";
    }

    private function add(string $type, string $repository, array $options, bool $install, ?array $prepared = null): string|WP_Error
    {
        $this->warnings = [];
        $extension = $this->definition($type, $repository, $options);
        if (is_wp_error($extension)) {
            return $extension;
        }
        $state = $this->localState($type, $extension);
        if (is_wp_error($state)) {
            return $state;
        }
        ['existing' => $existing, 'installed' => $installed] = $state;
        if (($prepared['action'] ?? '') === 'skip' && (!$existing || !$installed)) {
            return $this->error('local_state_changed', 'The installed or managed state changed since review. Run prerequisite checks again before registering it.');
        }
        if ($existing && $installed) {
            return "$repository is already registered with this configuration.";
        }
        if (!$install && !$installed) {
            return $this->error('not_installed', 'The plugin/theme is not installed in the specified folder. Use install first.');
        }
        if ($install && $this->installer->destinationExists($type, $extension->installationFolder)) {
            return $this->error('destination_exists', 'The installation folder already exists. Use register for an installed plugin/theme; install never overwrites it.');
        }
        if ($prepared === null) {
            $checked = $this->checkRemote($extension);
            if (is_wp_error($checked)) {
                return $checked;
            }
        } else {
            if (empty($prepared['ref']) || !is_string($prepared['ref'])) {
                return $this->error('invalid_plan', 'The reviewed repository ref is missing. Run preflight again.');
            }
            $extension->remoteVersion = $prepared['ref'];
            $extension->remoteReadableVersion = $prepared['readable'] ?? '';
            $extension->remoteRelease = is_array($prepared['release'] ?? null) ? $prepared['release'] : [];
            $extension->lastChecked = $prepared['checked_at'];
            $extension->lastWarning = $prepared['warning'] ?? '';
            if ($extension->lastWarning !== '') {
                $this->warnings[] = $extension->lastWarning;
            }
        }
        if ($install) {
            $result = $this->installer->install($type, $extension);
            if (is_wp_error($result)) {
                return $result;
            }
            $extension->localVersion = $extension->remoteVersion;
        }
        // Registration cannot prove which Git ref existing files contain.
        // An unknown localVersion must not be marked as up to date.
        $extensions = $this->extensions($type);
        if ($existing) {
            $extension->id = $existing->id;
            foreach ($extensions as $key => $candidate) {
                if ($candidate->id === $existing->id) {
                    $extensions[$key] = $extension;
                }
            }
        } else {
            $extensions[] = $extension;
        }
        $saved = $this->save($type, $extensions);
        if (is_wp_error($saved)) {
            return $saved;
        }
        return $install
            ? "$repository installed and registered ({$extension->updates}: {$extension->remoteVersion}). Activation is unchanged."
            : "$repository registered. The installed Git ref is unknown; the next update can install the selected remote ref.";
    }

    /**
     * Check installation presence and conflicts with an existing source policy.
     *
     * @param string $type Plugin or theme.
     * @param Extension $extension Proposed association.
     * @return array|WP_Error Existing association and installation state, or a conflict.
     */
    private function localState(string $type, Extension $extension): array|WP_Error
    {
        $existing = null;
        foreach ($this->extensions($type) as $candidate) {
            $sameRepository = $candidate->connectorId === $extension->connectorId
                && $candidate->repository === $extension->repository;
            $sameFolder = $candidate->installationFolder === $extension->installationFolder;
            if (!$sameRepository && !$sameFolder) {
                continue;
            }
            if (!$sameRepository || !$sameFolder
                || $candidate->branch !== $extension->branch || $candidate->updates !== $extension->updates
                || $candidate->ignorePrereleases !== $extension->ignorePrereleases) {
                return $this->error('conflicting_repository', 'The repository or folder already has a different association. Unregister it before changing the configuration.');
            }
            $existing = $candidate;
        }
        $installed = $this->installer->isInstalled($type, $extension->installationFolder);
        if (!$installed && $this->installer->destinationExists($type, $extension->installationFolder)) {
            return $this->error('destination_exists', 'The destination contains unrecognized files. Inspect it before proceeding.');
        }
        return ['existing' => $existing, 'installed' => $installed];
    }

    /**
     * Build an extension only from validated source and destination values.
     *
     * @param string $type Plugin or theme.
     * @param string $repository Repository name.
     * @param array<string, mixed> $options Connector, folder, branch and policy.
     * @return Extension|WP_Error Parsed extension or input error.
     */
    private function definition(string $type, string $repository, array $options): Extension|WP_Error
    {
        $folder = $options['folder'] ?? $repository;
        foreach ([$repository, $folder] as $name) {
            if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $name) || str_contains($name, '..')) {
                return $this->error('invalid_name', 'Repository and folder must be single names without path separators or "..".');
            }
        }
        if (!is_string($options['connector'] ?? null)) {
            return $this->error('unknown_connector', 'Unknown connector ID. Run wp rrze-updater connector list.');
        }
        $connector = $this->settings->getConnectorById($options['connector']);
        if (!$connector) {
            return $this->error('unknown_connector', 'Unknown connector ID. Run wp rrze-updater connector list.');
        }
        $onlyRelease = !empty($options['only-release']);
        if ($onlyRelease && isset($options['updates']) && $options['updates'] !== 'releases') {
            return $this->error('conflicting_updates', '--only-release conflicts with --updates=tags or --updates=commits.');
        }
        $updates = $onlyRelease ? 'releases' : ($options['updates'] ?? 'tags');
        if (!in_array($updates, ['tags', 'commits', 'releases'], true)) {
            return $this->error('invalid_updates', 'Update policy must be tags, commits or releases.');
        }
        $branch = $options['branch'] ?? 'main';
        if (!is_string($branch) || $branch === '' || preg_match('/[\x00-\x20\x7f]/', $branch)) {
            return $this->error('invalid_branch', 'Branch must be a nonempty ref without whitespace or control characters.');
        }
        $class = match ($type) {
            'plugin' => Plugin::class, 'theme' => Theme::class,
            default => throw new \InvalidArgumentException('Unknown extension type.'),
        };
        $extension = $class::createFromArray([
            'connectorId' => $connector->id, 'repository' => $repository,
            'installationFolder' => $folder, 'branch' => $branch, 'updates' => $updates,
            'ignorePrereleases' => !empty($options['ignore-prereleases']),
        ]);
        $extension->connector = $connector;
        return $extension;
    }

    private function checkRemote(Extension $extension): true|WP_Error
    {
        if ($extension->updates === 'commits') {
            $validation = $extension instanceof Plugin
                ? $extension->validateRemotePluginBranch($extension->branch)
                : $extension->validateRemoteThemeBranch($extension->branch);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
        $extension->checkForUpdates();
        if (!$extension->remoteVersion || $extension->lastError) {
            return $this->error('remote_ref_unavailable', sprintf(
                'No usable %s ref found for %s. Check repository access and the configured branch or update policy. %s',
                $extension->updates, $extension->repository, (string) $extension->lastError));
        }
        $validation = $extension instanceof Plugin
            ? $extension->validateRemotePluginRepository($extension->remoteVersion)
            : $extension->validateRemoteThemeRepository($extension->remoteVersion);
        if (is_wp_error($validation) && $extension instanceof Plugin
            && Plugin::isRepositoryFileWarning($validation)) {
            $extension->lastWarning = $validation->get_error_message();
            $this->warnings[] = $extension->lastWarning;
            return true;
        }
        return is_wp_error($validation) ? $validation : true;
    }

    private function extensions(string $type): array
    {
        return match ($type) {
            'plugin' => $this->settings->plugins, 'theme' => $this->settings->themes,
            default => throw new \InvalidArgumentException('Unknown extension type.'),
        };
    }

    private function save(string $type, array $extensions): true|WP_Error
    {
        $property = $type === 'plugin' ? 'plugins' : 'themes';
        $previous = $this->settings->$property;
        $this->settings->$property = array_values($extensions);
        $saved = $this->settings->save();
        // WordPress also returns false for an unchanged option.
        $optionName = (new Config())->getOptionName();
        $stored = is_multisite() ? get_site_option($optionName) : get_option($optionName);
        if (!$saved && $stored !== $this->settings->asArray()) {
            $this->settings->$property = $previous;
            return $this->error('save_failed', 'Could not save repository settings. If files were installed, use register after fixing database access.');
        }
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        return true;
    }

    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error('rrze_updater_' . $code, $message);
    }
}

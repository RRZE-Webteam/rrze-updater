<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Settings;
use RRZE\Updater\Config;
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

    public function register(string $type, string $repository, array $options): string|WP_Error
    {
        return $this->add($type, $repository, $options, false);
    }

    public function install(string $type, string $repository, array $options): string|WP_Error
    {
        return $this->add($type, $repository, $options, true);
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

    private function add(string $type, string $repository, array $options, bool $install): string|WP_Error
    {
        $this->warnings = [];
        $extension = $this->definition($type, $repository, $options);
        if (is_wp_error($extension)) {
            return $extension;
        }
        $existing = null;
        foreach ($this->extensions($type) as $candidate) {
            $sameRepository = $candidate->connectorId === $extension->connectorId
                && $candidate->repository === $extension->repository;
            $sameFolder = $candidate->installationFolder === $extension->installationFolder;
            if (!$sameRepository && !$sameFolder) {
                continue;
            }
            if (!$sameRepository || !$sameFolder
                || $candidate->branch !== $extension->branch || $candidate->updates !== $extension->updates) {
                return $this->error('conflicting_repository', 'The repository or folder already has a different association. Unregister it before changing the configuration.');
            }
            $existing = $candidate;
        }
        $installed = $this->installer->isInstalled($type, $extension->installationFolder);
        if ($existing && $installed) {
            return "$repository is already registered with this configuration.";
        }
        if (!$install && !$installed) {
            return $this->error('not_installed', 'The plugin/theme is not installed in the specified folder. Use install first.');
        }
        if ($install && $this->installer->destinationExists($type, $extension->installationFolder)) {
            return $this->error('destination_exists', 'The installation folder already exists. Use register for an installed plugin/theme; install never overwrites it.');
        }
        $checked = $this->checkRemote($extension);
        if (is_wp_error($checked)) {
            return $checked;
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

    private function definition(string $type, string $repository, array $options): Extension|WP_Error
    {
        $folder = $options['folder'] ?? $repository;
        foreach ([$repository, $folder] as $name) {
            if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $name) || str_contains($name, '..')) {
                return $this->error('invalid_name', 'Repository and folder must be single names without path separators or "..".');
            }
        }
        $connector = $this->settings->getConnectorById($options['connector'] ?? '');
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
                'No usable %s ref found for %s. Check access and whether a tag/release exists. %s',
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

<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use RRZE\Updater\Settings;
use RRZE\Updater\Controller;

use RRZE\Updater\Core\GithubConnector;
use RRZE\Updater\Core\GitlabConnector;
use RRZE\Updater\Core\Plugin;

use RRZE\Updater\Upgrader\PluginUpgraderSkin;
use RRZE\Updater\Upgrader\ThemeUpgraderSkin;

use WP_Error;

/**
 * The Main class for the RRZE Updater plugin.
 *
 * This class serves as the entry point for the RRZE Updater plugin. It initializes the necessary components and
 * sets up the plugin for use within WordPress.
 */
class Main
{
    /**
     * The controller instance responsible for managing plugin settings and operations.
     *
     * @var Controller
     */
    public $controller;

    /**
     * The settings instance for storing and managing plugin settings.
     *
     * @var Settings
     */
    public $settings;

    /**
     * The plugin configuration.
     *
     * @var Config
     */
    protected $config;

    /**
     * The current extension type ('plugin' or 'theme') being operated on.
     *
     * @var string
     */
    public $currentExtension;

    public function __construct()
    {
        $this->config = new Config();
        $this->settings = new Settings();
        $this->controller = new Controller($this->settings);
        new Cron($this->settings, $this->controller);
    }

    /**
     * Initializes and configures various hooks and filters when the RRZE Updater plugin is loaded.
     *
     * This method is responsible for setting up various WordPress hooks, filters, and actions needed for the
     * functionality of the RRZE Updater plugin. It initializes settings, admin menus, actions for handling
     * plugin and theme updates, and various filters to modify plugin and theme data.
     */
    public function loaded()
    {
        $this->initSettings();
        (new BundleAdmin())->register();

        (new AdminIntegration($this->settings, $this->controller, $this->config))->register();

        (new UpdateProvider($this->settings))->register();

        // Download authenticated repository packages only when the upgrader needs them.
        add_filter('upgrader_pre_download', [$this, 'upgraderPreDownloadFilter'], 10, 4);

        // Set up a filter for modifying the source selection during plugin/theme updates.
        add_filter('upgrader_source_selection', [$this, 'upgraderSourceSelectionFilter'], 10, 4);

        // Set up a filter for post-installation actions during plugin/theme updates.
        add_filter('upgrader_post_install', [$this, 'upgraderPostInstallFilter'], 10, 3);
        add_action('automatic_updates_complete', [$this, 'automaticUpdatesComplete']);

        if (defined('WP_CLI') && WP_CLI) {
            CLI::registerCommands($this->settings);
        }
    }

    /**
     * Filter for selecting the source location during the upgrade process.
     *
     * This method is a filter used to determine the source location for an upgrade process.
     * It is responsible for handling different types of upgrades, such as plugins, themes, and bulk actions.
     * The method computes the new source location based on the provided parameters and moves the files accordingly.
     * Additionally, it sets the current extension type ('plugin' or 'theme') based on the upgrade context.
     *
     * @param string|WP_Error $source The source location before the upgrade.
     * @param string $remoteSource The remote source location.
     * @param WP_Upgrader $upgrader The upgrader instance performing the upgrade.
     * @param array $hookExtra Additional upgrade data, such as plugin or theme information.
     * @return string|WP_Error The new source location after the upgrade or a WP_Error object on failure.
     */
    /** Authenticated package identity, scoped to each upgrader instance. */
    private ?\WeakMap $downloadedPackages = null;
    private ?array $currentPackage = null;
    private array $automaticPackages = [];

    public function upgraderSourceSelectionFilter($source, $remoteSource, $upgrader, $hookExtra)
    {
        global $wp_filesystem;
        $this->currentExtension = '';
        $this->currentPackage = null;
        if (is_wp_error($source)) {
            return $source;
        }
        $extension = $this->getManagedExtensionForUpgrade($upgrader, $hookExtra);
        if (!$extension) {
            return $source;
        }
        $folder = $extension->installationFolder;
        if (!is_string($folder) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $folder) || str_contains($folder, '..')) {
            return new WP_Error('rrze_updater_invalid_destination', __('The managed installation folder is invalid.', 'rrze-updater'));
        }
        $newSource = trailingslashit($remoteSource) . trailingslashit($folder);
        if (untrailingslashit($newSource) !== untrailingslashit($source)
            && !$wp_filesystem->move($source, $newSource, false)) {
            return new WP_Error('rrze_updater_source_move_failed', __('Could not prepare the managed installation folder.', 'rrze-updater'));
        }
        $this->currentExtension = $extension instanceof Plugin ? 'plugin' : 'theme';
        $package = $this->downloadedPackages[$upgrader] ?? null;
        if ($package && $package['extension'] === $extension) {
            // Core's manual AJAX skin inherits from the automatic skin, but its
            // requests never fire automatic_updates_complete.
            $automatic = $upgrader->skin instanceof \Automatic_Upgrader_Skin
                && !($upgrader->skin instanceof \WP_Ajax_Upgrader_Skin);
            $this->currentPackage = $package + ['upgrader' => $upgrader, 'automatic' => $automatic];
        }
        if ($this->downloadedPackages !== null) {
            unset($this->downloadedPackages[$upgrader]);
        }
        return $newSource;
    }

    /** Resolve exact core identifiers; display names are not unique identities. */
    private function getManagedExtensionForUpgrade($upgrader, array $hookExtra, bool $forDownload = false): \RRZE\Updater\Core\Extension|false
    {
        if (isset($hookExtra['plugin']) || isset($hookExtra['theme'])) {
            // Never fall back to a skin's old extension for an explicit target.
            if (isset($hookExtra['plugin'], $hookExtra['theme'])) {
                return false;
            }
            if (isset($hookExtra['plugin'])) {
                if (!is_string($hookExtra['plugin']) || !str_contains($hookExtra['plugin'], '/')) {
                    return false;
                }
                $folder = dirname($hookExtra['plugin']);
                $extensions = $this->settings->plugins;
            } else {
                $folder = $hookExtra['theme'];
                $extensions = $this->settings->themes;
            }
            $matches = array_values(array_filter($extensions, static fn($extension) => $extension->installationFolder === $folder));
            return count($matches) === 1 ? $matches[0] : false;
        }
        // A legacy skin only identifies a candidate for URL verification. Source
        // selection requires the verified download, consumed once per package.
        // Core reuses child-theme skins for unrelated parent downloads.
        if (!$forDownload) {
            return $this->downloadedPackages[$upgrader]['extension'] ?? false;
        }
        if (($upgrader->skin instanceof PluginUpgraderSkin || $upgrader->skin instanceof ThemeUpgraderSkin)
            && $upgrader->skin->extension instanceof \RRZE\Updater\Core\Extension) {
            return $upgrader->skin->extension;
        }
        return false;
    }

    public function upgraderPreDownloadFilter($reply, $package, $upgrader, $hookExtra)
    {
        $this->downloadedPackages ??= new \WeakMap();
        unset($this->downloadedPackages[$upgrader]);
        if (false !== $reply || !is_string($package)) {
            return $reply;
        }
        $extension = $this->getRepositoryExtensionForUpgrade($upgrader, $hookExtra);
        if (!$extension) {
            return false;
        }
        if ($extension->connector instanceof GitlabConnector) {
            $package = remove_query_arg('private_token', $package);
        }
        // Both providers put the encoded ref last in their canonical archive URL.
        // Validate the entire reconstructed URL before sending any credentials.
        $prefix = $extension->connector->downloadRepoZip($extension->repository, '');
        if (!is_string($prefix) || $prefix === '' || !str_starts_with($package, $prefix)) {
            return $this->rejectUnmanagedPackage($hookExtra);
        }
        $ref = rawurldecode(substr($package, strlen($prefix)));
        if ($ref === '' || preg_match('/[\x00-\x20\x7f]/', $ref)
            || $package !== $extension->connector->downloadRepoZip($extension->repository, $ref)) {
            return $this->rejectUnmanagedPackage($hookExtra);
        }
        if ($extension instanceof Plugin) {
            $validation = $this->validatePluginRepositoryForUpgrade($extension, $ref);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
        $download = $extension->connector->downloadRepoZipToTempFile($extension->repository, $ref);
        if (!$download) {
            do_action('rrze.log.error', 'Download failed for {repository} at ref {ref}.', array_merge([
                'plugin' => $this->config->getLogPlugin(), 'repository' => $extension->repository, 'ref' => $ref,
                'service' => $extension->connector->display ?? '',
                'error' => $extension->connector->error ?: __('Download failed.', 'rrze-updater'),
            ], $this->getConnectorErrorContext($extension)));
            return new WP_Error('download_failed', $extension->connector->error ?: __('Download failed.', 'rrze-updater'));
        }
        $this->downloadedPackages[$upgrader] = ['extension' => $extension, 'ref' => $ref,
            'type' => $extension instanceof Plugin ? 'plugin' : 'theme'];
        return $download;
    }

    private function rejectUnmanagedPackage(array $hookExtra): false|WP_Error
    {
        // A skin alone may be reused for an unrelated parent-theme install.
        if (!isset($hookExtra['plugin']) && !isset($hookExtra['theme'])) {
            return false;
        }
        return new WP_Error('rrze_updater_untrusted_package', __('The package does not match the managed repository. Refresh the update information before retrying.', 'rrze-updater'));
    }

    private function validatePluginRepositoryForUpgrade(Plugin $extension, string $ref): bool|WP_Error
    {
        $validation = $extension->getRemotePluginRepositoryWarning($ref);
        if (!is_wp_error($validation)) {
            return true;
        }

        if (!Plugin::isRepositoryFileWarning($validation)) {
            do_action(
                'rrze.log.error',
                'Plugin update failed for {repository}: {error}',
                [
                    'plugin' => $this->config->getLogPlugin(),
                    'repository' => $extension->repository,
                    'installation-folder' => $extension->installationFolder,
                    'ref' => $ref,
                    'service' => $extension->connector->display ?? '',
                    'error' => $validation->get_error_message()
                ]
            );

            return $validation;
        }

        $extension->lastWarning = $validation->get_error_message();
        $this->settings->save();

        do_action(
            'rrze.log.warning',
            'Plugin update warning for {repository}: {warning}',
            [
                'plugin' => $this->config->getLogPlugin(),
                'repository' => $extension->repository,
                'installation-folder' => $extension->installationFolder,
                'ref' => $ref,
                'service' => $extension->connector->display ?? '',
                'warning' => $validation->get_error_message()
            ]
        );

        return true;
    }

    private function getRepositoryExtensionForUpgrade($upgrader, array $hookExtra)
    {
        // Core bulk updates provide plugin/theme identifiers without a type key.
        $extension = $this->getManagedExtensionForUpgrade($upgrader, $hookExtra, true);
        return $extension && ($extension->connector instanceof GithubConnector || $extension->connector instanceof GitlabConnector)
            ? $extension : false;
    }

    /** Stage the verified ref; later post-install filters may still fail. */
    public function upgraderPostInstallFilter($response, $hookExtra, $result)
    {
        $package = $this->currentPackage;
        $this->currentPackage = null;
        if ($response !== true || !$package || !is_array($result)
            || ($result['destination_name'] ?? '') !== $package['extension']->installationFolder) {
            return $response;
        }
        // Register after package preparation, so this runs after existing result
        // filters. Nested parent-theme packages must not consume the child's ref.
        $finish = function ($outcome, $extra) use ($package, $hookExtra, &$finish) {
            foreach (['plugin', 'theme'] as $key) {
                if (($hookExtra[$key] ?? null) !== ($extra[$key] ?? null)) {
                    return $outcome;
                }
            }
            if (is_array($outcome) && ($outcome['destination_name'] ?? '') !== $package['extension']->installationFolder) {
                return $outcome;
            }
            remove_filter('upgrader_install_package_result', $finish, PHP_INT_MAX);
            if (!is_array($outcome)) {
                if (is_wp_error($outcome)) {
                    $package['upgrader']->result = $outcome;
                }
                return $outcome;
            }
            $target = $extra[$package['type']] ?? '';
            if ($package['automatic'] && $target !== '') {
                // Core's fatal-error check and rollback happen after upgrade().
                // The batch completion event contains the actual final outcome.
                $this->automaticPackages[$package['type'] . ':' . $target] = $package;
                return $outcome;
            }
            $saved = $this->saveInstalledPackage($package);
            if (is_wp_error($saved)) {
                // Core's callers also inspect this property after run() returns.
                $package['upgrader']->result = $saved;
                return $saved;
            }
            return $outcome;
        };
        add_filter('upgrader_install_package_result', $finish, PHP_INT_MAX, 2);
        return $response;
    }

    public function automaticUpdatesComplete(array $results): void
    {
        $packages = $this->automaticPackages;
        $this->automaticPackages = [];
        foreach (['plugin', 'theme'] as $type) {
            foreach ($results[$type] ?? [] as $update) {
                $target = $update->item->{$type} ?? '';
                $package = $packages[$type . ':' . $target] ?? null;
                if (!$package) {
                    continue;
                }
                $outcome = $update->result ?? false;
                if (is_wp_error($outcome) && in_array('plugin_update_fatal_error_rollback_failed', $outcome->get_error_codes(), true)) {
                    // Neither the new nor the previous files can be assumed intact.
                    $package['ref'] = '';
                } elseif ($outcome !== true) {
                    // Successful rollback retains the previous installed ref.
                    continue;
                }
                $saved = $this->saveInstalledPackage($package);
                if (is_wp_error($saved)) {
                    $update->result = $saved;
                    do_action('rrze.log.error', 'Could not record the final repository update: {error}', [
                        'plugin' => $this->config->getLogPlugin(), 'error' => $saved->get_error_message(),
                    ]);
                }
            }
        }
    }

    private function saveInstalledPackage(array $package): true|WP_Error
    {
        $extension = $package['extension'];
        $previous = $extension->localVersion;
        $extension->localVersion = $package['ref'];
        if (!$this->settings->save()) {
            $extension->localVersion = $previous;
            return new WP_Error('rrze_updater_save_failed', __('Could not save the installed repository version. Reconcile the installation before retrying.', 'rrze-updater'));
        }
        if ($package['ref'] !== '') {
            $this->logSuccessfulUpdate($package['type'], $extension);
        }
        return true;
    }

    /**
     * Logs a successful repository update.
     *
     * @param string $type The extension type.
     * @param object $extension The updated extension.
     */
    private function logSuccessfulUpdate(string $type, object $extension)
    {
        $admin = $this->getCurrentAdminContext();
        $context = [
            'plugin' => $this->config->getLogPlugin(),
            'extension-type' => $type,
            'repository' => $extension->repository ?? '',
            'installation-folder' => $extension->installationFolder ?? '',
            'version' => $extension->localVersion === $extension->remoteVersion ? $extension->getReadableRemoteVersion() : $extension->localVersion,
            'git-version' => $extension->localVersion ?? '',
            'branch' => $extension->branch ?? '',
            'service' => isset($extension->connector) ? $extension->connector->display : '',
            'admin-id' => $admin['id'],
            'admin-login' => $admin['login'],
            'admin-email' => $admin['email']
        ];

        Logger::info(
            $this->settings,
            'Updated {extension-type} {repository} to {version}. Git ref: {git-version}. Admin: {admin-login}',
            $context
        );
    }

    /**
     * Returns context for the current admin user.
     *
     * @return array Admin context.
     */
    private function getCurrentAdminContext(): array
    {
        if (!function_exists('wp_get_current_user')) {
            return [
                'id' => 0,
                'login' => 'unknown',
                'email' => ''
            ];
        }

        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) {
            return [
                'id' => 0,
                'login' => 'system',
                'email' => ''
            ];
        }

        return [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email
        ];
    }

    private function getConnectorErrorContext($extension): array {
        if (
            empty($extension->connector)
            || !method_exists($extension->connector, 'getLastErrorContext')
        ) {
            return [];
        }

        return $extension->connector->getLastErrorContext();
    }

    /**
     * Initialize Settings
     *
     * This method is responsible for initializing the settings object with default values
     * for connectors, plugins, and other configuration options, in case the settings are empty.
     */
    protected function initSettings()
    {
        // Check if the connectors array in settings is not empty.
        if (!empty($this->settings->connectors)) {
            return; // If not empty, no need to initialize settings.
        }

        $defaultRepository = $this->config->getDefaultRepository();

        // Add a GitlabConnector with default configuration.
        $connector = GitlabConnector::createFromArray(
            [
                'owner' => $defaultRepository['owner'] ?? 'rrze-webteam',
                'type' => $defaultRepository['connector_type'] ?? 'gitlab',
                'token' => '' // Replace with a valid GitLab token if needed.
            ]
        );

        // Add the GitlabConnector to the connectors array in settings.
        $this->settings->connectors[] = $connector;

        // Add a Plugin with default configuration.
        $plugin = Plugin::createFromArray(
            [
                'connectorId' => $connector->id, // Use the ID of the created connector.
                'repository' => $defaultRepository['repository'] ?? 'rrze-updater', // Replace with the desired repository name.
                'branch' => $defaultRepository['branch'] ?? 'master', // Specify the branch to track.
                'installationFolder' => dirname(plugin()->getBaseName()), // Default installation folder.
                'updates' => $defaultRepository['updates'] ?? 'commits' // Specify how updates are tracked (e.g., 'commits', 'tags').
            ]
        );

        // Add the created plugin to the plugins array in settings.
        $this->settings->plugins[] = $plugin;

        // Save the updated settings with the initialized values.
        $this->settings->save();
    }
}

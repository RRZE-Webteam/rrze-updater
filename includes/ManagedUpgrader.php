<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

use RRZE\Updater\Core\GithubConnector;
use RRZE\Updater\Core\GitlabConnector;
use RRZE\Updater\Core\Plugin;
use RRZE\Updater\Upgrader\PluginUpgraderSkin;
use RRZE\Updater\Upgrader\ThemeUpgraderSkin;
use WP_Error;

/**
 * Downloads managed plugins and themes and saves the version that was installed.
 *
 * WordPress calls this class at several update steps through hooks:
 * 1. Check the download URL, then download the ZIP using the connector's token.
 * 2. Prepare the folder where WordPress will install the files.
 * 3. Save the installed version only after WordPress reports success.
 *
 * A "ref" is the Git commit, tag or branch requested in the download URL.
 * Background updates may restore the old files if a check fails (a rollback),
 * so their version is saved only after those checks finish.
 */
class ManagedUpgrader
{
    /**
     * Downloads that passed our checks, stored separately for each WordPress upgrader.
     *
     * Each entry records the plugin or theme, its Git ref, and its type.
     * Starting another download clears that upgrader's old entry. Preparing the
     * install folder also removes the entry after passing it to $currentPackage.
     * WeakMap removes entries automatically when their upgrader object is destroyed.
     *
     * @var \WeakMap<\WP_Upgrader, array{extension: Core\Extension, ref: string, type: 'plugin'|'theme'}>|null
     */
    private ?\WeakMap $downloadedPackages = null;

    /**
     * The checked download whose files are currently being installed, or null.
     *
     * Also remembers the upgrader object and whether this is a background update.
     * Cleared when another folder is prepared or the post-install hook reads it.
     * A user clicking "Update" through AJAX counts as a manual update.
     *
     * @var array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool}|null
     */
    private ?array $currentPackage = null;

    /**
     * Background updates waiting for WordPress's final success or failure report.
     *
     * Keys identify the installation, for example "plugin:example/example.php"
     * or "theme:example". automaticUpdatesComplete() reads and clears this list
     * so a later report cannot save the same updates again.
     *
     * @var array<string, array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool}>
     */
    private array $automaticPackages = [];

    /**
     * Stores the settings and configuration used by this class.
     *
     * Call register() separately to connect this object to WordPress.
     *
     * @param Settings $settings The same settings object used by the other plugin components.
     * @param Config   $config   Plugin configuration, including its name in log messages.
     */
    public function __construct(
        /**
         * Managed plugins, themes and connectors, including their saved versions.
         *
         * @var Settings
         */
        private readonly Settings $settings,
        /**
         * Configuration used to identify this plugin in log messages.
         *
         * @var Config
         */
        private readonly Config $config
    ) {}

    /**
     * Tells WordPress which methods to call during an update.
     *
     * These hooks use priority 10. The final result callback is added later,
     * when upgraderPostInstallFilter() has a package to finish.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('upgrader_pre_download', [$this, 'upgraderPreDownloadFilter'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'upgraderSourceSelectionFilter'], 10, 4);
        add_filter('upgrader_post_install', [$this, 'upgraderPostInstallFilter'], 10, 3);
        add_action('automatic_updates_complete', [$this, 'automaticUpdatesComplete']);
    }

    /**
     * Prepares the downloaded files under the folder name saved in Updater settings.
     *
     * Called by the upgrader_source_selection hook after WordPress unzips a package.
     * Leaves other plugins and themes alone. For a managed update, checks the folder
     * name and moves the files without replacing a folder that already exists.
     * Remembers the checked download so its Git ref can be saved after installation.
     *
     * @global \WP_Filesystem_Base $wp_filesystem WordPress's object for reading and moving files.
     *
     * @param string|WP_Error     $source       Folder containing the unzipped files, or an earlier error.
     * @param string              $remoteSource Working folder containing the unzipped source folder.
     * @param \WP_Upgrader        $upgrader     WordPress object running this update.
     * @param array<string, mixed> $hookExtra    Update details; plugin or theme identifies the installation.
     *
     * @return string|WP_Error Folder to install from, or an error if checking or moving it failed.
     */
    public function upgraderSourceSelectionFilter($source, $remoteSource, $upgrader, $hookExtra)
    {
        global $wp_filesystem;
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
        $package = $this->downloadedPackages[$upgrader] ?? null;
        if ($package && $package['extension'] === $extension) {
            // AJAX updates reuse the background-update skin class, but are manual
            // updates. They do not trigger automatic_updates_complete.
            $automatic = $upgrader->skin instanceof \Automatic_Upgrader_Skin
                && !($upgrader->skin instanceof \WP_Ajax_Upgrader_Skin);
            $this->currentPackage = $package + ['upgrader' => $upgrader, 'automatic' => $automatic];
        }
        if ($this->downloadedPackages !== null) {
            unset($this->downloadedPackages[$upgrader]);
        }
        return $newSource;
    }

    /**
     * Finds the managed plugin or theme for this update.
     *
     * Uses a plugin path such as "example/example.php" or a theme folder name.
     * There must be exactly one match in settings; display names are not unique.
     * If WordPress supplies neither path, older Updater code may identify the
     * extension through its custom skin (the object that shows update progress).
     * That is enough to check a download URL, but choosing an install folder
     * requires a download that has already passed our checks.
     *
     * @param \WP_Upgrader        $upgrader    WordPress object running the update.
     * @param array<string, mixed> $hookExtra   Update details, optionally including plugin or theme.
     * @param bool                $forDownload True when checking a download; allows the older skin lookup.
     *
     * @return Core\Extension|false Matching plugin/theme, or false if no single match can be found.
     */
    private function getManagedExtensionForUpgrade($upgrader, array $hookExtra, bool $forDownload = false): \RRZE\Updater\Core\Extension|false
    {
        if (isset($hookExtra['plugin']) || isset($hookExtra['theme'])) {
            // Use the plugin or theme named by WordPress, not an old value in the skin.
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
        // Older skins can tell us which repository URL to check. Moving files
        // requires a checked download: WordPress can reuse a child theme's
        // skin when it downloads a different parent theme.
        if (!$forDownload) {
            return $this->downloadedPackages[$upgrader]['extension'] ?? false;
        }
        if (($upgrader->skin instanceof PluginUpgraderSkin || $upgrader->skin instanceof ThemeUpgraderSkin)
            && $upgrader->skin->extension instanceof \RRZE\Updater\Core\Extension) {
            return $upgrader->skin->extension;
        }
        return false;
    }

    /**
     * Checks the package URL and downloads the ZIP with the connector's token.
     *
     * Called by the upgrader_pre_download hook. Keeps a path or error returned by
     * an earlier filter. Otherwise, checks that the URL belongs to the configured
     * repository before using credentials. Old GitLab tokens in URLs are removed.
     * After downloading, remembers the requested Git ref for the install step.
     *
     * @param false|string|WP_Error $reply     Earlier filter result: false, a downloaded file path, or an error.
     * @param string                $package   Download URL or local ZIP path supplied by WordPress.
     * @param \WP_Upgrader          $upgrader  WordPress object running this update.
     * @param array<string, mixed>  $hookExtra Update details used to find the managed plugin or theme.
     *
     * @return false|string|WP_Error Earlier result, downloaded ZIP path, or an error.
     *     False means WordPress should handle the download itself.
     */
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
        // GitHub and GitLab put the URL-encoded Git ref at the end of the URL.
        // Rebuild and compare the full URL before sending the connector's token.
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

    /**
     * Blocks a package URL that does not belong to the managed repository.
     *
     * If WordPress did not name a plugin or theme, let it handle the download.
     * It may be installing a parent theme while reusing the child theme's skin.
     *
     * @param array<string, mixed> $hookExtra Update details, optionally including plugin or theme.
     *
     * @return false|WP_Error False if no plugin/theme was named; otherwise an error blocking the update.
     */
    private function rejectUnmanagedPackage(array $hookExtra): false|WP_Error
    {
        // WordPress may reuse the skin while installing a different parent theme.
        if (!isset($hookExtra['plugin']) && !isset($hookExtra['theme'])) {
            return false;
        }
        return new WP_Error('rrze_updater_untrusted_package', __('The package does not match the managed repository. Refresh the update information before retrying.', 'rrze-updater'));
    }

    /**
     * Checks the plugin's repository files at the Git ref being downloaded.
     *
     * A missing main file, plugin header or readme is treated as a warning.
     * Stores that warning on the plugin, tries to save it, and writes it to the log.
     * Other repository errors are logged and stop the update.
     *
     * @param Plugin $extension Managed plugin and its connector.
     * @param string $ref       Commit, tag or branch from the checked download URL.
     *
     * @return true|WP_Error True to continue, or the error that stops the update.
     */
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

    /**
     * Finds the plugin or theme and checks that it uses GitHub or GitLab.
     *
     * Also works when WordPress updates several items at once and supplies
     * plugin/theme paths without a separate type field.
     *
     * @param \WP_Upgrader        $upgrader  WordPress object running this update.
     * @param array<string, mixed> $hookExtra Update details used to find the plugin or theme.
     *
     * @return Core\Extension|false Plugin/theme with a supported connector, or false.
     */
    private function getRepositoryExtensionForUpgrade($upgrader, array $hookExtra)
    {
        // When updating several items, WordPress may omit the separate type field.
        $extension = $this->getManagedExtensionForUpgrade($upgrader, $hookExtra, true);
        return $extension && ($extension->connector instanceof GithubConnector || $extension->connector instanceof GitlabConnector)
            ? $extension : false;
    }

    /**
     * Waits for the final package result before saving an installed version.
     *
     * Called by upgrader_post_install after WordPress copies the files. Other
     * filters can still report an error, so this method does not save yet.
     * Instead, it adds a temporary callback to upgrader_install_package_result
     * at PHP_INT_MAX, a high priority that runs after lower-priority callbacks.
     * That callback saves manual updates or remembers background updates for later.
     *
     * @param mixed               $response  Earlier filter result; only true means success here.
     * @param array<string, mixed> $hookExtra Details identifying the installed plugin or theme.
     * @param array<string, mixed> $result    Install details; destination_name is the installed folder name.
     *
     * @return mixed The original response, unchanged.
     */
    public function upgraderPostInstallFilter($response, $hookExtra, $result)
    {
        $package = $this->currentPackage;
        $this->currentPackage = null;
        if ($response !== true || !$package || !is_array($result)
            || ($result['destination_name'] ?? '') !== $package['extension']->installationFolder) {
            return $response;
        }
        /**
         * Handles the final result for this package, then removes this callback.
         *
         * Ignores results for other packages, such as a child theme's parent.
         * Also puts errors on the upgrader object so WordPress callers see them.
         * Background updates are kept for the later success or rollback report.
         *
         * @param mixed               $outcome Install result or an earlier filter's result.
         * @param array<string, mixed> $extra   Details identifying the completed plugin or theme.
         *
         * @return mixed Original result, or an error if saving the version fails.
         */
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
                // WordPress may still find a fatal error and restore the old files.
                // Wait for automatic_updates_complete before saving this ref.
                $this->automaticPackages[$package['type'] . ':' . $target] = $package;
                return $outcome;
            }
            $saved = $this->saveInstalledPackage($package);
            if (is_wp_error($saved)) {
                // WordPress also checks the upgrader's result property after run().
                $package['upgrader']->result = $saved;
                return $saved;
            }
            return $outcome;
        };
        add_filter('upgrader_install_package_result', $finish, PHP_INT_MAX, 2);
        return $response;
    }

    /**
     * Saves background-update versions after WordPress finishes its checks.
     *
     * Called by automatic_updates_complete. Matches each result to an update
     * we remembered earlier, then handles it as follows:
     * - Success: save the downloaded Git ref.
     * - Old files restored after an error: keep the previous ref.
     * - Restoring the old files also failed: save an empty ref, meaning "unknown".
     * If saving fails, put the error on the result object and write it to the log.
     * Clears all remembered updates, including any missing from the report.
     *
     * @param array<string, array<array-key, object>> $results WordPress results grouped by update type.
     *     Each plugin/theme result has an item object identifying the installation
     *     and a result property containing true, false or WP_Error.
     *
     * @return void
     */
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
                    // The update and the restore both failed; the installed version is unknown.
                    $package['ref'] = '';
                } elseif ($outcome !== true) {
                    // A failed update, including one that restored old files, keeps the old ref.
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

    /**
     * Saves the installed Git ref in settings.
     *
     * An empty ref means we no longer know which version is installed.
     * Only known versions get a success log message. If saving fails, restores
     * localVersion on the settings object to its previous value and returns an error.
     *
     * @param array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool} $package
     *     Details of the completed update. Its ref can be empty after a failed rollback.
     *
     * @return true|WP_Error True if saved, or an error if the settings could not be saved.
     */
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
     * Writes a success message if informational logging is enabled.
     *
     * Includes the repository, installed Git ref, branch, service and current user.
     * Uses a readable version label only if it belongs to the ref just installed.
     *
     * @param 'plugin'|'theme' $type      Whether a plugin or theme was updated.
     * @param Core\Extension  $extension Plugin/theme whose installed version has been saved.
     *
     * @return void
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
     * Gets the current user's details for the log message.
     *
     * Uses ID 0 and login "system" when nobody is logged in. If WordPress's user
     * function is unavailable, uses login "unknown" instead. Both use an empty email.
     *
     * @return array{id: int, login: string, email: string} User details to include in the log.
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

    /**
     * Gets extra details about the connector's latest error for logging.
     *
     * @param Core\Extension $extension Plugin/theme whose connector made the request.
     *
     * @return array<string, mixed> Error details, or an empty array if none are available.
     */
    private function getConnectorErrorContext($extension): array {
        if (
            empty($extension->connector)
            || !method_exists($extension->connector, 'getLastErrorContext')
        ) {
            return [];
        }

        return $extension->connector->getLastErrorContext();
    }
}

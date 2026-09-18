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
 * Verifies repository packages and records their final installation outcomes.
 *
 * One instance handles the download, source-selection and completion hooks for
 * a request. It tracks the ref encoded in each authenticated package URL rather
 * than assuming that the latest available repository ref was installed.
 * Manual updates commit their ref after the final package result; background
 * updates wait for the batch outcome so fatal-error checks and rollbacks count.
 */
class ManagedUpgrader
{
    /**
     * Verified downloads awaiting source selection, keyed by the core upgrader.
     *
     * Created on the first download attempt. Each new attempt clears the previous
     * entry for that upgrader; successful source selection consumes its entry.
     * Weak keys avoid retaining an upgrader solely for an unused download record.
     *
     * @var \WeakMap<\WP_Upgrader, array{extension: Core\Extension, ref: string, type: 'plugin'|'theme'}>|null
     */
    private ?\WeakMap $downloadedPackages = null;

    /**
     * Verified package selected for the current installation, or null.
     *
     * Source selection adds the originating upgrader and background-update flag.
     * This state is reset on each source-selection attempt and consumed by the
     * post-install hook. Manual AJAX skins are not treated as background updates.
     *
     * @var array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool}|null
     */
    private ?array $currentPackage = null;

    /**
     * Completed background packages awaiting final batch outcomes.
     *
     * Keys are "plugin:<plugin basename>" or "theme:<stylesheet directory>".
     * The batch-completion handler drains this queue before examining outcomes,
     * preventing later events from reusing completed or unmatched package state.
     *
     * @var array<string, array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool}>
     */
    private array $automaticPackages = [];

    /**
     * Receives shared dependencies without registering WordPress hooks.
     *
     * @param Settings $settings Registry shared with the other plugin components.
     * @param Config   $config   Configuration used to identify the plugin in logs.
     */
    public function __construct(
        /**
         * Shared registry used to resolve managed targets and persist installed refs.
         *
         * @var Settings
         */
        private readonly Settings $settings,
        /**
         * Plugin configuration used for logging context.
         *
         * @var Config
         */
        private readonly Config $config
    ) {}

    /**
     * Registers this instance's download, source-selection and completion hooks.
     *
     * All four hooks run at priority 10. The temporary final-package-result
     * callback is added separately by upgraderPostInstallFilter() when needed.
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
     * Prepare the managed destination and carry forward the verified package.
     *
     * Handles upgrader_source_selection. Unmanaged sources and earlier errors
     * pass through. Managed sources are moved to their registered folder without
     * overwriting an existing destination. Only a matching verified download is
     * staged for later version persistence.
     *
     * @global \WP_Filesystem_Base $wp_filesystem Active WordPress filesystem adapter.
     *
     * @param string|WP_Error       $source         Unpacked source directory or an earlier error.
     * @param string                $remoteSource   Unpack working directory containing the source.
     * @param \WP_Upgrader          $upgrader       Core upgrader processing this package.
     * @param array<string, mixed>  $hookExtra      Core context; plugin/theme keys identify the target.
     *
     * @return string|WP_Error Original or managed source path, or a validation/move error.
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

    /**
     * Resolve exact core identifiers; display names are not unique identities.
     *
     * An explicit plugin basename or theme stylesheet must match exactly one
     * registry entry and takes precedence over skin state. Without identifiers,
     * a legacy skin may supply a download candidate; source selection instead
     * requires an already verified download for this upgrader instance.
     *
     * @param \WP_Upgrader          $upgrader       Core upgrader and its associated skin.
     * @param array<string, mixed>  $hookExtra      Core context, optionally containing plugin or theme.
     * @param bool                  $forDownload    Whether an unverified legacy-skin candidate is allowed.
     *
     * @return Core\Extension|false Resolved extension, or false for an unknown or ambiguous target.
     */
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

    /**
     * Downloads a canonical managed archive using its connector credentials.
     *
     * Handles upgrader_pre_download and preserves earlier short-circuit replies.
     * GitLab tokens from legacy URLs are removed before URL verification. The
     * complete URL and encoded ref must match the connector's archive format
     * before repository validation or authenticated download can proceed.
     * Successful downloads record the actual package ref for source selection.
     *
     * @param false|string|WP_Error     $reply      Earlier reply: false, a local archive path, or an error.
     * @param string                    $package    Package URL or local archive path supplied by core.
     * @param \WP_Upgrader              $upgrader   Core upgrader processing this package.
     * @param array<string, mixed>      $hookExtra  Core context used to resolve the repository target.
     *
     * @return false|string|WP_Error    Earlier reply, downloaded archive path, false to let core
     *                                  handle an unmanaged package, or a verification/download error.
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

    /**
     * Rejects a foreign package for an explicitly identified managed target.
     *
     * Without an explicit target, the skin may have been reused for an unrelated
     * parent-theme installation; return false so core can handle that package.
     *
     * @param array<string, mixed> $hookExtra Core context, optionally containing plugin or theme.
     *
     * @return false|WP_Error False for a targetless package, otherwise an untrusted-package error.
     */
    private function rejectUnmanagedPackage(array $hookExtra): false|WP_Error
    {
        // A skin alone may be reused for an unrelated parent-theme install.
        if (!isset($hookExtra['plugin']) && !isset($hookExtra['theme'])) {
            return false;
        }
        return new WP_Error('rrze_updater_untrusted_package', __('The package does not match the managed repository. Refresh the update information before retrying.', 'rrze-updater'));
    }

    /**
     * Checks plugin repository contents at the ref that will actually be installed.
     *
     * Missing plugin-file, header or readme warnings are tolerated: the warning
     * is assigned to the extension, a settings save is attempted, and the warning
     * is logged. Other repository errors are logged and block the download.
     *
     * @param Plugin    $extension  Managed plugin with its configured connector.
     * @param string    $ref        Ref extracted from the verified archive URL.
     *
     * @return true|WP_Error True when installation may proceed, otherwise the blocking error.
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
     * Resolves a download candidate backed by a supported repository connector.
     *
     * Supports GitHub and GitLab, including core bulk-update contexts that carry
     * plugin/theme identifiers without a separate type key.
     *
     * @param \WP_Upgrader          $upgrader   Core upgrader and its associated skin.
     * @param array<string, mixed>  $hookExtra  Core context used to identify the extension.
     *
     * @return Core\Extension|false Candidate with a supported connector, or false.
     */
    private function getRepositoryExtensionForUpgrade($upgrader, array $hookExtra)
    {
        // Core bulk updates provide plugin/theme identifiers without a type key.
        $extension = $this->getManagedExtensionForUpgrade($upgrader, $hookExtra, true);
        return $extension && ($extension->connector instanceof GithubConnector || $extension->connector instanceof GitlabConnector)
            ? $extension : false;
    }

    /**
     * Stage the verified ref; later post-install filters may still fail.
     *
     * Handles upgrader_post_install and consumes the selected package state.
     * A successful response with the expected destination adds a temporary
     * upgrader_install_package_result callback at PHP_INT_MAX. That callback
     * commits manual updates or queues background updates for batch completion.
     * Unrelated nested package results do not consume the pending callback.
     *
     * @param mixed                $response  Earlier post-install response; only true stages a ref.
     * @param array<string, mixed> $hookExtra Core context identifying the installed package.
     * @param array<string, mixed> $result    Core installation data, including destination_name.
     *
     * @return mixed The original post-install response, unchanged.
     */
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
        /**
         * Finalizes the captured package and removes itself on a matching result.
         *
         * Package or persistence errors also update the originating upgrader's
         * result property. Background packages wait for the batch outcome.
         *
         * @param mixed                $outcome Core package result or an earlier filter's reply.
         * @param array<string, mixed> $extra   Core context identifying the completed package.
         *
         * @return mixed Original outcome, or a settings persistence error.
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

    /**
     * Records background-update refs after core's final checks and rollbacks.
     *
     * Handles automatic_updates_complete. Only queued plugin/theme targets are
     * considered. Success records the downloaded ref; successful rollback keeps
     * the previous ref; failed rollback records an empty ref because the installed
     * files are uncertain. Persistence errors replace the update object's result
     * and are logged. Pending state is consumed even for unmatched results.
     *
     * @param array<string, array<array-key, object>> $results Results grouped by update type.
     *     Plugin/theme entries expose an item object with the target identifier
     *     and a result property containing the final boolean or WP_Error.
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

    /**
     * Persists the package ref and logs a successful, known installed version.
     *
     * An empty ref represents an unknown installation after failed rollback and
     * is saved without a success log. If persistence fails, the extension's
     * in-memory localVersion is restored and an error is returned to the caller.
     *
     * @param array{extension: Core\Extension, ref: string, type: 'plugin'|'theme', upgrader: \WP_Upgrader, automatic: bool} $package
     *     Completed package context; ref may be empty after a failed rollback.
     *
     * @return true|WP_Error True after persistence, or an installed-version save error.
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
     * Logs a successful repository update.
     *
     * Includes the repository, installed ref, branch, service and current user.
     * A readable version is used only when the installed ref matches current
     * remote metadata. Logger::info() honors the informational logging setting.
     *
     * @param 'plugin'|'theme'  $type       Installed extension type.
     * @param Core\Extension    $extension  Extension whose installed ref has been persisted.
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
     * Returns context for the current admin user.
     *
     * Falls back to ID 0 and login "unknown" when the user API is unavailable,
     * or login "system" when no user is logged in. Both use an empty email.
     *
     * @return array{id: int, login: string, email: string} User identity for logging.
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
     * Returns the connector's most recent error details for download logs.
     *
     * @param Core\Extension $extension Extension whose connector performed the request.
     *
     * @return array<string, mixed> Connector error context, or an empty array when unavailable.
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

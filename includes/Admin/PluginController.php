<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Settings, InstalledExtensions, RepositoryActions, SettingsSynchronizer};
use RRZE\Updater\Core\{Plugin, RepositoryManager, PluginReplacement};
use RRZE\Updater\ListTable\PluginsListTable;
use WP_Error;

/** Coordinates plugin forms and reviewed repairs through dedicated services. */
class PluginController
{
    /** @var AdminMessages Notices shared by the current administrative request. */
    private AdminMessages $messages;

    /**
     * Inject the concrete dependencies used by this administrative component.
     *
     * @param Settings $settings Shared registry and user settings.
     * @param RepositoryActions $actions Shared persistence, checking and deletion operations.
     * @param SettingsSynchronizer $synchronizer Locked installed-extension reconciliation.
     * @param InstalledExtensions $installed Local extension metadata reader.
     * @param RepositoryPresenter $presenter Repository display-data builder.
     * @param AdminLinks $links Administrative URLs and optional tools.
     * @param AdminRenderer $renderer Explicit-data page renderer.
     * @param RepositoryManager $manager Repository source and installation service.
     * @param PluginReplacement $replacement Reviewed plugin file replacement service.
     * @param AdminMessages|null $messages Optional request-wide notice collection.
     */
    public function __construct(
        private Settings $settings,
        private RepositoryActions $actions,
        private SettingsSynchronizer $synchronizer,
        private InstalledExtensions $installed,
        private RepositoryPresenter $presenter,
        private AdminLinks $links,
        private AdminRenderer $renderer,
        private RepositoryManager $manager,
        private PluginReplacement $replacement,
        ?AdminMessages $messages = null
    ) {
        $this->messages = $messages ?? new AdminMessages();
    }

    /**
     * Dispatch a plugin form action with its existing nonce rules.
     *
     * @param string $action Selected administrative action.
     * @return void
     */
    public function getPluginAction(string $action): void
    {
        $nonceField = $_POST['rrze-updater-nonce'] ?? '';

        switch ($action) {
            case 'add':
                if (is_string($nonceField) && wp_verify_nonce($nonceField, 'rrze-updater-plugin-add')) {
                    $this->postPluginAdd();
                } else {
                    $this->getPluginAdd();
                }
                break;

            case 'check-updates':
                $this->getPluginUpdates();
                break;

            case 'edit':
                if (is_string($nonceField) && wp_verify_nonce($nonceField, 'rrze-updater-plugin-edit')) {
                    $this->postPluginEdit();
                } else {
                    $this->getPluginEdit();
                }
                break;

            case 'delete':
                if (isset($_GET['id'])) {
                    $this->getPluginDelete();
                }
                break;
        }
    }

    /**
     * Render the form for adding a managed plugin.
     *
     * @return void
     */
    public function getPluginAdd(): void
    {
        $data = [
            'connectors' => $this->settings->connectors
        ];

        $this->display('plugins/add', $data);
    }

    /**
     * Render the persisted plugin selected by the request.
     *
     * @return void
     */
    public function getPluginEdit(): void
    {
        $pluginId = $_GET['id'] ?? '';
        if (is_string($pluginId) && ($plugin = $this->settings->getPluginById(sanitize_text_field($pluginId)))) {
            $this->displayPluginEdit($plugin);
        }
    }

    /**
     * Check the administrative capabilities required to edit plugin sources.
     *
     * @return bool Whether this user may edit plugin source associations.
     */
    public function canManagePluginSources(): bool
    {
        return current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')
            && (!is_multisite() || is_super_admin())
            && current_user_can('update_plugins');
    }

    /**
     * Check administrative capabilities and the filesystem modification policy.
     *
     * @return bool Whether this user may install files under the current filesystem policy.
     */
    public function canReplacePluginFiles(): bool
    {
        return $this->canManagePluginSources()
            && current_user_can('install_plugins')
            && wp_is_file_mod_allowed('rrze_updater_install');
    }

    /**
     * Check the requested plugin and refresh its update cache when needed.
     *
     * @return void
     */
    public function getPluginUpdates(): void
    {
        $pluginId = $_GET['id'] ?? '';
        $pluginId = sanitize_text_field($pluginId);

        if ($plugin = $this->settings->getPluginById($pluginId)) {
            $result = $this->actions->check($plugin);
            if (is_wp_error($result)) {
                $this->messages->add($result);
                $this->display('');
                return;
            }

            if ($plugin->localVersion != $plugin->remoteVersion) {
                delete_site_transient('update_plugins');
            }

            $this->displayPluginEdit($plugin);
        }
    }

    /**
     * Authorize a single plugin association deletion.
     *
     * @return void
     */
    public function getPluginDelete(): void
    {
        $pluginId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        if (!is_string($nonceField) || !wp_verify_nonce($nonceField, 'rrze-updater-plugin-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $this->pluginDelete($pluginId);
    }

    /**
     * Find the existing managed association for an installation folder.
     *
     * @param string $folder Plugin installation directory name.
     * @return Plugin|null Existing managed plugin, or null.
     */
    private function getManagedPluginForFolder(string $folder): ?Plugin
    {
        foreach ($this->settings->plugins as $plugin) {
            if ($plugin->installationFolder === $folder) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Validate and dispatch the requested reviewed replacement step.
     *
     * @return void
     */
    public function postPluginReplacement(): void
    {
        $request = $this->replacementRequest();
        if ($request === null) {
            $this->display('');
            return;
        }
        $pluginId = $request['plugin_id'] ?? '';
        try {
            $result = $request['replacement_action'] === 'prepare'
                ? $this->prepareReplacement($request, $pluginId)
                : $this->completeReplacement($request, $pluginId);
            if (!is_wp_error($result)) {
                return;
            }
        } catch (\Throwable $exception) {
            // Transport and filesystem exceptions may contain credentials.
            $result = new WP_Error('rrze_updater_replacement_interrupted', __('Plugin replacement was interrupted. Inspect the installation before trying again.', 'rrze-updater'));
        }
        $this->displayReplacementError($result, $pluginId);
    }

    /**
     * Validate the POST-only replacement operation before calling its service.
     *
     * @return array|null Unslashed request, or null after a recoverable validation error.
     */
    private function replacementRequest(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Plugin replacement requires a form submission.', 'rrze-updater'));
        }
        if (!$this->canManagePluginSources() || !current_user_can('install_plugins')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }
        $request = $_POST['rrze-updater'] ?? null;
        $operation = is_array($request) ? ($request['replacement_action'] ?? '') : '';
        $nonce = $_POST['rrze-updater-replacement-nonce'] ?? $_POST['rrze-updater-nonce'] ?? '';
        if (!is_string($operation) || !in_array($operation, ['prepare', 'apply', 'cancel'], true)
            || !is_string($nonce) || !wp_verify_nonce($nonce, 'rrze-updater-plugin-replacement-' . $operation)) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }
        if (!wp_is_file_mod_allowed('rrze_updater_install')) {
            $this->messages->add(new WP_Error('file_modifications_disabled', __('File modifications are disabled on this installation.', 'rrze-updater')));
            return null;
        }
        foreach (['plugin_id', 'review_id', 'repository', 'connectorId', 'installationFolder', 'branch', 'updates'] as $field) {
            if (isset($request[$field]) && !is_string($request[$field])) {
                $this->messages->add(new WP_Error('invalid_request', __('Invalid repository installation request.', 'rrze-updater')));
                return null;
            }
        }
        return wp_unslash($request);
    }

    /**
     * Prepare and display a review using saved source data for reinstallations.
     *
     * @param array $request Validated replacement request.
     * @param string $pluginId Managed ID, or empty for a new association.
     * @return true|WP_Error True after displaying the review, or a preparation failure.
     */
    private function prepareReplacement(array $request, string $pluginId): true|WP_Error
    {
        if ($pluginId !== '') {
            $plugin = $this->settings->getPluginById($pluginId);
            if (!$plugin) {
                return new WP_Error('rrze_updater_missing_plugin', __('The managed plugin no longer exists. Reload the plugin list.', 'rrze-updater'));
            }
            // Unsaved editor fields must never alter a reinstallation source.
            $result = $this->replacement->prepare($plugin->repository, [
                'connector' => $plugin->connectorId,
                'folder' => $plugin->installationFolder,
                'branch' => $plugin->branch,
                'updates' => $plugin->updates,
                'ignore-prereleases' => $plugin->ignorePrereleases,
            ], $pluginId);
        } else {
            $repository = trim($request['repository'] ?? '');
            $result = $this->replacement->prepare($repository, [
                'connector' => $request['connectorId'] ?? '',
                'folder' => ($request['installationFolder'] ?? '') ?: $repository,
                'branch' => ($request['branch'] ?? '') ?: 'main',
                'updates' => ($request['updates'] ?? '') ?: 'tags',
            ]);
        }
        if (is_wp_error($result)) {
            return $result;
        }
        $this->display('plugins/replace', ['review' => $result]);
        return true;
    }

    /**
     * Resolve the server-side review and apply or cancel that exact operation.
     *
     * @param array $request Validated request containing only the review ID for execution.
     * @param string $pluginId Receives the trusted association ID for error navigation.
     * @return true|WP_Error True after rendering the outcome, or a service failure.
     */
    private function completeReplacement(array $request, string &$pluginId): true|WP_Error
    {
        $reviewId = $request['review_id'] ?? '';
        $review = $this->replacement->getReview($reviewId);
        if (is_wp_error($review)) {
            return $review;
        }
        $pluginId = $review['plugin_id'] ?? '';
        if ($request['replacement_action'] === 'cancel') {
            $this->cancelReplacement($reviewId, $pluginId);
            return true;
        }
        return $this->applyReplacement($reviewId);
    }

    /**
     * Cancel a review without changing files and return to its originating form.
     *
     * @param string $reviewId Server-side review ID.
     * @param string $pluginId Trusted managed association ID.
     * @return void
     */
    private function cancelReplacement(string $reviewId, string $pluginId): void
    {
        $this->replacement->cancel($reviewId);
        $this->messages->add(__('Plugin replacement cancelled. No files have changed.', 'rrze-updater'));
        $this->displayReplacementReturn($pluginId);
    }

    /**
     * Apply a reviewed exchange and announce success only after persistence.
     *
     * @param string $reviewId Server-side review ID.
     * @return true|WP_Error True on success, or the replacement failure.
     */
    private function applyReplacement(string $reviewId): true|WP_Error
    {
        $result = $this->replacement->apply($reviewId);
        if (is_wp_error($result)) {
            return $result;
        }
        $this->messages->add(__('Plugin files successfully reinstalled.', 'rrze-updater'));
        $this->displayReplacementReturn($result);
        return true;
    }

    /**
     * Present a replacement failure and the existing association when one conflicts.
     *
     * @param WP_Error $result Replacement failure.
     * @param string $pluginId Originating managed association ID.
     * @return void
     */
    private function displayReplacementError(WP_Error $result, string $pluginId): void
    {
        $this->messages->add($result);
        if ($result->get_error_code() === 'rrze_updater_managed_destination') {
            $errorData = $result->get_error_data();
            $existingId = is_array($errorData) ? ($errorData['plugin_id'] ?? '') : '';
            $this->display('plugins/add', [
                'connectors' => $this->settings->connectors,
                'managedPluginUrl' => is_string($existingId) && $existingId !== '' ? $this->links->getPluginEditUrl($existingId) : '',
            ]);
            return;
        }
        $this->displayReplacementReturn($pluginId);
    }

    /**
     * Return to the persisted plugin editor or the new-plugin form.
     *
     * @param string $pluginId Managed plugin ID, or empty for a new association.
     * @return void
     */
    private function displayReplacementReturn(string $pluginId): void
    {
        $plugin = $pluginId !== '' ? $this->settings->getPluginById($pluginId) : false;
        if ($plugin) {
            $this->displayPluginEdit($plugin);
        } else {
            $this->getPluginAdd();
        }
    }

    /**
     * Validate a plugin source change through the repository manager.
     *
     * @return void
     */
    public function postPluginEdit(): void
    {
        if (!$this->canManagePluginSources()) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }
        $request = $_POST['rrze-updater'] ?? null;
        if (!is_array($request)) {
            return;
        }
        foreach (['id', 'repository', 'connectorId', 'branch', 'updates'] as $field) {
            if (!isset($request[$field]) || !is_string($request[$field])) {
                $this->messages->add(new WP_Error('invalid_request', __('Invalid repository installation request.', 'rrze-updater')));
                $this->display('');
                return;
            }
        }
        $request = wp_unslash($request);
        $manager = $this->manager;
        $result = $manager->changePluginSource($request['id'], trim($request['repository']), [
            'connector' => $request['connectorId'],
            'branch' => $request['branch'] ?: 'main',
            'updates' => $request['updates'],
            'ignore-prereleases' => ($request['ignorePrereleases'] ?? '') === '1',
        ]);
        $this->messages->add($result);
        foreach ($manager->getWarnings() as $warning) {
            $this->messages->add(new WP_Error('rrze_updater_repository_warning', $warning));
        }
        $plugin = $this->settings->getPluginById($request['id']);
        if ($plugin) {
            $this->displayPluginEdit($plugin);
        } else {
            $this->display('');
        }
    }

    /**
     * Pass page data and the accumulated notices to the renderer.
     *
     * @param string $view Template name relative to the views directory.
     * @param array $data Explicit template data.
     * @return void
     */
    private function display(string $view, array $data = []): void
    {
        $this->renderer->display($view, $data, $this->messages->all());
    }

    /**
     * Read the supported page action without performing it.
     *
     * @return string Formatted value or empty when unavailable.
     */
    public function getAction(): string
    {
        return AdminRequest::action();
    }

    /**
     * Synchronize installed extensions and retain failures for display.
     *
     * @return bool Whether the installation inventory was synchronized and saved.
     */
    public function synchronizeSettings(): bool
    {
        $result = $this->synchronizer->synchronize();
        if (is_wp_error($result)) {
            $this->messages->add($result);
            return false;
        }
        return true;
    }

    /**
     * Delete a plugin association and retain any persistence error for display.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function pluginDelete($id): void
    {
        $result = $this->actions->deletePlugin(sanitize_text_field($id));
        if (is_wp_error($result)) {
            $this->messages->add($result);
        }
    }

    /**
     * Register the plugin list pagination preference.
     *
     * @return void
     */
    public function pluginsListScreenOptions(): void
    {
        new PluginsListTable();
        AdminRequest::screenOptions();
    }

    /**
     * Process plugin actions before preparing the read-only overview.
     *
     * @return void
     */
    public function getPluginIndex(): void
    {
        if (($_GET['action'] ?? '') === 'replace') {
            $this->postPluginReplacement();
            return;
        }
        if ($action = $this->getAction()) {
            $this->getPluginAction($action);
            if ($action !== 'delete') {
                return;
            }
        }
        $this->synchronizeSettings();
        foreach (AdminRequest::deleteIds('plugins', ['update_plugins']) as $id) {
            $this->pluginDelete($id);
        }
        $check = $this->links->isPluginCheckAvailable();
        $managerUrl = $this->links->getMultisiteManagerPluginsUrl();
        $table = new PluginsListTable($this->presenter->pluginRows(sanitize_text_field($_GET['s'] ?? '')), $check || $managerUrl !== '');
        $table->prepare_items();
        $this->display('plugins/index', [
            'listTable' => $table,
            'pluginCheckOverviewUrl' => $check ? (string) add_query_arg(['page' => 'plugin-check'], admin_url('tools.php')) : '',
            'multisiteManagerPluginsUrl' => $managerUrl,
        ]);
    }

    /**
     * Render editor data assembled by the repository presenter.
     *
     * @param Plugin $plugin Persisted managed plugin.
     * @return void
     */
    private function displayPluginEdit(Plugin $plugin): void
    {
        $this->display('plugins/edit', $this->presenter->pluginEditData($plugin, $this->canReplacePluginFiles()));
    }

    /**
     * Install a plugin without overwriting and offer the explicit repair path on conflict.
     *
     * @return void
     */
    public function postPluginAdd(): void
    {
        $result = (new RepositoryInstallAction($this->manager))->install('plugin');
        if ($result === null) {
            return;
        }
        $data = ['connectors' => $this->settings->connectors];
        foreach ($result['messages'] as $message) {
            $this->messages->add($message);
        }
        if (is_wp_error($result['result']) && $this->canReplacePluginFiles()) {
            $managed = $this->getManagedPluginForFolder($result['folder']);
            if ($managed) {
                $data['managedPluginUrl'] = $this->links->getPluginEditUrl($managed->id);
            } elseif ($this->installed->pluginDestinationExists($result['folder'])) {
                $data['canReplace'] = true;
            }
        }
        $this->display('plugins/' . (is_wp_error($result['result']) ? 'add' : 'add-progress'), $data);
    }

    /**
     * Return notices accumulated by this request before or after rendering.
     *
     * @return array<string|WP_Error> Request notices.
     */
    public function getMessages(): array
    {
        return $this->messages->all();
    }

}

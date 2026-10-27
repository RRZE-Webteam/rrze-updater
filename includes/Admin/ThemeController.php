<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Settings, RepositoryActions, SettingsSynchronizer};
use RRZE\Updater\Core\{Theme, RepositoryManager};
use RRZE\Updater\ListTable\ThemesListTable;
use WP_Error;

/** Coordinates theme forms without changing the legacy source-edit policy. */
class ThemeController
{
    /** @var AdminMessages Notices shared by the current administrative request. */
    private AdminMessages $messages;

    /**
     * Inject the concrete dependencies used by this administrative component.
     *
     * @param Settings $settings Shared registry and user settings.
     * @param RepositoryActions $actions Shared persistence, checking and deletion operations.
     * @param SettingsSynchronizer $synchronizer Locked installed-extension reconciliation.
     * @param RepositoryPresenter $presenter Repository display-data builder.
     * @param AdminLinks $links Administrative URLs and optional tools.
     * @param AdminRenderer $renderer Explicit-data page renderer.
     * @param RepositoryManager $manager Repository source and installation service.
     * @param AdminMessages|null $messages Optional request-wide notice collection.
     */
    public function __construct(
        private Settings $settings,
        private RepositoryActions $actions,
        private SettingsSynchronizer $synchronizer,
        private RepositoryPresenter $presenter,
        private AdminLinks $links,
        private AdminRenderer $renderer,
        private RepositoryManager $manager,
        ?AdminMessages $messages = null
    ) {
        $this->messages = $messages ?? new AdminMessages();
    }

    /**
     * Dispatch a theme form action with its existing nonce rules.
     *
     * @param string $action Selected administrative action.
     * @return void
     */
    public function getThemeAction(string $action): void
    {
        $nonceField = $_POST['rrze-updater-nonce'] ?? '';

        switch ($action) {
            case 'add':
                if (wp_verify_nonce($nonceField, 'rrze-updater-theme-add')) {
                    $this->postThemeAdd();
                } else {
                    $this->getThemeAdd();
                }
                break;

            case 'check-updates':
                $this->getThemeUpdates();
                break;

            case 'edit':
                if (wp_verify_nonce($nonceField, 'rrze-updater-theme-edit')) {
                    $this->postThemeEdit();
                } else {
                    $this->getThemeEdit();
                }
                break;

            case 'delete':
                if (isset($_GET['id'])) {
                    $this->getThemeDelete();
                }
                break;
        }
    }

    /**
     * Render the form for adding a managed theme.
     *
     * @return void
     */
    public function getThemeAdd(): void
    {
        $data = [
            'connectors' => $this->settings->connectors
        ];

        $this->display('themes/add', $data);
    }

    /**
     * Render the persisted theme selected by the request.
     *
     * @return void
     */
    public function getThemeEdit(): void
    {
        $themeId = $_GET['id'] ?? '';
        $themeId = sanitize_text_field($themeId);

        if ($theme = $this->settings->getThemeById($themeId)) {
            $data = $this->presenter->themeEditData($theme);

            $this->display('themes/edit', $data);
        }
    }

    /**
     * Check the requested theme and refresh its update cache when needed.
     *
     * @return void
     */
    public function getThemeUpdates(): void
    {
        $themeId = $_GET['id'] ?? '';
        $themeId = sanitize_text_field($themeId);

        if ($theme = $this->settings->getThemeById($themeId)) {
            $result = $this->actions->check($theme);
            if (is_wp_error($result)) {
                $this->messages->add($result);
                $this->display('');
                return;
            }

            if ($theme->localVersion != $theme->remoteVersion) {
                delete_site_transient('update_themes');
            }

            $data = $this->presenter->themeEditData($theme);

            $this->display('themes/edit', $data);
        }
    }

    /**
     * Authorize a single theme association deletion.
     *
     * @return void
     */
    public function getThemeDelete(): void
    {
        $themeId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        if (!is_string($nonceField) || !wp_verify_nonce($nonceField, 'rrze-updater-theme-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        if (!current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $this->themeDelete($themeId);
    }

    /**
     * Save a theme source using the established theme validation policy.
     *
     * @return void
     */
    public function postThemeEdit(): void
    {
        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        if (!current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $extensionId = $request['id'];

        if (!$extension = $this->settings->getThemeById($extensionId)) {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        if (!$request['repository']) {
            $this->messages->add(new WP_Error('error', __('The Repository field must not be empty.', 'rrze-updater')));
            $data = $this->presenter->themeEditData($extension);
            $this->display('themes/edit', $data);
            return;
        }

        $extension->connectorId = $request['connectorId'];
        $extension->connector = $this->settings->getConnectorById($extension->connectorId);
        $extension->repository = $request['repository'];
        $extension->branch = $request['branch'] ?: 'main';
        $extension->updates = $request['updates'];
        $extension->remoteVersion = $request['branch'];

        $extension->checkForUpdates();
        if (in_array($extension->updates, ['tags', 'commits', 'releases'], true)) {
            $this->settings->markSourceValidated($extension);
        }

        if (!$this->saveSettings()) {
            $extension = $this->settings->getThemeById($extensionId);
            if (!$extension) {
                $this->display('');
                return;
            }
        } else {
            delete_site_transient('update_themes');
        }

        $data = $this->presenter->themeEditData($extension);

        $this->display('themes/edit', $data);
    }

    /**
     * Validate Theme Check access and render its POST form handoff.
     *
     * @return void
     */
    public function postThemeCheckRedirect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $stylesheet = isset($_GET['theme']) ? sanitize_text_field((string) wp_unslash($_GET['theme'])) : '';
        if ($stylesheet === '') {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        check_admin_referer('rrze-updater-theme-check_' . $stylesheet);

        if (!$this->links->isThemeCheckAvailable()) {
            wp_die(esc_html__('Theme Check is not available.', 'rrze-updater'));
        }

        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            wp_die(esc_html__('The selected theme does not exist.', 'rrze-updater'));
        }

        $themeCheckUrl = admin_url('themes.php?page=themecheck');
        $themeCheckNonce = wp_create_nonce('themecheck-nonce');
        $this->renderer->partial('themes/check-redirect', compact('stylesheet', 'themeCheckUrl', 'themeCheckNonce'));
        exit;
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
     * Persist settings and retain failures for the current administrative response.
     *
     * @return bool Whether persistence succeeded; failures remain available as notices.
     */
    private function saveSettings(): bool
    {
        $result = $this->actions->save();
        if (is_wp_error($result)) {
            $this->messages->add($result);
            return false;
        }
        return true;
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
     * Delete a theme association and retain any persistence error for display.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function themeDelete($id): void
    {
        $result = $this->actions->deleteTheme(sanitize_text_field($id));
        if (is_wp_error($result)) {
            $this->messages->add($result);
        }
    }

    /**
     * Register the theme list pagination preference.
     *
     * @return void
     */
    public function themesListScreenOptions(): void
    {
        new ThemesListTable();
        AdminRequest::screenOptions();
    }

    /**
     * Process theme actions before preparing the read-only overview.
     *
     * @return void
     */
    public function getThemeIndex(): void
    {
        if ($action = $this->getAction()) {
            $this->getThemeAction($action);
            if ($action !== 'delete') {
                return;
            }
        }
        $this->synchronizeSettings();
        foreach (AdminRequest::deleteIds('themes', ['update_themes']) as $id) {
            $this->themeDelete($id);
        }
        $table = new ThemesListTable($this->presenter->themeRows(sanitize_text_field($_GET['s'] ?? '')));
        $table->prepare_items();
        $this->display('themes/index', ['listTable' => $table]);
    }

    /**
     * Install a theme through the shared non-overwriting form action.
     *
     * @return void
     */
    public function postThemeAdd(): void
    {
        $result = (new RepositoryInstallAction($this->manager))->install('theme');
        if ($result === null) {
            return;
        }
        foreach ($result['messages'] as $message) {
            $this->messages->add($message);
        }
        $this->display('themes/' . (is_wp_error($result['result']) ? 'add' : 'add-progress'), ['connectors' => $this->settings->connectors]);
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

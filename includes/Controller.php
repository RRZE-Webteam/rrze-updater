<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Admin\{AdminLinks, AdminMessages, AdminRenderer, PluginController, RepositoryController, RepositoryPresenter, RepositoryUpdateRunner, SettingsController, ThemeController};
use RRZE\Updater\Core\{PluginReplacement, RepositoryManager};

/**
 * Compatibility facade for the classical administration's WordPress callbacks.
 *
 * Each request is delegated to its page component. Background jobs depend on
 * SettingsSynchronizer directly and do not instantiate this facade.
 */
class Controller
{
    /** @var list<string> Existing route names accepted by the classical pages. */
    public const AVAILABLE_ACTIONS = ['add', 'bulk-update', 'check-updates', 'edit', 'delete'];

    /** @var RepositoryController Repository overview and update requests. */
    private RepositoryController $repositories;
    /** @var PluginController Plugin forms and repair requests. */
    private PluginController $plugins;
    /** @var ThemeController Theme forms and optional tool requests. */
    private ThemeController $themes;
    /** @var SettingsController Settings and connector requests. */
    private SettingsController $settingsPage;

    /**
     * Compose the administration around the shared settings and register legacy hooks.
     *
     * @param Settings $settings Shared mutable registry used by all components.
     */
    public function __construct(Settings $settings)
    {
        $config = new Config();
        $installed = new InstalledExtensions($config);
        $actions = new RepositoryActions($settings);
        $synchronizer = new SettingsSynchronizer($settings, $installed, $actions);
        $links = new AdminLinks($settings, $installed);
        $presenter = new RepositoryPresenter($settings, $installed, $links);
        $renderer = new AdminRenderer();
        $messages = new AdminMessages();
        $manager = new RepositoryManager($settings);
        $runner = new RepositoryUpdateRunner($settings, $installed, $renderer);
        $this->repositories = new RepositoryController($settings, $config, $actions, $presenter, $renderer, $runner, $messages);
        $this->plugins = new PluginController($settings, $actions, $synchronizer, $installed, $presenter,
            $links, $renderer, $manager, new PluginReplacement($settings), $messages);
        $this->themes = new ThemeController($settings, $actions, $synchronizer, $presenter, $links, $renderer, $manager, $messages);
        $this->settingsPage = new SettingsController($settings, $config, $actions, $presenter, $renderer, $messages);

        add_action('admin_post_rrze_updater_theme_check', [$this, 'postThemeCheckRedirect']);
        add_action('wp_ajax_rrze_updater_check_repository_update', [$this, 'ajaxCheckRepositoryUpdate']);
    }

    /**
     * Delegate getAction to the responsible administration component.
     *
     * @return string Selected route or an empty string.
     */
    public function getAction()
    {
        return $this->repositories->getAction();
    }

    /**
     * Delegate getRepoIndex to the responsible administration component.
     *
     * @return void
     */
    public function getRepoIndex()
    {
        return $this->repositories->getRepoIndex();
    }

    /**
     * Delegate ajaxCheckRepositoryUpdate to the responsible administration component.
     *
     * @return void
     */
    public function ajaxCheckRepositoryUpdate()
    {
        return $this->repositories->ajaxCheckRepositoryUpdate();
    }

    /**
     * Delegate getSettingsIndex to the responsible administration component.
     *
     * @return void
     */
    public function getSettingsIndex()
    {
        return $this->settingsPage->getSettingsIndex();
    }

    /**
     * Delegate settingsScreenOptions to the responsible administration component.
     *
     * @return void
     */
    public function settingsScreenOptions()
    {
        return $this->settingsPage->settingsScreenOptions();
    }

    /**
     * Delegate postThemeCheckRedirect to the responsible administration component.
     *
     * @return void
     */
    public function postThemeCheckRedirect(): void
    {
        $this->themes->postThemeCheckRedirect();
    }

    /**
     * Delegate repoListScreenOptions to the responsible administration component.
     *
     * @return void
     */
    public function repoListScreenOptions()
    {
        return $this->repositories->repoListScreenOptions();
    }

    /**
     * Delegate getRepoDelete to the responsible administration component.
     *
     * @return void
     */
    public function getRepoDelete()
    {
        return $this->repositories->getRepoDelete();
    }

    /**
     * Delegate getRepoBulkUpdate to the responsible administration component.
     *
     * @return void
     */
    public function getRepoBulkUpdate(): void
    {
        $this->repositories->getRepoBulkUpdate();
    }

    /**
     * Delegate repoBulkUpdate to the responsible administration component.
     *
     * @param array $repoIds Selected repository IDs.
     * @return void
     */
    public function repoBulkUpdate(array $repoIds): void
    {
        $this->repositories->repoBulkUpdate($repoIds);
    }

    /**
     * Delegate repoDelete to the responsible administration component.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function repoDelete($id)
    {
        return $this->repositories->repoDelete($id);
    }

    /**
     * Delegate getConnectorIndex to the responsible administration component.
     *
     * @return void
     */
    public function getConnectorIndex()
    {
        return $this->settingsPage->getConnectorIndex();
    }

    /**
     * Delegate connListScreenOptions to the responsible administration component.
     *
     * @return void
     */
    public function connListScreenOptions()
    {
        return $this->settingsPage->connListScreenOptions();
    }

    /**
     * Delegate getConnectorDelete to the responsible administration component.
     *
     * @return void
     */
    public function getConnectorDelete()
    {
        return $this->settingsPage->getConnectorDelete();
    }

    /**
     * Delegate connectorDelete to the responsible administration component.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function connectorDelete($id)
    {
        return $this->settingsPage->connectorDelete($id);
    }

    /**
     * Delegate getPluginIndex to the responsible administration component.
     *
     * @return void
     */
    public function getPluginIndex()
    {
        return $this->plugins->getPluginIndex();
    }

    /**
     * Delegate pluginsListScreenOptions to the responsible administration component.
     *
     * @return void
     */
    public function pluginsListScreenOptions()
    {
        return $this->plugins->pluginsListScreenOptions();
    }

    /**
     * Delegate getPluginDelete to the responsible administration component.
     *
     * @return void
     */
    public function getPluginDelete()
    {
        return $this->plugins->getPluginDelete();
    }

    /**
     * Delegate pluginDelete to the responsible administration component.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function pluginDelete($id)
    {
        return $this->plugins->pluginDelete($id);
    }

    /**
     * Delegate getThemeIndex to the responsible administration component.
     *
     * @return void
     */
    public function getThemeIndex()
    {
        return $this->themes->getThemeIndex();
    }

    /**
     * Delegate themesListScreenOptions to the responsible administration component.
     *
     * @return void
     */
    public function themesListScreenOptions()
    {
        return $this->themes->themesListScreenOptions();
    }

    /**
     * Delegate getThemeAdd to the responsible administration component.
     *
     * @return void
     */
    public function getThemeAdd()
    {
        return $this->themes->getThemeAdd();
    }

    /**
     * Delegate getThemeDelete to the responsible administration component.
     *
     * @return void
     */
    public function getThemeDelete()
    {
        return $this->themes->getThemeDelete();
    }

    /**
     * Delegate themeDelete to the responsible administration component.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function themeDelete($id)
    {
        return $this->themes->themeDelete($id);
    }

    /**
     * Delegate synchronizeSettings to the responsible administration component.
     *
     * @return bool Whether synchronization was saved.
     */
    public function synchronizeSettings()
    {
        return $this->plugins->synchronizeSettings();
    }
}

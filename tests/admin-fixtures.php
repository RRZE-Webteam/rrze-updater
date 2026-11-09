<?php

use RRZE\Updater\{Config, Settings, InstalledExtensions, RepositoryActions, SettingsSynchronizer};
use RRZE\Updater\Core\{RepositoryManager, PluginReplacement};
use RRZE\Updater\Admin\{AdminLinks, AdminRenderer, RepositoryPresenter, RepositoryController, PluginController, ThemeController, SettingsController, RepositoryUpdateRunner};

/** Captures rendered pages through the public rendering contract. */
class RecordingAdminRenderer extends AdminRenderer
{
    /** @var array Last rendered view and its explicit data. */
    public array $shown = [];
    /** @var array Last rendered request notices. */
    public array $messages = [];

    /**
     * Record a page without printing HTML or reading installed WordPress templates.
     *
     * @param string $view Template name.
     * @param array $data Explicit page data.
     * @param array $messages Request notices.
     * @return void
     */
    public function display(string $view, array $data = [], array $messages = []): void
    {
        $this->shown = compact('view', 'data');
        $this->messages = $messages;
    }
}

/** Keeps fixture version labels independent of the developer's installed files. */
class AdminInstalledExtensionsFixture extends InstalledExtensions
{
    /**
     * Return a fixed installed plugin version.
     *
     * @param string $installationFolder Installation directory.
     * @param string $repository Repository name.
     * @return string Fixture version.
     */
    public function pluginVersion(string $installationFolder, string $repository): string
    {
        return '1.0';
    }

    /**
     * Return a fixed installed theme version.
     *
     * @param string $installationFolder Installation directory.
     * @return string Fixture version.
     */
    public function themeVersion(string $installationFolder): string
    {
        return '1.0';
    }
}

/** Keeps test dates independent of WordPress timezone functions. */
class AdminRepositoryPresenterFixture extends RepositoryPresenter
{
    /**
     * Expose the supplied timestamp directly in fixture page data.
     *
     * @param mixed $timestamp Last-check timestamp.
     * @return string Unformatted timestamp.
     */
    public function lastChecked($timestamp)
    {
        return (string) $timestamp;
    }
}

/** Composes real request boundaries with explicit recording dependencies. */
class AdminPageFixture
{
    public RecordingAdminRenderer $renderer;
    public InstalledExtensions $installed;
    public AdminLinks $links;
    public RepositoryPresenter $presenter;
    public RepositoryActions $actions;
    public SettingsSynchronizer $synchronizer;
    public RepositoryUpdateRunner $updates;
    public RepositoryController $repositories;
    public PluginController $plugins;
    public ThemeController $themes;
    public SettingsController $services;

    /**
     * Compose request components, optionally injecting an isolated installer or repair service.
     *
     * @param Settings $settings Shared registry state.
     * @param RepositoryManager|null $manager Optional repository service fixture.
     * @param PluginReplacement|null $replacement Optional replacement service fixture.
     * @param InstalledExtensions|null $installed Optional installed metadata fixture.
     */
    public function __construct(Settings $settings, ?RepositoryManager $manager = null, ?PluginReplacement $replacement = null, ?InstalledExtensions $installed = null)
    {
        $config = new Config();
        $this->renderer = new RecordingAdminRenderer();
        $this->installed = $installed ?? new AdminInstalledExtensionsFixture();
        $this->links = new AdminLinks($settings, $this->installed);
        $this->presenter = new AdminRepositoryPresenterFixture($settings, $this->installed, $this->links);
        $this->actions = new RepositoryActions($settings);
        $this->synchronizer = new SettingsSynchronizer($settings, $this->installed, $this->actions);
        $this->updates = new RepositoryUpdateRunner($settings, $this->installed, $this->renderer);
        $manager ??= new RepositoryManager($settings);
        $replacement ??= new PluginReplacement($settings);
        $this->repositories = new RepositoryController($settings, $config, $this->actions, $this->presenter, $this->renderer, $this->updates);
        $this->plugins = new PluginController($settings, $this->actions, $this->synchronizer, $this->installed, $this->presenter, $this->links, $this->renderer, $manager, $replacement);
        $this->themes = new ThemeController($settings, $this->actions, $this->synchronizer, $this->presenter, $this->links, $this->renderer, $manager);
        $this->services = new SettingsController($settings, $config, $this->actions, $this->presenter, $this->renderer);
    }

    /**
     * Read notices through each page's public request-state contract.
     *
     * @return array Notices collected by this fixture's request components.
     */
    public function notices(): array
    {
        return array_merge($this->repositories->getMessages(), $this->plugins->getMessages(), $this->themes->getMessages(), $this->services->getMessages());
    }
}

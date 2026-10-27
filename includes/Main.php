<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/** Initializes shared dependencies and registers the plugin components. */
class Main
{
    public Controller $controller;
    public Settings $settings;
    protected Config $config;

    /**
     * Compose runtime components around one shared settings registry.
     */
    public function __construct()
    {
        $this->config = new Config();
        $this->settings = new Settings();
        $this->controller = new Controller($this->settings);
        new Cron($this->settings, new SettingsSynchronizer($this->settings));
    }

    /**
     * Initializes settings and registers the plugin's runtime components.
     *
     * @return void
     */
    public function loaded(): void
    {
        $this->settings->initializeDefaults($this->config);

        (new BundleAdmin())->register();
        (new AdminIntegration($this->settings, $this->controller, $this->config))->register();
        (new UpdateProvider($this->settings))->register();
        (new ManagedUpgrader($this->settings, $this->config))->register();
        (new TokenStatus())->register();

        if (defined('WP_CLI') && WP_CLI) {
            CLI::registerCommands($this->settings);
        }
    }
}

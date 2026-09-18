<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

use RRZE\Updater\Settings;
use RRZE\Updater\Controller;

use RRZE\Updater\Core\GitlabConnector;
use RRZE\Updater\Core\Plugin;

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

        (new ManagedUpgrader($this->settings, $this->config))->register();

        if (defined('WP_CLI') && WP_CLI) {
            CLI::registerCommands($this->settings);
        }
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

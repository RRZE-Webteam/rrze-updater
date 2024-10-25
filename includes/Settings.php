<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\Connector;
use RRZE\Updater\Core\Plugin;
use RRZE\Updater\Core\Theme;

/**
 * Settings Class for Managing Extensions and Connectors
 *
 * The `Settings` class is responsible for managing extension settings and connectors
 * within a WordPress environment. It provides methods for retrieving, updating, and
 * interacting with extension and connector data.
 */
class Settings
{
    /**
     * @var array $connectors An array of Connector objects.
     */
    public $connectors;

    /**
     * @var array $plugins An array of Plugin objects.
     */
    public $plugins;

    /**
     * @var array $themes An array of Theme objects.
     */
    public $themes;

    /**
     * @var string $optionName The name of the option used to store settings in WordPress.
     */
    protected $optionName = 'rrze_updater';

    /**
     * Constructor to Initialize Settings
     *
     * This constructor initializes the `Settings` object by retrieving settings
     * data from WordPress options and populating the `connectors`, `plugins`, and `themes`
     * arrays with corresponding objects.
     */
    public function __construct()
    {
        $config = is_multisite()
            ? get_site_option($this->optionName)
            : get_option($this->optionName);

        // Retrieve and initialize connectors.
        $connectors = $config['connectors'] ?? [];
        $this->connectors = [];
        foreach ($connectors as $connector) {
            $connectorObj = Connector::createFromArray($connector);
            if ($connectorObj !== false) {
                $this->connectors[] = $connectorObj;
            }
        }

        // Retrieve and initialize plugins.
        $plugins = $config['plugins'] ?? [];
        $this->plugins = [];
        foreach ($plugins as $plugin) {
            $pluginObj = Plugin::createFromArray($plugin);
            $pluginObj->connector = $this->getConnectorById($pluginObj->connectorId);
            if ($pluginObj !== false) {
                $this->plugins[] = $pluginObj;
            }
        }

        // Retrieve and initialize themes.
        $themes = $config['themes'] ?? [];
        $this->themes = [];
        foreach ($themes as $theme) {
            $themeObj = Theme::createFromArray($theme);
            $themeObj->connector = $this->getConnectorById($themeObj->connectorId);
            if ($themeObj !== false) {
                $this->themes[] = $themeObj;
            }
        }
    }

    /**
     * Converts Settings to an Associative Array
     *
     * This method converts the `Settings` object and its properties to an associative array
     * for easy storage and retrieval in WordPress options.
     *
     * @return array An associative array representation of the `Settings` object.
     */
    public function asArray()
    {
        $array = [];

        foreach ($this->connectors as $connector) {
            $array['connectors'][] = $connector->asArray();
        }
        foreach ($this->plugins as $plugin) {
            $array['plugins'][] = $plugin->asArray();
        }
        foreach ($this->themes as $theme) {
            $array['themes'][] = $theme->asArray();
        }

        return $array;
    }

    /**
     * Saves Settings to WordPress Options
     *
     * This method saves the current `Settings` object to WordPress options based on
     * whether the environment is a multisite or single site.
     *
     * @return bool True if the settings were successfully saved, false otherwise.
     */
    public function save()
    {
        return is_multisite()
            ? update_site_option($this->optionName, $this->asArray())
            : update_option($this->optionName, $this->asArray());
    }

    /**
     * Retrieves a Connector Object by ID
     *
     * This method retrieves a `Connector` object from the `connectors` array based on its ID.
     *
     * @param int $id The ID of the connector to retrieve.
     * @return Connector|false The `Connector` object if found, false otherwise.
     */
    public function getConnectorById($id)
    {
        foreach ($this->connectors as $connector) {
            if ($connector->id === $id) {
                return $connector;
            }
        }
        return false;
    }

    /**
     * Retrieves a Plugin Object by ID
     *
     * This method retrieves a `Plugin` object from the `plugins` array based on its ID.
     *
     * @param int $id The ID of the plugin to retrieve.
     * @return object|boolean The `Plugin` object if found, false otherwise.
     */
    public function getPluginById($id)
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->id === $id) {
                return $plugin;
            }
        }
        return false;
    }

    /**
     * Retrieves a Plugin Object by Repository
     *
     * This method retrieves a `Plugin` object from the `plugins` array based on its repository.
     *
     * @param string $repository The repository of the plugin to retrieve.
     * @return object|boolean The `Plugin` object if found, false otherwise.
     */
    public function getPluginByRepository($repository)
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->repository === $repository) {
                return $plugin;
            }
        }
        return false;
    }

    /**
     * Retrieves a Theme Object by ID
     *
     * This method retrieves a `Theme` object from the `themes` array based on its ID.
     *
     * @param int $id The ID of the theme to retrieve.
     * @return object|boolean The `Theme` object if found, false otherwise.
     */
    public function getThemeById($id)
    {
        foreach ($this->themes as $theme) {
            if ($theme->id === $id) {
                return $theme;
            }
        }
        return false;
    }

    /**
     * Retrieves a Plugin Object by Repository
     *
     * This method retrieves a `Plugin` object from the `plugins` array based on its repository.
     *
     * @param string $repository The repository of the plugin to retrieve.
     * @return object|boolean The `Plugin` object if found, false otherwise.
     */
    public function getThemeByRepository($repository)
    {
        foreach ($this->themes as $theme) {
            if ($theme->repository === $repository) {
                return $theme;
            }
        }
        return false;
    }

    /**
     * Checks if a Connector is Used by Extensions
     *
     * This method checks if a connector with a given ID is used by any plugins or themes.
     *
     * @param int $id The ID of the connector to check.
     * @return bool True if the connector is used, false otherwise.
     */
    public function isConnectorUsed($id)
    {
        $extensions = array_merge($this->plugins, $this->themes);
        foreach ($extensions as $extension) {
            if ($extension->connectorId == $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieves Connector Repositories by ID
     *
     * This method retrieves connector repositories based on the connector ID.
     *
     * @param int $id The ID of the connector.
     * @return array An array of repository information.
     */
    public function getConnectorRepos($id)
    {
        $repos = [];
        $key = 0;
        foreach ($this->plugins as $extension) {
            if ($extension->connectorId == $id) {
                $repos[$key]['plugin'] = $extension->repository;
                $repos[$key]['owner'] = $extension->connector->owner;
                $repos[$key]['repository'] = $extension->repository;
                $repos[$key]['installationFolder'] = $extension->installationFolder;
                $repos[$key]['id'] = $extension->id;
                $repos[$key]['display'] = $extension->connector->display;
                $repos[$key]['branch'] = $extension->branch;
                $key++;
            }
        }

        foreach ($this->themes as $extension) {
            if ($extension->connectorId == $id) {
                $repos[$key]['theme'] = $extension->repository;
                $repos[$key]['owner'] = $extension->connector->owner;
                $repos[$key]['repository'] = $extension->repository;
                $repos[$key]['installationFolder'] = $extension->installationFolder;
                $repos[$key]['id'] = $extension->id;
                $repos[$key]['display'] = $extension->connector->display;
                $repos[$key]['branch'] = $extension->branch;
                $key++;
            }
        }

        return $repos;
    }

    /**
     * Retrieves the Count of Connector Repositories by ID
     *
     * This method counts the number of repositories associated with a connector ID.
     *
     * @param int $id The ID of the connector.
     * @return int The number of repositories associated with the connector.
     */
    public function getConnectorRepoCount($id)
    {
        $extensions = array_merge($this->plugins, $this->themes);
        $count = 0;
        foreach ($extensions as $extension) {
            if ($extension->connectorId == $id) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Deletes Unused Connectors
     *
     * This method deletes connectors that are not used by any extensions.
     */
    public function deleteUnusedConnectors()
    {
        foreach ($this->connectors as $connectorIndex => $connector) {
            if (!$this->isConnectorUsed($connector->id)) {
                unset($this->connectors[$connectorIndex]);
            }
        }
        $this->save();
    }
}

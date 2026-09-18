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
     * @var array General plugin options.
     */
    public $options;

    /**
     * @var string $optionName The name of the option used to store settings in WordPress.
     */
    protected $optionName;

    /** The snapshot this request actually read, used to detect its own edits. */
    private array $baseline;

    /**
     * Constructor to Initialize Settings
     *
     * This constructor initializes the `Settings` object by retrieving settings
     * data from WordPress options and populating the `connectors`, `plugins`, and `themes`
     * arrays with corresponding objects.
     */
    public function __construct()
    {
        $this->optionName = (new Config())->getOptionName();
        $this->load();
    }

    /** Discard rejected edits and adopt the current persisted registry and baseline. */
    public function reload(): void
    {
        // A failed lock acquisition may leave the request's original cache primed.
        $this->clearOptionCache();
        $this->load();
    }

    private function clearOptionCache(): void
    {
        if (is_multisite()) {
            foreach ([$this->optionName, 'notoptions'] as $key) {
                wp_cache_delete(get_current_network_id() . ':' . $key, 'site-options');
            }
        } else {
            foreach ([$this->optionName, 'alloptions', 'notoptions'] as $key) {
                wp_cache_delete($key, 'options');
            }
        }
    }

    private function load(): void
    {
        $config = is_multisite()
            ? get_site_option($this->optionName)
            : get_option($this->optionName);

        $config = is_array($config) ? $config : [];

        $this->options = wp_parse_args($config['options'] ?? [], (new Config())->getDefaultSettings());

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
        $this->baseline = $this->asArray();
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
        $array['options'] = $this->options;

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
        global $wpdb;
        // Every writer (cron, admin, CLI and bundles) uses this short-lived lock.
        // Keep it separate from the bundle filesystem lock; never hold it over HTTP.
        $scope = is_multisite() ? 'network:' . get_current_network_id() : 'site:' . get_current_blog_id();
        $lock = 'rrze_settings_' . md5(DB_NAME . '|' . $wpdb->base_prefix . '|' . $scope);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== '1') {
            return false;
        }
        try {
            $this->clearOptionCache();
            $local = $this->asArray();
            $latest = (new self())->asArray();
            $merged = $latest;
            foreach (['connectors', 'plugins', 'themes'] as $kind) {
                $records = $this->mergeChanges(
                    array_column($this->baseline[$kind] ?? [], null, 'id'),
                    array_column($local[$kind] ?? [], null, 'id'),
                    array_column($latest[$kind] ?? [], null, 'id'),
                    true
                );
                if ($records) {
                    $merged[$kind] = array_values($records);
                } else {
                    unset($merged[$kind]);
                }
            }
            $merged['options'] = $this->mergeChanges($this->baseline['options'], $local['options'], $latest['options']);
            $this->assertRegistryConsistency($merged);
            $saved = is_multisite()
                ? update_site_option($this->optionName, $merged)
                : update_option($this->optionName, $merged);
            if ($saved || $merged === $latest) {
                // Keep the local baseline: this object has not adopted remote edits.
                $this->baseline = $local;
                return true;
            }
            return false;
        } catch (\UnexpectedValueException $e) {
            // Concurrent edits to the same value require reloading, not data loss.
            return false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function mergeChanges(array $before, array $local, array $latest, bool $records = false): array
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($local))) as $key) {
            $had = array_key_exists($key, $before);
            $has = array_key_exists($key, $local);
            $exists = array_key_exists($key, $latest);
            if ($had === $has && (!$has || $before[$key] === $local[$key])) {
                continue;
            }
            if ($has === $exists && (!$has || $local[$key] === $latest[$key])) {
                continue;
            }
            if ($records && $had && $has && $exists) {
                $latest[$key] = $this->mergeChanges($before[$key], $local[$key], $latest[$key]);
                continue;
            }
            if ($had !== $exists || ($had && $before[$key] !== $latest[$key])) {
                throw new \UnexpectedValueException('Settings changed in another request.');
            }
            if ($has) {
                $latest[$key] = $local[$key];
            } else {
                unset($latest[$key]);
            }
        }
        return $latest;
    }

    /** Check relationships against the merged state while the save lock is held. */
    private function assertRegistryConsistency(array $settings): void
    {
        $connectors = array_column($settings['connectors'] ?? [], null, 'id');
        foreach (['plugins', 'themes'] as $kind) {
            $folders = $repositories = [];
            foreach ($settings[$kind] ?? [] as $extension) {
                $connector = $connectors[$extension['connectorId'] ?? ''] ?? null;
                if (!$connector) {
                    throw new \UnexpectedValueException('A repository references a removed connector.');
                }
                $folder = strtolower((string) ($extension['installationFolder'] ?? ''));
                $repository = (string) ($extension['repository'] ?? '');
                if (($connector['type'] ?? '') === 'github') {
                    $repository = strtolower($repository);
                }
                // Folder uniqueness follows preflight's case-insensitive checks.
                // A repository may only have one association per connector/type.
                $identity = json_encode([$extension['connectorId'], $repository]);
                if ($folder === '' || $repository === '' || isset($folders[$folder]) || isset($repositories[$identity])) {
                    throw new \UnexpectedValueException('Repository or installation folder has conflicting associations.');
                }
                $folders[$folder] = $repositories[$identity] = true;
            }
        }
    }

    /**
     * Checks whether informative log messages are enabled.
     *
     * @return bool Whether messages may be sent to the info log channel.
     */
    public function isInfoLoggingEnabled(): bool
    {
        return !empty($this->options['info_logging_enabled']);
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
                $repos[$key]['updates'] = $extension->updates;
                $repos[$key]['remoteVersion'] = $extension->remoteVersion;
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
                $repos[$key]['updates'] = $extension->updates;
                $repos[$key]['remoteVersion'] = $extension->remoteVersion;
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

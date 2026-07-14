<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use RRZE\Updater\Settings;
use RRZE\Updater\Controller;

use RRZE\Updater\Core\GithubConnector;
use RRZE\Updater\Core\GitlabConnector;
use RRZE\Updater\Core\Plugin;

use RRZE\Updater\Upgrader\PluginUpgraderSkin;
use RRZE\Updater\Upgrader\ThemeUpgraderSkin;

use Plugin_Upgrader_Skin;
use Theme_Upgrader_Skin;
use Bulk_Plugin_Upgrader_Skin;
use Bulk_Theme_Upgrader_Skin;
use WP_Ajax_Upgrader_Skin;
use WP_Error;

use stdClass;

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

    /**
     * The current extension type ('plugin' or 'theme') being operated on.
     *
     * @var string
     */
    public $currentExtension;

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

        if (!is_multisite()) {
            add_action('admin_menu', [$this, 'adminMenu']);
            add_filter('plugin_action_links', [$this, 'pluginActionLinks'], 10, 2);
        } else {
            add_action('network_admin_menu', [$this, 'adminMenu']);
            add_action('admin_bar_menu', [$this, 'adminBarMenu'], 100);
            add_filter('network_admin_plugin_action_links', [$this, 'pluginActionLinks'], 10, 2);
            add_filter('gettext', [$this, 'translateNetworkActivationLabel'], 10, 3);
        }

        // Set up filters and actions related to plugin and theme updates.
        add_filter('site_transient_update_plugins', [$this, 'siteTransientUpdatePlugins']);
        add_filter('site_transient_update_themes', [$this, 'siteTransientUpdateThemes']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'preSetSiteTransientUpdatePlugins']);
        add_filter('pre_set_site_transient_update_themes', [$this, 'preSetSiteTransientUpdateThemes']);
        add_filter('update_plugin_complete_actions', [$this, 'updatePluginCompleteActions'], 10, 2);

        // Download authenticated GitHub packages only when the upgrader actually needs them.
        add_filter('upgrader_pre_download', [$this, 'upgraderPreDownloadFilter'], 10, 4);

        // Set up a filter for modifying the source selection during plugin/theme updates.
        add_filter('upgrader_source_selection', [$this, 'upgraderSourceSelectionFilter'], 10, 4);

        // Set up a filter for post-installation actions during plugin/theme updates.
        add_filter('upgrader_post_install', [$this, 'upgraderPostInstallFilter'], 10, 3);

        // Set up a filter for modifying screen options.
        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);

        // Set up filters for modifying plugin and theme metadata.
        add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 2);
        add_filter('theme_row_meta', [$this, 'themeRowMeta'], 10, 2);
    }

    public function translateNetworkActivationLabel($translation, $text, $domain) {
        if (!is_network_admin()) {
            return $translation;
        }

        if (in_array($text, ['Activate Plugin', 'Network Activate'], true)) {
            return __('Plugin netzwerkweit aktivieren', 'rrze-updater');
        }

        if (in_array($text, ['Activate Theme', 'Network Enable'], true)) {
            return __('Theme netzwerkweit aktivieren', 'rrze-updater');
        }

        return $translation;
    }

    public function updatePluginCompleteActions(array $updateActions, string $pluginFile): array {
        if (!$this->isManagedPluginFile($pluginFile)) {
            return $updateActions;
        }

        $menuSettings = $this->config->getMenuSettings();
        $repositoriesSlug = $menuSettings['repositories_slug'] ?? 'rrze-updater';
        $url = is_multisite()
            ? network_admin_url('admin.php?page=' . $repositoriesSlug)
            : self_admin_url('admin.php?page=' . $repositoriesSlug);

        $updateActions['rrze_updater'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($url),
            esc_html__('Zurück zum Updater', 'rrze-updater')
        );

        return $updateActions;
    }

    private function isManagedPluginFile(string $pluginFile): bool {
        $pluginFileParts = explode('/', $pluginFile);
        $installationFolder = $pluginFileParts[0] ?? '';

        if ($installationFolder === '') {
            return false;
        }

        foreach ($this->settings->plugins as $plugin) {
            if ($plugin->installationFolder == $installationFolder) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a repositories link to the network administration admin bar menu.
     *
     * @param WP_Admin_Bar $wpAdminBar The WordPress admin bar instance.
     */
    public function adminBarMenu($wpAdminBar)
    {
        if (!is_multisite() || !current_user_can('manage_network')) {
            return;
        }

        $menuSettings = $this->config->getMenuSettings();

        $wpAdminBar->add_node([
            'id' => $menuSettings['admin_bar_repositories_id'] ?? 'rrze-updater-network-repositories',
            'parent' => $menuSettings['admin_bar_network_parent'] ?? 'network-admin',
            'title' => __('Updater', 'rrze-updater'),
            'href' => network_admin_url('admin.php?page=' . ($menuSettings['repositories_slug'] ?? 'rrze-updater'))
        ]);
    }

    /**
     * Initializes the RRZE Updater plugin's admin menu.
     *
     * This method is responsible for creating the plugin's menu and submenu pages in the WordPress admin interface.
     * It adds menu items for "Repositories," "Services," "Plugins," and "Themes," each with its respective subpage.
     * Additionally, it sets up screen options for each of these admin pages.
     */
    public function adminMenu()
    {
        $menuSettings = $this->config->getMenuSettings();
        $capability = $menuSettings['capability'] ?? 'manage_options';
        $repositoriesSlug = $menuSettings['repositories_slug'] ?? 'rrze-updater';
        $pluginsSlug = $menuSettings['plugins_slug'] ?? 'rrze-updater-plugins';
        $themesSlug = $menuSettings['themes_slug'] ?? 'rrze-updater-themes';
        $settingsSlug = $menuSettings['settings_slug'] ?? 'rrze-updater-settings';

        $repositoriesMenuTitle = __('Updater', 'rrze-updater') . $this->getRepositoryUpdatesBadge();

        // Add the main "Updater" menu page.
        $repoPage = add_menu_page(
            __('Updater', 'rrze-updater'),             // Page title
            $repositoriesMenuTitle,             // Menu title
            $capability,                      // Capability required to access
            $repositoriesSlug,                       // Menu slug
            [$this->controller, 'getRepoIndex'],            // Callback function for the page content
            'dashicons-update'                      // Dashicon icon
        );

        $pluginsPage = add_submenu_page(
            $repositoriesSlug,                      // Parent menu slug
            __('Plugins', 'rrze-updater'),                  // Page title
            __('Plugins', 'rrze-updater'),                  // Menu title
            $capability,                      // Capability required to access
            $pluginsSlug,                // Menu slug
            [$this->controller, 'getPluginIndex']           // Callback function for the page content
        );

        $themesPage = add_submenu_page(
            $repositoriesSlug,                      // Parent menu slug
            __('Themes', 'rrze-updater'),                   // Page title
            __('Themes', 'rrze-updater'),                   // Menu title
            $capability,                     // Capability required to access
            $themesSlug,                // Menu slug
            [$this->controller, 'getThemeIndex']            // Callback function for the page content
        );

        $settingsPage = add_submenu_page(
            $repositoriesSlug,
            __('Einstellungen', 'rrze-updater'),
            __('Einstellungen', 'rrze-updater'),
            $capability,
            $settingsSlug,
            [$this->controller, 'getSettingsIndex']
        );

        // Set up screen options for each admin page.
        add_action("load-$repoPage", [$this->controller, 'repoListScreenOptions']);
        add_action("load-$pluginsPage", [$this->controller, 'pluginsListScreenOptions']);
        add_action("load-$themesPage", [$this->controller, 'themesListScreenOptions']);
        add_action("load-$settingsPage", [$this->controller, 'settingsScreenOptions']);
    }

    private function getRepositoryUpdatesBadge(): string
    {
        $count = 0;

        foreach (array_merge($this->settings->plugins, $this->settings->themes) as $extension) {
            if ($this->extensionHasUpdate($extension)) {
                $count++;
            }
        }

        if ($count <= 0) {
            return '';
        }

        return sprintf(
            ' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>',
            $count
        );
    }

    private function extensionHasUpdate(object $extension): bool
    {
        return !empty($extension->remoteVersion)
            && $extension->remoteVersion != $extension->localVersion
            && empty($extension->lastError);
    }

    /**
     * Adjusts screen options for RRZE Updater plugin pages.
     *
     * This method is a callback used to modify screen options for RRZE Updater plugin pages.
     * It specifically targets the configured per-page option and returns the provided value.
     * By doing so, it allows users to configure the number of items displayed per page on RRZE Updater pages.
     *
     * @param mixed $status The current status of the option.
     * @param string $option The name of the option.
     * @param mixed $value The value of the option.
     *
     * @return mixed The modified value of the option or the current status.
     */
    public function setScreenOption($status, $option, $value)
    {
        if ($this->config->getScreenOptionPerPage() == $option) {
            return $value;
        }
        return $status;
    }

    /**
     * Filter for adding custom action links to plugin rows in the WordPress admin.
     *
     * This method is a filter used to add custom action links to plugin rows displayed
     * in the WordPress admin. It is specifically designed to add an "Edit repository"
     * link for plugins managed by the RRZE Updater plugin.
     *
     * @param array $actions An array of action links for the plugin.
     * @param string $pluginFile The path to the main plugin file.
     * @return array An updated array of action links for the plugin.
     */
    public function pluginActionLinks($actions, $pluginFile)
    {
        $pluginFileParts = explode('/', $pluginFile);

        // Iterate through custom plugins managed by RRZE Updater
        foreach ($this->settings->plugins as $customPlugin) {
            if ($customPlugin->installationFolder == $pluginFileParts[0]) {
                // This is one of our custom plugins :)
                // Add an "Edit repository" action link
                $action['edit-repository'] = sprintf(
                    '<a href="%1$s" title="%2$s" class="edit">%3$s</a>',
                    wp_nonce_url('admin.php?page=rrze-updater-plugins&action=edit&id=' . $customPlugin->id),
                    esc_attr__('Edit repository', 'rrze-updater'),
                    esc_html__('Edit repository', 'rrze-updater')
                );

                // Insert the custom action link at a specific position in the array
                array_splice($actions, 1, 0, $action);
            }
        }

        return $actions;
    }

    /**
     * Filters the site transient for plugin updates.
     *
     * This method is a filter used to modify the site transient data for plugin updates before it's returned to WordPress.
     * It primarily customizes the display of plugin update information in the WordPress admin by hooking into the
     * 'after_plugin_row_' action for RRZE Updater-managed plugins. It removes the default update action and adds a
     * custom action to display RRZE Updater-specific update information.
     *
     * @param object $transient The site transient data for plugin updates.
     * @return object Modified site transient data for plugin updates.
     */
    public function siteTransientUpdatePlugins($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $plugins = get_plugins();
        $pluginFiles = array_keys($plugins);

        foreach ($pluginFiles as $pluginFile) {
            foreach ($this->settings->plugins as $extension) {
                $pluginFileParts = explode('/', $pluginFile);

                if ($extension->installationFolder == $pluginFileParts[0]) {
                    // This is one of our custom plugins :)
                    remove_action('after_plugin_row_' . $pluginFile, 'wp_plugin_update_row');
                    add_action('after_plugin_row_' . $pluginFile, [$this, 'afterPluginRow'], 11, 2);
                    break;
                }
            }
        }

        return $transient;
    }

    /**
     * Filters the site transient for theme updates.
     *
     * This method is a filter used to modify the site transient data for theme updates. It customizes the update information
     * for RRZE Updater-managed themes by removing the default theme update response and adding a custom response to include
     * RRZE Updater-specific data for themes. This customization ensures that RRZE Updater-managed themes are properly
     * handled and displayed in the WordPress admin interface during the update process.
     *
     * @param object $transient The site transient data for theme updates.
     * @return object Modified site transient data for theme updates.
     */
    public function siteTransientUpdateThemes($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $themes = wp_get_themes();
        $themeFolders = array_keys($themes);

        foreach ($themeFolders as $themeFolder) {
            foreach ($this->settings->themes as $extension) {
                if ($extension->installationFolder == $themeFolder) {
                    // This is one of our custom themes :)
                    remove_action("after_theme_row_" . $themeFolder, 'wp_theme_update_row');
                    add_action("after_theme_row_" . $themeFolder, [$this, 'afterThemeRow'], 11, 2);
                    break;
                }
            }
        }

        return $transient;
    }

    /**
     * Filters the site transient for plugin updates before it's set.
     *
     * This method is a filter used to modify the site transient data for plugin updates before it's set in WordPress.
     * It customizes the update information for RRZE Updater-managed plugins, including removing the default update response
     * and adding a custom response to include RRZE Updater-specific data such as remote version and download URLs.
     *
     * @param object $transient The site transient data for plugin updates.
     * @return object Modified site transient data for plugin updates.
     */
    public function preSetSiteTransientUpdatePlugins($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $plugins = get_plugins();
        $pluginFiles = array_keys($plugins);

        foreach ($pluginFiles as $pluginFile) {
            foreach ($this->settings->plugins as $extension) {
                $pluginFileParts = explode('/', $pluginFile);

                if ($extension->installationFolder == $pluginFileParts[0]) {
                    // This is one of our custom plugins :)
                    if (
                        $extension->remoteVersion
                        && ($extension->remoteVersion != $extension->localVersion)
                    ) {
                        $response = new stdClass();
                        $response->id = $pluginFile;
                        $response->slug = $extension->installationFolder;
                        $response->plugin = $pluginFile;
                        $response->new_version = $extension->getRemoteVersionLabel();
                        $response->url = $extension->connector->getUrl($extension->repository);
                        $response->package = $extension->connector->downloadRepoZip($extension->repository, $extension->remoteVersion);
                        $response->icons = [];
                        $response->banners = [];
                        $response->banners_rtl = [];
                        $response->tested = '';
                        $response->requires_php = '';
                        $response->compatibility = new stdClass();

                        // Adding $response to the `no_update` property is required
                        // for the enable/disable auto-updates links to correctly appear in UI.
                        $transient->no_update[$pluginFile] = $response;

                        $transient->response[$pluginFile] = $response;
                    }
                    break;
                }
            }
        }

        return $transient;
    }

    /**
     * Filters the pre-set site transient for theme updates.
     *
     * This method is a filter used to modify the pre-set site transient data for theme updates. It customizes the update information
     * for RRZE Updater-managed themes by removing the default theme update response and adding a custom response to include
     * RRZE Updater-specific data for themes. This customization ensures that RRZE Updater-managed themes are properly
     * handled and displayed in the WordPress admin interface during the update process. It also includes data required
     * for enabling/disabling auto-updates links to appear correctly in the UI.
     *
     * @param object $transient The pre-set site transient data for theme updates.
     * @return object Modified pre-set site transient data for theme updates.
     */
    public function preSetSiteTransientUpdateThemes($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $themes = wp_get_themes();
        $themeFolders = array_keys($themes);

        foreach ($themeFolders as $themeFolder) {
            foreach ($this->settings->themes as $extension) {
                if ($extension->installationFolder == $themeFolder) {
                    // This is one of our custom themes :)
                    if (
                        $extension->remoteVersion
                        && ($extension->remoteVersion != $extension->localVersion)
                    ) {
                        $response = [];
                        $response['theme'] = $themeFolder;
                        $response['new_version'] = $extension->getRemoteVersionLabel();
                        $response['url'] = $extension->connector->getUrl($extension->repository);
                        $response['package'] = $extension->connector->downloadRepoZip($extension->repository, $extension->remoteVersion);
                        $response['requires'] = '';
                        $response['requires_php'] = '';

                        // Adding $response to the `no_update` property is required
                        // for the enable/disable auto-updates links to correctly appear in UI.
                        $transient->no_update[$themeFolder] = $response;

                        $transient->response[$themeFolder] = $response;
                    }
                    break;
                }
            }
        }

        return $transient;
    }

    /**
     * Customizes the display of update messages for RRZE Updater-managed plugins.
     *
     * This method is responsible for customizing the display of update messages for RRZE Updater-managed plugins in the
     * WordPress admin interface. It checks if there is an available update for a specific plugin and, if so, modifies the
     * update message to include information about the update and a link to update the plugin. The update message is displayed
     * in the plugins list table.
     *
     * @param string $file The path to the plugin's primary file relative to the plugins directory.
     * @param array $plugin_data An array of plugin metadata obtained using `get_plugin_data()`.
     * @return bool False if there is no available update for the plugin, true otherwise.
     */
    public function afterPluginRow($file, $plugin_data)
    {
        $current = get_site_transient('update_plugins');
        if (!isset($current->response[$file])) {
            return false;
        }

        $response = $current->response[$file];

        $plugins_allowedtags = [
            'a' => [
                'href' => [],
                'title' => []
            ],
            'abbr' => [
                'title' => []
            ],
            'acronym' => [
                'title' => []
            ],
            'code' => [],
            'em' => [],
            'strong' => [],
        ];

        $plugin_name = wp_kses($plugin_data['Name'], $plugins_allowedtags);

        $wpListTable = _get_list_table('WP_Plugins_List_Table');

        if (is_network_admin() || !is_multisite()) {
            if (is_network_admin()) {
                $activeClass = is_plugin_active_for_network($file) ? ' active' : '';
            } else {
                $activeClass = is_plugin_active($file) ? ' active' : '';
            }

            echo '<tr class="plugin-update-tr' . esc_attr($activeClass) . '" id="' . esc_attr($response->slug . '-update') . '" data-slug="' . esc_attr($response->slug) . '" data-plugin="' . esc_attr($file) . '"><td colspan="' . esc_attr($wpListTable->get_column_count()) . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>';

            if (!current_user_can('update_plugins')) {
                printf(
                    /* translators: 1: Extension name, 2: Version number */
                    esc_html__('There is a new version of %1$s available: %2$s.', 'rrze-updater'),
                    wp_kses_post($plugin_name),
                    esc_html($response->new_version)
                );
            } elseif (empty($response->package)) {
                printf(
                    /* translators: 1: Extension name, 2: Version number */
                    wp_kses_post(__('There is a new version of %1$s available: %2$s. <em>Automatic update is unavailable for this plugin.</em>', 'rrze-updater')),
                    wp_kses_post($plugin_name),
                    esc_html($response->new_version)
                );
            } else {
                printf(
                    /* translators: 1: Extension name, 2: Version number, 3: Update URL, 4: Additional link attributes */
                    wp_kses_post(__('There is a new version of %1$s available: %2$s. <a href="%3$s" %4$s>Update now</a>.', 'rrze-updater')),
                    wp_kses_post($plugin_name),
                    esc_html($response->new_version),
                    esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=') . $file, 'upgrade-plugin_' . $file)),
                    sprintf(
                        'class="update-link" aria-label="%s"',
                        sprintf(
                            /* translators: %s: Extension name */
                            esc_attr__('Update %s now', 'rrze-updater'),
                            esc_attr(wp_strip_all_tags($plugin_name))
                        )
                    )
                );
            }

            /**
             * Fires at the end of the update message container in each
             * row of the plugins list table.
             *
             * The dynamic portion of the hook name, `$file`, refers to the path
             * of the plugin's primary file relative to the plugins directory.
             *
             * @since 2.8.0
             *
             * @param array  $plugin_data An array of plugin metadata. See get_plugin_data()
             *                            and the {@see 'plugin_row_meta'} filter for the list
             *                            of possible values.
             * @param object $response {
             *     An object of metadata about the available plugin update.
             *
             *     @type string   $id           Plugin ID, e.g. `w.org/plugins/[plugin-name]`.
             *     @type string   $slug         Plugin slug.
             *     @type string   $plugin       Plugin basename.
             *     @type string   $new_version  New plugin version.
             *     @type string   $url          Plugin URL.
             *     @type string   $package      Plugin update package URL.
             *     @type string[] $icons        An array of plugin icon URLs.
             *     @type string[] $banners      An array of plugin banner URLs.
             *     @type string[] $banners_rtl  An array of plugin RTL banner URLs.
             *     @type string   $requires     The version of WordPress which the plugin requires.
             *     @type string   $tested       The version of WordPress the plugin is tested against.
             *     @type string   $requires_php The version of PHP which the plugin requires.
             * }
             */
            do_action("in_plugin_update_message-{$file}", $plugin_data, $response); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

            echo '</p></div></td></tr>';
        }
    }

    /**
     * Customizes the display of update messages for RRZE Updater-managed themes.
     *
     * This method is responsible for customizing the display of update messages for RRZE Updater-managed themes in the WordPress
     * admin interface. It checks if there is an available update for a specific theme and, if so, modifies the update message
     * to include information about the update and a link to update the theme. The update message is displayed in the themes list table.
     *
     * @param string $theme_key The theme slug as found in the WordPress.org themes repository.
     * @param WP_Theme $theme The WP_Theme object representing the theme.
     * @return bool False if there is no available update for the theme, true otherwise.
     */
    public function afterThemeRow($theme_key, $theme)
    {
        $current = get_site_transient('update_themes');

        if (!isset($current->response[$theme_key])) {
            return false;
        }

        $response = $current->response[$theme_key];

        $wpListTable = _get_list_table('WP_MS_Themes_List_Table');

        $activeClass = $theme->is_allowed('network') ? ' active' : '';

        echo '<tr class="plugin-update-tr' . esc_attr($activeClass) . '" id="' . esc_attr($theme->get_stylesheet() . '-update') . '" data-slug="' . esc_attr($theme->get_stylesheet()) . '"><td colspan="' . esc_attr($wpListTable->get_column_count()) . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>';
        if (!current_user_can('update_themes')) {
            printf(
                /* translators: 1: Extension name, 2: Version number */
                esc_html__('There is a new version of %1$s available: %2$s.', 'rrze-updater'),
                esc_html($theme['Name']),
                esc_html($response['new_version'])
            );
        } elseif (empty($response['package'])) {
            printf(
                /* translators: 1: Extension name, 2: Version number */
                wp_kses_post(__('There is a new version of %1$s available: %2$s. <em>Automatic update is unavailable for this theme.</em>', 'rrze-updater')),
                esc_html($theme['Name']),
                esc_html($response['new_version'])
            );
        } else {
            printf(
                /* translators: 1: Extension name, 2: Version number, 3: Update URL, 4: Additional link attributes */
                wp_kses_post(__('There is a new version of %1$s available: %2$s. <a href="%3$s" %4$s>Update now</a>.', 'rrze-updater')),
                esc_html($theme['Name']),
                esc_html($response['new_version']),
                esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-theme&theme=') . $theme_key, 'upgrade-theme_' . $theme_key)),
                sprintf(
                    'class="update-link" aria-label="%s"',
                    esc_attr(
                        sprintf(
                            /* translators: %s: Extension name */
                            __('Update %s now', 'rrze-updater'),
                            esc_html($theme['Name'])
                        )
                    )
                )
            );
        }

        /**
         * Fires at the end of the update message container in each
         * row of the themes list table.
         *
         * The dynamic portion of the hook name, `$theme_key`, refers to
         * the theme slug as found in the WordPress.org themes repository.
         *
         * @since 3.1.0
         *
         * @param WP_Theme $theme    The WP_Theme object.
         * @param array    $response {
         *     An array of metadata about the available theme update.
         *
         *     @type string $new_version New theme version.
         *     @type string $url         Theme URL.
         *     @type string $package     Theme update package URL.
         * }
         */
        do_action("in_theme_update_message-{$theme_key}", $theme, $response); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

        echo '</p></div></td></tr>';
    }

    /**
     * Customizes the metadata displayed for RRZE Updater-managed plugins.
     *
     * This method customizes the metadata displayed for RRZE Updater-managed plugins in the WordPress admin interface. It modifies the
     * metadata to include additional information such as the branch, repository URL, and installation folder for each managed plugin.
     *
     * @param array $pluginMeta An array of plugin metadata, typically including the plugin version and other details.
     * @param string $pluginFile The path to the main plugin file relative to the plugins directory.
     * @return array The modified array of plugin metadata.
     */
    public function pluginRowMeta($pluginMeta, $pluginFile)
    {
        foreach ($this->settings->plugins as $key => $extension) {
            $strpos = strpos($pluginFile, $extension->installationFolder);
            if ($strpos === 0) {
                $version = $pluginMeta[0];
                unset($pluginMeta);
                $pluginMeta[] = $version;
                $pluginMeta[] = sprintf(
                    /* translators: %s: Repository branch name */
                    esc_html__('Branch %s', 'rrze-updater'),
                    esc_html(mb_strimwidth($extension->branch, 0, 20, '...'))
                );
                $pluginMeta[] = sprintf(
                    '%s <a href="%s" aria-label="%s">%s</a>',
                    esc_html__('Repository', 'rrze-updater'),
                    esc_url(sprintf(
                        /* translators: 1: Repository url, 2: Repository local version */
                        '%1$s/tree/%2$s',
                        $extension->connector->getUrl($extension->repository),
                        $extension->localVersion
                    )),
                    esc_attr(__('Repository', 'rrze-updater')),
                    esc_html(preg_replace("#^[^:/.]*[:/]+#i", "", $extension->connector->getUrl($extension->repository)))
                );
                $pluginMeta[] = sprintf(
                    /* translators: %s: Installation folder path */
                    esc_html__('Folder %s', 'rrze-updater'),
                    esc_html($extension->installationFolder)
                );
            }
        }
        return $pluginMeta;
    }

    /**
     * Customizes the metadata displayed for RRZE Updater-managed themes.
     *
     * This method customizes the metadata displayed for RRZE Updater-managed themes in the WordPress admin interface. It modifies the
     * metadata to include additional information such as the branch, repository URL, and installation folder for each managed theme.
     *
     * @param array $themeMeta An array of theme metadata, typically including the theme version and other details.
     * @param string $stylesheet The stylesheet name (directory name) of the theme being displayed.
     * @return array The modified array of theme metadata.
     */
    public function themeRowMeta($themeMeta, $stylesheet)
    {
        foreach ($this->settings->themes as $key => $extension) {
            if ($extension->installationFolder == $stylesheet) {
                $version = $themeMeta[0];
                unset($themeMeta);
                $themeMeta[] = $version;
                $themeMeta[] = sprintf(
                    /* translators: %s: Branch name */
                    esc_html__('Branch %s', 'rrze-updater'),
                    esc_html(mb_strimwidth($extension->branch, 0, 20, '...'))
                );
                $themeMeta[] = sprintf(
                    '%s <a href="%s" aria-label="%s">%s</a>',
                    esc_html__('Repository', 'rrze-updater'),
                    esc_url(sprintf('%s/tree/%s', $extension->connector->getUrl($extension->repository), $extension->localVersion)),
                    esc_attr(__('Repository', 'rrze-updater')),
                    esc_html(preg_replace("#^[^:/.]*[:/]+#i", "", $extension->connector->getUrl($extension->repository)))
                );
                $themeMeta[] = sprintf(
                    /* translators: %s: Theme folder name */
                    esc_html__('Folder %s', 'rrze-updater'),
                    esc_html($extension->installationFolder)
                );
            }
        }
        return $themeMeta;
    }

    /**
     * Filter for selecting the source location during the upgrade process.
     *
     * This method is a filter used to determine the source location for an upgrade process.
     * It is responsible for handling different types of upgrades, such as plugins, themes, and bulk actions.
     * The method computes the new source location based on the provided parameters and moves the files accordingly.
     * Additionally, it sets the current extension type ('plugin' or 'theme') based on the upgrade context.
     *
     * @param string|WP_Error $source The source location before the upgrade.
     * @param string $remoteSource The remote source location.
     * @param WP_Upgrader $upgrader The upgrader instance performing the upgrade.
     * @param array $hookExtra Additional upgrade data, such as plugin or theme information.
     * @return string|WP_Error The new source location after the upgrade or a WP_Error object on failure.
     */
    public function upgraderSourceSelectionFilter($source, $remoteSource, $upgrader, $hookExtra)
    {
        global $wp_filesystem;

        if ($upgrader->skin instanceof PluginUpgraderSkin) {
            // Handle plugin upgrades
            $newSource = trailingslashit($remoteSource) . trailingslashit($upgrader->skin->extension->installationFolder);

            if (!$newSource) {
                return new WP_Error();
            }
            if (($newSource != $source) && !$wp_filesystem->move($source, $newSource, true)) {
                return new WP_Error();
            }
            $this->currentExtension = 'plugin';

            return $newSource;
        } elseif ($upgrader->skin instanceof ThemeUpgraderSkin) {
            // Handle theme upgrades
            $newSource = trailingslashit($remoteSource) . trailingslashit($upgrader->skin->extension->installationFolder);
            if (!$newSource) {
                return new WP_Error();
            }
            if (($newSource != $source) && !$wp_filesystem->move($source, $newSource, true)) {
                return new WP_Error();
            }
            $this->currentExtension = 'theme';

            return $newSource;
        } elseif ($upgrader->skin instanceof Plugin_Upgrader_Skin) {
            // Handle plugin upgrades (alternative)
            $pluginFileParts = explode('/', $upgrader->skin->plugin);
            $newSource = trailingslashit($remoteSource) . trailingslashit($pluginFileParts[0]);

            if (!$newSource) {
                return new WP_Error();
            }
            if (($newSource != $source) && !$wp_filesystem->move($source, $newSource, true)) {
                return new WP_Error();
            }
            $this->currentExtension = 'plugin';

            return $newSource;
        } elseif ($upgrader->skin instanceof Theme_Upgrader_Skin) {
            // Handle theme upgrades (alternative)
            $newSource = trailingslashit($remoteSource) . trailingslashit($upgrader->skin->theme);
            if (!$newSource) {
                return new WP_Error();
            }
            if (!$wp_filesystem->move($source, $newSource, true)) {
                return new WP_Error();
            }
            $this->currentExtension = 'theme';

            return $newSource;
        } elseif (
            ($upgrader->skin instanceof Bulk_Plugin_Upgrader_Skin
                || $upgrader->skin instanceof WP_Ajax_Upgrader_Skin)
            && !empty($upgrader->skin->plugin_info['Name'])
        ) {
            // Handle bulk plugin upgrades
            $newSource = false;
            $installedPlugins = get_plugins();

            foreach ($installedPlugins as $pluginFile => $plugin_info) {
                if (($plugin_info['Name'] == $upgrader->skin->plugin_info['Name']) || (isset($hookExtra['plugin']) && $pluginFile == $hookExtra['plugin'])) {
                    $pluginFileParts = explode('/', $pluginFile);
                    $newSource = trailingslashit($remoteSource) . trailingslashit($pluginFileParts[0]);
                    if (($newSource != $source) && !$wp_filesystem->move($source, $newSource, true)) {
                        return new WP_Error();
                    }
                    break;
                }
            }

            if (!$newSource) {
                return new WP_Error();
            }
            $this->currentExtension = 'plugin';

            return $newSource;
        } elseif (
            ($upgrader->skin instanceof Bulk_Theme_Upgrader_Skin
                || $upgrader->skin instanceof WP_Ajax_Upgrader_Skin)
            && !empty($upgrader->skin->theme_info['Name'])
        ) {
            // Handle bulk theme upgrades
            $newSource = false;
            $installedThemes = wp_get_themes();

            foreach ($installedThemes as $themeFolder => $themeInfo) {
                if ($themeInfo['Name'] == $upgrader->skin->theme_info['Name'] && $themeFolder == $upgrader->skin->theme_info->stylesheet) {
                    $newSource = trailingslashit($remoteSource) . trailingslashit($themeFolder);
                    if (($newSource != $source) && !$wp_filesystem->move($source, $newSource, true)) {
                        return new WP_Error();
                    }
                    break;
                }
            }

            if (!$newSource) {
                return new WP_Error();
            }
            $this->currentExtension = 'theme';

            return $newSource;
        }

        return $source;
    }

    public function upgraderPreDownloadFilter($reply, $package, $upgrader, $hookExtra)
    {
        if (false !== $reply || !is_string($package)) {
            return $reply;
        }

        $pluginExtension = $this->getPluginExtensionForUpgrade($upgrader, $hookExtra);
        if ($pluginExtension) {
            $validation = $this->validatePluginRepositoryForUpgrade($pluginExtension);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }

        $extension = $this->getGithubExtensionForUpgrade($upgrader, $hookExtra);
        if (!$extension) {
            return false;
        }

        $refs = array_filter(array_unique([
            $extension->remoteVersion ?? '',
            $extension->branch ?? '',
            'main'
        ]));

        foreach ($refs as $ref) {
            if ($package !== $extension->connector->downloadRepoZip($extension->repository, $ref)) {
                continue;
            }

            $download = $extension->connector->downloadRepoZipToTempFile($extension->repository, $ref);
            if (!$download) {
                do_action(
                    'rrze.log.error',
                    'Download failed for {repository} at ref {ref}.',
                    [
                        'plugin' => $this->config->getLogPlugin(),
                        'repository' => $extension->repository,
                        'ref' => $ref,
                        'service' => $extension->connector->display ?? '',
                        'error' => $extension->connector->error ?: __('Download failed.', 'rrze-updater')
                    ]
                );

                return new WP_Error(
                    'download_failed',
                    $extension->connector->error ?: __('Download failed.', 'rrze-updater')
                );
            }

            return $download;
        }

        return false;
    }

    private function validatePluginRepositoryForUpgrade(Plugin $extension): bool|WP_Error
    {
        $ref = $extension->remoteVersion ?: ($extension->branch ?: 'main');
        $validation = $extension->validateRemotePluginRepository($ref);
        if (!is_wp_error($validation)) {
            return true;
        }

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

    private function getPluginExtensionForUpgrade($upgrader, array $hookExtra): Plugin|bool
    {
        if (
            $upgrader->skin instanceof PluginUpgraderSkin
            && $upgrader->skin->extension instanceof Plugin
        ) {
            return $upgrader->skin->extension;
        }

        if (($hookExtra['type'] ?? '') == 'plugin' && !empty($hookExtra['plugin'])) {
            $pluginFileParts = explode('/', $hookExtra['plugin']);
            $installationFolder = $pluginFileParts[0];

            foreach ($this->settings->plugins as $extension) {
                if (
                    $extension instanceof Plugin
                    && $extension->installationFolder == $installationFolder
                ) {
                    return $extension;
                }
            }
        }

        return false;
    }

    private function getGithubExtensionForUpgrade($upgrader, array $hookExtra)
    {
        if (
            ($upgrader->skin instanceof PluginUpgraderSkin
                || $upgrader->skin instanceof ThemeUpgraderSkin)
            && $upgrader->skin->extension->connector instanceof GithubConnector
        ) {
            return $upgrader->skin->extension;
        }

        if (($hookExtra['type'] ?? '') == 'plugin' && !empty($hookExtra['plugin'])) {
            $pluginFileParts = explode('/', $hookExtra['plugin']);
            $installationFolder = $pluginFileParts[0];

            foreach ($this->settings->plugins as $extension) {
                if (
                    $extension->installationFolder == $installationFolder
                    && $extension->connector instanceof GithubConnector
                ) {
                    return $extension;
                }
            }
        }

        if (($hookExtra['type'] ?? '') == 'theme' && !empty($hookExtra['theme'])) {
            foreach ($this->settings->themes as $extension) {
                if (
                    $extension->installationFolder == $hookExtra['theme']
                    && $extension->connector instanceof GithubConnector
                ) {
                    return $extension;
                }
            }
        }

        return false;
    }

    /**
     * Add a filter for the upgrader_post_install hook.
     *
     * This method adds a filter to the upgrader_post_install hook, which is triggered after a plugin or theme installation/update.
     * It allows updating the local version of the installed/updated extension in the settings object.
     *
     * @param int    $response     The response code (1 for success, 0 for failure).
     * @param array  $hookExtra    Additional data about the installation/update hook.
     * @param object $result       The result object of the installation/update operation.
     *
     * @return object $result      The updated result object of the installation/update operation.
     */
    public function upgraderPostInstallFilter($response, $hookExtra, $result)
    {
        if ($response == 1) {
            // Check the current extension type ('plugin' or 'theme').
            if ($this->currentExtension == 'plugin') {
                // Iterate through the plugins in settings.
                foreach ($this->settings->plugins as $key => $plugin) {
                    // Check if the destination folder matches the plugin's installation folder.
                    if ($result['destination_name'] == $plugin->installationFolder) {
                        // Update the local version of the plugin in settings.
                        $this->settings->plugins[$key]->localVersion = $this->settings->plugins[$key]->remoteVersion;
                        $this->settings->save();
                        $this->logSuccessfulUpdate('plugin', $plugin);
                        break;
                    }
                }
            }

            if ($this->currentExtension == 'theme') {
                // Iterate through the themes in settings.
                foreach ($this->settings->themes as $key => $theme) {
                    // Check if the destination folder matches the theme's installation folder.
                    if ($result['destination_name'] == $theme->installationFolder) {
                        // Update the local version of the theme in settings.
                        $this->settings->themes[$key]->localVersion = $this->settings->themes[$key]->remoteVersion;
                        $this->settings->save();
                        $this->logSuccessfulUpdate('theme', $theme);
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Logs a successful repository update.
     *
     * @param string $type The extension type.
     * @param object $extension The updated extension.
     */
    private function logSuccessfulUpdate(string $type, object $extension)
    {
        $admin = $this->getCurrentAdminContext();
        $context = [
            'plugin' => $this->config->getLogPlugin(),
            'extension-type' => $type,
            'repository' => $extension->repository ?? '',
            'installation-folder' => $extension->installationFolder ?? '',
            'version' => method_exists($extension, 'getReadableRemoteVersion') ? $extension->getReadableRemoteVersion() : ($extension->remoteVersion ?? ''),
            'git-version' => $extension->remoteVersion ?? '',
            'branch' => $extension->branch ?? '',
            'service' => isset($extension->connector) ? $extension->connector->display : '',
            'admin-id' => $admin['id'],
            'admin-login' => $admin['login'],
            'admin-email' => $admin['email']
        ];

        do_action(
            'rrze.log.info',
            'Updated {extension-type} {repository} to {version}. Git ref: {git-version}. Admin: {admin-login}',
            $context
        );
    }

    /**
     * Returns context for the current admin user.
     *
     * @return array Admin context.
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

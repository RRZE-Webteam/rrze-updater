<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

/** WordPress admin menus, notices, links and managed extension presentation. */
class AdminIntegration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Controller $controller,
        private readonly Config $config
    ) {}

    public function register(): void
    {
        if (!is_multisite()) {
            add_action('admin_menu', [$this, 'adminMenu']);
            add_action('admin_notices', [$this, 'invalidTokenNotice']);
            add_filter('plugin_action_links', [$this, 'pluginActionLinks'], 10, 2);
        } else {
            add_action('network_admin_menu', [$this, 'adminMenu']);
            add_action('network_admin_notices', [$this, 'invalidTokenNotice']);
            add_action('admin_bar_menu', [$this, 'adminBarMenu'], 100);
            add_filter('network_admin_plugin_action_links', [$this, 'pluginActionLinks'], 10, 2);
            add_filter('gettext', [$this, 'translateNetworkActivationLabel'], 10, 3);
        }

        add_filter('site_transient_update_plugins', [$this, 'registerPluginUpdateRows']);
        add_filter('site_transient_update_themes', [$this, 'registerThemeUpdateRows']);
        add_filter('update_plugin_complete_actions', [$this, 'updatePluginCompleteActions'], 10, 2);
        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);
        add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 2);
        add_filter('theme_row_meta', [$this, 'themeRowMeta'], 10, 2);
    }

    /** Show rejected-token notices only on an authorized dashboard. */
    public function invalidTokenNotice(): void
    {
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        if (!current_user_can($capability)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['dashboard', 'dashboard-network'], true)) {
            return;
        }

        $notices = TokenNotice::getActive($this->settings);
        if (empty($notices)) {
            return;
        }

        $settingsSlug = $this->config->getMenuSettings()['settings_slug'] ?? 'rrze-updater-settings';
        $settingsUrl = is_multisite()
            ? network_admin_url('admin.php?page=' . $settingsSlug . '&tab=services')
            : self_admin_url('admin.php?page=' . $settingsSlug . '&tab=services');

        foreach ($notices as $notice) {
            $service = (string) ($notice['service'] ?? '');
            $owner = (string) ($notice['owner'] ?? '');
            $httpCode = absint($notice['http-code'] ?? 0);
            $message = sprintf(
                /* translators: 1: Service name, 2: User or group, 3: HTTP response code */
                __('The token for %1$s / %2$s was rejected while checking for updates (HTTP %3$d). Please check and replace the token in the service settings.', 'rrze-updater'),
                $service,
                $owner,
                $httpCode
            );

            printf(
                '<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
                esc_html($message),
                esc_url($settingsUrl),
                esc_html__('Open services', 'rrze-updater')
            );
        }
    }

    /** Keep network activation labels consistent in the admin UI. */
    public function translateNetworkActivationLabel($translation, $text, $domain)
    {
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

    /** Link managed plugin updates back to the repository list. */
    public function updatePluginCompleteActions(array $updateActions, string $pluginFile): array
    {
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

    private function isManagedPluginFile(string $pluginFile): bool
    {
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

    /** Add the network-admin shortcut for authorized users. */
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

    /** Register menu pages and their screen-option callbacks. */
    public function adminMenu()
    {
        $menuSettings = $this->config->getMenuSettings();
        $capability = $menuSettings['capability'] ?? 'manage_options';
        $repositoriesSlug = $menuSettings['repositories_slug'] ?? 'rrze-updater';
        $pluginsSlug = $menuSettings['plugins_slug'] ?? 'rrze-updater-plugins';
        $themesSlug = $menuSettings['themes_slug'] ?? 'rrze-updater-themes';
        $settingsSlug = $menuSettings['settings_slug'] ?? 'rrze-updater-settings';

        $repositoriesMenuTitle = __('Updater', 'rrze-updater') . $this->getRepositoryUpdatesBadge();

        $repoPage = add_menu_page(
            __('Updater', 'rrze-updater'),
            $repositoriesMenuTitle,
            $capability,
            $repositoriesSlug,
            [$this->controller, 'getRepoIndex'],
            'dashicons-update'
        );

        $pluginsPage = add_submenu_page(
            $repositoriesSlug,
            __('Plugins', 'rrze-updater'),
            __('Plugins', 'rrze-updater'),
            $capability,
            $pluginsSlug,
            [$this->controller, 'getPluginIndex']
        );

        $themesPage = add_submenu_page(
            $repositoriesSlug,
            __('Themes', 'rrze-updater'),
            __('Themes', 'rrze-updater'),
            $capability,
            $themesSlug,
            [$this->controller, 'getThemeIndex']
        );

        $settingsPage = add_submenu_page(
            $repositoriesSlug,
            __('Einstellungen', 'rrze-updater'),
            __('Einstellungen', 'rrze-updater'),
            $capability,
            $settingsSlug,
            [$this->controller, 'getSettingsIndex']
        );

        add_action("load-$repoPage", [$this->controller, 'repoListScreenOptions']);
        add_action("load-$repoPage", [$this, 'enqueueUpdateCheckScript']);
        add_action("load-$pluginsPage", [$this->controller, 'pluginsListScreenOptions']);
        add_action("load-$themesPage", [$this->controller, 'themesListScreenOptions']);
        add_action("load-$settingsPage", [$this->controller, 'settingsScreenOptions']);
    }

    public function enqueueUpdateCheckScript(): void
    {
        $root = dirname(__DIR__);
        wp_enqueue_script('rrze-updater-check-runner', plugins_url('assets/js/update-check-runner.js', $root . '/rrze-updater.php'),
            [], (string) filemtime($root . '/assets/js/update-check-runner.js'), false);
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

    /** Leave screen options belonging to other plugins untouched. */
    public function setScreenOption($status, $option, $value)
    {
        if ($this->config->getScreenOptionPerPage() == $option) {
            return $value;
        }
        return $status;
    }

    /** Add the repository editor link for managed plugins. */
    public function pluginActionLinks($actions, $pluginFile)
    {
        $pluginFileParts = explode('/', $pluginFile);

        foreach ($this->settings->plugins as $customPlugin) {
            if ($customPlugin->installationFolder == $pluginFileParts[0]) {
                $action['edit-repository'] = sprintf(
                    '<a href="%1$s" title="%2$s" class="edit">%3$s</a>',
                    wp_nonce_url('admin.php?page=rrze-updater-plugins&action=edit&id=' . $customPlugin->id),
                    esc_attr__('Edit repository', 'rrze-updater'),
                    esc_html__('Edit repository', 'rrze-updater')
                );

                array_splice($actions, 1, 0, $action);
            }
        }

        return $actions;
    }

    /** Register managed plugin rows without changing update metadata. */
    public function registerPluginUpdateRows($transient)
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
                    remove_action('after_plugin_row_' . $pluginFile, 'wp_plugin_update_row');
                    add_action('after_plugin_row_' . $pluginFile, [$this, 'afterPluginRow'], 11, 2);
                    break;
                }
            }
        }

        return $transient;
    }

    /** Register managed theme rows without changing update metadata. */
    public function registerThemeUpdateRows($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $themes = wp_get_themes();
        $themeFolders = array_keys($themes);

        foreach ($themeFolders as $themeFolder) {
            foreach ($this->settings->themes as $extension) {
                if ($extension->installationFolder == $themeFolder) {
                    remove_action("after_theme_row_" . $themeFolder, 'wp_theme_update_row');
                    add_action("after_theme_row_" . $themeFolder, [$this, 'afterThemeRow'], 11, 2);
                    break;
                }
            }
        }

        return $transient;
    }

    /** Render the WordPress-compatible managed plugin update row. */
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

            require __DIR__ . '/views/admin/plugin-update-row.php';
        }
    }

    /** Render the WordPress-compatible managed theme update row. */
    public function afterThemeRow($theme_key, $theme)
    {
        $current = get_site_transient('update_themes');

        if (!isset($current->response[$theme_key])) {
            return false;
        }

        $response = $current->response[$theme_key];

        $wpListTable = _get_list_table('WP_MS_Themes_List_Table');

        $activeClass = $theme->is_allowed('network') ? ' active' : '';

        require __DIR__ . '/views/admin/theme-update-row.php';
    }

    public function pluginRowMeta($pluginMeta, $pluginFile)
    {
        foreach ($this->settings->plugins as $key => $extension) {
            $strpos = strpos($pluginFile, $extension->installationFolder);
            if ($strpos === 0) {
                $version = $pluginMeta[0];
                unset($pluginMeta);
                $pluginMeta[] = $version;
                $pluginMeta[] = sprintf(
                    /* translators: %s: Branch name */
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
                    /* translators: %s: Installation folder name */
                    esc_html__('Folder %s', 'rrze-updater'),
                    esc_html($extension->installationFolder)
                );
            }
        }
        return $pluginMeta;
    }

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
                    /* translators: %s: Installation folder name */
                    esc_html__('Folder %s', 'rrze-updater'),
                    esc_html($extension->installationFolder)
                );
            }
        }
        return $themeMeta;
    }
}

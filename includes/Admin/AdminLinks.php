<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Settings, InstalledExtensions};
use RRZE\Updater\Core\{Plugin, Theme};

/** Builds administrative URLs and detects optional administrative tools. */
class AdminLinks
{
    /**
     * Inject the concrete dependencies used by this administrative component.
     *
     * @param Settings $settings Shared registry and user settings.
     * @param InstalledExtensions $installed Local extension metadata reader.
     */
    public function __construct(
        private Settings $settings,
        private InstalledExtensions $installed
    ) {
    }

    /**
     * Build the native single-plugin update URL and nonce.
     *
     * @param Plugin $extension Managed plugin.
     * @return string Formatted value or empty when unavailable.
     */
    public function getPluginUpdateUrl(Plugin $extension): string
    {
        $pluginFile = $this->installed->getPluginFile($extension->installationFolder, $extension->repository);

        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=') . $pluginFile,
            'upgrade-plugin_' . $pluginFile
        );
    }

    /**
     * Build the Plugin Check link when the tool and entry point are available.
     *
     * @param Plugin $extension Managed plugin.
     * @return string Formatted value or empty when unavailable.
     */
    public function getPluginCheckUrl(Plugin $extension): string
    {
        if (!$this->isPluginCheckAvailable()) {
            return '';
        }

        $pluginFile = $this->installed->getPluginFile($extension->installationFolder, $extension->repository);
        if (!$pluginFile) {
            return '';
        }

        return (string) add_query_arg(
            [
                'page' => 'plugin-check',
                'plugin' => $pluginFile
            ],
            admin_url('tools.php')
        );
    }

    /**
     * Detect an installed and active Plugin Check tool.
     *
     * @return bool Whether Plugin Check is installed and active locally or network-wide.
     */
    public function isPluginCheckAvailable(): bool
    {
        return $this->isToolActive('plugin-check/plugin.php');
    }

    /**
     * Build the plugin overview link when Multisite Manager is active.
     *
     * @return string Formatted value or empty when unavailable.
     */
    public function getMultisiteManagerPluginsUrl(): string
    {
        if (!$this->isToolActive('rrze-multisite-manager/rrze-multisite-manager.php')) {
            return '';
        }
        return (string) add_query_arg(['page' => 'rrze-multisite-manager-plugin-overview'], admin_url('admin.php'));
    }

    /**
     * Build the plugin details link for an available Multisite Manager.
     *
     * @param Plugin $extension Managed plugin.
     * @return string Formatted value or empty when unavailable.
     */
    public function getMultisiteManagerPluginDetailsUrl(Plugin $extension): string
    {
        if ($this->getMultisiteManagerPluginsUrl() === '') {
            return '';
        }

        $pluginFile = $this->installed->getPluginFile($extension->installationFolder, $extension->repository);
        if (!$pluginFile) {
            return '';
        }

        return (string) add_query_arg(
            [
                'page' => 'rrze-multisite-manager-plugin-details',
                'plugin' => $pluginFile
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Build the theme overview link when Multisite Manager is active.
     *
     * @return string Formatted value or empty when unavailable.
     */
    public function getMultisiteManagerThemesUrl(): string
    {
        if (!$this->isToolActive('rrze-multisite-manager/rrze-multisite-manager.php')) {
            return '';
        }
        return (string) add_query_arg(['page' => 'rrze-multisite-manager-theme-overview'], admin_url('admin.php'));
    }

    /**
     * Build the theme details link for an available Multisite Manager.
     *
     * @param Theme $extension Managed theme.
     * @return string Formatted value or empty when unavailable.
     */
    public function getMultisiteManagerThemeDetailsUrl(Theme $extension): string
    {
        if ($this->getMultisiteManagerThemesUrl() === '') {
            return '';
        }

        $stylesheet = trim((string) $extension->installationFolder);
        if ($stylesheet === '') {
            return '';
        }

        return (string) add_query_arg(
            [
                'page' => 'rrze-multisite-manager-theme-details',
                'theme' => $stylesheet
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Build the nonce-protected Theme Check form handoff URL.
     *
     * @param Theme $extension Managed theme.
     * @return string Formatted value or empty when unavailable.
     */
    public function getThemeCheckUrl(Theme $extension): string
    {
        if (!$this->isThemeCheckAvailable()) {
            return '';
        }

        $stylesheet = trim((string) $extension->installationFolder);
        if ($stylesheet === '') {
            return '';
        }

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'rrze_updater_theme_check',
                    'theme' => $stylesheet
                ],
                admin_url('admin-post.php')
            ),
            'rrze-updater-theme-check_' . $stylesheet
        );
    }

    /**
     * Detect an installed and active Theme Check tool.
     *
     * @return bool Whether Theme Check is installed and active locally or network-wide.
     */
    public function isThemeCheckAvailable(): bool
    {
        return $this->isToolActive('theme-check/theme-check.php');
    }

    /**
     * Build the native single-theme update URL and nonce.
     *
     * @param Theme $extension Managed theme.
     * @return string Formatted value or empty when unavailable.
     */
    public function getThemeUpdateUrl(Theme $extension): string
    {
        return wp_nonce_url(
            self_admin_url('update.php?action=upgrade-theme&theme=') . $extension->installationFolder,
            'upgrade-theme_' . $extension->installationFolder
        );
    }

    /**
     * Build the managed plugin editor URL for the current administration.
     *
     * @param string $id Managed association ID.
     * @return string Formatted value or empty when unavailable.
     */
    public function getPluginEditUrl(string $id): string
    {
        return (string) add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'edit', 'id' => $id], self_admin_url('admin.php'));
    }

    /**
     * Resolve the remote repository URL from the attached or stored connector.
     *
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @return string Formatted value or empty when unavailable.
     */
    public function getExtensionRepositoryUrl($extension): string
    {
        if (!$extension || empty($extension->repository)) {
            return '';
        }

        $connector = $extension->connector ?? false;
        if (!$connector && !empty($extension->connectorId)) {
            $connector = $this->settings->getConnectorById($extension->connectorId);
        }

        if (!$connector || !method_exists($connector, 'getUrl')) {
            return '';
        }

        return (string) $connector->getUrl($extension->repository);
    }

    /**
     * Detect a locally or network-active tool after checking its entry file.
     *
     * @param string $pluginFile Plugin basename relative to WP_PLUGIN_DIR.
     * @return bool Whether the optional tool is installed and active.
     */
    private function isToolActive(string $pluginFile): bool
    {
        if (!defined('WP_PLUGIN_DIR') || !is_file(WP_PLUGIN_DIR . '/' . $pluginFile)) {
            return false;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($pluginFile) || is_plugin_active_for_network($pluginFile);
    }

}

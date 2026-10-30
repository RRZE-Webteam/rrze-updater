<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use Plugin_Upgrader;
use RRZE\Updater\InstalledExtensions;
use RRZE\Updater\Settings;
use RRZE\Updater\Upgrader\PluginUpgraderSkin;
use RRZE\Updater\Upgrader\ThemeUpgraderSkin;
use Theme_Upgrader;

/** Runs the classic selection as sequential WordPress single updates. */
class RepositoryUpdateRunner
{
    /**
     * Share registry, local metadata and the streaming admin renderer.
     *
     * @param Settings $settings Shared repository registry.
     * @param InstalledExtensions $installed Local installation metadata.
     * @param AdminRenderer $renderer Renderer for progress fragments.
     */
    public function __construct(
        private Settings $settings,
        private InstalledExtensions $installed,
        private AdminRenderer $renderer
    ) {
    }

    /**
     * Resolve eligible updates in selection order and enforce their capabilities.
     *
     * Incomplete plugin folders require explicit repair and never enter this flow.
     * The item shape retains the original extension and WordPress upgrader objects
     * so warnings produced during an update are available immediately afterwards.
     *
     * @param array $ids Requested managed repository IDs.
     * @return array Eligible update items containing type, name, extension, upgrader and target.
     */
    public function items(array $ids): array
    {
        $updates = [];
        foreach ($ids as $repoId) {
            $id = sanitize_text_field((string) $repoId);
            $plugin = $this->settings->getPluginById($id);
            if ($plugin && !$this->installed->isPluginIncomplete($plugin) && $this->hasUpdate($plugin)) {
                $this->requireCapability('update_plugins');
                $file = $this->installed->getPluginFile($plugin->installationFolder, $plugin->repository);
                $updates[] = [
                    'type' => 'plugin',
                    'name' => $this->installed->pluginName($plugin->installationFolder, $plugin->repository),
                    'extension' => $plugin,
                    'upgrader' => new Plugin_Upgrader(new PluginUpgraderSkin($plugin, ['plugin' => $file])),
                    'target' => $file,
                ];
                continue;
            }

            $theme = $this->settings->getThemeById($id);
            if ($theme && $this->hasUpdate($theme)) {
                $this->requireCapability('update_themes');
                $updates[] = [
                    'type' => 'theme',
                    'name' => $this->installed->themeName($theme->installationFolder),
                    'extension' => $theme,
                    'upgrader' => new Theme_Upgrader(new ThemeUpgraderSkin($theme, ['theme' => $theme->installationFolder])),
                    'target' => $theme->installationFolder,
                ];
            }
        }
        return $updates;
    }

    /**
     * Refresh relevant update caches, then stream progress around each update.
     *
     * WordPress skins print while upgrade() runs. Keeping each heading before
     * that call and each warning after it preserves the existing output and hook
     * order without allowing a view to trigger an update.
     *
     * @param array $ids Requested managed repository IDs.
     * @param array $messages Messages collected by the requesting controller.
     * @return void
     */
    public function run(array $ids, array $messages = []): void
    {
        $updates = $this->items($ids);
        $this->refreshUpdateCaches($updates);

        $this->renderer->begin($messages);
        $this->renderer->partial('repositories/bulk-update-progress', ['updates' => $updates]);
        foreach ($updates as $update) {
            $this->renderer->partial('repositories/bulk-update-heading', ['name' => $update['name']]);
            $update['upgrader']->upgrade($update['target']);
            $this->renderer->partial('repositories/bulk-update-warning', [
                'warning' => $update['extension']->lastWarning,
            ]);
        }
        $this->renderer->partial('repositories/bulk-update-end', [
            'returnUrl' => self_admin_url('admin.php?page=rrze-updater'),
        ]);
        $this->renderer->end();
    }

    /**
     * Apply the shared target policy before preparing an update.
     *
     * @param object $extension Managed plugin or theme.
     * @return bool Whether a successful check offers a different version.
     */
    private function hasUpdate(object $extension): bool
    {
        if ($extension instanceof \RRZE\Updater\Core\Extension) {
            return $extension->hasUpdate();
        }
        return !empty($extension->remoteVersion)
            && $extension->remoteVersion != $extension->localVersion
            && empty($extension->lastError);
    }

    /**
     * Stop before preparing an update the current user cannot execute.
     *
     * @param string $capability WordPress update capability.
     * @return void
     */
    private function requireCapability(string $capability): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }
    }

    /**
     * Refresh each selected extension type once, before any progress output.
     *
     * @param array $updates Eligible update items.
     * @return void
     */
    private function refreshUpdateCaches(array $updates): void
    {
        if (!function_exists('wp_update_plugins') || !function_exists('wp_update_themes')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $types = array_column($updates, 'type');
        if (in_array('plugin', $types, true)) {
            delete_site_transient('update_plugins');
            wp_update_plugins();
        }
        if (in_array('theme', $types, true)) {
            delete_site_transient('update_themes');
            wp_update_themes();
        }
    }
}

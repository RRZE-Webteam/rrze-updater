<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use WP_Error;

/** Keep managed definitions aligned with installations without a UI dependency. */
class SettingsSynchronizer
{
    private InstalledExtensions $installed;
    private RepositoryActions $actions;

    /**
     * Use the shared settings object for discovery and persistence.
     *
     * @param Settings $settings Managed repository definitions.
     * @param InstalledExtensions|null $installed Local installation reader.
     * @param RepositoryActions|null $actions Persistence operations.
     */
    public function __construct(
        private Settings $settings,
        ?InstalledExtensions $installed = null,
        ?RepositoryActions $actions = null
    ) {
        $this->installed = $installed ?? new InstalledExtensions();
        $this->actions = $actions ?? new RepositoryActions($settings);
    }

    /**
     * Remove absent installations while retaining plugin folders needing repair.
     *
     * Discovery, pruning, and persistence share the replacement lock. Otherwise
     * the rename interval during repair could look like a deleted installation.
     *
     * @return true|WP_Error Persistence result or an unavailable-lock error.
     */
    public function synchronize(): true|WP_Error
    {
        return FilesystemLock::withLock(function (): true|WP_Error {
            $this->prunePlugins();
            $this->pruneThemes();
            return $this->actions->save();
        });
    }

    /**
     * Remove plugin mappings only when neither recognized files nor a folder remain.
     *
     * @return void
     */
    private function prunePlugins(): void
    {
        $folders = array_map('dirname', array_keys(get_plugins()));
        foreach ($this->settings->plugins as $key => $plugin) {
            if (!$plugin->installationFolder || (!in_array($plugin->installationFolder, $folders, true)
                && !$this->installed->pluginDestinationExists($plugin->installationFolder))) {
                unset($this->settings->plugins[$key]);
            }
        }
    }

    /**
     * Preserve the existing theme policy: only themes recognized by WordPress remain.
     *
     * @return void
     */
    private function pruneThemes(): void
    {
        $folders = array_keys(wp_get_themes());
        foreach ($this->settings->themes as $key => $theme) {
            if (!$theme->installationFolder || !in_array($theme->installationFolder, $folders)) {
                unset($this->settings->themes[$key]);
            }
        }
    }
}

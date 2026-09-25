<?php

namespace RRZE\Updater\Upgrader;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\Extension;
use RRZE\Updater\Core\GithubConnector;
use RRZE\Updater\Core\GitlabConnector;
use WP_Error;

/** Install using WordPress's upgrader, without HTML output or activation. */
class RepositoryInstaller
{
    public function isInstalled(string $type, string $folder): bool
    {
        if ($type === 'theme') {
            return isset(wp_get_themes()[$folder]);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (array_keys(get_plugins()) as $file) {
            if (dirname($file) === $folder) {
                return true;
            }
        }
        return false;
    }

    public function destinationExists(string $type, string $folder): bool
    {
        $root = $type === 'plugin' ? WP_PLUGIN_DIR : get_theme_root();
        $path = trailingslashit($root) . $folder;
        return file_exists($path) || is_link($path) || $this->isInstalled($type, $folder);
    }

    public function install(string $type, Extension $extension): true|WP_Error
    {
        if ($this->destinationExists($type, $extension->installationFolder)) {
            return new WP_Error('rrze_updater_destination_exists', 'Installation folder already exists.');
        }
        $upgrader = $this->createUpgrader($type);
        $connector = $extension->connector;
        $temporary = $connector instanceof GithubConnector || $connector instanceof GitlabConnector;
        $package = $temporary
            ? $connector->downloadRepoZipToTempFile($extension->repository, $extension->remoteVersion)
            : $connector->downloadRepoZip($extension->repository, $extension->remoteVersion);
        if (!$package) {
            return new WP_Error('rrze_updater_download_failed', 'Could not download the selected repository ref.');
        }
        $sourceSelected = false;
        $selectSource = static function ($source, $remoteSource, $currentUpgrader) use ($upgrader, $extension, &$sourceSelected) {
            if ($currentUpgrader !== $upgrader || $sourceSelected || is_wp_error($source)) {
                return $source;
            }
            // WordPress reuses this upgrader when installing a missing parent theme.
            // Only the requested package should use our configured folder name.
            $sourceSelected = true;
            global $wp_filesystem;
            $destination = trailingslashit($remoteSource) . $extension->installationFolder . '/';
            if (untrailingslashit($source) !== untrailingslashit($destination)
                && !$wp_filesystem->move($source, $destination, false)) {
                return new WP_Error('rrze_updater_source_move_failed', 'Could not prepare the installation folder.');
            }
            return $destination;
        };
        add_filter('upgrader_source_selection', $selectSource, 20, 3);
        try {
            $result = $upgrader->install($package, ['overwrite_package' => false]);
        } finally {
            remove_filter('upgrader_source_selection', $selectSource, 20);
            if ($temporary && is_file($package)) {
                wp_delete_file($package);
            }
        }
        if (is_wp_error($result)) {
            return $result;
        }
        if (!$result || !$this->isInstalled($type, $extension->installationFolder)) {
            return new WP_Error('rrze_updater_install_failed', 'WordPress could not install the plugin/theme. Check the package structure and filesystem permissions.');
        }
        return true;
    }

    protected function createUpgrader(string $type): \WP_Upgrader
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $skin = new \Automatic_Upgrader_Skin();
        return $type === 'plugin' ? new \Plugin_Upgrader($skin) : new \Theme_Upgrader($skin);
    }
}

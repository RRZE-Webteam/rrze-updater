<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;

/**
 * Class Plugin
 *
 * Represents a plugin extension that can be updated.
 */
class Plugin extends Extension
{
    /**
     * Add a Plugin object from an array of data.
     *
     * @static
     * @param array $array An associative array of data for creating the plugin.
     * @return object The created Plugin object.
     */
    public static function createFromArray(array $array): object
    {
        // Add a new Plugin object.
        // Update its properties from the provided array using the parent class method.
        // Return the created object.

        $plugin = new Plugin();
        $plugin->updateFromArray($array);
        return $plugin;
    }

    protected function getVersionFileCandidates(): array
    {
        $config = new Config();

        return array_values(array_unique(array_merge(
            $config->getReadmeFiles(),
            [
                $config->getPackageFile(),
                sprintf($config->getPluginMainFilePattern(), $this->installationFolder),
                sprintf($config->getPluginMainFilePattern(), $this->repository)
            ]
        )));
    }

    public function validateRemotePluginRepository(string $ref): bool|\WP_Error
    {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this plugin.', 'rrze-updater')
            );
        }

        $mainFile = $this->getRemotePluginMainFile($ref);
        if ($mainFile === false) {
            return new \WP_Error(
                'rrze_updater_missing_plugin_main_file',
                __('The repository does not contain a valid WordPress plugin main file.', 'rrze-updater')
            );
        }

        if (!$this->hasRemoteReadmeFile($ref)) {
            return new \WP_Error(
                'rrze_updater_missing_plugin_readme',
                __('The repository does not contain a readme.txt or README.md file.', 'rrze-updater')
            );
        }

        return true;
    }

    private function getRemotePluginMainFile(string $ref): string|bool
    {
        foreach ($this->getPluginMainFileCandidates() as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (!is_string($content)) {
                continue;
            }

            if (preg_match('/^[\s\/\*#@]*Plugin Name\s*:\s*(.+)$/mi', $content)) {
                return $filePath;
            }
        }

        return false;
    }

    private function hasRemoteReadmeFile(string $ref): bool
    {
        $config = new Config();

        foreach ($config->getReadmeFiles() as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (is_string($content)) {
                return true;
            }
        }

        return false;
    }

    private function getPluginMainFileCandidates(): array
    {
        $config = new Config();

        return array_values(array_unique([
            sprintf($config->getPluginMainFilePattern(), $this->installationFolder),
            sprintf($config->getPluginMainFilePattern(), $this->repository)
        ]));
    }
}

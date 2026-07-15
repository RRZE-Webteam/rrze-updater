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
            [
                sprintf($config->getPluginMainFilePattern(), $this->installationFolder),
                sprintf($config->getPluginMainFilePattern(), $this->repository),
                $config->getPackageFile()
            ],
            $config->getReadmeFiles()
        )));
    }

    public function validateRemotePluginRepository(string $ref): bool|\WP_Error
    {
        return $this->getRemotePluginRepositoryWarning($ref) ?: true;
    }

    public function getRemotePluginRepositoryWarning(string $ref): \WP_Error|false {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this plugin.', 'rrze-updater')
            );
        }

        $mainFileValidation = $this->validateRemotePluginMainFile($ref);
        if (is_wp_error($mainFileValidation)) {
            return $mainFileValidation;
        }

        if (!$this->hasRemoteReadmeFile($ref)) {
            $config = new Config();
            return new \WP_Error(
                'rrze_updater_missing_plugin_readme',
                sprintf(
                    /* translators: %s: Comma-separated list of checked readme files */
                    __('The repository is not recognized as a WordPress plugin. Missing readme file. Checked: %s.', 'rrze-updater'),
                    implode(', ', $config->getReadmeFiles())
                )
            );
        }

        return false;
    }

    public function validateRemotePluginBranch(string $branch): bool|\WP_Error
    {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this plugin.', 'rrze-updater')
            );
        }

        if ($this->connector->remoteBranchExists($this->repository, $branch)) {
            return true;
        }

        return new \WP_Error(
            'rrze_updater_missing_branch',
            sprintf(
                /* translators: 1: Branch name, 2: Repository name */
                __('The repository branch "%1$s" could not be found for "%2$s". Check the branch name before the plugin contents are validated.', 'rrze-updater'),
                $branch,
                $this->repository
            )
        );
    }

    private function validateRemotePluginMainFile(string $ref): bool|\WP_Error
    {
        $foundFiles = [];
        $checkedFiles = $this->getPluginMainFileCandidates();

        foreach ($checkedFiles as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (!is_string($content)) {
                continue;
            }

            $foundFiles[] = $filePath;
            if (preg_match('/^[\s\/\*#@]*Plugin Name\s*:\s*(.+)$/mi', $content)) {
                return true;
            }
        }

        if (empty($foundFiles)) {
            return new \WP_Error(
                'rrze_updater_missing_plugin_main_file',
                sprintf(
                    /* translators: %s: Comma-separated list of checked plugin main file candidates */
                    __('The repository is not recognized as a WordPress plugin. Missing plugin main file. Checked: %s.', 'rrze-updater'),
                    implode(', ', $checkedFiles)
                )
            );
        }

        return new \WP_Error(
            'rrze_updater_missing_plugin_name_header',
            sprintf(
                /* translators: %s: Comma-separated list of plugin main file candidates found without Plugin Name header */
                __('The repository is not recognized as a WordPress plugin. Found plugin main file candidate, but the required "Plugin Name:" header is missing. Checked: %s.', 'rrze-updater'),
                implode(', ', $foundFiles)
            )
        );
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

<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;

/**
 * Class Theme
 *
 * Represents a theme extension that can be updated.
 */
class Theme extends Extension
{
    /**
     * Add a Theme object from an array of data.
     *
     * @static
     * @param array $array An associative array of data for creating the theme.
     * @return object The created Theme object.
     */
    public static function createFromArray(array $array): object
    {
        // Add a new Theme object.
        // Update its properties from the provided array using the parent class method.
        // Return the created object.

        $theme = new Theme();
        $theme->updateFromArray($array);
        return $theme;
    }

    protected function getVersionFileCandidates(): array
    {
        $config = new Config();

        return array_values(array_unique(array_merge(
            [
                $config->getThemeMainFile(),
                $config->getThemeFunctionsFile(),
                $config->getPackageFile(),
            ],
            $config->getReadmeFiles()
        )));
    }

    public function validateRemoteThemeBranch(string $branch): bool|\WP_Error
    {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this theme.', 'rrze-updater')
            );
        }

        if ($this->connector->remoteBranchExists($this->repository, $branch)) {
            return true;
        }

        return new \WP_Error(
            'rrze_updater_missing_branch',
            sprintf(
                /* translators: 1: Branch name, 2: Repository name */
                __('The repository branch "%1$s" could not be found for "%2$s". Check the branch name before the theme contents are validated.', 'rrze-updater'),
                $branch,
                $this->repository
            )
        );
    }

    public function validateRemoteThemeRepository(string $ref): bool|\WP_Error
    {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this theme.', 'rrze-updater')
            );
        }

        $styleValidation = $this->validateRemoteThemeStylesheet($ref);
        if (is_wp_error($styleValidation)) {
            return $styleValidation;
        }

        if (!$this->hasRemoteThemeIndexTemplate($ref)) {
            $config = new Config();
            return new \WP_Error(
                'rrze_updater_missing_theme_index_template',
                sprintf(
                    /* translators: 1: Classic theme index file, 2: Block theme index file */
                    __('The repository is not recognized as a WordPress theme. Missing theme index template. Checked: %1$s or %2$s.', 'rrze-updater'),
                    $config->getThemeClassicIndexFile(),
                    $config->getThemeBlockIndexFile()
                )
            );
        }

        return true;
    }

    private function validateRemoteThemeStylesheet(string $ref): bool|\WP_Error
    {
        $config = new Config();
        $styleFile = $config->getThemeMainFile();
        $content = $this->connector->getRemoteFile($this->repository, $ref, $styleFile);

        if (!is_string($content)) {
            return new \WP_Error(
                'rrze_updater_missing_theme_stylesheet',
                sprintf(
                    /* translators: %s: Theme stylesheet file path */
                    __('The repository is not recognized as a WordPress theme. Missing stylesheet file: %s.', 'rrze-updater'),
                    $styleFile
                )
            );
        }

        if (preg_match('/^[\s\/\*#@]*Theme Name\s*:\s*(.+)$/mi', $content)) {
            return true;
        }

        return new \WP_Error(
            'rrze_updater_missing_theme_name_header',
            sprintf(
                /* translators: %s: Theme stylesheet file path */
                __('The repository is not recognized as a WordPress theme. Found %s, but the required "Theme Name:" header is missing.', 'rrze-updater'),
                $styleFile
            )
        );
    }

    private function hasRemoteThemeIndexTemplate(string $ref): bool
    {
        $config = new Config();
        $files = [
            $config->getThemeClassicIndexFile(),
            $config->getThemeBlockIndexFile()
        ];

        foreach ($files as $filePath) {
            $content = $this->connector->getRemoteFile($this->repository, $ref, $filePath);
            if (is_string($content)) {
                return true;
            }
        }

        return false;
    }
}

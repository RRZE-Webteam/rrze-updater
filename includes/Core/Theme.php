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

        $accessError = $this->getMissingGitlabTokenBranchError($branch);
        if ($accessError) {
            return $accessError;
        }

        $branches = $this->connector->getRemoteBranches($this->repository);
        if ($branches === false) {
            $repositoryError = $this->getRemoteRepositoryLookupError();
            if ($repositoryError) {
                return $repositoryError;
            }
        }

        return $this->getMissingBranchError($branch, is_array($branches) ? $branches : []);
    }

    public function validateRemoteThemeRepository(string $ref): bool|\WP_Error
    {
        if (!$this->connector) {
            return new \WP_Error(
                'rrze_updater_missing_connector',
                __('No repository service is configured for this theme.', 'rrze-updater')
            );
        }

        $stylesheet = $this->getValidatedRemoteThemeStylesheet($ref);
        if (is_wp_error($stylesheet)) {
            return $stylesheet;
        }

        // Child themes inherit templates from the parent named in style.css.
        if (!$this->getStylesheetHeader($stylesheet, 'Template') && !$this->hasRemoteThemeIndexTemplate($ref)) {
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

    private function getValidatedRemoteThemeStylesheet(string $ref): string|\WP_Error
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

        if ($this->getStylesheetHeader($content, 'Theme Name') !== '') {
            return $content;
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

    /** Read remote header text using the rules of WordPress's get_file_data(). */
    private function getStylesheetHeader(string $stylesheet, string $header): string
    {
        $headerText = str_replace("\r", "\n", substr($stylesheet, 0, 8192));
        $pattern = '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi';
        if (!preg_match($pattern, $headerText, $match)) {
            return '';
        }
        return trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
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

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
            $config->getReadmeFiles(),
            [
                $config->getPackageFile(),
                $config->getThemeMainFile()
            ]
        )));
    }
}

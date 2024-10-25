<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

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
}

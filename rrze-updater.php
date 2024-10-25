<?php

/*
Plugin Name:        RRZE Updater
Plugin URI:         https://github.com/RRZE-Webteam/rrze-updater
Version:            2.5.3
Description:        Sync Plugins and Themes with the corresponding GitHub or GitLab repositories.
Author:             RRZE Webteam
Author URI:         https://github.com/RRZE-Webteam
License:            GNU General Public License v2
License URI:        http://www.gnu.org/licenses/gpl-2.0.html
Text Domain:        rrze-updater
Domain Path:        /languages
Requires at least:  6.6
Requires PHP:       8.2
Update URI:         https://github.com/RRZE-Webteam/rrze-updater
*/

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\{Main, Plugin};

/**
 * Register a custom autoloader (PSR-4) using spl_autoload_register().
 */
spl_autoload_register(function ($class) {
    // Define the namespace prefix for the classes.
    $prefix = __NAMESPACE__;

    // Define the base directory where the class files are located.
    $baseDir = __DIR__ . '/includes/';

    // Calculate the length of the namespace prefix.
    $len = strlen($prefix);

    // Check if the provided class starts with the defined namespace prefix.
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // The class does not belong to this namespace, so no action is taken.
    }

    // Extract the relative class name (without the namespace).
    $relativeClass = substr($class, $len);

    // Build the file path based on the namespace and relative class name.
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Check if the file exists before attempting to include it.
    if (file_exists($file)) {
        require $file; // Include the class file.
    }
});

// Register activation hook for the plugin
register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');

// Register deactivation hook for the plugin
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');

/**
 * Add an action hook for the 'plugins_loaded' hook.
 *
 * This code hooks into the 'plugins_loaded' action hook to execute a callback function when
 * WordPress has fully loaded all active plugins and the theme's functions.php file.
 */
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

/**
 * Load the text domain for plugin localization.
 *
 * This method loads the translation files for the plugin.
 */
function loadTextdomain()
{
    // Load the plugin's text domain for localization.
    load_plugin_textdomain(
        'rrze-updater', // The text domain used for translating plugin strings.
        false, // Deprecated. Should be set to 'false'.
        sprintf('%s/languages/', dirname(plugin_basename(__FILE__))) // The path to the translation files.
    );
}

/**
 * Handle the activation of the plugin.
 *
 * This function is called when the plugin is activated.
 *
 * @param bool $networkWide True if the plugin is being activated network-wide on a multisite installation, false if it's site-specific.
 */
function activation($networkWide)
{
    // Load the plugin's text domain for localization.
    loadTextdomain();

    // Initialize an error message string.
    $error = '';

    // If the plugin is being activated on a multisite installation and not network-wide.
    if (is_multisite() && !$networkWide) {
        // Set an error message indicating that the plugin can only be installed network-wide in a multisite installation.
        $error = __('In a multisite installation, the plugin can only be installed network-wide.', 'rrze-updater');
    }

    // If there is an error, deactivate the plugin and display an error message.
    if ($error) {
        // Deactivate the plugin.
        deactivate_plugins(plugin_basename(__FILE__));

        // Display an error message with the plugin's name and the specific error message.
        wp_die(sprintf(__('Plugins: %1$s: %2$s', 'rrze-updater'), plugin_basename(__FILE__), $error));
    }
}

/**
 * Handle the deactivation of the plugin.
 *
 * This function is called when the plugin is deactivated.
 * It clears any scheduled cron jobs associated with the plugin.
 */
function deactivation()
{
    // Clear any scheduled cron jobs associated with the plugin.
    Cron::clearSchedule();
}

/**
 * Singleton pattern for initializing and accessing the main plugin instance.
 *
 * This method ensures that only one instance of the Plugin class is created and returned.
 *
 * @return Plugin The main instance of the Plugin class.
 */
function plugin()
{
    // Declare a static variable to hold the instance.
    static $instance;

    // Check if the instance is not already created.
    if (null === $instance) {
        // Add a new instance of the Plugin class, passing the current file (__FILE__) as a parameter.
        $instance = new Plugin(__FILE__);
    }

    // Return the main instance of the Plugin class.
    return $instance;
}

/**
 * Check system requirements for the plugin.
 *
 * This method checks if the server environment meets the minimum WordPress and PHP version requirements
 * for the plugin to function properly.
 *
 * @return string An error message string if requirements are not met, or an empty string if requirements are satisfied.
 */
function systemRequirements(): string
{
    // Get the global WordPress version.
    global $wp_version;

    // Get the PHP version.
    $phpVersion = phpversion();

    // Initialize an error message string.
    $error = '';

    // Check if the WordPress version is compatible with the plugin's requirement.
    if (!is_wp_version_compatible(plugin()->getRequiresWP())) {
        $error = sprintf(
            /* translators: 1: Server WordPress version number, 2: Required WordPress version number. */
            __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-updater'),
            $wp_version,
            plugin()->getRequiresWP()
        );
    } elseif (!is_php_version_compatible(plugin()->getRequiresPHP())) {
        // Check if the PHP version is compatible with the plugin's requirement.
        $error = sprintf(
            /* translators: 1: Server PHP version number, 2: Required PHP version number. */
            __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-updater'),
            $phpVersion,
            plugin()->getRequiresPHP()
        );
    }

    // Return the error message string, which will be empty if requirements are satisfied.
    return $error;
}

/**
 * Handle the loading of the plugin.
 *
 * This function is responsible for initializing the plugin, loading text domains for localization,
 * checking system requirements, and displaying error notices if necessary.
 */
function loaded()
{
    // Load the plugin's text domain for localization.
    loadTextdomain();

    // Trigger the 'loaded' method of the main plugin instance.
    plugin()->loaded();

    // Check system requirements and store any error messages.
    if ($error = systemRequirements()) {
        // If there is an error, add an action to display an admin notice with the error message.
        add_action('admin_init', function () use ($error) {
            // Check if the current user has the capability to activate plugins.
            if (current_user_can('activate_plugins')) {
                // Get plugin data to retrieve the plugin's name.
                $pluginName = plugin()->getName();

                // Determine the admin notice tag based on network-wide activation.
                $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';

                // Add an action to display the admin notice.
                add_action($tag, function () use ($pluginName, $error) {
                    printf(
                        '<div class="notice notice-error"><p>' .
                            /* translators: 1: The plugin name, 2: The error string. */
                            esc_html__('Plugins: %1$s: %2$s', 'rrze-updater') .
                            '</p></div>',
                        $pluginName,
                        $error
                    );
                });
            }
        });

        // Return to prevent further initialization if there is an error.
        return;
    }

    // If there are no errors, create an instance of the 'Main' class and trigger its 'loaded' method.
    (new Main)->loaded();
}

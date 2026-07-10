<?php

/*
Plugin Name:        RRZE Updater
Plugin URI:         https://github.com/RRZE-Webteam/rrze-updater
Version:            2.5.11
Description:        Sync Plugins and Themes with the corresponding GitHub or GitLab repositories.
Author:             RRZE Webteam
Author URI:         https://github.com/RRZE-Webteam
License:            GNU General Public License Version 3
License URI:        https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        rrze-updater
Domain Path:        /languages
Requires at least:  6.8
Requires PHP:       8.2
Network:            true
*/

namespace RRZE\Updater;

defined('ABSPATH') || exit;

/**
 * Register a custom autoloader (PSR-4) using spl_autoload_register().
 */
spl_autoload_register(__NAMESPACE__ . '\autoload');

/**
 * Autoload plugin classes.
 *
 * @param string $class Fully qualified class name.
 */
function autoload($class) {
    $prefix = __NAMESPACE__;
    $baseDir = __DIR__ . '/includes/';
    $lengthOfNamespacePrefix = strlen($prefix);

    if (strncmp($prefix, $class, $lengthOfNamespacePrefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, $lengthOfNamespacePrefix);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
}


add_action('init', __NAMESPACE__ . '\loadTextdomain');
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');


/**
 * Handle the activation of the plugin.
 */
function activation($networkWide) {
    //
}

/**
 * Handle the deactivation of the plugin.
 */
function deactivation() {
    Cron::clearSchedule();
    Cron::clearEmailSchedule();
}

/**
 * Load plugin translations.
 */
function loadTextdomain() {
    load_plugin_textdomain('rrze-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Singleton pattern for initializing and accessing the main plugin instance.
 *
 * This method ensures that only one instance of the Plugin class is created and returned.
 *
 * @return Plugin The main instance of the Plugin class.
 */
function plugin() {
    static $instance;

    // Check if the instance is not already created.
    if (null === $instance) {
        $instance = new Plugin(__FILE__);
    }

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
function systemRequirements(): string {
    global $wp_version;
    $phpVersion = phpversion();
    $error = '';

    if (!is_wp_version_compatible(plugin()->getRequiresWP())) {
        $error = sprintf(
            /* translators: 1: Server WordPress version number, 2: Required WordPress version number. */
            __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-updater'),
            $wp_version,
            plugin()->getRequiresWP()
        );
    } elseif (!is_php_version_compatible(plugin()->getRequiresPHP())) {
        $error = sprintf(
            /* translators: 1: Server PHP version number, 2: Required PHP version number. */
            __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-updater'),
            $phpVersion,
            plugin()->getRequiresPHP()
        );
    } elseif (is_multisite() && !is_plugin_active_for_network(plugin()->getBaseName())) {
        $error = __('In a multisite installation, the plugin can only be installed network-wide.', 'rrze-updater');
    }
    return $error;
}

/**
 * Handle the loading of the plugin.
 *
 * This function is responsible for initializing the plugin, loading text domains for localization,
 * checking system requirements, and displaying error notices if necessary.
 */
function loaded() {
    plugin()->loaded();

    if (systemRequirements()) {
        add_action('admin_init', function () {
            $error = systemRequirements();
            if (current_user_can('activate_plugins')) {
                $pluginName = plugin()->getName();

                $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';

                add_action($tag, function () use ($pluginName, $error) {
                    printf(
                        '<div class="notice notice-error"><p>' .
                            /* translators: 1: The plugin name, 2: The error string. */
                            esc_html__('Plugins: %1$s: %2$s', 'rrze-updater') .
                            '</p></div>',
                        esc_html($pluginName),
                        esc_html($error)
                    );
                });
            }
        });

        return;
    }

    (new Main)->loaded();
}

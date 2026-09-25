<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

echo '<tr class="plugin-update-tr' . esc_attr($activeClass) . '" id="' . esc_attr($response->slug . '-update') . '" data-slug="' . esc_attr($response->slug) . '" data-plugin="' . esc_attr($file) . '"><td colspan="' . esc_attr($wpListTable->get_column_count()) . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>';

if (!current_user_can('update_plugins')) {
    printf(
        /* translators: 1: Extension name, 2: Version number */
        esc_html__('There is a new version of %1$s available: %2$s.', 'rrze-updater'),
        wp_kses_post($plugin_name),
        esc_html($response->new_version)
    );
} elseif (empty($response->package)) {
    printf(
        /* translators: 1: Extension name, 2: Version number */
        wp_kses_post(__('There is a new version of %1$s available: %2$s. <em>Automatic update is unavailable for this plugin.</em>', 'rrze-updater')),
        wp_kses_post($plugin_name),
        esc_html($response->new_version)
    );
} else {
    printf(
        /* translators: 1: Extension name, 2: Version number, 3: Update URL, 4: Additional link attributes */
        wp_kses_post(__('There is a new version of %1$s available: %2$s. <a href="%3$s" %4$s>Update now</a>.', 'rrze-updater')),
        wp_kses_post($plugin_name),
        esc_html($response->new_version),
        esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=') . $file, 'upgrade-plugin_' . $file)),
        sprintf(
            'class="update-link" aria-label="%s"',
            sprintf(
                /* translators: %s: Extension name */
                esc_attr__('Update %s now', 'rrze-updater'),
                esc_attr(wp_strip_all_tags($plugin_name))
            )
        )
    );
}

/**
 * Fires at the end of the update message container in each
 * row of the plugins list table.
 *
 * The dynamic portion of the hook name, `$file`, refers to the path
 * of the plugin's primary file relative to the plugins directory.
 *
 * @since 2.8.0
 *
 * @param array  $plugin_data An array of plugin metadata. See get_plugin_data()
 *                            and the {@see 'plugin_row_meta'} filter for the list
 *                            of possible values.
 * @param object $response {
 *     An object of metadata about the available plugin update.
 *
 *     @type string   $id           Plugin ID, e.g. `w.org/plugins/[plugin-name]`.
 *     @type string   $slug         Plugin slug.
 *     @type string   $plugin       Plugin basename.
 *     @type string   $new_version  New plugin version.
 *     @type string   $url          Plugin URL.
 *     @type string   $package      Plugin update package URL.
 *     @type string[] $icons        An array of plugin icon URLs.
 *     @type string[] $banners      An array of plugin banner URLs.
 *     @type string[] $banners_rtl  An array of plugin RTL banner URLs.
 *     @type string   $requires     The version of WordPress which the plugin requires.
 *     @type string   $tested       The version of WordPress the plugin is tested against.
 *     @type string   $requires_php The version of PHP which the plugin requires.
 * }
 */
do_action("in_plugin_update_message-{$file}", $plugin_data, $response); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

echo '</p></div></td></tr>';

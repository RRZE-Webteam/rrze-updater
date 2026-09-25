<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

echo '<tr class="plugin-update-tr' . esc_attr($activeClass) . '" id="' . esc_attr($theme->get_stylesheet() . '-update') . '" data-slug="' . esc_attr($theme->get_stylesheet()) . '"><td colspan="' . esc_attr($wpListTable->get_column_count()) . '" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>';
if (!current_user_can('update_themes')) {
    printf(
        /* translators: 1: Extension name, 2: Version number */
        esc_html__('There is a new version of %1$s available: %2$s.', 'rrze-updater'),
        esc_html($theme['Name']),
        esc_html($response['new_version'])
    );
} elseif (empty($response['package'])) {
    printf(
        /* translators: 1: Extension name, 2: Version number */
        wp_kses_post(__('There is a new version of %1$s available: %2$s. <em>Automatic update is unavailable for this theme.</em>', 'rrze-updater')),
        esc_html($theme['Name']),
        esc_html($response['new_version'])
    );
} else {
    printf(
        /* translators: 1: Extension name, 2: Version number, 3: Update URL, 4: Additional link attributes */
        wp_kses_post(__('There is a new version of %1$s available: %2$s. <a href="%3$s" %4$s>Update now</a>.', 'rrze-updater')),
        esc_html($theme['Name']),
        esc_html($response['new_version']),
        esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-theme&theme=') . $theme_key, 'upgrade-theme_' . $theme_key)),
        sprintf(
            'class="update-link" aria-label="%s"',
            esc_attr(
                sprintf(
                    /* translators: %s: Extension name */
                    __('Update %s now', 'rrze-updater'),
                    esc_html($theme['Name'])
                )
            )
        )
    );
}

/**
 * Fires at the end of the update message container in each
 * row of the themes list table.
 *
 * The dynamic portion of the hook name, `$theme_key`, refers to
 * the theme slug as found in the WordPress.org themes repository.
 *
 * @since 3.1.0
 *
 * @param WP_Theme $theme    The WP_Theme object.
 * @param array    $response {
 *     An array of metadata about the available theme update.
 *
 *     @type string $new_version New theme version.
 *     @type string $url         Theme URL.
 *     @type string $package     Theme update package URL.
 * }
 */
do_action("in_theme_update_message-{$theme_key}", $theme, $response); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

echo '</p></div></td></tr>';

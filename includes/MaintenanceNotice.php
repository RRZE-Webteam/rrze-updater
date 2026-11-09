<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

/**
 * Presentation-only maintenance guidance for managed network administration actions.
 *
 * @internal This helper is not an extension API and never changes upgrader behavior.
 */
final class MaintenanceNotice
{
    /**
     * Match the actual upgrader path, independently of the extension's activation label.
     *
     * WordPress bulk/AJAX updates enable maintenance in multisite even for inactive
     * extensions. Our overview instead calls upgrade() for each selected item.
     * Manual plugin upgrade() does not enable maintenance; manual theme upgrade()
     * does so only for the current stylesheet, not its parent or another site's theme.
     *
     * @param string $action replacement, core-bulk, automatic, manual or updater-bulk.
     * @param string $type plugin or theme.
     * @param string $target Theme stylesheet for manual and overview bulk actions.
     * @return bool Whether this action has a maintenance phase in multisite.
     */
    public static function applies(string $action, string $type = 'plugin', string $target = ''): bool
    {
        if (!is_multisite() || !in_array($type, ['plugin', 'theme'], true)) {
            return false;
        }
        return match ($action) {
            'replacement' => $type === 'plugin',
            'core-bulk', 'automatic' => true,
            'manual', 'updater-bulk' => $type === 'theme' && $target !== '' && get_stylesheet() === $target,
            default => false,
        };
    }

    /**
     * Restrict notices to authorized superadministrators in network administration.
     *
     * @param string $type Extension type whose update capability is required.
     * @return bool Whether the current screen and user may receive the notice.
     */
    public static function visible(string $type): bool
    {
        return in_array($type, ['plugin', 'theme'], true)
            && is_multisite() && is_network_admin() && is_super_admin()
            && current_user_can('manage_network_options')
            && current_user_can('update_' . $type . 's');
    }

    /**
     * Render shared guidance without changing update actions or their confirmation state.
     *
     * Compact markup is phrasing content so it can appear inside WordPress update
     * messages and outside the links replaced when AJAX toggles automatic updates.
     * The duration is a coarse orientation, not a measured or guaranteed limit.
     *
     * @param string $action Upgrader context accepted by applies().
     * @param string $type plugin or theme.
     * @param string $target Theme stylesheet for conditional maintenance actions.
     * @param bool $compact Whether to render an inline hint instead of a full notice.
     * @return string Escaped markup, or an empty string outside the relevant context.
     */
    public static function render(string $action, string $type = 'plugin', string $target = '', bool $compact = false): string
    {
        if (!self::visible($type) || !self::applies($action, $type, $target)
            || ($action === 'replacement' && !current_user_can('install_plugins'))) {
            return '';
        }

        $introduction = match ($action) {
            'replacement' => __('After confirmation, replacing the plugin files activates WordPress maintenance mode.', 'rrze-updater'),
            'automatic' => __('Future automatic updates activate WordPress maintenance mode. Enabling automatic updates does not start maintenance by itself.', 'rrze-updater'),
            'core-bulk' => __('WordPress activates maintenance mode for bulk updates and updates that run directly within the list.', 'rrze-updater'),
            'updater-bulk' => __('Selecting a theme marked with a maintenance notice also activates maintenance mode during this Updater bulk action.', 'rrze-updater'),
            default => __('This action activates WordPress maintenance mode.', 'rrze-updater'),
        };
        $scope = __('All websites in this WordPress installation will temporarily be unavailable for normal use.', 'rrze-updater');
        $estimate = __('Estimated interruption: a few seconds to a few minutes.', 'rrze-updater');
        $uncertainty = __('This is a rough estimate. Multiple updates, large packages, slow servers or recovery can take longer.', 'rrze-updater');
        $impact = __('Visitors may see a maintenance message. Form submissions and other interactive requests may fail and need to be repeated.', 'rrze-updater');
        $preparation = $action === 'replacement'
            ? __('Download and package validation take place before maintenance mode starts.', 'rrze-updater') : '';

        if ($compact) {
            $shortIntroduction = $action === 'automatic'
                ? __('Future automatic updates interrupt all websites in this WordPress installation. Enabling this setting alone does not start maintenance.', 'rrze-updater')
                : ($action === 'replacement'
                    ? __('After confirmation, maintenance mode temporarily interrupts all websites in this WordPress installation.', 'rrze-updater')
                    : __('Maintenance mode temporarily interrupts all websites in this WordPress installation.', 'rrze-updater'));
            return '<span class="description rrze-updater-maintenance-hint" style="display:block">'
                . esc_html($shortIntroduction) . ' <strong>' . esc_html($estimate) . '</strong> '
                . esc_html__('Rough estimate; large packages, slow servers or recovery can take longer.', 'rrze-updater') . ' '
                . esc_html__('Visitors may see a maintenance message; form submissions and interactive requests may fail.', 'rrze-updater') . '</span>';
        }

        return '<div class="notice notice-warning inline rrze-updater-maintenance-notice">'
            . '<p><strong>' . esc_html__('Temporary interruption of all websites', 'rrze-updater') . '</strong></p>'
            . '<p>' . esc_html($introduction . ' ' . $scope) . '</p>'
            . '<p><strong>' . esc_html($estimate) . '</strong> ' . esc_html($uncertainty) . '</p>'
            . '<p>' . esc_html($impact) . '</p>'
            . ($preparation !== '' ? '<p>' . esc_html($preparation) . '</p>' : '') . '</div>';
    }
}

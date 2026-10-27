<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;

/** Interprets administrative request fields without changing repositories. */
class AdminRequest
{
    /**
     * Read the supported page action.
     *
     * @return string Supported action or an empty string.
     */
    public static function action(): string
    {
        $action = $_GET['action'] ?? '';
        return in_array($action, ['add', 'bulk-update', 'check-updates', 'edit', 'delete'], true) ? $action : '';
    }

    /**
     * Resolve the selected action using WordPress list-table precedence.
     *
     * @return string Selected bulk action, or an empty string when filtering.
     */
    public static function bulkAction(): string
    {
        if (isset($_REQUEST['filter_action'])) {
            return '';
        }
        foreach (['action', 'action2'] as $field) {
            if (isset($_REQUEST[$field]) && $_REQUEST[$field] !== '-1') {
                return is_string($_REQUEST[$field]) ? $_REQUEST[$field] : '';
            }
        }
        return '';
    }

    /**
     * Validate a confirmed bulk deletion before returning its selected IDs.
     *
     * @param string $plural List-table plural and nonce suffix.
     * @param array<string> $capabilities Required capabilities.
     * @return array Selected IDs; empty for unconfirmed or unrelated requests.
     */
    public static function deleteIds(string $plural, array $capabilities): array
    {
        $ids = $_GET[$plural] ?? [];
        if (self::bulkAction() !== 'delete' || !is_array($ids) || $ids === []
            || ($_GET['rrze-updater-bulk-delete-confirmed'] ?? '') !== '1') {
            return [];
        }
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'bulk-' . $plural)) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }
        foreach ($capabilities as $capability) {
            if (!current_user_can($capability)) {
                wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
            }
        }
        return $ids;
    }

    /**
     * Register the shared list pagination screen option.
     *
     * @return void
     */
    public static function screenOptions(): void
    {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-updater'),
            'default' => 20,
            'option' => (new Config())->getScreenOptionPerPage(),
        ]);
    }
}

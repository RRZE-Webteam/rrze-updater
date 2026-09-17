<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Bundles\BundleManager;
use RRZE\Updater\Bundles\Catalog;

/** Network-admin presentation and authenticated request boundary. */
class BundleAdmin
{
    private const SLUG = 'rrze-updater-bundles';
    private const ACTION = 'rrze_updater_bundle';
    private string $pageHook = '';

    public function register(): void
    {
        add_action('network_admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'request']);
    }

    public function menu(): void
    {
        if (!is_multisite()) {
            return;
        }
        $this->pageHook = add_submenu_page(
            (new Config())->getMenuSettings()['repositories_slug'],
            __('Recommended installation', 'rrze-updater'),
            __('Recommended installation', 'rrze-updater'),
            'manage_network_options', self::SLUG, [$this, 'page']
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== $this->pageHook || $this->pageHook === '') {
            return;
        }
        $root = dirname(__DIR__);
        wp_enqueue_style('rrze-updater-bundles', plugins_url('assets/css/bundles.css', $root . '/rrze-updater.php'), [], (string) filemtime($root . '/assets/css/bundles.css'));
        wp_enqueue_script('rrze-updater-bundles', plugins_url('assets/js/bundles.js', $root . '/rrze-updater.php'), [], (string) filemtime($root . '/assets/js/bundles.js'), true);
        wp_localize_script('rrze-updater-bundles', 'rrzeUpdaterBundles', [
            'url' => network_site_url('wp-admin/admin-ajax.php'), 'action' => self::ACTION,
            'network' => get_current_network_id(), 'nonce' => wp_create_nonce($this->nonceAction()),
            'catalog' => (new Catalog())->get(),
            'labels' => [
                'idle' => __('Select connectors, then check prerequisites to review this bundle.', 'rrze-updater'),
                'selectionChanged' => __('Connector selection changed. Run prerequisite checks again to use these connectors.', 'rrze-updater'),
                'checking' => __('Checking prerequisites…', 'rrze-updater'),
                'ready' => __('Ready. Review the planned actions, then install the bundle.', 'rrze-updater'),
                'blocked' => __('Prerequisites need attention. Fix the listed errors, then retry checks.', 'rrze-updater'),
                'running' => __('Installing and registering repositories…', 'rrze-updater'),
                'complete' => __('Processing finished. Review each result; failed entries can be retried.', 'rrze-updater'),
                'paused' => __('Paused. Resume to continue from saved progress.', 'rrze-updater'),
                'networkError' => __('The request did not finish normally. Progress is saved. Wait for the server request to finish, then resume or reload.', 'rrze-updater'),
                'pending' => __('Pending', 'rrze-updater'), 'error' => __('Prerequisite error', 'rrze-updater'),
                'queued' => __('Queued', 'rrze-updater'), 'installing' => __('Processing', 'rrze-updater'),
                'done' => __('Successful', 'rrze-updater'), 'skipped' => __('Already managed', 'rrze-updater'),
                'failed' => __('Failed', 'rrze-updater'), 'install' => __('Install and register', 'rrze-updater'),
                'register' => __('Register existing installation', 'rrze-updater'), 'skip' => __('Already managed', 'rrze-updater'),
                'checked' => __('Checked', 'rrze-updater'), 'progress' => __('%1$s of %2$s entries processed', 'rrze-updater'),
            ],
        ]);
    }

    public function page(): void
    {
        if (!$this->allowed()) {
            wp_die(esc_html__('Network administration and plugin/theme installation permissions are required.', 'rrze-updater'));
        }
        $bundle = (new Catalog())->get();
        $connectorChoices = (new BundleManager())->connectorChoices();
        $servicesUrl = network_admin_url('admin.php?page=rrze-updater-settings&tab=services');
        require __DIR__ . '/views/bundles/index.php';
    }

    public function request(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->allowed()) {
            wp_send_json_error(['message' => __('You cannot manage bundle installations.', 'rrze-updater')], 403);
            return;
        }
        if ((int) ($_POST['network'] ?? 0) !== get_current_network_id()) {
            wp_send_json_error(['message' => __('The selected network does not match this request.', 'rrze-updater')], 403);
            return;
        }
        check_ajax_referer($this->nonceAction(), 'nonce');
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        if ($operation !== 'status' && !wp_is_file_mod_allowed('rrze_updater_bundle')) {
            wp_send_json_error(['message' => __('File modifications are disabled on this installation.', 'rrze-updater')], 403);
            return;
        }
        $connectors = $_POST['connectors'] ?? [];
        if (!is_array($connectors)) {
            wp_send_json_error(['message' => __('Invalid connector selection.', 'rrze-updater')], 400);
            return;
        }
        foreach ($connectors as $provider => $id) {
            if (!is_string($id)) {
                wp_send_json_error(['message' => __('Invalid connector selection.', 'rrze-updater')], 400);
                return;
            }
            $connectors[$provider] = sanitize_text_field(wp_unslash($id));
        }
        try {
            $result = (new BundleManager())->handle(
                $operation,
                sanitize_text_field(wp_unslash($_POST['job'] ?? '')),
                (int) ($_POST['revision'] ?? -1),
                $connectors
            );
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 409);
                return;
            }
            wp_send_json_success($result);
        } catch (\Throwable $exception) {
            // Never return exception text: HTTP clients may include credential URLs.
            wp_send_json_error(['message' => __('Could not process or save bundle progress. Reload before continuing and check database availability.', 'rrze-updater')], 500);
        }
    }

    private function allowed(): bool
    {
        return is_multisite() && current_user_can('manage_network_options')
            && current_user_can('install_plugins') && current_user_can('install_themes');
    }

    private function nonceAction(): string
    {
        return self::ACTION . '_' . get_current_network_id();
    }
}

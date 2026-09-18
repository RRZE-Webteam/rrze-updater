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
            __('Install repositories', 'rrze-updater'),
            __('Install repositories', 'rrze-updater'),
            'manage_network_options', self::SLUG, [$this, 'page']
        );
    }

    public function assets(string $hook): void
    {
        if ($hook !== $this->pageHook || $this->pageHook === '') {
            return;
        }
        $root = dirname(__DIR__);
        $asset = require $root . '/build/installer.asset.php';
        wp_enqueue_style('rrze-updater-installer', plugins_url('build/installer.css', $root . '/rrze-updater.php'), ['wp-components'], $asset['version']);
        wp_enqueue_script('rrze-updater-installer', plugins_url('build/installer.js', $root . '/rrze-updater.php'), $asset['dependencies'], $asset['version'], true);
        wp_set_script_translations('rrze-updater-installer', 'rrze-updater', $root . '/languages');
        wp_localize_script('rrze-updater-installer', 'rrzeUpdaterBundles', [
            'url' => network_site_url('wp-admin/admin-ajax.php'), 'action' => self::ACTION,
            'network' => get_current_network_id(), 'nonce' => wp_create_nonce($this->nonceAction()),
            'catalog' => (new Catalog())->get(),
            'recommendedConnectors' => (new BundleManager())->connectorChoices(),
            'connectors' => array_values((new \RRZE\Updater\Core\RepositoryManager(new Settings()))->listConnectors()),
            'servicesUrl' => network_admin_url('admin.php?page=rrze-updater-settings&tab=services'),
            'fileModifications' => wp_is_file_mod_allowed('rrze_updater_bundle'),
        ]);
    }

    public function page(): void
    {
        if (!$this->allowed()) {
            wp_die(esc_html__('Network administration and plugin/theme installation permissions are required.', 'rrze-updater'));
        }
        require __DIR__ . '/views/bundles/index.php';
    }

    public function request(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->allowed()) {
            wp_send_json_error(['message' => __('You cannot manage bundle installations.', 'rrze-updater')], 403);
            return;
        }
        if (!is_scalar($_POST['network'] ?? null) || (int) $_POST['network'] !== get_current_network_id()) {
            wp_send_json_error(['message' => __('The selected network does not match this request.', 'rrze-updater')], 403);
            return;
        }
        check_ajax_referer($this->nonceAction(), 'nonce');
        foreach (['operation', 'job', 'revision', 'connector', 'repository', 'page', 'selection'] as $field) {
            if (isset($_POST[$field]) && !is_scalar($_POST[$field])) {
                wp_send_json_error(['message' => __('Invalid request.', 'rrze-updater')], 400);
                return;
            }
        }
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        if (!in_array($operation, ['status', 'cancel', 'browse_repositories', 'browse_branches'], true) && !wp_is_file_mod_allowed('rrze_updater_bundle')) {
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
            if (in_array($operation, ['browse_repositories', 'browse_branches'], true)) {
                $this->browse($operation);
                return;
            }
            $selection = $_POST['selection'] ?? '[]';
            if (!is_string($selection) || strlen($selection) > 65536) {
                wp_send_json_error(['message' => __('Invalid repository selection.', 'rrze-updater')], 400);
                return;
            }
            $selection = json_decode(wp_unslash($selection), true);
            if (!is_array($selection) || !array_is_list($selection)) {
                wp_send_json_error(['message' => __('Invalid repository selection.', 'rrze-updater')], 400);
                return;
            }
            $result = (new BundleManager())->handle(
                $operation,
                sanitize_text_field(wp_unslash($_POST['job'] ?? '')),
                (int) ($_POST['revision'] ?? -1),
                $connectors, $selection
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

    private function browse(string $operation): void
    {
        $settings = new Settings();
        $connector = $settings->getConnectorById(sanitize_text_field(wp_unslash($_POST['connector'] ?? '')));
        $page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT);
        if (!$connector || !in_array($connector->getType(), ['github', 'gitlab'], true) || !$page || $page < 1 || $page > 1000) {
            wp_send_json_error(['message' => __('Invalid connector or page.', 'rrze-updater')], 400);
            return;
        }
        $api = new \RRZE\Updater\Core\RepositoryDiscovery($connector);
        if ($operation === 'browse_repositories') {
            $result = $api->repositories($page, ($_POST['refresh'] ?? '') === '1');
            if (!is_wp_error($result)) {
                foreach ($result['items'] as &$item) {
                    $item['managed'] = false;
                    foreach (array_merge($settings->plugins, $settings->themes) as $extension) {
                        if ($extension->connectorId === $connector->id && $extension->repository === $item['repository']) {
                            $item['managed'] = true;
                            break;
                        }
                    }
                }
                unset($item);
            }
        } else {
            $repository = wp_unslash($_POST['repository'] ?? '');
            if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $repository) || str_contains($repository, '..')) {
                wp_send_json_error(['message' => __('Invalid repository name.', 'rrze-updater')], 400);
                return;
            }
            $result = $api->branches($repository, $page);
        }
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 409);
            return;
        }
        wp_send_json_success($result);
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

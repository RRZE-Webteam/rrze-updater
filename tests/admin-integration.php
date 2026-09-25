<?php
namespace RRZE\Updater {
    function add_menu_page(...$args) { $GLOBALS['admin_menus'][] = $args; return 'page-' . $args[3]; }
    function add_submenu_page(...$args) { $GLOBALS['admin_menus'][] = $args; return 'page-' . $args[4]; }
}

namespace {
require_once __DIR__ . '/admin-saves.php';

use RRZE\Updater\{AdminIntegration, Config, Main, TokenNotice, UpdateProvider};

// Small admin API fixtures; use WordPress's real hook dispatcher throughout.
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function esc_html__($value, $domain = '') { return esc_html(__($value, $domain)); }
function esc_attr__($value, $domain = '') { return esc_attr(__($value, $domain)); }
function wp_kses($value, $allowed) { return strip_tags($value, '<a><abbr><acronym><code><em><strong>'); }
function wp_kses_post($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_hash($value) { return hash('sha256', $value); }
function get_site_transient($key) { return $GLOBALS['admin_transients'][$key] ?? false; }
function set_site_transient($key, $value) { $GLOBALS['admin_transients'][$key] = $value; return true; }
function is_network_admin() { return $GLOBALS['admin_network_screen'] ?? false; }
function get_current_screen() { return $GLOBALS['admin_screen'] ?? null; }
function self_admin_url($path = '') { return '/wp-admin/' . $path; }
function network_admin_url($path = '') { return '/wp-admin/network/' . $path; }
function wp_nonce_url($url, $action = '-1') { return $url . '&_wpnonce=' . rawurlencode($action); }
function plugins_url($path, $plugin = '') { return '/wp-content/plugins/rrze-updater/' . $path; }
function wp_enqueue_script(...$args) { $GLOBALS['admin_enqueued_scripts'][] = $args; }
function _get_list_table($class) { return new class { public function get_column_count() { return 5; } }; }
function adminOutput(callable $callback): string {
    ob_start();
    try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); }
}
class AdminThemeFixture extends ArrayObject {
    public function get_stylesheet() { return 'target'; }
    public function is_allowed($context) { return true; }
}

$beforeAdminIntegration = $checks;
$savedAdminHooks = $wp_filter;
foreach ([false, true] as $multisite) {
    $wp_filter = [];
    [$settings, $controller] = adminSaveFixture();
    $settings->plugins[0]->installationFolder = $settings->themes[0]->installationFolder = 'target';
    $settings->plugins[0]->remoteVersion = 'next';
    $settings->plugins[0]->localVersion = 'current';
    $settings->themes[0]->remoteVersion = 'next';
    $settings->themes[0]->lastError = 'Unavailable';
    $config = new Config();
    $admin = new AdminIntegration($settings, $controller, $config);
    $admin->register();
    $prefix = $multisite ? 'network_admin' : 'admin';
    check(has_action($prefix . '_menu', [$admin, 'adminMenu']) === 10, 'Admin menu uses the correct site/network hook.');
    check(has_action($prefix . '_notices', [$admin, 'invalidTokenNotice']) === 10, 'Token notice uses the correct site/network hook.');
    check(has_action(($multisite ? 'admin' : 'network_admin') . '_menu', [$admin, 'adminMenu']) === false, 'No menu registered in the other admin context.');
    check(has_action('admin_bar_menu', [$admin, 'adminBarMenu']) === ($multisite ? 100 : false), 'Network shortcut preserves its priority.');

    $admin_menus = [];
    do_action($prefix . '_menu');
    check(count($admin_menus) === 4, 'Registers the repository, plugin, theme and settings pages.');
    check(str_contains($admin_menus[0][1], 'count-1'), 'Badge counts available updates and excludes failed checks.');
    foreach (['getRepoIndex', 'getPluginIndex', 'getThemeIndex', 'getSettingsIndex'] as $i => $method) {
        check($admin_menus[$i][$i === 0 ? 4 : 5] === [$controller, $method], 'Page callback uses the shared controller.');
    }
    foreach (['rrze-updater' => 'repoListScreenOptions', 'rrze-updater-plugins' => 'pluginsListScreenOptions',
        'rrze-updater-themes' => 'themesListScreenOptions', 'rrze-updater-settings' => 'settingsScreenOptions'] as $slug => $method) {
        check(has_action('load-page-' . $slug, [$controller, $method]) === 10, 'Page retains its screen-option callback.');
    }
    check(has_action('load-page-rrze-updater', [$admin, 'enqueueUpdateCheckScript']) === 10,
        'The overview loads its check runner before rendering the dialog.');
    $admin_enqueued_scripts = [];
    $admin->enqueueUpdateCheckScript();
    check($admin_enqueued_scripts[0][0] === 'rrze-updater-check-runner'
        && str_ends_with($admin_enqueued_scripts[0][1], '/assets/js/update-check-runner.js')
        && $admin_enqueued_scripts[0][4] === false, 'The runner is enqueued in the header before the inline dialog script.');
    check(apply_filters('set-screen-option', false, $config->getScreenOptionPerPage(), 37) === 37, 'Updater screen option is saved.');
    check(apply_filters('set-screen-option', false, 'unrelated', 37) === false, 'Other screen options are untouched.');

    $linkHook = $multisite ? 'network_admin_plugin_action_links' : 'plugin_action_links';
    $actions = ['Activate', 'Delete'];
    check(apply_filters($linkHook, $actions, 'other/main.php') === $actions, 'Unmanaged action links are untouched.');
    $links = apply_filters($linkHook, $actions, 'target/target.php');
    check(count($links) === 3 && str_contains($links[1], 'Edit repository'), 'Managed editor link retains its position.');
    check(apply_filters('update_plugin_complete_actions', [], 'other/main.php') === [], 'Unmanaged completion links are untouched.');
    $links = apply_filters('update_plugin_complete_actions', [], 'target/target.php');
    check(str_contains($links['rrze_updater'], $multisite ? '/wp-admin/network/' : '/wp-admin/'), 'Completion link targets the appropriate admin.');

    $source_test_themes = ['target' => new AdminThemeFixture(['Name' => 'Example theme'])];
    foreach (['plugin', 'theme'] as $kind) {
        $key = $kind === 'plugin' ? 'target/target.php' : 'target';
        $rowHook = 'after_' . $kind . '_row_' . $key;
        $coreCallback = 'wp_' . $kind . '_update_row';
        add_action($rowHook, $coreCallback);
        $empty = (object) [];
        check(apply_filters('site_transient_update_' . $kind . 's', $empty) === $empty
            && has_action($rowHook, $coreCallback) === 10, 'Incomplete transient leaves core row registration untouched.');
        $transient = (object) ['checked' => [$key => '1.0'], 'response' => []];
        check(apply_filters('site_transient_update_' . $kind . 's', $transient) === $transient, 'Presentation filter preserves the transient.');
        check(has_action($rowHook, $coreCallback) === false
            && has_action($rowHook, [$admin, 'after' . ucfirst($kind) . 'Row']) === 11, 'Managed row replaces core at the original priority.');
        apply_filters('site_transient_update_' . $kind . 's', $transient);
        check(count($wp_filter[$rowHook]->callbacks[11]) === 1, 'Repeated transient reads do not duplicate rows.');
    }

    $admin_network_screen = $multisite;
    $storage['active_plugins'] = ['target/target.php'];
    $storage['active_sitewide_plugins'] = ['target/target.php' => 1];
    foreach (['plugin', 'theme'] as $kind) {
        $key = $kind === 'plugin' ? 'target/target.php' : 'target';
        $data = $kind === 'plugin' ? ['Name' => 'Example plugin'] : $source_test_themes['target'];
        $hookCalls = [];
        $messageHook = 'in_' . $kind . '_update_message-' . $key;
        add_action($messageHook, static function ($extension, $response) use (&$hookCalls) {
            $hookCalls[] = [$extension, $response]; echo ' extra message';
        }, 10, 2);
        foreach (['unauthorized', 'no-package', 'available'] as $state) {
            $bundle_caps = ['update_' . $kind . 's' => $state !== 'unauthorized'];
            $response = ['slug' => 'target', 'new_version' => '2.0', 'package' => $state === 'no-package' ? '' : 'https://example.test/package.zip'];
            if ($kind === 'plugin') $response = (object) $response;
            $admin_transients['update_' . $kind . 's'] = (object) ['response' => [$key => $response]];
            $html = adminOutput(fn() => do_action('after_' . $kind . '_row_' . $key, $key, $data));
            check(str_contains($html, 'plugin-update-tr active') && str_contains($html, 'colspan="5"'), 'Update row retains active state and table layout.');
            check(str_contains($html, 'Update now') === ($state === 'available'), 'Update action requires permission and a package.');
            check(str_contains($html, 'Automatic update is unavailable') === ($state === 'no-package'), 'Missing package retains its explanatory message.');
            check(str_contains($html, '2.0') && str_contains($html, 'extra message'), 'Version and third-party update message are rendered.');
            check(end($hookCalls) === [$data, $response], 'Dynamic update-message hook retains both arguments.');
        }
        $admin_transients['update_' . $kind . 's'] = false;
        check(adminOutput(fn() => do_action('after_' . $kind . '_row_' . $key, $key, $data)) === '', 'Missing update produces no row.');
    }

    TokenNotice::record($settings->connectors[0], 401);
    foreach ([false, true] as $authorized) {
        foreach (['plugins', 'dashboard', 'dashboard-network'] as $screen) {
            $bundle_caps = [($multisite ? 'manage_network_options' : 'manage_options') => $authorized];
            $admin_screen = (object) ['base' => $screen];
            $html = adminOutput(fn() => do_action($prefix . '_notices'));
            check(($html !== '') === ($authorized && $screen !== 'plugins'), 'Rejected-token notices respect capability and dashboard scope.');
            check(!str_contains($html, $settings->connectors[0]->token), 'Notices never expose the token.');
        }
    }
}

// Exercise production wiring, including presentation-before-metadata ordering.
$wp_filter = [];
$multisite = false;
adminSaveFixture();
$main = new Main();
$main->loaded();
foreach (['plugins', 'themes'] as $kind) {
    $callbacks = array_values($wp_filter['site_transient_update_' . $kind]->callbacks[10]);
    check(count($callbacks) === 2 && $callbacks[0]['function'][0] instanceof AdminIntegration
        && $callbacks[1]['function'][0] instanceof UpdateProvider, 'Bootstrap registers presentation before update metadata at priority 10.');
    $writeCallbacks = array_values($wp_filter['pre_set_site_transient_update_' . $kind]->callbacks[10]);
    check(count($writeCallbacks) === 1 && $writeCallbacks[0]['function'] === $callbacks[1]['function'],
        'Transient reads and writes share the same update provider callback.');
}
$wp_filter = $savedAdminHooks;
echo 'Passed ' . ($checks - $beforeAdminIntegration) . " admin integration checks.\n";
}

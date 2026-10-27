<?php
/**
 * Exercise the real facade, page templates and WordPress list tables on a disposable site.
 *
 * Run with wp eval-file, the source repository argument and --skip-plugins --skip-themes --user=1.
 * Requires the same explicit RRZE_UPDATER_TEST_INSTALLATION guard as the repair suite.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI
    || !defined('RRZE_UPDATER_TEST_INSTALLATION') || RRZE_UPDATER_TEST_INSTALLATION !== true) {
    throw new RuntimeException('Run only on an explicitly marked disposable WordPress installation.');
}
$adminSource = realpath($args[0] ?? dirname(__DIR__));
if (!$adminSource || !is_file($adminSource . '/rrze-updater.php')) {
    throw new RuntimeException('Pass the rrze-updater source directory.');
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once $adminSource . '/rrze-updater.php';
RRZE\Updater\plugin()->loaded();

use RRZE\Updater\{Config, Controller, Settings};
use RRZE\Updater\Core\{GithubConnector, Plugin, Theme};

/**
 * @param bool $condition Expected invariant.
 * @param string $message Explanation.
 * @return void
 */
function updaterAdminAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['updaterAdminChecks']++;
}

/**
 * Render an existing facade entry in its real site/network administration context.
 *
 * @param Controller $controller Facade under test.
 * @param string $method Existing page entry point.
 * @param string $page Menu slug.
 * @param array $query Additional request fields.
 * @return string Rendered WordPress administration HTML.
 */
function updaterAdminPage(Controller $controller, string $method, string $page, array $query = []): string
{
    $_GET = $_REQUEST = ['page' => $page] + $query;
    $_POST = [];
    $GLOBALS['hook_suffix'] = 'toplevel_page_' . $page . (is_multisite() ? '-network' : '');
    set_current_screen($GLOBALS['hook_suffix']);
    ob_start();
    try {
        $controller->$method();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

$GLOBALS['updaterAdminChecks'] = 0;
$_SERVER['HTTP_HOST'] = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
updaterAdminAssert(current_user_can('update_plugins') && current_user_can('update_themes'), 'Run as the disposable administrator.');
updaterAdminAssert(!is_multisite() || is_super_admin(), 'Run as the disposable superadministrator on Multisite.');
$folder = 'rrze-admin-fixture';
$pluginPath = WP_PLUGIN_DIR . '/' . $folder;
$themePath = get_theme_root() . '/' . $folder;
updaterAdminAssert(!file_exists($pluginPath) && !file_exists($themePath), 'Fixture directories must not already exist.');
$settings = new Settings();
updaterAdminAssert($settings->plugins === [] && $settings->themes === [] && $settings->connectors === [], 'Use an empty disposable updater registry.');

/**
 * @param mixed $response Preempted HTTP response.
 * @return WP_Error Block unintended live traffic.
 */
$blockHttp = static function ($response) { return new WP_Error('fixture_http', 'No live services in administration integration tests.'); };
add_filter('pre_http_request', $blockHttp);
mkdir($pluginPath, 0700, true);
mkdir($themePath, 0700, true);
file_put_contents($pluginPath . '/main.php', "<?php\n/* Plugin Name: Admin Fixture\nVersion: 1.0\n*/\n");
file_put_contents($themePath . '/style.css', "/* Theme Name: Admin Fixture Theme\nVersion: 1.0\n*/\n");
file_put_contents($themePath . '/index.php', '<?php');
wp_clean_plugins_cache();
wp_clean_themes_cache();

try {
    $connector = GithubConnector::createFromArray(['id' => 'admin-service', 'owner' => 'example', 'type' => 'github']);
    $plugin = Plugin::createFromArray(['id' => 'admin-plugin', 'connectorId' => $connector->id,
        'repository' => $folder, 'installationFolder' => $folder, 'branch' => 'main',
        'updates' => 'commits', 'localVersion' => 'a', 'remoteVersion' => 'a']);
    $theme = Theme::createFromArray(['id' => 'admin-theme', 'connectorId' => $connector->id,
        'repository' => $folder, 'installationFolder' => $folder, 'branch' => 'main',
        'updates' => 'commits', 'localVersion' => 'a', 'remoteVersion' => 'a']);
    $plugin->connector = $theme->connector = $connector;
    $settings->connectors = [$connector];
    $settings->plugins = [$plugin];
    $settings->themes = [$theme];
    updaterAdminAssert($settings->save(), 'Persist the actual WordPress admin fixture.');
    $controller = new Controller($settings);
    updaterAdminAssert(has_action('wp_ajax_rrze_updater_check_repository_update', [$controller, 'ajaxCheckRepositoryUpdate']) === 10,
        'The public facade retains the existing AJAX hook and priority.');
    updaterAdminAssert(has_action('admin_post_rrze_updater_theme_check', [$controller, 'postThemeCheckRedirect']) === 10,
        'The public facade retains the existing Theme Check hook.');

    foreach ([
        ['getRepoIndex', 'rrze-updater', [], 'Admin Fixture'],
        ['getPluginIndex', 'rrze-updater-plugins', [], 'Admin Fixture'],
        ['getThemeIndex', 'rrze-updater-themes', [], 'Admin Fixture Theme'],
        ['getPluginIndex', 'rrze-updater-plugins', ['action' => 'edit', 'id' => 'admin-plugin'], 'plugin-reinstall'],
        ['getThemeIndex', 'rrze-updater-themes', ['action' => 'edit', 'id' => 'admin-theme'], 'value="edit-theme"'],
        ['getPluginIndex', 'rrze-updater-plugins', ['action' => 'add'], 'value="add-plugin"'],
        ['getThemeIndex', 'rrze-updater-themes', ['action' => 'add'], 'value="add-theme"'],
        ['getSettingsIndex', 'rrze-updater-settings', [], 'rrze-updater-settings'],
        ['getSettingsIndex', 'rrze-updater-settings', ['tab' => 'services'], 'example'],
    ] as [$method, $page, $query, $expected]) {
        $html = updaterAdminPage($controller, $method, $page, $query);
        updaterAdminAssert(str_contains($html, $expected), "$method " . json_encode($query) . " renders '$expected'.");
        updaterAdminAssert(str_contains($html, '<div class="wrap">') && str_contains($html, '<hr>'), 'The page retains its complete administration wrapper.');
    }

    $option = (new Config())->getOptionName();
    /**
     * /**
     * @param mixed $next Proposed settings.
     * @param mixed $previous Stored settings.
     * @return mixed Reject the write.
     */
    $rejectWrite = static function ($next, $previous) { return $previous; };
    $writeHook = (is_multisite() ? 'pre_update_site_option_' : 'pre_update_option_') . $option;
    add_filter($writeHook, $rejectWrite, 10, 2);
    $controller->pluginDelete('admin-plugin');
    remove_filter($writeHook, $rejectWrite, 10);
    $html = updaterAdminPage($controller, 'getRepoIndex', 'rrze-updater');
    updaterAdminAssert(str_contains($html, 'Could not save the changes.'), 'A failed mutation retains its notice across a different facade page.');
    updaterAdminAssert($settings->getPluginById('admin-plugin') !== false, 'Failed deletion reloads the shared registry in place.');

    unlink($pluginPath . '/main.php');
    wp_clean_plugins_cache();
    $html = updaterAdminPage(new Controller($settings), 'getPluginIndex', 'rrze-updater-plugins');
    updaterAdminAssert(str_contains($html, 'Installation incomplete') && str_contains($html, '#plugin-reinstall'), 'A rest directory retains a visible repair action.');
    updaterAdminAssert(count((new Settings())->plugins) === 1, 'Synchronization keeps the incomplete association.');
    rmdir($pluginPath);
    wp_clean_plugins_cache();
    updaterAdminPage(new Controller($settings), 'getPluginIndex', 'rrze-updater-plugins');
    updaterAdminAssert((new Settings())->plugins === [], 'Synchronization removes a fully absent plugin directory.');
    updaterAdminAssert(!file_exists(ABSPATH . '.maintenance'), 'Read-only admin pages never enable maintenance mode.');
    echo 'Passed ' . $GLOBALS['updaterAdminChecks'] . ' real ' . (is_multisite() ? 'network' : 'single-site') . " administration checks.\n";
} finally {
    remove_filter('pre_http_request', $blockHttp);
    $settings->reload();
    $settings->plugins = $settings->themes = $settings->connectors = [];
    $settings->save();
    foreach ([$pluginPath . '/main.php', $themePath . '/style.css', $themePath . '/index.php'] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ([$pluginPath, $themePath] as $directory) {
        if (is_dir($directory)) rmdir($directory);
    }
    wp_clean_plugins_cache();
    wp_clean_themes_cache();
}

<?php
/** Isolated tool-link and theme-check request characterization, using temporary plugin files. */

define('ABSPATH', dirname(__DIR__, 4) . '/');
define('HOUR_IN_SECONDS', 3600);
$toolRoot = sys_get_temp_dir() . '/rrze-admin-links-' . bin2hex(random_bytes(6));
define('WP_PLUGIN_DIR', $toolRoot);

/**
 * @param string $class Production class name.
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Updater\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

/**
 * @param string $text Source message.
 * @param string $domain Text domain.
 * @return string
 */
function __($text, $domain = '') { return $text; }
/**
 * @param mixed $text Text to escape.
 * @return string
 */
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
/**
 * @param mixed $text Attribute value.
 * @return string
 */
function esc_attr($text) { return esc_html($text); }
/**
 * @param string $url URL.
 * @return string
 */
function esc_url($url) { return esc_html($url); }
/**
 * @param string $text Source message.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__($text, $domain = '') { return esc_html($text); }
/**
 * @param string $text Source message.
 * @param string $domain Text domain.
 * @return void
 */
function esc_html_e($text, $domain = '') { echo esc_html($text); }
/**
 * @param string $field Site metadata field.
 * @return void
 */
function bloginfo($field) { echo 'UTF-8'; }
/**
 * @param string $value Request field.
 * @return string
 */
function wp_unslash($value) { return stripslashes($value); }
/**
 * @param string $value Request field.
 * @return string
 */
function sanitize_text_field($value) { return trim(strip_tags($value)); }
/**
 * @param string $path Admin-relative path.
 * @return string
 */
function admin_url($path = '') { return 'https://fixture.test/wp-admin/' . $path; }
/**
 * @param string $path Admin-relative path.
 * @return string
 */
function self_admin_url($path = '') { return admin_url(($GLOBALS['toolNetwork'] ? 'network/' : '') . $path); }
/**
 * @param array $args Query values.
 * @param string $url Base URL.
 * @return string
 */
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
/**
 * @param string $url Base URL.
 * @param string $action Nonce action.
 * @return string
 */
function wp_nonce_url($url, $action) { return add_query_arg(['_wpnonce' => $action], $url); }
/**
 * @param string $action Nonce action.
 * @return string
 */
function wp_create_nonce($action) { return 'nonce:' . $action; }
/**
 * @param string $file Plugin basename.
 * @return bool
 */
function is_plugin_active($file) { return in_array($file, $GLOBALS['toolLocal'], true); }
/**
 * @param string $file Plugin basename.
 * @return bool
 */
function is_plugin_active_for_network($file) { return in_array($file, $GLOBALS['toolNetworkActive'], true); }
/**
 * @param string $capability Capability name.
 * @return bool
 */
function current_user_can($capability) { return $GLOBALS['toolAllowed']; }
/**
 * @param string $message Request failure.
 * @return never
 */
function wp_die($message) { throw new RuntimeException($message); }
/**
 * @param string $action Expected nonce action.
 * @return void
 */
function check_admin_referer($action) {
    if (($GLOBALS['toolNonce'] ?? '') !== $action) throw new RuntimeException('Invalid nonce');
}
/**
 * @param string $stylesheet Theme folder.
 * @return object
 */
function wp_get_theme($stylesheet) {
    return new class {
        /** @return bool Whether the fixture theme exists. */
        public function exists() { return $GLOBALS['toolThemeExists']; }
    };
}

/** Initialize only the registry data needed by URL construction. */
class ToolSettingsFixture extends RRZE\Updater\Settings {
    /** Initialize without reading WordPress options. */
    public function __construct() { $this->plugins = $this->themes = $this->connectors = $this->options = []; }
}
/** Use a known detected basename without a real WordPress plugin cache. */
class ToolInstalledFixture extends RRZE\Updater\InstalledExtensions {
    /**
     * /**
     * @param string $installationFolder Plugin directory.
     * @param string $repository Repository name.
     * @return string
     */
    public function getPluginFile(string $installationFolder, string $repository): string { return $installationFolder . '/actual-main.php'; }
}
/** Stops the redirect response before its production exit statement. */
class ToolResponseCaptured extends RuntimeException {}
/** Capture the public rendering boundary of the theme-check request. */
class ToolRendererFixture extends RRZE\Updater\Admin\AdminRenderer {
    public array $response = [];
    /**
     * /**
     * @param string $view Template name.
     * @param array $data Explicit template data.
     * @return void
     */
    public function partial(string $view, array $data = []): void {
        $this->response = compact('view', 'data');
        throw new ToolResponseCaptured();
    }
}
/**
 * @param bool $condition Invariant.
 * @param string $message Explanation.
 * @return void
 */
function toolCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['toolChecks']++;
}

$toolChecks = 0;
$toolLocal = $toolNetworkActive = [];
$toolNetwork = false;
$toolAllowed = $toolThemeExists = true;
$files = ['plugin-check/plugin.php', 'theme-check/theme-check.php', 'rrze-multisite-manager/rrze-multisite-manager.php'];
$settings = new ToolSettingsFixture();
$installed = new ToolInstalledFixture();
$links = new RRZE\Updater\Admin\AdminLinks($settings, $installed);
$plugin = RRZE\Updater\Core\Plugin::createFromArray(['id' => 'managed', 'repository' => 'repo', 'installationFolder' => 'folder']);
$theme = RRZE\Updater\Core\Theme::createFromArray(['id' => 'theme', 'repository' => 'theme', 'installationFolder' => 'style']);

try {
    toolCheck($links->getPluginCheckUrl($plugin) === '' && $links->getThemeCheckUrl($theme) === ''
        && $links->getMultisiteManagerPluginsUrl() === '', 'Absent optional tools do not expose links.');
    foreach ($files as $file) {
        mkdir(dirname($toolRoot . '/' . $file), 0700, true);
        file_put_contents($toolRoot . '/' . $file, '<?php');
    }
    toolCheck(!$links->isPluginCheckAvailable() && !$links->isThemeCheckAvailable()
        && $links->getMultisiteManagerThemesUrl() === '', 'Installed but inactive tools stay unavailable.');
    foreach ([false, true] as $toolNetwork) {
        foreach (['local', 'network'] as $activation) {
            $toolLocal = $activation === 'local' ? $files : [];
            $toolNetworkActive = $activation === 'network' ? $files : [];
            toolCheck($links->getPluginCheckUrl($plugin) === admin_url('tools.php?page=plugin-check&plugin=folder%2Factual-main.php'), 'Plugin Check targets the detected basename in the site tools page.');
            toolCheck($links->getMultisiteManagerPluginDetailsUrl($plugin) === admin_url('admin.php?page=rrze-multisite-manager-plugin-details&plugin=folder%2Factual-main.php'), 'Plugin detail URL retains its tool route and basename.');
            toolCheck($links->getMultisiteManagerThemeDetailsUrl($theme) === admin_url('admin.php?page=rrze-multisite-manager-theme-details&theme=style'), 'Theme detail URL retains its stylesheet.');
            toolCheck($links->getMultisiteManagerPluginsUrl() === admin_url('admin.php?page=rrze-multisite-manager-plugin-overview')
                && $links->getMultisiteManagerThemesUrl() === admin_url('admin.php?page=rrze-multisite-manager-theme-overview'), 'Overview tool links preserve their site-admin routes.');
            toolCheck($links->getThemeCheckUrl($theme) === admin_url('admin-post.php?action=rrze_updater_theme_check&theme=style&_wpnonce=rrze-updater-theme-check_style'), 'Theme Check retains its POST bridge and nonce action.');
            toolCheck($links->getPluginUpdateUrl($plugin) === self_admin_url('update.php?action=upgrade-plugin&plugin=folder/actual-main.php&_wpnonce=upgrade-plugin_folder%2Factual-main.php'), 'Plugin update URL retains its context and core nonce.');
            toolCheck($links->getThemeUpdateUrl($theme) === self_admin_url('update.php?action=upgrade-theme&theme=style&_wpnonce=upgrade-theme_style'), 'Theme update URL retains its context and core nonce.');
            toolCheck($links->getPluginEditUrl('managed') === self_admin_url('admin.php?page=rrze-updater-plugins&action=edit&id=managed'), 'Repair editor link retains its context and managed ID.');
        }
    }

    $renderer = new ToolRendererFixture();
    $actions = new RRZE\Updater\RepositoryActions($settings);
    $presenter = new RRZE\Updater\Admin\RepositoryPresenter($settings, $installed, $links);
    $controller = new RRZE\Updater\Admin\ThemeController($settings, $actions,
        new RRZE\Updater\SettingsSynchronizer($settings, $installed, $actions), $presenter, $links,
        $renderer, new RRZE\Updater\Core\RepositoryManager($settings));
    foreach (['permission', 'missing-theme', 'nonce', 'inactive-tool', 'absent-theme', 'success'] as $case) {
        $toolAllowed = $case !== 'permission';
        $toolThemeExists = $case !== 'absent-theme';
        $toolLocal = $case === 'inactive-tool' ? [] : $files;
        $toolNetworkActive = [];
        $toolNonce = $case === 'nonce' ? 'incorrect' : 'rrze-updater-theme-check_style';
        $_GET = ['theme' => $case === 'missing-theme' ? '' : 'style'];
        $renderer->response = [];
        try { $controller->postThemeCheckRedirect(); }
        catch (ToolResponseCaptured $captured) { toolCheck($case === 'success', 'Only an authorized valid theme check may render its bridge.'); }
        catch (RuntimeException $failure) { toolCheck($case !== 'success', 'Valid theme checks must reach the form response.'); }
        toolCheck(($renderer->response !== []) === ($case === 'success'), 'Denied theme checks do not render or submit a form.');
    }
    toolCheck($renderer->response['view'] === 'themes/check-redirect'
        && $renderer->response['data'] === ['stylesheet' => 'style', 'themeCheckUrl' => admin_url('themes.php?page=themecheck'), 'themeCheckNonce' => 'nonce:themecheck-nonce'], 'The response carries the existing Theme Check form contract.');
    $data = $renderer->response['data'];
    ob_start();
    require dirname(__DIR__) . '/includes/views/themes/check-redirect.php';
    $html = ob_get_clean();
    toolCheck(str_contains($html, 'method="post"') && str_contains($html, 'name="themename" value="style"')
        && str_contains($html, 'name="_wpnonce" value="nonce:themecheck-nonce"'), 'The extracted view submits the expected theme and nonce.');
    toolCheck(str_contains($html, '<noscript>') && str_contains($html, '.submit();'), 'Both the automatic submission and no-JavaScript fallback remain.');
    echo "Passed $toolChecks optional tool and theme-check request checks.\n";
} finally {
    foreach ($files as $file) {
        if (is_file($toolRoot . '/' . $file)) unlink($toolRoot . '/' . $file);
        if (is_dir(dirname($toolRoot . '/' . $file))) rmdir(dirname($toolRoot . '/' . $file));
    }
    if (is_dir($toolRoot)) rmdir($toolRoot);
}

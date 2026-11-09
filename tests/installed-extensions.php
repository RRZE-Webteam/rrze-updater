<?php
// Isolated metadata and synchronization regressions; no WordPress database or remote API.

define('ABSPATH', dirname(__DIR__, 4) . '/');
define('HOUR_IN_SECONDS', 3600);
define('DB_NAME', 'metadata-fixture');
$metadataRoot = sys_get_temp_dir() . '/rrze-installed-' . bin2hex(random_bytes(6));
define('WP_PLUGIN_DIR', $metadataRoot . '/plugins');
require ABSPATH . 'wp-includes/class-wp-error.php';

/**
 * Load production classes without initializing the plugin lifecycle.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RRZE\\Updater\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

/**
 * @param string $text Source string.
 * @param string $domain Text domain.
 * @return string Untranslated fixture text.
 */
function __($text, $domain = '') { return $text; }
/**
 * @param mixed $value Text value.
 * @return string Trimmed plain text.
 */
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
/**
 * @param string $path Directory path.
 * @return string Path ending with one slash.
 */
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
/**
 * @return array Recognized plugin paths in fixture discovery order.
 */
function get_plugins() {
    if ($GLOBALS['metadataRequireLock'] ?? false) {
        metadataCheck($GLOBALS['wpdb']->locked, 'Plugin discovery occurs inside the shared filesystem lock.');
    }
    return $GLOBALS['metadataPlugins'];
}
/**
 * Parse the relevant headers from actual temporary files.
 *
 * @param string $file Absolute plugin path.
 * @return array Plugin name and version headers.
 */
function get_plugin_data($file) {
    $content = file_get_contents($file);
    $data = [];
    foreach (['Name' => 'Plugin Name', 'Version' => 'Version'] as $key => $header) {
        preg_match('/^\s*' . preg_quote($header, '/') . ':\s*(.*?)\s*$/m', $content, $match);
        $data[$key] = $match[1] ?? '';
    }
    return $data;
}
/**
 * @param string $folder Stylesheet directory.
 * @return MetadataThemeFixture Theme fixture.
 */
function wp_get_theme($folder) { return new MetadataThemeFixture($GLOBALS['metadataThemes'][$folder] ?? null); }
/**
 * @return array Installed themes.
 */
function wp_get_themes() {
    if ($GLOBALS['metadataRequireLock'] ?? false) {
        metadataCheck($GLOBALS['wpdb']->locked, 'Theme discovery occurs inside the shared filesystem lock.');
    }
    return $GLOBALS['metadataThemes'];
}
/**
 * @param string $folder Optional stylesheet directory.
 * @return string Absolute theme root.
 */
function get_theme_root($folder = '') { return $GLOBALS['metadataRoot'] . '/themes'; }
/**
 * @param mixed $value Checked result.
 * @return bool Whether it is a WordPress error.
 */
function is_wp_error($value) { return $value instanceof WP_Error; }
/**
 * @return bool Single-site scope for fixture database locks.
 */
function is_multisite() { return false; }
/**
 * @return int Fixture site ID.
 */
function get_current_blog_id() { return 1; }
/**
 * @param string $hook Hook name.
 * @param callable $callback Callback.
 * @param int $priority Priority.
 * @return void
 */
function add_action($hook, $callback, $priority = 10) {}
/**
 * @param string $hook Hook name.
 * @param mixed ...$args Hook arguments.
 * @return void
 */
function do_action($hook, ...$args) {}
/**
 * @param string $name Update transient.
 * @return void
 */
function delete_site_transient($name) { $GLOBALS['metadataInvalidated'][] = $name; }

/** Minimal theme data with WordPress-compatible existence and field lookups. */
class MetadataThemeFixture {
    /**
     * @param array|null $data Theme headers, or null for an absent theme.
     */
    public function __construct(private ?array $data) {}
    /**
     * @return bool Whether WordPress recognizes this theme.
     */
    public function exists() { return $this->data !== null; }
    /**
     * @param string $key Header name.
     * @return string Header value or an empty string.
     */
    public function get($key) { return $this->data[$key] ?? ''; }
}

/** Record lock ownership so discovery and persistence can be checked together. */
class MetadataDatabaseFixture {
    public string $base_prefix = 'wp_';
    public bool $deny = false;
    public bool $locked = false;
    /**
     * @param string $sql Query template.
     * @param string $name Lock name.
     * @return string Fixture query.
     */
    public function prepare($sql, $name) { return str_replace('%s', $name, $sql); }
    /**
     * @param string $sql Lock query.
     * @return string MySQL-style success flag.
     */
    public function get_var($sql) {
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->locked = false;
            return '1';
        }
        if ($this->deny) return '0';
        $this->locked = true;
        return '1';
    }
}

/** Store copies of fixtures to exercise in-place reload after rejected writes. */
class MetadataSettingsFixture extends RRZE\Updater\Settings {
    public bool $fail = false;
    public int $saveCalls = 0;
    public int $reloadCalls = 0;
    public bool $lastSaveLocked = false;
    private array $snapshot = [];
    /** Initialize without accessing WordPress options. */
    public function __construct() {
        $this->plugins = $this->themes = $this->connectors = $this->options = [];
        $this->remember();
    }
    /**
     * @return void Copy current values as the persisted fixture state.
     */
    public function remember(): void {
        $this->snapshot = unserialize(serialize([$this->plugins, $this->themes, $this->connectors, $this->options]));
    }
    /**
     * @return bool Whether the fixture write succeeds.
     */
    public function save() {
        $this->saveCalls++;
        $this->lastSaveLocked = $GLOBALS['wpdb']->locked;
        if ($this->fail) return false;
        $this->remember();
        return true;
    }
    /**
     * @return void Restore rejected edits on the original settings object.
     */
    public function reload(): void {
        $this->reloadCalls++;
        [$this->plugins, $this->themes, $this->connectors, $this->options] = unserialize(serialize($this->snapshot));
    }
}

/** Exercise check persistence without a remote API request. */
class MetadataCheckedPlugin extends RRZE\Updater\Core\Plugin {
    public int $checks = 0;
    /**
     * @return void Record fresh check metadata on the managed definition.
     */
    public function checkForUpdates() {
        $this->checks++;
        $this->remoteVersion = 'checked-version';
        $this->lastError = 'remote-error';
    }
}

/**
 * @param bool $condition Expected invariant.
 * @param string $message Failure detail.
 * @return void
 */
function metadataCheck(bool $condition, string $message): void {
    $GLOBALS['metadataChecks']++;
    if (!$condition) throw new RuntimeException($message);
}
/**
 * @param string $path Fixture-relative path.
 * @param string $contents File body.
 * @return void
 */
function metadataFile(string $path, string $contents): void {
    $absolute = $GLOBALS['metadataRoot'] . '/' . $path;
    if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0700, true);
    file_put_contents($absolute, $contents);
}
/**
 * @param string $folder Installation directory.
 * @param string $repository Repository name.
 * @return RRZE\Updater\Core\Plugin Definition.
 */
function metadataPlugin(string $folder, string $repository = 'repository'): RRZE\Updater\Core\Plugin {
    $plugin = new RRZE\Updater\Core\Plugin();
    $plugin->id = $folder;
    $plugin->installationFolder = $folder;
    $plugin->repository = $repository;
    return $plugin;
}
/**
 * @param string $folder Stylesheet directory.
 * @return RRZE\Updater\Core\Theme Definition.
 */
function metadataTheme(string $folder): RRZE\Updater\Core\Theme {
    $theme = new RRZE\Updater\Core\Theme();
    $theme->id = $theme->installationFolder = $folder;
    return $theme;
}
/**
 * @param string $path Fixture path, never followed through symbolic links.
 * @return void
 */
function metadataRemove(string $path): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) metadataRemove($path . '/' . $name);
        rmdir($path);
    }
}

$metadataChecks = 0;
$metadataPlugins = $metadataThemes = $metadataInvalidated = [];
$wpdb = new MetadataDatabaseFixture();
$installed = new RRZE\Updater\InstalledExtensions();
mkdir(WP_PLUGIN_DIR, 0700, true);
try {
    metadataFile('plugins/multiple/first.php', "Plugin Name: First\n");
    metadataFile('plugins/multiple/second.php', "Plugin Name: Second\nVersion: 2.0\n");
    metadataFile('plugins/multiple/package.json', '{"version":"3.0"}');
    $metadataPlugins = ['multiple/first.php' => [], 'multiple/second.php' => []];
    $plugin = metadataPlugin('multiple');
    metadataCheck($installed->getPluginFile('multiple', 'repository') === 'multiple/first.php', 'Admin chooses the first recognized entry point.');
    metadataCheck($installed->pluginVersion('multiple', 'repository') === '3.0', 'Admin falls back directly to package.json when the selected header has no version.');
    metadataCheck($installed->emailVersion($plugin) === '2.0', 'Email continues to later recognized entry points before trying package.json.');
    metadataCheck($installed->pluginName('multiple', 'repository') === 'First', 'Admin name follows the selected entry point.');
    metadataCheck(!$installed->isPluginIncomplete($plugin), 'A recognized entry point marks the installation complete.');
    $metadataPlugins = array_reverse($metadataPlugins, true);
    metadataCheck($installed->pluginVersion('multiple', 'repository') === '2.0', 'WordPress discovery order is preserved rather than sorted.');

    $metadataPlugins = ['prefix-neighbor/main.php' => []];
    metadataCheck($installed->getInstalledPluginFile('prefix') === '', 'Local discovery matches a complete folder instead of a prefix.');
    metadataFile('plugins/conventional/repository.php', '');
    metadataFile('plugins/conventional/conventional.php', "Plugin Name: Folder\nVersion: 4.0\n");
    metadataFile('plugins/conventional/a-header.php', "Plugin Name: Header\nVersion: 5.0\n");
    metadataCheck($installed->getPluginFile('conventional', 'repository') === 'conventional/repository.php', 'Repository-named file takes precedence over folder and header candidates.');
    metadataCheck($installed->pluginVersion('conventional', 'repository') === '&mdash;', 'Admin does not skip an existing conventional file with an empty header.');
    metadataCheck($installed->emailVersion(metadataPlugin('conventional')) === '4.0', 'Email can continue to the folder-named version header.');
    unlink(WP_PLUGIN_DIR . '/conventional/repository.php');
    metadataCheck($installed->getPluginFile('conventional', 'repository') === 'conventional/conventional.php', 'Folder-named file is the second conventional candidate.');
    unlink(WP_PLUGIN_DIR . '/conventional/conventional.php');
    metadataCheck($installed->getPluginFile('conventional', 'repository') === 'conventional/a-header.php', 'A plugin header identifies non-conventional filenames.');
    metadataFile('plugins/scan/.hidden.php', "Plugin Name: Hidden\nVersion: 9.0\n");
    metadataFile('plugins/scan/a-no-header.php', '<?php');
    metadataFile('plugins/scan/b-valid.php', "Plugin Name: Visible\nVersion: 1.0\n");
    metadataFile('plugins/scan/z-valid.php', "Plugin Name: Last\nVersion: 9.0\n");
    metadataCheck($installed->getPluginFile('scan', 'repository') === 'scan/b-valid.php', 'Header fallback ignores hidden and nameless files, retaining alphabetical discovery.');
    metadataCheck($installed->getPluginFile('missing', 'repository') === 'missing/repository.php', 'An absent plugin keeps its conventional path fallback.');
    metadataCheck($installed->pluginVersion('missing', 'repository') === '&mdash;' && $installed->emailVersion(metadataPlugin('missing')) === 'n/a', 'Unknown version placeholders remain specific to admin and email.');
    metadataCheck($installed->emailVersion(new stdClass()) === 'n/a', 'Email keeps its fallback for unknown extension types.');

    metadataFile('plugins/fallback/package.json', '{"name":"Package Name","version":" 6.0 "}');
    metadataCheck($installed->pluginName('fallback', 'repository') === 'Package Name' && $installed->pluginVersion('fallback', 'repository') === '6.0', 'Package metadata provides trimmed name and version fallbacks.');
    foreach (["=== Readme Name ===\n", "# Readme Name\n", "Plugin Name: Readme Name\n", "=== Readme\xc2\xa0Name ===\n"] as $readme) {
        metadataFile('plugins/fallback/readme.txt', $readme);
        metadataCheck($installed->pluginName('fallback', 'repository') === 'Readme Name', 'Supported readme names precede package metadata.');
    }
    metadataFile('plugins/fallback/readme.txt', 'unrecognized text');
    foreach (['{"version":42,"name":false}', 'invalid-json', '{"version":"","name":""}'] as $package) {
        metadataFile('plugins/fallback/package.json', $package);
        metadataCheck($installed->pluginName('fallback', 'repository') === 'repository' && $installed->pluginVersion('fallback', 'repository') === '&mdash;', 'Invalid or empty metadata leaves the existing repository and version fallbacks.');
    }
    metadataCheck($installed->pluginName('missing', '') === 'missing', 'The folder is the final name fallback when no repository is given.');
    metadataFile('plugins/fallback/package.json', '{"version":" 0 ","name":" 0 "}');
    metadataCheck($installed->pluginVersion('fallback', 'repository') === '0' && $installed->emailVersion(metadataPlugin('fallback')) === '0' && $installed->pluginName('fallback', 'repository') === '0', 'Trimmed numeric-looking strings are retained, rather than treated as absent.');

    $metadataThemes = ['present' => ['Name' => ' Theme Name ', 'Version' => '7.0'], 'empty' => []];
    metadataCheck($installed->themeName('present') === 'Theme Name' && $installed->themeVersion('present') === '7.0', 'Theme headers supply the administrative name and version.');
    metadataCheck($installed->themeVersion('empty') === '' && $installed->emailVersion(metadataTheme('empty')) === 'n/a', 'Existing themes with empty versions preserve the admin/email distinction.');
    metadataFile('themes/missing/readme.txt', '# Readme Theme');
    metadataFile('themes/missing/package.json', '{"name":"Package Theme","version":"8.0"}');
    metadataCheck($installed->themeName('missing') === 'Readme Theme', 'Unrecognized themes can use their readme name.');
    unlink($metadataRoot . '/themes/missing/readme.txt');
    metadataCheck($installed->themeName('missing') === 'Package Theme', 'Theme names fall back to package metadata.');
    metadataCheck($installed->themeVersion('missing') === '&mdash;' && $installed->emailVersion(metadataTheme('missing')) === 'n/a', 'Theme versions never adopt package.json versions.');
    metadataCheck($installed->themeName('absent') === 'absent', 'Theme directory is the final name fallback.');

    mkdir(WP_PLUGIN_DIR . '/rest', 0700);
    symlink(WP_PLUGIN_DIR . '/absent', WP_PLUGIN_DIR . '/broken-link');
    metadataCheck($installed->pluginDestinationExists('rest') && $installed->pluginDestinationExists('broken-link'), 'Remaining folders and dangling links retain their mappings for inspection.');
    foreach (['', '.', '..', '../rest', 'nested/rest', 'nested\\rest', "nul\0rest", 'absent'] as $unsafe) {
        metadataCheck(!$installed->pluginDestinationExists($unsafe), 'Unsafe paths and absent destinations are not retained.');
    }
    metadataCheck($installed->isPluginIncomplete(metadataPlugin('rest')), 'A rest folder without a recognized entry point is incomplete.');

    $settings = new MetadataSettingsFixture();
    $settings->plugins = [metadataPlugin('multiple'), metadataPlugin('rest'), metadataPlugin('absent')];
    $settings->themes = [metadataTheme('present'), metadataTheme('missing')];
    $settings->remember();
    $metadataPlugins = ['multiple/first.php' => []];
    $synchronizer = new RRZE\Updater\SettingsSynchronizer($settings, $installed);
    $metadataRequireLock = true;
    $wpdb->deny = true;
    $result = $synchronizer->synchronize();
    metadataCheck(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_filesystem_busy' && count($settings->plugins) === 3 && $settings->saveCalls === 0, 'A busy filesystem prevents discovery/pruning/persistence.');
    $wpdb->deny = false;
    $settings->fail = true;
    $result = $synchronizer->synchronize();
    metadataCheck(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_save_failed' && count($settings->plugins) === 3 && $settings->reloadCalls === 1, 'Failed synchronization restores stored values on the shared settings object.');
    metadataCheck($settings->lastSaveLocked && !$wpdb->locked, 'The filesystem lock covers persistence and is released after failure.');
    $settings->fail = false;
    metadataCheck($synchronizer->synchronize() === true && array_map(fn($entry) => $entry->id, $settings->plugins) === ['multiple', 'rest'], 'Synchronization removes only fully absent plugins.');
    metadataCheck(count($settings->themes) === 1 && $settings->themes[0]->id === 'present' && $settings->lastSaveLocked && !$wpdb->locked, 'Theme pruning and successful save remain within the same lock.');
    $metadataRequireLock = false;

    $actions = new RRZE\Updater\RepositoryActions($settings);
    $metadataInvalidated = [];
    $settings->fail = true;
    metadataCheck(is_wp_error($actions->deletePlugin('multiple')) && count($settings->plugins) === 2 && $metadataInvalidated === [], 'Rejected deletion restores the mapping without clearing update caches.');
    $settings->fail = false;
    metadataCheck($actions->deletePlugin(' multiple ') === true && $settings->getPluginById('multiple') === false && $metadataInvalidated === ['update_plugins'], 'Successful plugin deletion sanitizes IDs and clears the cache only after save.');
    $previousSaves = $settings->saveCalls;
    metadataCheck($actions->deletePlugin('absent') === true && $settings->saveCalls === $previousSaves, 'Deleting an absent mapping is a successful no-op.');
    metadataCheck($actions->deleteTheme('present') === true && $metadataInvalidated === ['update_plugins', 'update_themes'], 'Theme deletion retains success-only invalidation.');

    $connector = new RRZE\Updater\Core\GithubConnector();
    $connector->id = 'used';
    $unused = clone $connector;
    $unused->id = 'unused';
    $settings->connectors = [$connector, $unused];
    $settings->plugins[1]->connectorId = 'used';
    $settings->remember();
    $metadataInvalidated = [];
    metadataCheck($actions->deleteConnector('used') === true && count($settings->connectors) === 2, 'Used connectors remain in the registry.');
    $previousSaves = $settings->saveCalls;
    metadataCheck($actions->deleteConnector('absent') === true && $settings->saveCalls === $previousSaves, 'An absent connector does not trigger a write.');
    $settings->fail = true;
    metadataCheck(is_wp_error($actions->deleteConnector('unused')) && count($settings->connectors) === 2, 'Failed connector deletion reloads the original mappings.');
    $settings->fail = false;
    metadataCheck($actions->deleteConnector(' unused ') === true && count($settings->connectors) === 1 && $metadataInvalidated === [], 'Unused connectors are removed without introducing cache invalidation.');

    $checked = new MetadataCheckedPlugin();
    $checked->id = 'checked';
    $checked->remoteVersion = 'before-check';
    $settings->plugins = [$checked];
    $settings->remember();
    metadataCheck($actions->check($checked) === true && $checked->checks === 1 && $checked->lastError === 'remote-error' && $metadataInvalidated === [], 'The check service persists remote success/error metadata without invalidating caches.');
    $checked->remoteVersion = 'persisted-version';
    $settings->remember();
    $settings->fail = true;
    metadataCheck(is_wp_error($actions->check($checked)) && $settings->plugins[0]->remoteVersion === 'persisted-version' && $metadataInvalidated === [], 'A rejected check result reloads the stored version in place.');
    echo 'Passed ' . $metadataChecks . " installed metadata and synchronization checks.\n";
} finally {
    RRZE\Updater\FilesystemLock::releaseAll();
    metadataRemove($metadataRoot);
}

<?php
/**
 * Optional network integration: actual maintenance files, core upgraders and rendered notices.
 *
 * Run with wp eval-file on an explicitly marked disposable Multisite, as documented
 * for wordpress-repair.php. That suite supplies the guarded bootstrap and also runs
 * its real replacement/rollback tests. No live provider or loopback HTTP is needed.
 */
require __DIR__ . '/wordpress-repair.php';

use RRZE\Updater\{AdminIntegration, Config, Controller, MaintenanceNotice, Settings};
use RRZE\Updater\Core\{GithubConnector, Plugin, Theme};
use RRZE\Updater\Upgrader\PluginPackageReplacement;
use RRZE\Updater\Admin\{AdminRenderer, RepositoryUpdateRunner};
use RRZE\Updater\InstalledExtensions;

if (!is_multisite()) {
    throw new RuntimeException('The maintenance integration requires a disposable Multisite.');
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
set_current_screen('plugins-network');

/** Supply an already approved automatic update without outside connectivity checks. */
class MaintenanceAutomaticFixture extends WP_Automatic_Updater
{
    /**
     * Authorize only the disposable fixture update supplied by the test.
     *
     * @param string $type Extension type.
     * @param object $item Offered fixture update.
     * @param string $context Installation directory.
     * @return bool Whether this fixture should be processed.
     */
    public function should_update($type, $item, $context) { return true; }

    /**
     * Avoid a loopback request for the inert fixture PHP file.
     *
     * @return bool Always false; fatal-error detection is outside this UI test.
     */
    protected function has_fatal_error() { return false; }
}

/** Provide a local archive through the replacement's real GitHub adapter boundary. */
class MaintenanceArchiveFixture extends GithubConnector
{
    /** @var string Test-owned archive copied for each replacement. */
    public string $archive;

    /**
     * Copy a fixture archive so normal cleanup can consume the downloaded package.
     *
     * @param string $repository Fixture repository.
     * @param string $branch Reviewed commit.
     * @return string|bool Temporary package or false on copy failure.
     */
    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool
    {
        $copy = wp_tempnam('maintenance-copy.zip');
        return copy($this->archive, $copy) ? $copy : false;
    }
}

/** Measure only the real replacement maintenance phase without product telemetry. */
class MaintenanceMeasuredReplacement extends PluginPackageReplacement
{
    /** @var float Monotonic start time, zero until maintenance actually begins. */
    public float $started = 0;

    /**
     * Observe the actual maintenance marker after delegating its creation.
     *
     * @param object $filesystem WordPress filesystem adapter.
     * @return array|WP_Error Owned maintenance state or the original error.
     */
    protected function beginMaintenance(object $filesystem): array|WP_Error
    {
        $result = parent::beginMaintenance($filesystem);
        if (!is_wp_error($result)) {
            $this->started = microtime(true);
            clearstatcache(true, ABSPATH . '.maintenance');
            rrzeRepairAssert(is_file(ABSPATH . '.maintenance'), 'Replacement activates real WordPress maintenance.');
        }
        return $result;
    }
}

/** Render production progress fragments without requiring the plugin lifecycle proxy to expose paths. */
class MaintenanceProgressRenderer extends AdminRenderer
{
    /** @param string $source Absolute source repository directory. */
    public function __construct(private string $source) {}

    /**
     * Include the same fragments used by a normal administrative update.
     *
     * @param string $view Relative view name.
     * @param array $data Explicit rendering data.
     * @return void
     */
    public function partial(string $view, array $data = []): void
    {
        include $this->source . '/includes/views/' . $view . '.php';
    }
}

/**
 * Create an inert plugin or theme in a real fixture directory and optional ZIP.
 *
 * @param string $type plugin or theme.
 * @param string $folder Destination directory basename.
 * @param string $archive ZIP destination; empty to create installed files only.
 * @param int $files Extra payload files for a representative larger package.
 * @return void
 */
function maintenancePackage(string $type, string $folder, string $archive = '', int $files = 0): void
{
    $root = ($type === 'plugin' ? WP_PLUGIN_DIR : get_theme_root()) . '/' . $folder;
    wp_mkdir_p($root);
    $contents = $type === 'plugin'
        ? ['main.php' => "<?php\n/* Plugin Name: Maintenance Fixture\nVersion: 2.0\n*/\n"]
        : ['style.css' => "/* Theme Name: Maintenance Fixture\nVersion: 2.0\n*/\n", 'index.php' => '<?php'];
    if ($archive === '') {
        foreach ($contents as $file => $body) file_put_contents($root . '/' . $file, $body);
        if ($files > 0) {
            wp_mkdir_p($root . '/old-payload');
            for ($index = 0; $index < $files; $index++) file_put_contents($root . '/old-payload/' . $index . '.txt', str_repeat('old-fixture-', 512));
        }
        return;
    }
    $zip = new ZipArchive();
    rrzeRepairAssert($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Create local update package.');
    foreach ($contents as $file => $body) $zip->addFromString($folder . '/' . $file, $body);
    for ($index = 0; $index < $files; $index++) $zip->addFromString($folder . '/payload/' . $index . '.txt', str_repeat('fixture-', 512));
    $zip->close();
}

$maintenanceStartChecks = $GLOBALS['rrzeRepairChecks'];
$maintenanceTemp = get_temp_dir() . 'rrze-maintenance-' . bin2hex(random_bytes(8));
mkdir($maintenanceTemp);
$maintenanceOriginal = ['active_plugins' => get_option('active_plugins'), 'stylesheet' => get_option('stylesheet'), 'template' => get_option('template')];
$maintenanceNetworkActive = get_site_option('active_sitewide_plugins');
$maintenanceTransients = [get_site_transient('update_plugins'), get_site_transient('update_themes')];
$maintenanceObserved = [];
/**
 * Observe actual maintenance during the core installation phase without changing it.
 *
 * @param mixed $response Original pre-install result.
 * @return mixed Unchanged result.
 */
$maintenanceObserve = static function ($response) use (&$maintenanceObserved) {
    // WP-CLI bypasses the visitor-facing maintenance check, so inspect its real marker.
    clearstatcache(true, ABSPATH . '.maintenance');
    $maintenanceObserved[] = is_file(ABSPATH . '.maintenance');
    return $response;
};
/**
 * Block unrelated WordPress background HTTP during deterministic local upgrades.
 *
 * @return WP_Error Local fixture network boundary.
 */
$maintenanceHttp = static fn() => new WP_Error('fixture_http', 'External HTTP is disabled by the isolated test.');
add_filter('pre_http_request', $maintenanceHttp);
add_filter('upgrader_pre_install', $maintenanceObserve, 999);
try {
    foreach (['maintenance-theme', 'maintenance-parent', 'maintenance-other'] as $folder) maintenancePackage('theme', $folder);
    maintenancePackage('plugin', 'maintenance-plugin');
    update_option('stylesheet', 'maintenance-theme');
    update_option('template', 'maintenance-parent');
    wp_clean_themes_cache();
    wp_clean_plugins_cache();

    foreach ([['manual', 'plugin', 'maintenance-plugin', false], ['manual', 'plugin', 'maintenance-plugin', true],
        ['core-bulk', 'plugin', 'maintenance-plugin', false], ['core-bulk', 'plugin', 'maintenance-plugin', true],
        ['manual', 'theme', 'maintenance-theme', false], ['manual', 'theme', 'maintenance-parent', false],
        ['manual', 'theme', 'maintenance-other', false], ['core-bulk', 'theme', 'maintenance-other', false],
        ['automatic', 'plugin', 'maintenance-plugin', false], ['automatic', 'plugin', 'maintenance-plugin', true],
        ['automatic', 'theme', 'maintenance-other', false],
    ] as $index => [$action, $type, $folder, $active]) {
        $target = $type === 'plugin' ? $folder . '/main.php' : $folder;
        update_site_option('active_sitewide_plugins', $active ? ['maintenance-plugin/main.php' => time()] : []);
        $archive = $maintenanceTemp . '/update-' . $index . '.zip';
        maintenancePackage($type, $folder, $archive);
        $offer = ['slug' => $folder, $type => $target, 'new_version' => '2.0', 'package' => $archive, 'url' => 'https://fixture.invalid'];
        $transientOffer = $type === 'plugin' ? (object) $offer : $offer;
        set_site_transient('update_' . $type . 's', (object) ['last_checked' => time(), 'checked' => [$target => '1.0'], 'response' => [$target => $transientOffer], 'no_update' => []]);
        $maintenanceObserved = [];
        $upgrader = $type === 'plugin' ? new Plugin_Upgrader(new Automatic_Upgrader_Skin()) : new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result = match ($action) {
            'core-bulk' => $upgrader->bulk_upgrade([$target]),
            'automatic' => (new MaintenanceAutomaticFixture())->update($type, (object) $offer),
            default => $upgrader->upgrade($target),
        };
        if ($action === 'core-bulk') $result = is_array($result) ? ($result[$target] ?? false) : false;
        rrzeRepairSuccess($result, 'Real core update succeeds: ' . $action . '/' . $type . '/' . $folder);
        rrzeRepairAssert($maintenanceObserved !== [] && in_array(true, $maintenanceObserved, true) === MaintenanceNotice::applies($action, $type, $folder),
            'Notice classification matches actual maintenance: ' . $action . '/' . $folder . ' observed=' . wp_json_encode($maintenanceObserved));
        rrzeRepairAssert(!file_exists(ABSPATH . '.maintenance'), 'Core cleans its maintenance marker after updating.');
    }

    // Exercise the extracted runner itself with both real single upgraders.
    $runnerSettings = new Settings();
    $runnerOffers = [];
    foreach (['plugin' => 'maintenance-plugin', 'theme' => 'maintenance-theme'] as $type => $folder) {
        $archive = $maintenanceTemp . '/runner-' . $type . '.zip';
        maintenancePackage($type, $folder);
        maintenancePackage($type, $folder, $archive);
        $target = $type === 'plugin' ? $folder . '/main.php' : $folder;
        $definition = ['id' => 'runner-' . $type, 'repository' => $folder, 'installationFolder' => $folder,
            'localVersion' => 'before', 'remoteVersion' => 'after'];
        $runnerSettings->{$type . 's'} = [$type === 'plugin' ? Plugin::createFromArray($definition) : Theme::createFromArray($definition)];
        $offer = ['slug' => $folder, $type => $target, 'new_version' => '2.0', 'package' => $archive, 'url' => 'https://fixture.invalid'];
        $runnerOffers[$type] = (object) ['last_checked' => time(), 'checked' => [$target => '1.0'],
            'response' => [$target => $type === 'plugin' ? (object) $offer : $offer], 'no_update' => []];
    }
    /** @return object Local plugin offer, even after the runner refreshes core caches. */
    $runnerPluginOffer = static fn() => $runnerOffers['plugin'];
    /** @return object Local theme offer, even after the runner refreshes core caches. */
    $runnerThemeOffer = static fn() => $runnerOffers['theme'];
    add_filter('site_transient_update_plugins', $runnerPluginOffer);
    add_filter('site_transient_update_themes', $runnerThemeOffer);
    wp_clean_plugins_cache(false);
    wp_clean_themes_cache(false);
    $maintenanceObserved = [];
    $runnerBufferLevel = ob_get_level();
    ob_start();
    try {
        (new RepositoryUpdateRunner($runnerSettings, new InstalledExtensions(), new MaintenanceProgressRenderer($repairSource)))
            ->run(['runner-plugin', 'runner-theme']);
    } finally {
        // Core may flush and remove progress buffers while it installs files.
        while (ob_get_level() > $runnerBufferLevel) ob_end_clean();
        remove_filter('site_transient_update_plugins', $runnerPluginOffer);
        remove_filter('site_transient_update_themes', $runnerThemeOffer);
    }
    rrzeRepairAssert($maintenanceObserved === [false, true], 'The real mixed runner preserves plugin-single then current-theme maintenance behavior.');
    rrzeRepairAssert(get_plugin_data(WP_PLUGIN_DIR . '/maintenance-plugin/main.php')['Version'] === '2.0'
        && wp_get_theme('maintenance-theme')->get('Version') === '2.0', 'Both real single upgraders install the target files through the extracted runner.');
    rrzeRepairAssert(!file_exists(ABSPATH . '.maintenance'), 'The mixed runner leaves no maintenance marker.');

    $durations = [];
    foreach ([0, 1500] as $files) {
        $archive = $maintenanceTemp . '/replacement-' . $files . '.zip';
        maintenancePackage('plugin', 'maintenance-plugin', '', $files);
        maintenancePackage('plugin', 'maintenance-plugin', $archive, $files);
        $connector = new MaintenanceArchiveFixture(); $connector->archive = $archive;
        $plugin = Plugin::createFromArray(['repository' => 'maintenance-plugin', 'installationFolder' => 'maintenance-plugin']);
        $plugin->connector = $connector;
        $replacement = new MaintenanceMeasuredReplacement();
        $snapshot = $replacement->snapshot('maintenance-plugin');
        rrzeRepairSuccess($replacement->replace($plugin, str_repeat('a', 40), $snapshot, static fn() => true), 'Actual replacement succeeds.');
        rrzeRepairAssert($replacement->started > 0 && !file_exists(ABSPATH . '.maintenance'), 'Measured replacement leaves no maintenance marker.');
        $durations[$files] = round(microtime(true) - $replacement->started, 3);
    }

    $settings = new Settings();
    $settings->plugins = [Plugin::createFromArray(['repository' => 'maintenance-plugin', 'installationFolder' => 'maintenance-plugin'])];
    $settings->themes = [Theme::createFromArray(['repository' => 'maintenance-theme', 'installationFolder' => 'maintenance-theme'])];
    $admin = new AdminIntegration($settings, new Controller($settings), new Config());
    foreach (['plugins-network', 'themes-network', 'update-core-network', 'plugins'] as $screen) {
        set_current_screen($screen);
        ob_start(); $admin->maintenanceNotice(); $html = ob_get_clean();
        rrzeRepairAssert(($html !== '') === ($screen !== 'plugins'), 'Native network screen scope: ' . $screen);
    }
    set_current_screen('plugins-network');
    $admin->register();
    $control = '<a class="toggle-auto-update">Enable auto-updates</a>';
    $html = apply_filters('plugin_auto_update_setting_html', $control, 'maintenance-plugin/main.php');
    rrzeRepairAssert($html === $control, 'Managed automatic-update controls have no repeated row hint.');
    WP_CLI::success(sprintf('%d real maintenance/UI assertions passed. Replacement maintenance: %ss (small), %ss (1500 payload files). These local timings are not production guarantees.',
        $GLOBALS['rrzeRepairChecks'] - $maintenanceStartChecks, $durations[0], $durations[1500]));
} finally {
    remove_filter('pre_http_request', $maintenanceHttp);
    remove_filter('upgrader_pre_install', $maintenanceObserve, 999);
    foreach ($maintenanceOriginal as $key => $value) update_option($key, $value);
    update_site_option('active_sitewide_plugins', $maintenanceNetworkActive);
    foreach (['plugins', 'themes'] as $index => $type) set_site_transient('update_' . $type, $maintenanceTransients[$index]);
    rrzeRepairRemove(WP_PLUGIN_DIR . '/maintenance-plugin');
    foreach (['maintenance-theme', 'maintenance-parent', 'maintenance-other'] as $folder) rrzeRepairRemove(get_theme_root() . '/' . $folder);
    rrzeRepairRemove($maintenanceTemp);
    wp_clean_plugins_cache(false);
    wp_clean_themes_cache(false);
}

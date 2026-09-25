<?php
require_once __DIR__ . '/update-refs.php';

use RRZE\Updater\{ManagedUpgrader, Settings, Config};
use RRZE\Updater\Core\Theme;
use RRZE\Updater\Upgrader\ThemeUpgraderSkin;

class LegacyChildSkinFixture extends ThemeUpgraderSkin {
    public $api = null;
    public function __construct($extension) { $this->extension = $extension; }
    public function feedback($feedback, ...$args) {}
}
class LegacyParentUpgraderFixture extends ParentThemeUpgraderFixture {
    public array $downloadResults = [];
    public function run($options) {
        // Retain core's real install/check_parent_theme_filter orchestration.
        // Only transport, filesystem operations and theme discovery are fixtures.
        $download = apply_filters('upgrader_pre_download', false, $options['package'], $this, $options['hook_extra'] ?? []);
        $this->downloadResults[] = $download;
        check(!is_wp_error($download), 'No download failure in child/parent flow.');
        try { return parent::run($options); }
        finally { if (is_string($download) && is_file($download)) wp_delete_file($download); }
    }
}
$beforeLegacyParent = $checks;
$storage = [];
$connector = new BulkGithubPackageFixture();
$connector->id = 'parent-fixture'; $connector->owner = 'owner'; $connector->token = 'fixture-secret';
$child = Theme::createFromArray(['id' => 'child-fixture', 'connectorId' => $connector->id, 'repository' => 'child',
    'installationFolder' => 'child-custom', 'branch' => 'main', 'localVersion' => '', 'remoteVersion' => 'v1']);
$child->connector = $connector;
$settings = new Settings();
$managed = new ManagedUpgrader($settings, new Config());
$settings->connectors = [$connector]; $settings->themes = [$child]; $settings->save();
$upgrader = new LegacyParentUpgraderFixture(new ParentThemeInstallerFixture('generated-child'));
$upgrader->skin = new LegacyChildSkinFixture($child);
add_filter('upgrader_pre_download', [$managed, 'upgraderPreDownloadFilter'], 10, 4);
add_filter('upgrader_source_selection', [$managed, 'upgraderSourceSelectionFilter'], 10, 4);
add_filter('upgrader_post_install', [$managed, 'upgraderPostInstallFilter'], 10, 3);
try {
    check($upgrader->install($connector->downloadRepoZip('child', 'v1')) === true, 'Legacy child install succeeds with a missing parent.');
    check($upgrader->runs === 2 && $upgrader->adapter->installedFolders === ['child-custom', 'parent-theme'], 'Core installs the parent under its own folder with empty hook_extra.');
    check(is_string($upgrader->downloadResults[0]) && $upgrader->downloadResults[1] === false, 'Only the child package uses connector credentials.');
    check($child->localVersion === 'v1' && count((new Settings())->themes) === 1, 'Parent completion does not change or create Updater associations.');
} finally {
    remove_filter('upgrader_pre_download', [$managed, 'upgraderPreDownloadFilter'], 10);
    remove_filter('upgrader_source_selection', [$managed, 'upgraderSourceSelectionFilter'], 10);
    remove_filter('upgrader_post_install', [$managed, 'upgraderPostInstallFilter'], 10);
}
// Even if another caller skips the download hook, consumed context cannot be reused.
$file = $managed->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('child', 'v1'), $upgrader, []);
check($managed->upgraderSourceSelectionFilter('/tmp/child/', '/tmp/work/', $upgrader, []) === '/tmp/work/child-custom/', 'Verified legacy package retains its custom folder.');
check($managed->upgraderSourceSelectionFilter('/tmp/parent/', '/tmp/work/', $upgrader, []) === '/tmp/parent/', 'A second source on the reused skin does not inherit the child destination.');
wp_delete_file($file);
echo 'Passed ' . ($checks - $beforeLegacyParent) . " legacy parent theme checks.\n";

<?php
// Exercise production hooks with core skins and an in-memory filesystem adapter.
require_once __DIR__ . '/gitlab.php';

use RRZE\Updater\{Main, Settings};
use RRZE\Updater\Core\{Plugin, Theme};
use RRZE\Updater\Upgrader\{PluginUpgraderSkin, ThemeUpgraderSkin};

function wp_cache_get($key, $group = '') {
    return ['' => ['first/first.php' => ['Name' => 'Shared Name'], 'target/target.php' => ['Name' => 'Shared Name']]];
}
$beforeUpgradeChecks = $checks;
$main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
$main->settings = new Settings();
$plugin = Plugin::createFromArray(['repository' => 'target', 'installationFolder' => 'target']);
$theme = Theme::createFromArray(['repository' => 'theme-target', 'installationFolder' => 'theme-target']);
$main->settings->plugins = [$plugin]; $main->settings->themes = [$theme];
$wp_filesystem = new class {
    public array $moves = [];
    public bool $fail = false;
    public function move($source, $destination, $overwrite) {
        $this->moves[] = [$source, $destination, $overwrite];
        return !$this->fail;
    }
};
foreach ([Bulk_Plugin_Upgrader_Skin::class, WP_Ajax_Upgrader_Skin::class, Plugin_Upgrader_Skin::class, Automatic_Upgrader_Skin::class] as $skinClass) {
    $skin = (new ReflectionClass($skinClass))->newInstanceWithoutConstructor();
    if (property_exists($skin, 'plugin_info')) $skin->plugin_info = ['Name' => 'Shared Name'];
    if (property_exists($skin, 'plugin')) $skin->plugin = 'target/target.php';
    $upgrader = new WP_Upgrader($skin);
    $result = $main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['plugin' => 'target/target.php']);
    check($result === '/tmp/work/target/', 'Exact plugin path wins over duplicate display names for ' . $skinClass . '.');
    check(end($wp_filesystem->moves) === ['/tmp/archive/', '/tmp/work/target/', false], 'Only the managed target is prepared without replacing an existing directory.');
    $moves = count($wp_filesystem->moves);
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['plugin' => 'first/first.php']) === '/tmp/archive/', 'Unmanaged plugin with the same display name is untouched.');
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, []) === '/tmp/archive/', 'A display name alone cannot choose a target.');
    check(count($wp_filesystem->moves) === $moves, 'Unmanaged or unidentified updates perform no moves.');
}
foreach ([Bulk_Theme_Upgrader_Skin::class, WP_Ajax_Upgrader_Skin::class, Theme_Upgrader_Skin::class, Automatic_Upgrader_Skin::class] as $skinClass) {
    $skin = (new ReflectionClass($skinClass))->newInstanceWithoutConstructor();
    if (property_exists($skin, 'theme')) $skin->theme = 'theme-target';
    $upgrader = new WP_Upgrader($skin);
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['theme' => 'theme-target']) === '/tmp/work/theme-target/', 'Managed theme uses its exact stylesheet identifier.');
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['theme' => 'unmanaged']) === '/tmp/archive/', 'Unmanaged theme is untouched.');
    $moves = count($wp_filesystem->moves);
    check($main->upgraderSourceSelectionFilter('/tmp/work/theme-target/', '/tmp/work/', $upgrader, ['theme' => 'theme-target']) === '/tmp/work/theme-target/'
        && count($wp_filesystem->moves) === $moves, 'An already-correct theme source is not moved onto itself.');
}
foreach ([PluginUpgraderSkin::class => $plugin, ThemeUpgraderSkin::class => $theme] as $skinClass => $extension) {
    $skin = (new ReflectionClass($skinClass))->newInstanceWithoutConstructor();
    $skin->extension = $extension;
    $upgrader = new WP_Upgrader($skin);
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, []) === '/tmp/work/' . $extension->installationFolder . '/', 'Legacy explicit installs retain their configured folder.');
    check($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['theme' => 'unmanaged-parent']) === '/tmp/archive/', 'Explicit core identifiers take priority over a reused custom skin.');
}
$wp_filesystem->fail = true;
check(is_wp_error($main->upgraderSourceSelectionFilter('/tmp/archive/', '/tmp/work/', $upgrader, ['plugin' => 'target/target.php'])), 'Filesystem failure blocks the update.');
$error = new WP_Error('invalid_archive', 'Invalid archive');
check($main->upgraderSourceSelectionFilter($error, '/tmp/work/', $upgrader, ['plugin' => 'target/target.php']) === $error, 'Preserve an earlier source-validation failure.');
$wp_filesystem->fail = false;
echo 'Passed ' . ($checks - $beforeUpgradeChecks) . " upgrade target checks.\n";

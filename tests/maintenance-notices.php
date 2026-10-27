<?php
// Loaded by discovery.php after the isolated admin fixtures.

use RRZE\Updater\{AdminIntegration, Config, Controller, MaintenanceNotice};
use RRZE\Updater\ListTable\{RepoListTable, ThemesListTable};

$beforeMaintenance = $checks;
$maintenanceSaved = [$multisite, $admin_network_screen, $token_super_admin, $bundle_caps, $wp_filter,
    $source_test_themes, $admin_screen ?? null];
$multisite = $admin_network_screen = $token_super_admin = true;
$bundle_caps = ['manage_network_options' => true, 'update_plugins' => true, 'update_themes' => true, 'install_plugins' => true];
$maintenance_stylesheet = 'target';

// Test the actual difference between core bulk and the overview's repeated singles.
foreach ([['replacement', 'plugin', '', true], ['replacement', 'theme', '', false],
    ['core-bulk', 'plugin', '', true], ['core-bulk', 'theme', '', true],
    ['automatic', 'plugin', '', true], ['automatic', 'theme', '', true],
    ['manual', 'plugin', 'target', false], ['manual', 'theme', 'target', true],
    ['manual', 'theme', 'parent', false], ['manual', 'theme', 'other-site-theme', false],
    ['updater-bulk', 'plugin', 'target', false], ['updater-bulk', 'theme', 'target', true],
    ['updater-bulk', 'theme', 'parent', false], ['check', 'plugin', '', false],
    ['source-save', 'plugin', '', false], ['install', 'plugin', '', false], ['unknown', 'theme', '', false],
] as [$action, $type, $target, $expected]) {
    foreach ([[], ['target/target.php']] as $active) {
        $storage['active_plugins'] = $active;
        check(MaintenanceNotice::applies($action, $type, $target) === $expected, "$action/$type follows its upgrader path, not plugin activation.");
        $html = MaintenanceNotice::render($action, $type, $target);
        check(($html !== '') === $expected, "$action/$type has the expected visible warning.");
        if ($expected) {
            check(str_contains($html, 'a few seconds to a few minutes') && str_contains($html, 'rough estimate')
                && str_contains($html, 'can take longer') && str_contains($html, 'Form submissions'), 'Warnings explain uncertainty and visitor impact.');
            check(str_contains($html, 'Download and package validation') === ($action === 'replacement'), 'Only replacement promises preparation outside maintenance.');
        }
    }
}
foreach (['single-site', 'site-admin', 'not-super', 'no-manage', 'no-update', 'no-install'] as $denial) {
    $multisite = $denial !== 'single-site';
    $admin_network_screen = $denial !== 'site-admin';
    $token_super_admin = $denial !== 'not-super';
    $bundle_caps['manage_network_options'] = $denial !== 'no-manage';
    $bundle_caps['update_plugins'] = $denial !== 'no-update';
    $bundle_caps['install_plugins'] = $denial !== 'no-install';
    check(MaintenanceNotice::render('replacement') === '', 'Replacement notice excludes ' . $denial . '.');
}
$multisite = $admin_network_screen = $token_super_admin = true;
$bundle_caps = ['manage_network_options' => true, 'update_plugins' => true, 'update_themes' => true, 'install_plugins' => true];
[$settings, $controller] = adminSaveFixture();
$settings->plugins[0]->installationFolder = $settings->themes[0]->installationFolder = 'target';
$settings->plugins[0]->localVersion = $settings->themes[0]->localVersion = 'old';
$settings->plugins[0]->remoteVersion = $settings->themes[0]->remoteVersion = 'new';
$source_test_themes = ['target' => new AdminThemeFixture(['Name' => 'Example theme'])];
$wp_filter = [];
$admin = new AdminIntegration($settings, new Controller($settings), new Config());
$admin->register();
foreach (['plugins', 'plugins-network', 'themes', 'themes-network', 'update-core', 'update-core-network', 'dashboard', 'toplevel_page_rrze-updater'] as $base) {
    $admin_screen = (object) ['base' => $base];
    $html = adminOutput(fn() => $admin->maintenanceNotice());
    $expected = in_array($base, ['plugins', 'plugins-network', 'themes', 'themes-network', 'update-core', 'update-core-network']);
    check(substr_count($html, 'rrze-updater-maintenance-notice') === ($expected ? 1 : 0), 'Only relevant core screens get one bulk notice: ' . $base);
}
$admin_screen = (object) ['base' => 'update-core'];
$managedPlugins = $settings->plugins; $managedThemes = $settings->themes;
$settings->plugins = $settings->themes = [];
check(adminOutput(fn() => $admin->maintenanceNotice()) === '', 'No notice when there are no managed extensions.');
$settings->plugins = $managedPlugins; $settings->themes = $managedThemes;

foreach (['plugin' => 'target/target.php', 'theme' => 'target'] as $type => $target) {
    $hook = $type . '_auto_update_setting_html';
    foreach (['Enable auto-updates', 'Disable auto-updates'] as $label) {
        $control = '<a class="toggle-auto-update"><span class="label">' . $label . '</span></a>';
        check(apply_filters($hook, $control, $target) === $control,
            'Managed automatic-update controls remain unchanged without a repeated row hint.');
    }
    check(apply_filters($hook, $control, $type === 'plugin' ? 'target-extra/main.php' : 'target-extra') === $control, 'Prefix-matching unmanaged folders receive no notice.');
    $admin_network_screen = false;
    check(apply_filters($hook, $control, $target) === $control, 'Ordinary site dashboards retain native auto-update controls.');
    $admin_network_screen = true;
}

$pluginRow = $controller->presenter->addRepoUpdateData(['installationFolder' => 'target'], $settings->plugins[0], 'plugin');
$themeRow = $controller->presenter->addRepoUpdateData(['installationFolder' => 'target'], $settings->themes[0], 'theme');
check($pluginRow['maintenanceNotice'] === '' && $themeRow['maintenanceNotice'] !== '', 'Overview marks only manual updates that enter maintenance.');
check($controller->presenter->getBulkMaintenanceNotice([$pluginRow]) === '', 'Plugin-only overview selection receives no false bulk warning.');
check(str_contains($controller->presenter->getBulkMaintenanceNotice([$pluginRow, $themeRow]), 'Selecting a theme marked'), 'Mixed overview explains which selected item can trigger maintenance.');
foreach ([RepoListTable::class, ThemesListTable::class] as $class) {
    $table = new $class();
    $html = $table->column_version($themeRow + ['version' => '1.0']);
    check(str_contains($html, esc_url($themeRow['updateUrl'])) && str_contains($html, 'rrze-updater-maintenance-hint'), 'The existing update link retains its nearby notice.');
}
$settings->themes[0]->remoteVersion = 'old';
$upToDate = $controller->presenter->addRepoUpdateData(['installationFolder' => 'target'], $settings->themes[0], 'theme');
check($upToDate['maintenanceNotice'] === '' && $controller->presenter->getBulkMaintenanceNotice([$upToDate]) === '', 'An up-to-date theme does not warn about a nonexistent update action.');

$review = (new PluginRepairServiceFixture())->review;
$html = adminOutput(static function () use ($review): void {
    $data = ['review' => $review];
    require dirname(__DIR__) . '/includes/views/plugins/replace.php';
});
check(strpos($html, 'rrze-updater-maintenance-notice') < strpos($html, 'replacement_action'), 'Full repair warning appears before confirmation controls.');
check(str_contains($html, 'including custom changes') && substr_count($html, 'method="post"') === 2, 'File-loss warning and the original confirmation/cancel forms are preserved.');
check(!str_contains($html, 'type="checkbox"') && !str_contains($html, 'is-dismissible'), 'No extra acknowledgement or dismiss control is introduced.');
$multisite = false;
$single = adminOutput(static function () use ($review): void {
    $data = ['review' => $review];
    require dirname(__DIR__) . '/includes/views/plugins/replace.php';
});
check(!str_contains($single, 'rrze-updater-maintenance-') && str_contains($single, 'including custom changes'), 'Single-site confirmation remains unchanged.');

[$multisite, $admin_network_screen, $token_super_admin, $bundle_caps, $wp_filter, $source_test_themes, $admin_screen] = $maintenanceSaved;
unset($maintenance_stylesheet);
echo 'Passed ' . ($checks - $beforeMaintenance) . " maintenance notice checks.\n";

<?php
require_once __DIR__ . '/update-sources.php';

use RRZE\Updater\{Main, Settings, Config};
use RRZE\Updater\Core\Theme;

function outcomeFixture(string $type, bool $automatic): array {
    $GLOBALS['storage'] = [];
    $GLOBALS['fail_save'] = false;
    $connector = new BulkGithubPackageFixture();
    $connector->id = 'outcome-connector'; $connector->owner = 'owner'; $connector->token = 'fixture-token';
    $extension = $type === 'plugin' ? new RefPluginFixture() : new Theme();
    $extension->updateFromArray(['id' => 'outcome-extension', 'connectorId' => $connector->id, 'repository' => 'package',
        'installationFolder' => 'package', 'localVersion' => 'v1', 'remoteVersion' => 'v2', 'updates' => 'commits']);
    $extension->connector = $connector;
    $main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Main::class, 'config'))->setValue($main, new Config());
    $main->settings = new Settings(); $main->settings->connectors = [$connector];
    $main->settings->plugins = $main->settings->themes = [];
    $property = $type === 'plugin' ? 'plugins' : 'themes';
    $main->settings->$property = [$extension];
    $main->settings->save();
    $skinClass = $automatic ? Automatic_Upgrader_Skin::class : WP_Upgrader_Skin::class;
    $upgrader = new WP_Upgrader((new ReflectionClass($skinClass))->newInstanceWithoutConstructor());
    $target = $type === 'plugin' ? 'package/main.php' : 'package';
    $extra = [$type => $target];
    $file = $main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v2'), $upgrader, $extra);
    $main->upgraderSourceSelectionFilter('/tmp/work/package/', '/tmp/work/', $upgrader, $extra);
    wp_delete_file($file);
    return compact('main', 'extension', 'extra', 'target', 'property', 'upgrader');
}
$beforeUpdateOutcomes = $checks;
foreach (['plugin', 'theme'] as $type) {
    foreach (['success', 'post-error', 'result-error', 'save-error'] as $outcome) {
        $f = outcomeFixture($type, false);
        $main = $f['main'];
        add_filter('upgrader_post_install', [$main, 'upgraderPostInstallFilter'], 10, 3);
        $failure = static fn($result) => new WP_Error('late_failure', 'Later validation failed.');
        if ($outcome === 'post-error') add_filter('upgrader_post_install', $failure, 20);
        if ($outcome === 'result-error') add_filter('upgrader_install_package_result', $failure, 20);
        $result = ['destination_name' => 'package'];
        $post = apply_filters('upgrader_post_install', true, $f['extra'], $result);
        check((new Settings())->{$f['property']}[0]->localVersion === 'v1', 'Post-install alone cannot commit the installed ref.');
        $fail_save = $outcome === 'save-error';
        $final = apply_filters('upgrader_install_package_result', is_wp_error($post) ? $post : $result, $f['extra']);
        $fail_save = false;
        check(is_wp_error($final) === ($outcome !== 'success'), 'Final manual result includes downstream validation and save failures.');
        if (is_wp_error($final)) check($f['upgrader']->result === $final, 'Core callers also see the final failure on the upgrader object.');
        check((new Settings())->{$f['property']}[0]->localVersion === ($outcome === 'success' ? 'v2' : 'v1'), 'Only a successful final manual result advances the ref.');
        remove_filter('upgrader_post_install', [$main, 'upgraderPostInstallFilter'], 10);
        remove_filter('upgrader_post_install', $failure, 20);
        remove_filter('upgrader_install_package_result', $failure, 20);
        check(!has_filter('upgrader_install_package_result'), 'Per-package completion callback is removed.');
    }
    foreach (['success', 'rollback', 'rollback-failed', 'save-error', 'unrelated-result'] as $outcome) {
        $f = outcomeFixture($type, true);
        $main = $f['main'];
        $result = ['destination_name' => 'package'];
        $main->upgraderPostInstallFilter(true, $f['extra'], $result);
        check(apply_filters('upgrader_install_package_result', $result, $f['extra']) === $result, 'Automatic package preparation preserves the core result.');
        check((new Settings())->{$f['property']}[0]->localVersion === 'v1', 'Automatic ref stays unchanged until fatal-error checks and rollback finish.');
        $update = (object) ['item' => (object) [$type => $outcome === 'unrelated-result' ? 'unmanaged' : $f['target']],
            'result' => match ($outcome) {
                'rollback' => new WP_Error('plugin_update_fatal_error_rollback_successful', 'Restored previous files.'),
                'rollback-failed' => new WP_Error('plugin_update_fatal_error_rollback_failed', 'Could not restore files.'),
                default => true,
            }];
        $fail_save = $outcome === 'save-error';
        $main->automaticUpdatesComplete([$type => [$update]]);
        $fail_save = false;
        $expected = match ($outcome) { 'success' => 'v2', 'rollback-failed' => '', default => 'v1' };
        check((new Settings())->{$f['property']}[0]->localVersion === $expected, 'Final automatic outcome determines the installed ref; failed rollback leaves it unknown.');
        if ($outcome === 'save-error') check(is_wp_error($update->result), 'Automatic metadata-save failure is included in batch results.');
        $main->automaticUpdatesComplete([$type => [(object) ['item' => (object) [$type => $f['target']], 'result' => true]]]);
        check((new Settings())->{$f['property']}[0]->localVersion === $expected, 'Consumed or unmatched batch context cannot be reused.');
        check(!has_filter('upgrader_install_package_result'), 'Automatic completion leaves no per-package filter behind.');
    }
}
echo 'Passed ' . ($checks - $beforeUpdateOutcomes) . " final update outcome checks.\n";

<?php
require_once __DIR__ . '/admin-integration.php';

use RRZE\Updater\{Main, ManagedUpgrader, Settings};
use RRZE\Updater\Core\Theme;

// Exercise the complete registered hook chain through production bootstrap.
// The other lifecycle suites cover transport, validation and rollback failures.
$beforeManagedUpgrader = $checks;
$savedManagedHooks = $wp_filter;
$savedMultisite = $multisite;
foreach ([false, true] as $multisite) {
    foreach (['plugin', 'theme'] as $type) {
        foreach ([WP_Upgrader_Skin::class, WP_Ajax_Upgrader_Skin::class, Automatic_Upgrader_Skin::class] as $skinClass) {
            $wp_filter = [];
            adminSaveFixture();
            $main = new Main();
            $connector = new BulkGithubPackageFixture();
            $connector->id = 'hook-connector'; $connector->owner = 'owner'; $connector->token = 'fixture-token';
            $extension = $type === 'plugin' ? new RefPluginFixture() : new Theme();
            $extension->updateFromArray(['id' => 'hook-extension', 'connectorId' => $connector->id,
                'repository' => 'package', 'installationFolder' => 'package', 'localVersion' => 'v1',
                'remoteVersion' => 'v3', 'branch' => 'main', 'updates' => 'commits']);
            $extension->connector = $connector;
            $property = $type === 'plugin' ? 'plugins' : 'themes';
            $main->settings->connectors = [$connector];
            $main->settings->plugins = $main->settings->themes = [];
            $main->settings->$property = [$extension];
            check($main->settings->save(), 'Persist the bootstrap lifecycle fixture.');
            $main->loaded();

            $owner = null;
            foreach (['upgrader_pre_download' => 4, 'upgrader_source_selection' => 4,
                'upgrader_post_install' => 3, 'automatic_updates_complete' => 1] as $hook => $args) {
                $callbacks = array_values($wp_filter[$hook]->callbacks[10]);
                $callbackOwner = $callbacks[0]['function'][0];
                $owner ??= $callbackOwner;
                check(count($callbacks) === 1 && $callbackOwner instanceof ManagedUpgrader
                    && $callbackOwner === $owner && $callbacks[0]['accepted_args'] === $args,
                    'Bootstrap connects all lifecycle hooks to one service at priority 10 with the original arguments.');
            }

            $upgrader = new WP_Upgrader(new $skinClass());
            $automatic = $skinClass === Automatic_Upgrader_Skin::class;
            $target = $type === 'plugin' ? 'package/main.php' : 'package';
            $extra = [$type => $target];
            foreach (['v2', 'v3'] as $ref) {
                $previous = $extension->localVersion;
                $file = apply_filters('upgrader_pre_download', false, $connector->downloadRepoZip('package', $ref), $upgrader, $extra);
                check(is_string($file) && is_file($file), 'Registered download hook authenticates the package.');
                try {
                    check(apply_filters('upgrader_source_selection', '/tmp/generated/', '/tmp/work/', $upgrader, $extra) === '/tmp/work/package/',
                        'Registered source hook selects the managed destination.');
                    $result = ['destination_name' => 'package'];
                    check(apply_filters('upgrader_post_install', true, $extra, $result) === true
                        && $extension->localVersion === $previous, 'Post-install stages the ref without saving it prematurely.');
                    check(apply_filters('upgrader_install_package_result', $result, $extra) === $result,
                        'Final package callback preserves the successful core result.');
                    check($extension->localVersion === ($automatic ? $previous : $ref),
                        'Manual and AJAX updates save immediately; background updates wait for batch completion.');
                    do_action('automatic_updates_complete', [$type => [(object) [
                        'item' => (object) [$type => $target], 'result' => true,
                    ]]]);
                    check($extension->localVersion === $ref && (new Settings())->{$property}[0]->localVersion === $ref,
                        'Completion updates both shared settings and the persisted registry with the verified ref.');
                    check(!has_filter('upgrader_install_package_result'), 'Completed packages remove their temporary result callback.');
                } finally {
                    wp_delete_file($file);
                }
            }
            check($connector->downloads === 2, 'Reusing the upgrader downloads each package exactly once.');
            $unmanaged = [$type => $type === 'plugin' ? 'other/main.php' : 'other'];
            check(apply_filters('upgrader_pre_download', false, 'https://example.test/other.zip', $upgrader, $unmanaged) === false,
                'The same registered service leaves unrelated downloads to core.');
            check(apply_filters('upgrader_source_selection', '/tmp/other/', '/tmp/work/', $upgrader, $unmanaged) === '/tmp/other/',
                'The reused upgrader does not apply the previous managed destination to another package.');
        }
    }
}
$multisite = $savedMultisite;
$wp_filter = $savedManagedHooks;
echo 'Passed ' . ($checks - $beforeManagedUpgrader) . " registered upgrader lifecycle checks.\n";

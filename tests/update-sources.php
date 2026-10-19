<?php
require_once __DIR__ . '/legacy-installs.php';

use RRZE\Updater\{ManagedUpgrader, Settings, Config, UpdateProvider};
use RRZE\Updater\Core\{Plugin, Theme};

function wp_get_themes($args = []) { return $GLOBALS['source_test_themes'] ?? []; }
$source_test_themes = ['target' => new class { public function get($field) { return '1.0'; } }];
$beforeUpdateSources = $checks;
$settings = new Settings();
$savedUpdateHooks = $wp_filter;
$wp_filter = [];
$provider = new UpdateProvider($settings);
$provider->register();
$connector = new BulkGithubPackageFixture();
$connector->owner = 'owner';
$plugin = Plugin::createFromArray(['repository' => 'target', 'installationFolder' => 'target', 'localVersion' => 'v1']);
$theme = Theme::createFromArray(['repository' => 'target', 'installationFolder' => 'target', 'localVersion' => 'v1']);
$settings->plugins = [$plugin]; $settings->themes = [$theme];
foreach (['plugin' => $plugin, 'theme' => $theme] as $type => $extension) {
    $key = $type === 'plugin' ? 'target/target.php' : 'target';
    foreach (['pre_set_site_transient_update_', 'site_transient_update_'] as $prefix) {
        foreach ([false, null, (object) [], (object) ['checked' => []]] as $incomplete) {
            check(apply_filters($prefix . $type . 's', $incomplete) === $incomplete,
                'Missing or incomplete transients are returned unchanged.');
        }
    }
    foreach (['v1', '', 'v2', 'missing-connector'] as $state) {
        $extension->connector = $state === 'missing-connector' ? false : $connector;
        $extension->remoteVersion = $state;
        foreach (['pre_set_site_transient_update_', 'site_transient_update_'] as $prefix) {
            $hook = $prefix . $type . 's';
            $offer = ['package' => 'https://downloads.wordpress.org/' . $type . '/target.99.zip'];
            $offer = $type === 'plugin' ? (object) $offer : $offer;
            $transient = (object) ['checked' => [$key => '1.0'], 'response' => [$key => $offer, 'unmanaged' => $offer],
                'no_update' => [$key => $offer, 'unmanaged' => $offer], 'last_checked' => 123];
            $result = apply_filters($hook, $transient);
            check($result->response['unmanaged'] === $offer, 'Unmanaged update offers are preserved.');
            check($result->no_update['unmanaged'] === $offer && $result->checked === [$key => '1.0'] && $result->last_checked === 123,
                'Unmanaged no-update entries and transient bookkeeping are preserved.');
            if ($state === 'v2') {
                $package = $type === 'plugin' ? $result->response[$key]->package : $result->response[$key]['package'];
                check($package === $connector->downloadRepoZip('target', 'v2') && !isset($result->no_update[$key]), 'Only the selected Git repository provides a managed update.');
            } else {
                check(!isset($result->response[$key]), "$hook suppresses foreign/cached updates when Git is current or unavailable.");
                $package = $type === 'plugin' ? $result->no_update[$key]->package : $result->no_update[$key]['package'];
                check($package === '', 'Managed no-update entry retains auto-update controls without a foreign download.');
            }
            $entry = $state === 'v2' ? $result->response[$key] : $result->no_update[$key];
            check($type === 'plugin' ? is_object($entry) : is_array($entry), 'Retain WordPress plugin-object and theme-array metadata formats.');
            $entry = (array) $entry;
            check($entry['new_version'] === ($state === 'v2' ? $extension->getRemoteVersionLabel() : ($type === 'plugin' ? '' : '1.0')),
                'Update labels use the Git ref; no-update labels use the installed header version.');
            check($entry['url'] === ($state === 'missing-connector' ? '' : $connector->getUrl('target')),
                'Repository links follow the managed connector, including an absent connector.');
        }
    }
}
// The provider keeps the shared Settings instance, so registration changes in
// the same request affect subsequent reads without rebuilding the provider.
$settings->plugins = $settings->themes = [];
foreach (['plugins', 'themes'] as $kind) {
    $transient = (object) ['checked' => ['target' => '1.0'], 'response' => ['target' => 'core-offer']];
    $before = serialize($transient);
    check(serialize(apply_filters('site_transient_update_' . $kind, $transient)) === $before,
        'An emptied registry leaves core metadata unchanged.');
}
$settings->plugins = [$plugin]; $settings->themes = [$theme];
check($connector->downloads === 0, 'Projecting update metadata does not download packages.');
$wp_filter = $savedUpdateHooks;
$managed = new ManagedUpgrader($settings, new Config());
$plugin->connector = $connector;
$upgrader = new WP_Upgrader();
check(is_wp_error($managed->upgraderPreDownloadFilter(false, 'https://downloads.wordpress.org/plugin/target.99.zip', $upgrader, ['plugin' => 'target/target.php'])), 'A stale or externally injected foreign package cannot bypass transient filtering.');
check($managed->upgraderPreDownloadFilter(false, 'https://downloads.wordpress.org/plugin/first.99.zip', $upgrader, ['plugin' => 'first/first.php']) === false, 'Unmanaged downloads still use core.');
echo 'Passed ' . ($checks - $beforeUpdateSources) . " managed update source checks.\n";

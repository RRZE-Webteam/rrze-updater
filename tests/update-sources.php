<?php
require_once __DIR__ . '/legacy-installs.php';

use RRZE\Updater\{Main, Settings};
use RRZE\Updater\Core\{Plugin, Theme};

function wp_get_themes($args = []) { return $GLOBALS['source_test_themes'] ?? []; }
$source_test_themes = ['target' => new class { public function get($field) { return '1.0'; } }];
$beforeUpdateSources = $checks;
$main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
$main->settings = new Settings();
$connector = new BulkGithubPackageFixture();
$connector->owner = 'owner';
$plugin = Plugin::createFromArray(['repository' => 'target', 'installationFolder' => 'target', 'localVersion' => 'v1']);
$theme = Theme::createFromArray(['repository' => 'target', 'installationFolder' => 'target', 'localVersion' => 'v1']);
$main->settings->plugins = [$plugin]; $main->settings->themes = [$theme];
foreach (['plugin' => $plugin, 'theme' => $theme] as $type => $extension) {
    $key = $type === 'plugin' ? 'target/target.php' : 'target';
    foreach (['v1', '', 'v2', 'missing-connector'] as $state) {
        $extension->connector = $state === 'missing-connector' ? false : $connector;
        $extension->remoteVersion = $state;
        foreach (['preSetSiteTransientUpdate', 'siteTransientUpdate'] as $prefix) {
            $method = $prefix . ($type === 'plugin' ? 'Plugins' : 'Themes');
            $offer = ['package' => 'https://downloads.wordpress.org/' . $type . '/target.99.zip'];
            $offer = $type === 'plugin' ? (object) $offer : $offer;
            $transient = (object) ['checked' => [$key => '1.0'], 'response' => [$key => $offer, 'unmanaged' => $offer], 'no_update' => [$key => $offer]];
            $result = $main->$method($transient);
            check($result->response['unmanaged'] === $offer, 'Unmanaged update offers are preserved.');
            if ($state === 'v2') {
                $package = $type === 'plugin' ? $result->response[$key]->package : $result->response[$key]['package'];
                check($package === $connector->downloadRepoZip('target', 'v2') && !isset($result->no_update[$key]), 'Only the selected Git repository provides a managed update.');
            } else {
                check(!isset($result->response[$key]), "$method suppresses foreign/cached updates when Git is current or unavailable.");
                $package = $type === 'plugin' ? $result->no_update[$key]->package : $result->no_update[$key]['package'];
                check($package === '', 'Managed no-update entry retains auto-update controls without a foreign download.');
            }
        }
    }
}
$plugin->connector = $connector;
$upgrader = new WP_Upgrader();
check(is_wp_error($main->upgraderPreDownloadFilter(false, 'https://downloads.wordpress.org/plugin/target.99.zip', $upgrader, ['plugin' => 'target/target.php'])), 'A stale or externally injected foreign package cannot bypass transient filtering.');
check($main->upgraderPreDownloadFilter(false, 'https://downloads.wordpress.org/plugin/first.99.zip', $upgrader, ['plugin' => 'first/first.php']) === false, 'Unmanaged downloads still use core.');
echo 'Passed ' . ($checks - $beforeUpdateSources) . " managed update source checks.\n";

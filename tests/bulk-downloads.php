<?php
require_once __DIR__ . '/upgrades.php';

use RRZE\Updater\{Main, Settings, Config};
use RRZE\Updater\Core\{Plugin, Theme};

class BulkDownloadPluginFixture extends Plugin {
    public int $validations = 0;
    public bool $reject = false;
    public function getRemotePluginRepositoryWarning(string $ref): WP_Error|false {
        $this->validations++;
        return $this->reject ? new WP_Error('invalid_fixture', 'Invalid repository') : false;
    }
}
class BulkGithubPackageFixture extends PackageFixture {
    public int $downloads = 0;
    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool {
        $this->downloads++;
        return parent::downloadRepoZipToTempFile($repository, $branch);
    }
}
$beforeBulkDownloads = $checks;
$lab = new GitlabArchiveFixture();
$lab->owner = 'group'; $lab->host = 'gitlab.example.org'; $lab->apiUri = '/api/v4/projects'; $lab->token = 'fixture-secret';
$github = new BulkGithubPackageFixture(); $github->owner = 'owner'; $github->token = 'fixture-secret';
foreach ([$github, $lab] as $connector) {
    $main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
    $main->settings = new Settings();
    (new ReflectionProperty(Main::class, 'config'))->setValue($main, new Config());
    $plugin = new BulkDownloadPluginFixture();
    $plugin->repository = $plugin->installationFolder = 'package';
    $plugin->branch = 'main'; $plugin->remoteVersion = 'v1'; $plugin->connector = $connector;
    $theme = Theme::createFromArray(['repository' => 'package', 'installationFolder' => 'package', 'remoteVersion' => 'v1', 'branch' => 'main']);
    $theme->connector = $connector;
    $main->settings->plugins = [$plugin]; $main->settings->themes = [$theme];
    foreach (['plugin' => [Bulk_Plugin_Upgrader_Skin::class, WP_Ajax_Upgrader_Skin::class, Automatic_Upgrader_Skin::class],
        'theme' => [Bulk_Theme_Upgrader_Skin::class, WP_Ajax_Upgrader_Skin::class, Automatic_Upgrader_Skin::class]] as $type => $skins) {
        // These are the per-package identifiers supplied by core bulk_upgrade().
        $extra = [$type => $type === 'plugin' ? 'package/main.php' : 'package', 'temp_backup' => ['slug' => 'package']];
        foreach ($skins as $skinClass) {
            $skin = (new ReflectionClass($skinClass))->newInstanceWithoutConstructor();
            $upgrader = new WP_Upgrader($skin);
            $beforeValidation = $plugin->validations;
            $path = $main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v1'), $upgrader, $extra);
            check(is_string($path) && is_file($path), $connector->getType() . ' ' . $type . ' bulk update uses the authenticated archive adapter.');
            check($plugin->validations === $beforeValidation + ($type === 'plugin' ? 1 : 0), 'Bulk plugin updates retain repository validation without requiring a type key.');
            wp_delete_file($path);
        }
        $path = $main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v1'), $upgrader, $extra + ['type' => $type]);
        check(is_string($path) && is_file($path), 'Single-update payloads still authenticate.');
        wp_delete_file($path);
        check($main->upgraderPreDownloadFilter(false, 'https://unrelated.example/archive.zip', $upgrader, $extra) === false, 'Never authenticate unrelated package URLs.');
        check($main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v1'), $upgrader,
            [$type => $type === 'plugin' ? 'unmanaged/main.php' : 'unmanaged']) === false, 'Unmanaged bulk updates are left to core.');
        $failureProperty = $connector instanceof BulkGithubPackageFixture ? 'downloadFails' : 'fail';
        $connector->$failureProperty = true;
        check(is_wp_error($main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v1'), $upgrader, $extra)), 'Authenticated bulk download failure never falls back to a public request.');
        $connector->$failureProperty = false;
    }
    $plugin->reject = true;
    $downloads = $connector instanceof BulkGithubPackageFixture ? $connector->downloads : count($connector->requests);
    check(is_wp_error($main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v1'), $upgrader, ['plugin' => 'package/main.php'])), 'Invalid bulk plugin repository blocks the download.');
    check(($connector instanceof BulkGithubPackageFixture ? $connector->downloads : count($connector->requests)) === $downloads, 'Validation failure does not download an archive.');
}
echo 'Passed ' . ($checks - $beforeBulkDownloads) . " authenticated bulk download checks.\n";

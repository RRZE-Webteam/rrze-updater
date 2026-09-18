<?php
require_once __DIR__ . '/bulk-downloads.php';

use RRZE\Updater\{Main, Settings, Config};
use RRZE\Updater\Core\Theme;

class RefPluginFixture extends BulkDownloadPluginFixture {
    public array $checkedRefs = [];
    public function getRemotePluginRepositoryWarning(string $ref): WP_Error|false {
        $this->checkedRefs[] = $ref;
        return parent::getRemotePluginRepositoryWarning($ref);
    }
}
function finishRefInstall(Main $main, $response, array $extra, array $result) {
    $post = $main->upgraderPostInstallFilter($response, $extra, $result);
    $outcome = apply_filters('upgrader_install_package_result', is_wp_error($post) ? $post : $result, $extra);
    return is_wp_error($outcome) ? $outcome : $post;
}
$beforeRefChecks = $checks;
foreach (['github', 'gitlab'] as $provider) {
    foreach (['plugin', 'theme'] as $type) {
        $storage = [];
        $connector = $provider === 'github' ? new BulkGithubPackageFixture() : new GitlabArchiveFixture();
        $connector->id = 'ref-connector'; $connector->owner = 'owner'; $connector->token = 'fixture-secret';
        if ($provider === 'gitlab') { $connector->host = 'gitlab.example.org'; $connector->apiUri = '/api/v4/projects'; }
        $extension = $type === 'plugin' ? new RefPluginFixture() : new Theme();
        $extension->updateFromArray(['id' => 'ref-extension', 'connectorId' => 'ref-connector', 'repository' => 'package',
            'installationFolder' => 'package', 'remoteVersion' => 'v2', 'localVersion' => 'v0', 'branch' => 'main', 'updates' => 'commits']);
        $extension->connector = $connector;
        $property = $type === 'plugin' ? 'plugins' : 'themes';
        $main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Main::class, 'config'))->setValue($main, new Config());
        $main->settings = new Settings(); $main->settings->connectors = [$connector]; $main->settings->$property = [$extension];
        check($main->settings->save(), 'Persist update-ref fixture.');
        $upgrader = new WP_Upgrader();
        $extra = [$type => $type === 'plugin' ? 'package/main.php' : 'package'];
        foreach (['v1', 'release/old', 'v1&encoded'] as $cachedRef) {
            $package = $connector->downloadRepoZip('package', $cachedRef);
            if ($provider === 'gitlab') $package .= '&private_token=old-secret';
            $file = $main->upgraderPreDownloadFilter(false, $package, $upgrader, $extra);
            check(is_string($file) && is_file($file), 'Authenticate the cached package ref independently of current metadata.');
            if ($type === 'plugin') check(end($extension->checkedRefs) === $cachedRef, 'Validate the actual package ref.');
            $main->upgraderSourceSelectionFilter('/tmp/work/package/', '/tmp/work/', $upgrader, $extra);
            check(finishRefInstall($main, true, $extra, ['destination_name' => 'package']) === true, 'Preserve the core post-install boolean.');
            $fresh = new Settings();
            check($fresh->{$property}[0]->localVersion === $cachedRef && $fresh->{$property}[0]->remoteVersion === 'v2', 'Record the installed ref without overwriting the newer available ref.');
            wp_delete_file($file);
        }
        $previous = $extension->localVersion;
        $file = $main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v3'), $upgrader, $extra);
        $main->upgraderSourceSelectionFilter('/tmp/work/package/', '/tmp/work/', $upgrader, $extra);
        $failure = new WP_Error('install_failed', 'Fixture failure');
        check(finishRefInstall($main, $failure, $extra, ['destination_name' => 'package']) === $failure
            && $extension->localVersion === $previous, 'Failed installation does not advance the installed ref.');
        wp_delete_file($file);
        $file = $main->upgraderPreDownloadFilter(false, $connector->downloadRepoZip('package', 'v3'), $upgrader, $extra);
        $main->upgraderSourceSelectionFilter('/tmp/work/package/', '/tmp/work/', $upgrader, $extra);
        $fail_save = true;
        check(is_wp_error(finishRefInstall($main, true, $extra, ['destination_name' => 'package']))
            && $extension->localVersion === $previous, 'A failed version save is reported and does not claim a successful metadata update.');
        $fail_save = false; wp_delete_file($file);
        foreach (['https://unrelated.example/archive.zip', $connector->downloadRepoZip('other-repo', 'v1'),
            $connector->downloadRepoZip('package', 'v1') . '&injected=true'] as $unrelated) {
            check(is_wp_error($main->upgraderPreDownloadFilter(false, $unrelated, $upgrader, $extra)), 'Reject noncanonical archive URLs for managed updates.');
            $main->upgraderSourceSelectionFilter('/tmp/work/package/', '/tmp/work/', $upgrader, $extra);
            finishRefInstall($main, true, $extra, ['destination_name' => 'package']);
            check($extension->localVersion === $previous, 'Untracked downloads cannot reuse the preceding package ref.');
        }
    }
}
echo 'Passed ' . ($checks - $beforeRefChecks) . " installed package ref checks.\n";

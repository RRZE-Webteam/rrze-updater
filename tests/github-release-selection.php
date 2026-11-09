<?php

use RRZE\Updater\Core\{GithubConnector, PluginReplacement, RepositorySource};

/**
 * Identify the authorized administrator in offline release service tests.
 *
 * @return int Fixture administrator ID.
 */
function get_current_user_id(): int { return 1; }

$beforeReleaseSelection = $checks;
$savedReleaseCaps = [$bundle_caps, $token_super_admin ?? false, $bundle_mods];
$bundle_caps = ['install_plugins' => true, 'update_plugins' => true, 'manage_options' => true, 'manage_network_options' => true];
$token_super_admin = $bundle_mods = true;
$savedReleaseHttp = $discovery_http;
$record = ['id' => 42, 'tag_name' => 'release/1.0', 'name' => 'First release', 'draft' => false,
    'published_at' => '2025-01-01T12:00:00Z', 'prerelease' => false,
    'html_url' => 'https://github.com/owner/repo/releases/tag/release%2F1.0', 'author' => ['login' => 'maintainer']];
$github = GithubConnector::createFromArray(['id' => 'releases', 'owner' => 'owner', 'token' => 'fixture-secret']);
discoveryReset(static function ($url, $args) use ($record) {
    check(str_contains($url, '/repos/owner/repo/releases?per_page=20&page=2'), 'Release list uses bounded page requests.');
    check(!str_contains($url, 'fixture-secret') && !empty($args['headers']['Authorization']), 'Release-list credentials remain in headers.');
    return array_merge(array_fill(0, 18, $record), [array_merge($record, ['draft' => true]), array_merge($record, ['id' => 43, 'prerelease' => true])]);
});
$page = $github->getReleasePage('repo', 2);
check(count($page['items']) === 19 && $page['has_more'] && $page['page'] === 2
    && end($page['items'])['prerelease'], 'Hide drafts but retain explicit prerelease choices and raw page boundaries.');
check($page['items'][0]['tag'] === 'release/1.0' && $page['items'][0]['author'] === 'maintainer', 'Normalize display metadata without altering the version tag.');
foreach (['42', '../42', '', '0', '42?repo=other'] as $id) {
    discoveryReset(static function ($url) use ($record) {
        check(str_ends_with($url, '/repos/owner/repo/releases/42'), 'Resolve IDs only inside the saved repository.');
        return $record;
    });
    check(($github->getReleaseById('repo', $id) !== false) === ($id === '42'), 'Reject malformed release identifiers before issuing requests.');
    check(count($discovery_requests) === ($id === '42' ? 1 : 0), 'Invalid release IDs never reach the API.');
}
foreach ([['draft' => true], ['published_at' => null], ['published_at' => '2999-01-01T00:00:00Z'], ['id' => 99], ['tag_name' => 'bad tag']] as $invalid) {
    discoveryReset(static fn() => array_merge($record, $invalid));
    check($github->getReleaseById('repo', '42') === false, 'Unpublished, mismatched or malformed releases cannot be selected.');
}
discoveryReset(static fn() => array_merge($record, ['prerelease' => true]));
check($github->getReleaseById('repo', '42')['prerelease'], 'Explicit selection permits a published prerelease.');
discoveryReset(static fn() => ['http_error' => 403]);
check($github->getReleasePage('repo') === false && $github->getReleaseById('repo', '42') === false, 'API errors never become installable records.');

// Exercise real Settings merging and source invalidation, beyond the ZIP transaction fixture.
[$settings, $manager, $original] = sourceSwitchFixture();
$original->updates = 'releases';
$original->localVersion = $original->remoteVersion = 'v1.2';
$settings->markSourceValidated($original);
check($settings->save(), 'Initialize a release-mode association.');
$settings->reload();
$original = $settings->plugins[0];
$original->localVersion = 'v1.2';
check($settings->save(), 'Record the known installed ref.');
$stale = new RRZE\Updater\Settings();
$original->pinnedRelease = ['id' => '42', 'tag' => 'v1.2', 'commit' => str_repeat('a', 40), 'version' => '1.2', 'release' => ['tag' => 'v1.2']];
$original->remoteVersion = 'v1.2';
$settings->markSourceValidated($original);
check($settings->save(), 'Persist the release hold through production Settings.');
$fresh = new RRZE\Updater\Settings();
check($fresh->plugins[0]->isReleasePinned() && $fresh->plugins[0]->localVersion === 'v1.2'
    && $fresh->plugins[0]->remoteVersion === 'v1.2', 'Holding a release preserves known installed refs and validated metadata.');
$stale->plugins[0]->remoteVersion = 'v9';
check(!$stale->save(), 'A check started before the hold cannot publish a stale update.');
$fresh->plugins[0]->branch = 'other';
check(!$fresh->save(), 'Changing the source cannot silently remove a release hold.');
$fresh->reload();
$fresh->connectors[0]->owner = 'other-owner';
check(!$fresh->save(), 'Changing a shared connector cannot silently change a held release source.');
$fresh->reload();
$fresh->connectors[0]->token = 'rotated-fixture';
check($fresh->save(), 'Credential rotation remains possible while holding a release.');
$manager = new RRZE\Updater\Core\RepositoryManager($fresh);
$options = ['connector' => 'source-github', 'branch' => 'main', 'updates' => 'releases'];
check(is_string($manager->changePluginSource('source-plugin', 'package', $options))
    && $fresh->plugins[0]->isReleasePinned() && $fresh->plugins[0]->localVersion === 'v1.2',
    'Saving unchanged editor fields preserves the held release and installed ref.');
check(is_wp_error($manager->changePluginSource('source-plugin', 'package', array_merge($options, ['updates' => 'tags'])))
    && $fresh->plugins[0]->updates === 'releases' && $fresh->plugins[0]->isReleasePinned(),
    'The source editor requires explicitly resuming updates before changing the held policy.');
$service = new PluginReplacement($fresh);
check($service->resumeReleaseUpdates($fresh->plugins[0]->id) === true, 'Release holds can be explicitly removed using the authorized service.');
$fresh->reload();
check(!$fresh->plugins[0]->isReleasePinned() && $fresh->plugins[0]->localVersion === 'v1.2'
    && $fresh->plugins[0]->remoteVersion === '', 'Resuming updates preserves the installed ref and clears the paused target.');

// A queued ordinary WordPress update must be rejected before it downloads a different release.
$f = outcomeFixture('plugin', false);
$f['extension']->pinnedRelease = ['id' => '42', 'tag' => 'v1', 'commit' => str_repeat('a', 40)];
$result = $f['managed']->upgraderPreDownloadFilter(false,
    $f['extension']->connector->downloadRepoZip('package', 'v9'), $f['upgrader'], $f['extra']);
check(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_release_pinned', 'Held plugins reject even pre-existing WordPress update jobs.');
$f['managed']->releaseFilesystemLock();
$discovery_http = $savedReleaseHttp;
[$bundle_caps, $token_super_admin, $bundle_mods] = $savedReleaseCaps;
echo 'Passed ' . ($checks - $beforeReleaseSelection) . " release API and persisted hold checks.\n";

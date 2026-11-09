<?php

use RRZE\Updater\Core\{GithubConnector, PluginReplacement, RepositoryManager};

$beforeCommitSelection = $checks;
$savedCommitState = [$discovery_http, $bundle_caps, $token_super_admin, $bundle_mods];
$bundle_caps = array_fill_keys(['install_plugins', 'update_plugins', 'manage_options', 'manage_network_options'], true);
$token_super_admin = $bundle_mods = true;
$sha = str_repeat('a', 40);
$record = ['sha' => $sha, 'html_url' => 'https://github.com/owner/repo/commit/' . $sha,
    'commit' => ['message' => "Fix regression\n\nDetailed message", 'author' => ['name' => 'Test Author', 'email' => 'private@example.test'],
        'committer' => ['date' => '2025-01-01T15:39:00Z']], 'author' => null];
$github = GithubConnector::createFromArray(['id' => 'commits', 'owner' => 'owner', 'token' => 'fixture-secret']);
discoveryReset(static function ($url, $args) use ($record) {
    check(str_contains($url, '/repos/owner/repo/commits?sha=refs%2Fheads%2Ffeature%2Ftest&per_page=20&page=2'),
        'History requests are bounded and scoped to the configured branch, including slash names.');
    check(!str_contains($url, 'fixture-secret') && !empty($args['headers']['Authorization']), 'Commit API credentials stay in headers.');
    return array_merge(array_fill(0, 19, $record), [['sha' => 'malformed']]);
});
$page = $github->getCommitPage('repo', 'feature/test', 2);
check(count($page['items']) === 19 && $page['has_more'] && $page['page'] === 2, 'Skip malformed records while preserving raw pagination boundaries.');
check($page['items'][0]['author'] === 'Test Author' && $page['items'][0]['author_url'] === ''
    && $page['items'][0]['title'] === 'Fix regression' && !str_contains(serialize($page), 'private@example.test'),
    'Commit metadata works without a linked GitHub account and does not retain author email.');
foreach (['', '../main', 'abcd1234', $sha . '?other=1'] as $invalid) {
    discoveryReset(static fn() => $record);
    check($github->getCommitForBranch('repo', 'main', $invalid) === false && !$discovery_requests,
        'Only full immutable SHAs can reach the commit API.');
}
foreach (['ahead', 'identical', 'behind', 'diverged', 'unknown'] as $status) {
    discoveryReset(static function ($url) use ($record, $sha, $status) {
        if (str_ends_with($url, '/repos/owner/repo/commits/' . $sha)) return $record;
        check(str_ends_with($url, '/compare/' . $sha . '...refs%2Fheads%2Ffeature%2Ftest?per_page=1'),
            'Ancestry checks use the saved branch and a bounded comparison response.');
        return ['status' => $status, 'merge_base_commit' => ['sha' => $sha]];
    });
    check(($github->getCommitForBranch('repo', 'feature/test', $sha) !== false) === in_array($status, ['ahead', 'identical'], true),
        'Only commits at or behind the configured branch may be selected.');
}
discoveryReset(static fn($url) => str_contains($url, '/compare/')
    ? ['status' => 'ahead', 'merge_base_commit' => ['sha' => str_repeat('b', 40)]] : $record);
check($github->getCommitForBranch('repo', 'main', $sha) === false, 'A mismatched merge base fails closed.');
discoveryReset(static fn() => array_merge($record, ['sha' => str_repeat('b', 40)]));
check($github->getCommitForBranch('repo', 'main', $sha) === false && count($discovery_requests) === 1,
    'A different returned commit cannot replace the selected target.');
discoveryReset(static fn() => ['http_error' => 403]);
check($github->getCommitPage('repo', 'main') === false && $github->getCommitForBranch('repo', 'main', $sha) === false,
    'Provider errors do not become selectable commits.');

// Exercise persistence, stale-check protection and source edits with production Settings.
[$settings] = sourceSwitchFixture();
$settings->plugins[0]->updates = 'commits';
check($settings->save(), 'Initialize commit mode.');
$settings->reload();
$plugin = $settings->plugins[0];
$plugin->localVersion = $sha;
check($settings->save(), 'Save the known installed commit.');
$stale = new RRZE\Updater\Settings();
$plugin->pinnedCommit = ['commit' => $sha, 'version' => '1.0', 'details' => ['title' => 'Old commit']];
$plugin->remoteVersion = $sha;
$settings->markSourceValidated($plugin);
check($settings->save(), 'Persist a commit hold without clearing the known installed commit.');
$settings->reload();
check($settings->plugins[0]->isUpdatePinned() && !$settings->plugins[0]->isReleasePinned()
    && $settings->plugins[0]->localVersion === $sha && $settings->plugins[0]->remoteVersion === $sha, 'Release and commit holds remain distinct after reloading.');
$stale->plugins[0]->remoteVersion = str_repeat('b', 40);
check(!$stale->save(), 'An update check started before the commit hold cannot publish its stale target.');
$manager = new RepositoryManager($settings);
$options = ['connector' => 'source-github', 'branch' => 'main', 'updates' => 'commits'];
check(is_string($manager->changePluginSource('source-plugin', 'package', $options)) && $settings->plugins[0]->isUpdatePinned(),
    'Saving unchanged source fields keeps the commit held.');
check(is_wp_error($manager->changePluginSource('source-plugin', 'package', array_merge($options, ['updates' => 'releases']))),
    'Changing update policy cannot bypass a commit hold.');
$settings->connectors[0]->owner = 'another-owner';
check(!$settings->save(), 'Changing a shared connector cannot bypass the held commit source.');
$settings->reload();
$settings->connectors[0]->token = 'rotated-fixture';
check($settings->save(), 'Token rotation is still allowed for a held commit.');
$service = new PluginReplacement($settings);
check($service->resumeCommitUpdates('source-plugin') === true, 'Explicitly resume normal commit updates.');
$settings->reload();
check(!$settings->plugins[0]->isUpdatePinned() && $settings->plugins[0]->localVersion === $sha
    && $settings->plugins[0]->remoteVersion === '', 'Resuming clears the hold and cached target while preserving the installed commit.');
$f = outcomeFixture('plugin', false);
$f['extension']->pinnedCommit = ['commit' => $sha, 'version' => '1.0'];
$result = $f['managed']->upgraderPreDownloadFilter(false,
    $f['extension']->connector->downloadRepoZip('package', 'main'), $f['upgrader'], $f['extra']);
check(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_commit_pinned', 'Queued WordPress updates cannot replace a held commit.');
$f['managed']->releaseFilesystemLock();
[$discovery_http, $bundle_caps, $token_super_admin, $bundle_mods] = $savedCommitState;
echo 'Passed ' . ($checks - $beforeCommitSelection) . " commit API and persisted hold checks.\n";

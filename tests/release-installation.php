<?php
// Runs inside plugin-replacement.php's disposable WordPress directory and real ZIP transaction.

/**
 * Prepare an installed v1 plugin and a published v0.9 rollback candidate.
 *
 * @return array Isolated settings, production service, ZIP package and connectors.
 */
function releaseInstallationFixture(): array
{
    $fixture = replacementFixture(true);
    $fixture['settings']->plugins[0]->updates = 'releases';
    $fixture['settings']->save();
    $fixture['connector']->releases['42'] = ['id' => '42', 'tag' => 'v0.9', 'title' => 'Earlier release',
        'url' => 'https://github.com/owner/sample/releases/tag/v0.9', 'author' => 'maintainer',
        'published_at' => '2025-01-01T12:00:00Z', 'prerelease' => false, 'body' => 'Older version', 'body_html' => ''];
    $fixture['connector']->archive = replacementZip(['wrapper/sample.php' => "<?php\n/* Plugin Name: Sample\nVersion: 0.9\n*/", 'wrapper/old.txt' => 'older-release']);
    return $fixture;
}

$beforeReleaseInstallation = $replacementChecks;
foreach ([false, true] as $multi) {
    $replacementMultisite = $replacementSuper = $multi;
    $replacementUser = $replacementSite = $replacementNetwork = 1;
    $replacementMods = true;
    $replacementCaps['update_plugins'] = true;
    $f = releaseInstallationFixture();
    $before = $f['packages']->snapshot('sample');
    $review = $f['service']->prepareRelease('managed-plugin', '42');
    replacementCheck(is_array($review) && $review['mode'] === 'release' && $review['ref'] === 'v0.9'
        && $review['installed_ref'] === 'v1' && $review['release']['id'] === '42', 'Review describes the exact chosen older release and installed ref.');
    replacementCheck($f['connector']->resolvedRef === '' && $f['packages']->snapshot('sample') === $before
        && !$f['settings']->plugins[0]->isReleasePinned(), 'Preparation uses a private connector and leaves both files and policy untouched.');
    // A retag after review must not change the downloaded archive.
    $f['connector']->commit = str_repeat('b', 40);
    replacementCheck($f['service']->apply($review['id']) === 'managed-plugin', 'Install an older release through the normal reviewed file transaction.');
    $plugin = $f['settings']->plugins[0];
    replacementCheck($f['connector']->downloadedCommit === str_repeat('a', 40)
        && $plugin->localVersion === 'v0.9' && $plugin->pinnedRelease['commit'] === str_repeat('a', 40), 'A moved tag cannot change the reviewed commit; successful installation persists the release hold.');
    replacementCheck(str_contains(file_get_contents(WP_PLUGIN_DIR . '/sample/sample.php'), 'Version: 0.9')
        && is_file(WP_PLUGIN_DIR . '/sample/old.txt'), 'The selected older archive is actually installed.');
    $plugin->checkForUpdates();
    replacementCheck(!$plugin->hasUpdate() && $plugin->remoteVersion === 'v0.9' && $f['connector']->latestChecks === 0,
        'Routine checks preserve the chosen release without looking up latest or offering an update.');
    $repeat = $f['service']->prepare('sample', ['connector' => 'source', 'folder' => 'sample', 'branch' => 'main', 'updates' => 'releases'], 'managed-plugin');
    replacementCheck(is_array($repeat) && $repeat['ref'] === 'v0.9' && $repeat['commit'] === str_repeat('a', 40),
        'Reinstalling a held release uses its saved commit even after the tag moved.');
    $f['service']->cancel($repeat['id']);
    $installed = $f['packages']->snapshot('sample');
    replacementCheck($f['service']->resumeReleaseUpdates('managed-plugin') === true
        && !$f['settings']->plugins[0]->isReleasePinned() && $f['settings']->plugins[0]->localVersion === 'v0.9'
        && $f['packages']->snapshot('sample') === $installed, 'Resuming updates clears only the hold and cached offer, preserving installed files and ref.');
    replacementError($f['service']->apply($review['id']), 'invalid_review');
}

$replacementMultisite = $replacementSuper = false;
foreach (['cancel', 'save', 'download', 'files', 'source', 'mode', 'missing', 'permission', 'resume-save', 'prerelease', 'pin-changed'] as $scenario) {
    $replacementMods = true;
    $replacementCaps['update_plugins'] = true;
    $f = releaseInstallationFixture();
    $before = $f['packages']->snapshot('sample');
    if ($scenario === 'mode') {
        $f['settings']->plugins[0]->updates = 'tags'; $f['settings']->save();
        replacementError($f['service']->prepareRelease('managed-plugin', '42'), 'release_selection_unavailable');
        continue;
    }
    if ($scenario === 'missing') {
        replacementError($f['service']->prepareRelease('managed-plugin', '999'), 'replacement_source_unavailable');
        continue;
    }
    if ($scenario === 'permission') {
        $replacementCaps['update_plugins'] = false;
        replacementError($f['service']->prepareRelease('managed-plugin', '42'), 'replacement_forbidden');
        $replacementCaps['update_plugins'] = true;
        continue;
    }
    if ($scenario === 'prerelease') $f['connector']->releases['42']['prerelease'] = true;
    $review = $f['service']->prepareRelease('managed-plugin', '42');
    replacementCheck(is_array($review), 'Prepare scenario ' . $scenario);
    if ($scenario === 'cancel') $f['service']->cancel($review['id']);
    if ($scenario === 'save') $f['settings']->failSave = true;
    if ($scenario === 'download') $f['connector']->downloadFails = true;
    if ($scenario === 'files') file_put_contents(WP_PLUGIN_DIR . '/sample/changed.txt', 'changed');
    if ($scenario === 'source') $f['connector']->owner = 'other-owner';
    if ($scenario === 'pin-changed') {
        $f['settings']->plugins[0]->pinnedRelease = ['id' => '43', 'tag' => 'v0.8', 'commit' => str_repeat('b', 40)];
        $f['settings']->save();
    }
    if (in_array($scenario, ['prerelease', 'resume-save'], true)) {
        replacementCheck($f['service']->apply($review['id']) === 'managed-plugin', 'Manual selection can install and hold a prerelease.');
        if ($scenario === 'prerelease') replacementCheck($f['settings']->plugins[0]->pinnedRelease['release']['prerelease'], 'Pre-release status remains visible in the held release.');
        if ($scenario === 'resume-save') {
            $f['settings']->failSave = true;
            replacementError($f['service']->resumeReleaseUpdates('managed-plugin'), 'save_failed');
            replacementCheck($f['settings']->plugins[0]->isReleasePinned(), 'Failed resume preserves the previously saved hold.');
        }
        continue;
    }
    replacementError($f['service']->apply($review['id']), match ($scenario) {
        'cancel' => 'invalid_review', 'save' => 'save_failed', 'download' => 'download_failed', default => 'replacement_changed',
    });
    if (!in_array($scenario, ['files', 'pin-changed'], true)) {
        replacementCheck(!$f['settings']->plugins[0]->isReleasePinned() && $f['packages']->snapshot('sample') === $before,
            'Failed or cancelled release installation preserves old files and never creates a hold.');
    }
}
$replacementCaps['update_plugins'] = true;
echo 'Passed ' . ($replacementChecks - $beforeReleaseInstallation) . " chosen-release installation and hold checks.\n";

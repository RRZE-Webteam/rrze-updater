<?php
// Runs inside plugin-replacement.php's disposable WordPress directory and real ZIP transaction.

/**
 * Seed a newer commit installation with an older, selectable archive.
 *
 * @return array Isolated registry, production replacement service and ZIP collaborators.
 */
function commitInstallationFixture(): array
{
    $fixture = replacementFixture(true);
    $fixture['settings']->plugins[0]->updates = 'commits';
    $fixture['settings']->plugins[0]->localVersion = str_repeat('b', 40);
    $fixture['settings']->save();
    $sha = str_repeat('a', 40);
    $fixture['connector']->commits[$sha] = ['sha' => $sha, 'title' => 'Earlier commit', 'message' => "Earlier commit\n\nDetails",
        'url' => 'https://github.com/owner/sample/commit/' . $sha, 'author' => 'Test Author', 'author_url' => '',
        'committed_at' => '2025-01-01T15:39:00Z'];
    $fixture['connector']->archive = replacementZip(['wrapper/sample.php' => "<?php\n/* Plugin Name: Sample\nVersion: 0.9\n*/", 'wrapper/old.txt' => 'old-commit']);
    return $fixture;
}

$beforeCommitInstallation = $replacementChecks;
$sha = str_repeat('a', 40);
foreach ([false, true] as $multi) {
    $replacementMultisite = $replacementSuper = $multi;
    $replacementUser = $replacementSite = $replacementNetwork = 1;
    $f = commitInstallationFixture();
    $before = $f['packages']->snapshot('sample');
    $review = $f['service']->prepareCommit('managed-plugin', $sha);
    replacementCheck(is_array($review) && $review['mode'] === 'commit' && $review['ref'] === $sha
        && $review['selected_commit']['title'] === 'Earlier commit' && $review['installed_ref'] === str_repeat('b', 40),
        'Commit review records the exact installed and selected commits with display metadata.');
    replacementCheck($f['packages']->snapshot('sample') === $before && !$f['settings']->plugins[0]->isUpdatePinned(),
        'Preparing a commit leaves files and update policy untouched.');
    $f['connector']->commit = str_repeat('c', 40);
    replacementCheck($f['service']->apply($review['id']) === 'managed-plugin', 'Install the historical commit in single-site and multisite.');
    $plugin = $f['settings']->plugins[0];
    replacementCheck($f['connector']->downloadedCommit === $sha && $plugin->pinnedCommit['commit'] === $sha
        && $plugin->localVersion === $sha && !$plugin->isReleasePinned(), 'A moving branch cannot change the confirmed download or the persisted hold.');
    replacementCheck(str_contains(file_get_contents(WP_PLUGIN_DIR . '/sample/sample.php'), 'Version: 0.9')
        && file_get_contents(WP_PLUGIN_DIR . '/sample/old.txt') === 'old-commit', 'The selected commit archive is actually installed.');
    $plugin->checkForUpdates();
    replacementCheck(!$plugin->hasUpdate() && $plugin->remoteVersion === $sha && $f['connector']->resolvedRef === '',
        'Normal checks preserve the held commit without resolving the latest branch.');
    $repeat = $f['service']->prepare('sample', ['connector' => 'source', 'folder' => 'sample', 'branch' => 'main', 'updates' => 'commits'], 'managed-plugin');
    replacementCheck(is_array($repeat) && $repeat['commit'] === $sha && $repeat['ref'] === $sha, 'Reinstallation repairs the held commit instead of installing branch HEAD.');
    $f['service']->cancel($repeat['id']);
    $installed = $f['packages']->snapshot('sample');
    replacementCheck($f['service']->resumeCommitUpdates('managed-plugin') === true && !$f['settings']->plugins[0]->isUpdatePinned()
        && $f['settings']->plugins[0]->localVersion === $sha && $f['packages']->snapshot('sample') === $installed,
        'Resuming branch updates preserves installed files and commit.');
    $f['settings']->plugins[0]->checkForUpdates();
    replacementCheck($f['settings']->plugins[0]->hasUpdate() && $f['settings']->plugins[0]->remoteVersion === str_repeat('c', 40),
        'After resuming, the current branch tip is offered normally.');
    replacementError($f['service']->apply($review['id']), 'invalid_review');
}
$replacementMultisite = $replacementSuper = false;
foreach (['cancel', 'save', 'download', 'files', 'source', 'mode', 'missing', 'permission', 'resume-save', 'resume-throw', 'resume-after-save', 'pin-changed'] as $scenario) {
    $f = commitInstallationFixture();
    $before = $f['packages']->snapshot('sample');
    if ($scenario === 'mode') {
        $f['settings']->plugins[0]->updates = 'releases'; $f['settings']->save();
        replacementError($f['service']->prepareCommit('managed-plugin', $sha), 'commit_selection_unavailable');
        continue;
    }
    if ($scenario === 'missing') {
        replacementError($f['service']->prepareCommit('managed-plugin', str_repeat('f', 40)), 'replacement_source_unavailable');
        continue;
    }
    if ($scenario === 'permission') {
        $replacementCaps['update_plugins'] = false;
        replacementError($f['service']->prepareCommit('managed-plugin', $sha), 'replacement_forbidden');
        $replacementCaps['update_plugins'] = true;
        continue;
    }
    $review = $f['service']->prepareCommit('managed-plugin', $sha);
    replacementCheck(is_array($review), 'Prepare commit scenario ' . $scenario);
    if (str_starts_with($scenario, 'resume-')) {
        replacementCheck($f['service']->apply($review['id']) === 'managed-plugin', 'Install before testing resume failure.');
        $f['settings']->failSave = $scenario === 'resume-save';
        $f['settings']->throwSave = $scenario === 'resume-throw';
        $f['settings']->throwAfterSave = $scenario === 'resume-after-save';
        $result = $f['service']->resumeCommitUpdates('managed-plugin');
        if ($scenario === 'resume-after-save') {
            replacementCheck($result === true && !$f['settings']->plugins[0]->isUpdatePinned(), 'Post-save exceptions cannot misreport a durably resumed commit hold.');
        } else {
            replacementError($result, 'save_failed');
            replacementCheck($f['settings']->plugins[0]->isUpdatePinned(), 'Failed resume preserves the previously saved commit hold.');
        }
        continue;
    }
    if ($scenario === 'cancel') $f['service']->cancel($review['id']);
    if ($scenario === 'save') $f['settings']->failSave = true;
    if ($scenario === 'download') $f['connector']->downloadFails = true;
    if ($scenario === 'files') file_put_contents(WP_PLUGIN_DIR . '/sample/changed.txt', 'changed');
    if ($scenario === 'source') $f['connector']->owner = 'other-owner';
    if ($scenario === 'pin-changed') {
        $f['settings']->plugins[0]->pinnedCommit = ['commit' => str_repeat('b', 40), 'version' => '1.0'];
        $f['settings']->save();
    }
    replacementError($f['service']->apply($review['id']), match ($scenario) {
        'cancel' => 'invalid_review', 'save' => 'save_failed', 'download' => 'download_failed', default => 'replacement_changed',
    });
    if (!in_array($scenario, ['files', 'pin-changed'], true)) {
        replacementCheck(!$f['settings']->plugins[0]->isUpdatePinned() && $f['packages']->snapshot('sample') === $before,
            'Failed or cancelled commit installation preserves previous files without creating a hold.');
    }
}
foreach ([false, true] as $failReselection) {
    $f = commitInstallationFixture();
    $review = $f['service']->prepareCommit('managed-plugin', $sha);
    replacementCheck($f['service']->apply($review['id']) === 'managed-plugin', 'Hold the first commit before selecting another.');
    $installed = $f['packages']->snapshot('sample');
    $other = str_repeat('d', 40);
    $f['connector']->commit = $other;
    $f['connector']->commits[$other] = array_merge($f['connector']->commits[$sha], ['sha' => $other, 'title' => 'Another commit']);
    $review = $f['service']->prepareCommit('managed-plugin', $other);
    replacementCheck(is_array($review) && $f['settings']->plugins[0]->pinnedCommit['commit'] === $sha,
        'A new selection can be reviewed while the previous commit remains held.');
    $f['settings']->failSave = $failReselection;
    $result = $f['service']->apply($review['id']);
    if ($failReselection) {
        replacementError($result, 'save_failed');
        replacementCheck($f['settings']->plugins[0]->pinnedCommit['commit'] === $sha && $f['packages']->snapshot('sample') === $installed,
            'A failed second selection restores the original files and hold.');
    } else {
        replacementCheck($result === 'managed-plugin' && $f['settings']->plugins[0]->pinnedCommit['commit'] === $other
            && $f['settings']->plugins[0]->localVersion === $other, 'A successful second selection replaces the held commit atomically.');
    }
}
echo 'Passed ' . ($replacementChecks - $beforeCommitInstallation) . " chosen-commit installation and hold checks.\n";

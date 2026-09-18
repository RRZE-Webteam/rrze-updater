<?php
// Concurrent-request regressions; fixtures never touch the live WordPress database.
require_once __DIR__ . '/bundles.php';

use RRZE\Updater\Settings;

$beforeSettings = $checks;
foreach ([true, false] as $multisite) {
    $storage = [];
    $f = bundleFixture([bundleEntry('newly-installed')]);
    check($f['settings']->save(), 'Persist initial connector.');
    $cron = new Settings();
    bundleAction($f, 'check'); bundleDrain($f);
    bundleAction($f, 'install'); bundleDrain($f);
    check(count((new Settings())->plugins) === 1, 'Installation registered its plugin.');
    $cron->options['info_logging_enabled'] = true;
    check($cron->save(), 'Stale request merges its unrelated edit.');
    check(count((new Settings())->plugins) === 1, 'Stale save preserves new registration.');
    // A second save by the same stale object must not erase remote additions either.
    check($cron->save() && count((new Settings())->plugins) === 1, 'Repeated stale saves preserve registrations.');

    $first = new Settings(); $second = new Settings();
    $first->connectors[0]->token = 'rotated-token';
    check($first->save(), 'Save rotated token.');
    $second->plugins[0]->lastChecked = 1234;
    check($second->save(), 'Concurrent update check saves its own metadata.');
    $fresh = new Settings();
    check($fresh->connectors[0]->token === 'rotated-token' && $fresh->plugins[0]->lastChecked === 1234,
        'Metadata save preserves rotated credentials.');

    $first = new Settings(); $second = new Settings();
    $first->plugins[0]->branch = 'release';
    $second->plugins[0]->lastChecked = 5678;
    check($first->save() && $second->save(), 'Independent fields on one record merge.');
    check((new Settings())->plugins[0]->branch === 'release', 'Metadata does not revert branch.');

    $first = new Settings(); $second = new Settings();
    $first->connectors[0]->token = 'new-token';
    $second->connectors[0]->token = 'conflicting-token';
    check($first->save() && !$second->save(), 'Conflicting edits fail without overwriting.');
    check((new Settings())->connectors[0]->token === 'new-token' && !$wpdb->locked, 'Conflict preserves data and releases lock.');

    $first = new Settings(); $second = new Settings();
    $first->plugins = [];
    check($first->save(), 'Explicit deletion is persisted.');
    $second->plugins[0]->lastChecked = 9999;
    check(!$second->save() && (new Settings())->plugins === [], 'Stale metadata cannot resurrect a removed record.');

    $fresh = new Settings(); $fresh->options['info_logging_enabled'] = false;
    $wpdb->deny = true;
    check(!$fresh->save(), 'Lock timeout prevents an unlocked write.');
    $wpdb->deny = false;
    $fail_save = true;
    check(!$fresh->save() && !$wpdb->locked, 'Failed persistence releases the lock.');
    $fail_save = false;
    check($fresh->save(), 'Failed save leaves the pending changes retryable.');
}
$multisite = true;
$result = (new RRZE\Updater\Bundles\JobStore())->withLock(function () {
    $settings = new Settings();
    return $settings->save();
});
check($result === true && !$wpdb->locked, 'Settings save can run inside the separate bundle lock.');

// Validate relationships after merging, not against either request's old snapshot.
foreach ([true, false] as $multisite) {
    foreach (['plugin', 'theme'] as $type) {
        $property = $type === 'plugin' ? 'plugins' : 'themes';
        $storage = [];
        $f = bundleFixture([bundleEntry('installed-during-delete', $type)]);
        check($f['settings']->save(), 'Seed connector for concurrent deletion.');
        $deletion = new Settings();
        check(!$deletion->isConnectorUsed('bundle-github'), 'Deletion request initially sees an unused connector.');
        bundleAction($f, 'check'); bundleDrain($f);
        bundleAction($f, 'install'); bundleDrain($f);
        $before = $storage;
        $deletion->connectors = [];
        check(!$deletion->save() && $storage === $before, 'Reject stale connector deletion after a new association was saved.');
        $fresh = new Settings();
        check(count($fresh->$property) === 1 && is_object($fresh->{$property}[0]->connector) && !$wpdb->locked,
            'Keep the installed extension and its connector intact and release the lock.');

        // Reverse the ordering: deleting the connector first must block a stale
        // registration, rather than resurrecting the connector or orphaning it.
        $storage = [];
        $f = bundleFixture([]); $f['settings']->save();
        $registration = new Settings();
        $deletion = new Settings(); $deletion->connectors = [];
        check($deletion->save(), 'An unused connector can still be deleted.');
        $installer = new InstallerFixture();
        $installer->installed["$type/existing"] = true;
        $plan = ['action' => 'register', 'ref' => str_repeat('a', 40), 'checked_at' => time()];
        $options = ['connector' => 'bundle-github', 'branch' => 'main', 'updates' => 'commits'];
        $before = $storage;
        $result = (new RRZE\Updater\Core\RepositoryManager($registration, $installer))->applyPrepared($type, 'existing', $options, $plan);
        check(is_wp_error($result) && $storage === $before && !(new Settings())->connectors && !(new Settings())->$property,
            'A stale registration cannot reference or recreate a deleted connector.');

        foreach ([
            ['other-repo', 'shared-folder', false],
            ['other-repo', 'SHARED-FOLDER', false],
            ['same-repo', 'different-folder', false],
            ['SAME-REPO', 'different-folder', false],
            ['other-repo', 'different-folder', true],
        ] as [$secondRepository, $secondFolder, $allowed]) {
            $storage = [];
            $f = bundleFixture([]); $f['settings']->save();
            $first = new Settings(); $second = new Settings();
            $installer = new InstallerFixture();
            $installer->installed["$type/shared-folder"] = $installer->installed["$type/$secondFolder"] = true;
            $firstManager = new RRZE\Updater\Core\RepositoryManager($first, $installer);
            $secondManager = new RRZE\Updater\Core\RepositoryManager($second, $installer);
            check(is_string($firstManager->applyPrepared($type, 'same-repo', $options + ['folder' => 'shared-folder'], $plan)), 'Persist the first registration.');
            $before = $storage;
            $result = $secondManager->applyPrepared($type, $secondRepository, $options + ['folder' => $secondFolder], $plan);
            check(is_string($result) === $allowed, 'Concurrent registrations must have unique folders and repository identities.');
            check(count((new Settings())->$property) === ($allowed ? 2 : 1) && !$wpdb->locked, 'Registry remains consistent after concurrent registrations.');
            if (!$allowed) {
                check($storage === $before && $second->$property === [], 'Rejected registration writes nothing and restores the caller state.');
            }
        }
    }
}
$multisite = true;

// Existing conflicting data can be repaired by removing the duplicate record.
$fresh = new Settings();
$duplicate = $fresh->themes[0]->asArray(); $duplicate['id'] = 'legacy-duplicate';
$storage['rrze_updater']['themes'][] = $duplicate;
$repair = new Settings(); array_pop($repair->themes);
check($repair->save() && count((new Settings())->themes) === 2, 'Allow a save that repairs pre-existing duplicate associations.');
echo 'Passed ' . ($checks - $beforeSettings) . " concurrent settings checks.\n";

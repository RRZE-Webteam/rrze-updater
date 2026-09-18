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
echo 'Passed ' . ($checks - $beforeSettings) . " concurrent settings checks.\n";

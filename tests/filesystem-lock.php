<?php

use RRZE\Updater\FilesystemLock;

$beforeFilesystemLock = $checks;
$lockGlobals = [];
foreach (['wpdb', 'multisite', 'bundle_network', 'fixture_blog_id'] as $key) {
    $lockGlobals[$key] = $GLOBALS[$key] ?? null;
}
FilesystemLock::releaseAll();
$wpdb = new SettingsDatabaseFixture();
$multisite = true;
$bundle_network = 1;

check(FilesystemLock::acquire() === true && $wpdb->locked, 'Filesystem acquisition owns the database lock.');
check(FilesystemLock::acquire() === true && $wpdb->acquisitions === 1, 'Nested operations reuse the same connection-owned lock.');
FilesystemLock::release();
check($wpdb->locked, 'Nested release keeps the outer operation protected.');
$bundle_network = 2;
check(is_wp_error(FilesystemLock::acquire()), 'Network switching cannot bypass the shared files lock.');
$bundle_network = 1;
FilesystemLock::release();
check(!$wpdb->locked, 'The final holder releases the lock.');

$wpdb->deny = true;
$ran = false;
check(is_wp_error(FilesystemLock::withLock(static function () use (&$ran): void {
    $ran = true;
})) && !$ran, 'A busy database lock prevents every filesystem callback.');
$wpdb->deny = false;
try {
    FilesystemLock::withLock(static function (): void {
        throw new RuntimeException('Fixture exception');
    });
} catch (RuntimeException $error) {
    check(!$wpdb->locked, 'Exceptions release the acquired lock.');
}

FilesystemLock::acquire();
FilesystemLock::acquire();
FilesystemLock::releaseAll();
check(!$wpdb->locked, 'Shutdown cleanup releases all nested holders.');

$multisite = false;
$fixture_blog_id = 7;
check(FilesystemLock::withLock(static fn(): bool => true) === true && !$wpdb->locked, 'Single-site operations use the shared lock and release it.');
foreach ($lockGlobals as $key => $value) {
    $GLOBALS[$key] = $value;
}
echo 'Passed ' . ($checks - $beforeFilesystemLock) . " shared filesystem lock checks.\n";

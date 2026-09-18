<?php
require_once __DIR__ . '/bundles.php';

use RRZE\Updater\Bundles\BundleManager;
use RRZE\Updater\Core\RepositoryManager;

$beforeRegistrations = $checks;
$f = bundleFixture([bundleEntry('git-clone'), bundleEntry('take-over'), bundleEntry('new-plugin')]);
$f['installer']->installed = ['plugin/git-clone' => true, 'plugin/take-over' => true];
bundleAction($f, 'check'); bundleDrain($f);
$job = bundleAction($f, 'install', ['plugin/take-over']);
check($job['registrations'] === ['plugin/take-over'], 'Persist explicit registration consent with the job.');
check($job['items']['plugin/git-clone']['status'] === 'registration_skipped', 'Unchecked installations stay unmanaged.');
// Resume with a new manager, without resubmitting the choices.
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
$job = bundleDrain($f);
check($job['phase'] === 'complete' && $f['installer']->installs === 1, 'Install new entries while preserving existing files.');
check(array_column($f['settings']->plugins, 'repository') === ['take-over', 'new-plugin'], 'Register only the selected existing installation and the newly installed plugin.');
check($f['settings']->plugins[0]->localVersion === '', 'Registration does not invent the existing Git commit.');

$f = bundleFixture([bundleEntry('existing')]);
$f['installer']->installed['plugin/existing'] = true;
bundleAction($f, 'check'); bundleDrain($f);
$beforeSaves = $saves;
$job = bundleAction($f, 'install');
check($job['phase'] === 'complete' && $job['items']['plugin/existing']['status'] === 'registration_skipped', 'Declining all registrations finishes successfully without a queue.');
check($saves === $beforeSaves && $f['installer']->installs === 0 && !$f['settings']->plugins, 'Omitted consent changes neither files nor registry.');

foreach ([['unknown'], ['plugin/new'], [12], [['plugin/existing']], ['id' => 'plugin/existing']] as $invalid) {
    $f = bundleFixture([bundleEntry('existing'), bundleEntry('new')]);
    $f['installer']->installed['plugin/existing'] = true;
    bundleAction($f, 'check'); $job = bundleDrain($f);
    $result = $f['manager']->handle('install', $job['id'], $job['revision'], [], [], $invalid);
    check(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_bundle_invalid_registrations', 'Reject consent for invalid or ineligible entries.');
    check($f['store']->load() === $job && !$f['settings']->plugins, 'Invalid consent leaves the saved plan unchanged.');
}

$f = bundleFixture([bundleEntry('keep'), bundleEntry('retry-registration')]);
$f['installer']->installed = ['plugin/keep' => true, 'plugin/retry-registration' => true];
bundleAction($f, 'check'); bundleDrain($f);
bundleAction($f, 'install', ['plugin/retry-registration']);
unset($f['installer']->installed['plugin/retry-registration']);
$job = bundleDrain($f);
check($job['items']['plugin/retry-registration']['status'] === 'failed', 'An approved registration fails if its installation disappeared.');
$f['installer']->installed['plugin/retry-registration'] = true;
bundleAction($f, 'retry'); $job = bundleDrain($f);
check($job['items']['plugin/keep']['status'] === 'registration_skipped'
    && array_column($f['settings']->plugins, 'repository') === ['retry-registration'], 'Retry keeps earlier consent and never adds declined entries.');

foreach ([false, true] as $removeParent) {
    $f = bundleFixture([bundleEntry('child', 'theme'), bundleEntry('parent', 'theme')]);
    $f['connector']->parents['child'] = 'parent';
    $f['installer']->installed['theme/parent'] = true;
    bundleAction($f, 'check'); bundleDrain($f);
    bundleAction($f, 'install');
    if ($removeParent) unset($f['installer']->installed['theme/parent']);
    $job = bundleDrain($f);
    check($job['items']['theme/parent']['status'] === 'registration_skipped', 'Parent theme can remain unmanaged.');
    check($job['items']['theme/child']['status'] === ($removeParent ? 'failed' : 'done'), 'Child requires actual parent files, not Updater registration.');
    check(array_column($f['settings']->themes, 'repository') === ($removeParent ? [] : ['child']), 'Never register the declined parent.');
}

$f = bundleFixture([bundleEntry('late-clone')]);
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install');
$f['installer']->installed['plugin/late-clone'] = true;
$job = bundleDrain($f);
check($job['items']['plugin/late-clone']['status'] === 'failed' && !$f['settings']->plugins && !$f['installer']->installs,
    'A clone appearing after preflight is never overwritten or silently registered.');

$f = bundleFixture([bundleEntry('legacy-existing')]);
$f['installer']->installed['plugin/legacy-existing'] = true;
bundleAction($f, 'check'); bundleDrain($f);
bundleAction($f, 'install', ['plugin/legacy-existing']);
unset($f['store']->job['registrations']);
$job = bundleDrain($f);
check($job['items']['plugin/legacy-existing']['status'] === 'registration_skipped' && !$f['settings']->plugins,
    'Jobs queued before consent support cannot silently register existing installations.');

$f = bundleFixture([bundleEntry('formerly-managed')]);
$f['installer']->installed['plugin/formerly-managed'] = true;
$repositories = new RepositoryManager($f['settings'], $f['installer']);
$options = ['connector' => 'bundle-github', 'branch' => 'main', 'updates' => 'commits'];
$repositories->register('plugin', 'formerly-managed', $options);
$plan = $repositories->prepare('plugin', 'formerly-managed', $options);
$repositories->unregister('plugin', 'formerly-managed');
$result = $repositories->applyPrepared('plugin', 'formerly-managed', $options, $plan);
check(is_wp_error($result) && !$f['settings']->plugins, 'An already-managed plan cannot silently restore a registration removed after review.');

// Partial installation applies the same consent boundary to passing entries.
$f = bundleFixture([bundleEntry('unmanaged'), bundleEntry('denied'), bundleEntry('fresh')]);
$f['installer']->installed['plugin/unmanaged'] = true;
$f['connector']->denied = ['denied'];
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install_anyways');
$job = bundleDrain($f);
check($job['items']['plugin/unmanaged']['status'] === 'registration_skipped'
    && $job['items']['plugin/denied']['status'] === 'prerequisite_skipped'
    && $job['items']['plugin/fresh']['status'] === 'done', 'Install passing entries respects registration opt-out.');

$admin = new RRZE\Updater\BundleAdmin();
$bundle_caps = ['manage_network_options' => true, 'install_plugins' => true, 'install_themes' => true];
$bundle_mods = true; $bundle_network = 1; $multisite = true;
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (['null', '{}', '{broken', '[1]', '[{}]', ['nested']] as $invalid) {
    $_POST = ['operation' => 'install', 'network' => 1, 'nonce' => 'rrze_updater_bundle_1', 'registrations' => $invalid];
    $admin->request();
    check($bundle_response['status'] === 400, 'AJAX rejects malformed registration consent before job mutation.');
}
echo 'Passed ' . ($checks - $beforeRegistrations) . " registration consent checks.\n";

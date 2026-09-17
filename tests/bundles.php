<?php
// Standalone integration tests: run with the same optional WP-CLI parser argument as cli.php.
require __DIR__ . '/cli.php';
define('DAY_IN_SECONDS', 86400);
function wp_generate_uuid4() { static $id = 0; return 'job-' . ++$id; }

use RRZE\Updater\Bundles\{BundleManager, Catalog, JobStore};
use RRZE\Updater\{BundleAdmin, Settings};
use RRZE\Updater\Core\RepositoryManager;

class BundleCatalogFixture extends Catalog {
    public array $items;
    public function __construct(array $items) { $this->items = $items; }
    public function get(): array {
        return ['id' => 'fixture', 'version' => 1, 'name' => 'Fixture',
            'connectors' => ['github' => ['type' => 'github', 'host' => 'github.com', 'owner' => 'RRZE-Webteam']],
            'items' => $this->items];
    }
}
class BundleStoreFixture extends JobStore {
    public ?array $job = null;
    public bool $locked = false;
    public int $writes = 0;
    public bool $fail = false;
    public function load(): ?array { return $this->job; }
    public function save(array $job): void {
        if ($this->fail) { throw new RuntimeException('Storage unavailable'); }
        $this->writes++;
        $this->job = $job;
    }
    public function withLock(callable $operation): mixed {
        if ($this->locked) { return new WP_Error('bundle_busy', 'Busy'); }
        $this->locked = true;
        try { return $operation(); } finally { $this->locked = false; }
    }
}
class BundleConnectorFixture extends ConnectorFixture {
    public array $parents = [];
    public array $denied = [];
    public function getRemoteTag(string $repository): string|false {
        if (in_array($repository, $this->denied, true)) {
            $this->error = 'Denied access with ' . $this->token;
            return false;
        }
        return parent::getRemoteTag($repository);
    }
    public function getRemoteFile(string $repository, string $ref, string $file): string|bool {
        if ($file === 'style.css' && isset($this->parents[$repository])) {
            return "Theme Name: $repository\nTemplate: {$this->parents[$repository]}";
        }
        return parent::getRemoteFile($repository, $ref, $file);
    }
}
class BundleInstallerFixture extends InstallerFixture {
    public array $refs = [];
    public array $failRepositories = [];
    public function install(string $type, RRZE\Updater\Core\Extension $extension): true|WP_Error {
        $this->refs[$extension->repository] = $extension->remoteVersion;
        if (in_array($extension->repository, $this->failRepositories, true)) {
            return new WP_Error('package_failed', 'Package failed');
        }
        return parent::install($type, $extension);
    }
}
function bundleEntry($repo, $type = 'plugin'): array {
    return ['id' => "$type/$repo", 'provider' => 'github', 'repository' => $repo,
        'folder' => $repo, 'type' => $type, 'branch' => 'main', 'updates' => 'tags'];
}
function bundleFixture(array $entries): array {
    $settings = new Settings();
    $settings->plugins = $settings->themes = [];
    $connector = new BundleConnectorFixture();
    $connector->id = 'bundle-github';
    $connector->owner = 'RRZE-Webteam';
    $connector->token = 'bundle-secret-token';
    $settings->connectors = [$connector];
    $store = new BundleStoreFixture();
    $catalog = new BundleCatalogFixture($entries);
    $installer = new BundleInstallerFixture();
    $manager = new BundleManager($catalog, $store, $settings, $installer);
    return compact('settings', 'connector', 'store', 'catalog', 'installer', 'manager');
}
function bundleAction(array $fixture, string $action): array {
    $job = $fixture['store']->load();
    $result = $fixture['manager']->handle($action, $job['id'] ?? '', $job['revision'] ?? -1);
    check(!is_wp_error($result), "Bundle action $action succeeds.");
    return $result['job'];
}
function bundleDrain(array $fixture): array {
    for ($i = 0; $i < 30; $i++) {
        $job = $fixture['store']->load();
        if (!in_array($job['phase'], ['checking', 'running'], true)) { return $job; }
        bundleAction($fixture, 'step');
    }
    throw new RuntimeException('Bundle did not finish');
}

$beforeBundleChecks = $checks;
$manifest = (new Catalog())->get();
check(count($manifest['items']) === 81, 'Complete 81-entry catalog.');
check(count(array_filter($manifest['items'], fn($item) => $item['type'] === 'theme')) === 10, 'Ten themes, including fau-events.');
check(count(array_unique(array_column($manifest['items'], 'id'))) === 81, 'Unique manifest entries.');
foreach ($manifest['items'] as $item) {
    check($item['updates'] === 'tags' && $item['repository'] === $item['folder'], 'Preserve default tags and exact folder case.');
    if ($item['repository'] === 'rrze-notices') {
        check($item['provider'] === 'gitlab' && $item['branch'] === 'master', 'Keep notices on GitLab master.');
    }
}

$f = bundleFixture([bundleEntry('child', 'theme'), bundleEntry('parent', 'theme'), bundleEntry('plugin')]);
$f['connector']->parents['child'] = 'parent';
$job = bundleAction($f, 'check');
check($f['installer']->installs === 0 && $f['settings']->themes === [], 'Starting preflight does not install or register.');
$job = bundleAction($f, 'step');
check(count(array_filter($job['items'], fn($item) => $item['status'] === 'ready')) === 1, 'One repository checked per request.');
$job = bundleDrain($f);
check($job['phase'] === 'ready', 'All prerequisites pass.');
check(array_search('theme/parent', array_keys($job['items'])) < array_search('theme/child', array_keys($job['items'])), 'Parents ordered before children.');
check(!str_contains(json_encode($job), $f['connector']->token), 'Job contains no credentials.');
$f['connector']->tag = 'v99';
bundleAction($f, 'install');
$job = bundleAction($f, 'step');
check($f['installer']->installs === 1, 'One installation per request.');
// A fresh manager simulates reopening the page; job state survives independently.
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
$job = bundleDrain($f);
check($job['phase'] === 'complete' && $f['installer']->installs === 3, 'Resume finishes the remaining items.');
check(array_values(array_unique($f['installer']->refs)) === ['v2.0.0'], 'Use reviewed refs despite newer tags.');
$oldJob = $job;
bundleAction($f, 'check');
check(is_wp_error($f['manager']->handle('install', $oldJob['id'], $oldJob['revision'])), 'Old browser job cannot start a replacement.');
$job = bundleDrain($f);
check(count(array_filter($job['items'], fn($i) => $i['plan']['action'] === 'skip')) === 3, 'Rerun skips managed installations.');
bundleAction($f, 'install'); bundleDrain($f);
check($f['installer']->installs === 3, 'Rerun does not overwrite files.');

$f = bundleFixture([bundleEntry('existing')]);
$f['installer']->installed['plugin/existing'] = true;
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['items']['plugin/existing']['plan']['action'] === 'register', 'Plan explicitly registers unmanaged installations.');
bundleAction($f, 'install'); bundleDrain($f);
check($f['installer']->installs === 0 && $f['settings']->plugins[0]->localVersion === '', 'Registration keeps files and does not invent an installed ref.');

foreach (['missing', 'duplicate', 'token', 'access', 'host', 'owner'] as $failure) {
    $f = bundleFixture([bundleEntry('denied')]);
    switch ($failure) {
        case 'missing': $f['settings']->connectors = []; break;
        case 'duplicate': $f['settings']->connectors[] = clone $f['connector']; break;
        case 'token': $f['connector']->token = ''; break;
        case 'access': $f['connector']->denied = ['denied']; break;
        case 'host': $f['catalog']->items[0]['provider'] = 'github';
            $f['settings']->connectors = [new class extends BundleConnectorFixture { public function getUrl(string $repository): string { return 'https://other.example/'; } }]; break;
        case 'owner': $f['connector']->owner = 'other'; break;
    }
    bundleAction($f, 'check'); $job = bundleDrain($f);
    check($job['phase'] === 'blocked', "Block $failure connector prerequisite.");
    check(!str_contains(json_encode($job), 'bundle-secret-token'), 'Redact tokens from failures.');
    check(is_wp_error($f['manager']->handle('install', $job['id'], $job['revision'])), 'Cannot bypass preflight errors.');
    check($f['installer']->installs === 0, 'No files changed on prerequisite failure.');
}

foreach (['missing-parent', 'cycle'] as $failure) {
    $f = bundleFixture([bundleEntry('child', 'theme'), bundleEntry('parent', 'theme')]);
    $f['connector']->parents = $failure === 'cycle' ? ['child' => 'parent', 'parent' => 'child'] : ['child' => 'absent'];
    bundleAction($f, 'check'); $job = bundleDrain($f);
    check($job['phase'] === 'blocked', "Block $failure before installation.");
}
$f = bundleFixture([bundleEntry('child', 'theme'), bundleEntry('parent', 'theme'), bundleEntry('independent')]);
$f['connector']->parents['child'] = 'parent';
$f['installer']->failRepositories = ['parent'];
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['theme/child']['status'] === 'failed' && !isset($f['installer']->refs['child']), 'Failed parent blocks its child.');
check($job['items']['plugin/independent']['status'] === 'done', 'Independent repositories continue after failure.');
$f['installer']->failRepositories = [];
bundleAction($f, 'retry'); $job = bundleDrain($f);
check(count(array_filter($job['items'], fn($i) => $i['status'] === 'done')) === 3 && $f['installer']->installs === 3, 'Retry only failed entries in dependency order.');

$f = bundleFixture([bundleEntry('interrupted')]);
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install');
$f['store']->job['items']['plugin/interrupted']['status'] = 'installing';
$f['installer']->installed['plugin/interrupted'] = true;
$job = bundleDrain($f);
check($job['items']['plugin/interrupted']['status'] === 'failed', 'Files without a saved association require inspection after interruption.');
check($f['settings']->plugins === [], 'Do not claim interrupted files match the reviewed ref.');

$f = bundleFixture([bundleEntry('interrupted-saved')]);
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install');
$item = $f['store']->job['items']['plugin/interrupted-saved'];
(new RepositoryManager($f['settings'], $f['installer']))->applyPrepared('plugin', $item['repository'], $item['options'], $item['plan']);
$f['store']->job['items'][$item['id']]['status'] = 'installing';
$job = bundleDrain($f);
check($job['items'][$item['id']]['status'] === 'done' && $f['installer']->installs === 1, 'Recover saved association without repeating installation.');

$f = bundleFixture([bundleEntry('race')]);
bundleAction($f, 'check'); bundleDrain($f);
$f['store']->locked = true;
$before = $f['store']->job;
check(is_wp_error($f['manager']->handle('install', $before['id'], $before['revision'])), 'Reject overlapping requests.');
check($f['store']->job === $before, 'Busy request cannot change progress.');
$f['store']->locked = false;
$f['store']->job['created_at'] = time() - 86401;
$job = $f['store']->job;
check(is_wp_error($f['manager']->handle('install', $job['id'], $job['revision'])), 'Expired preflight must be refreshed.');
$f['store']->fail = true;
try { bundleAction($f, 'check'); check(false, 'Storage failure must propagate.'); } catch (RuntimeException $e) {}
check(!$f['store']->locked && $f['installer']->installs === 0, 'Release lock on persistence failure before mutation.');



// Changes between preflight and execution must not silently change the plan.
$f = bundleFixture([bundleEntry('removed')]);
$f['installer']->installed['plugin/removed'] = true;
(new RepositoryManager($f['settings'], $f['installer']))->register('plugin', 'removed', ['connector' => 'bundle-github']);
bundleAction($f, 'check'); bundleDrain($f);
unset($f['installer']->installed['plugin/removed']);
bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['plugin/removed']['status'] === 'failed', 'Recheck skip entries if files disappear after review.');
check($f['installer']->installs === 0, 'Do not silently turn a reviewed skip into an install.');
$f = bundleFixture([bundleEntry('conflict')]);
$f['installer']->installed['plugin/conflict'] = true;
(new RepositoryManager($f['settings'], $f['installer']))->register('plugin', 'conflict', ['connector' => 'bundle-github', 'updates' => 'releases']);
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'blocked', 'Do not overwrite a conflicting update policy.');
$f = bundleFixture([bundleEntry('changed-connector')]);
bundleAction($f, 'check'); bundleDrain($f);
$f['connector']->id = 'replacement';
bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['plugin/changed-connector']['status'] === 'failed' && $f['installer']->installs === 0, 'Replaced connector requires fresh review.');
$f = bundleFixture([bundleEntry('child', 'theme')]);
$f['connector']->parents['child'] = 'outside-parent';
$f['installer']->installed['theme/outside-parent'] = true;
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'ready', 'Allow an already installed external parent.');
unset($f['installer']->installed['theme/outside-parent']);
bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['theme/child']['status'] === 'failed' && $f['installer']->installs === 0, 'Do not fetch an external parent removed after review.');
$f = bundleFixture([bundleEntry('refresh')]);
$f['connector']->token = '';
bundleAction($f, 'check'); bundleDrain($f);
$f['connector']->token = 'fixed';
bundleAction($f, 'retry'); $job = bundleDrain($f);
check($job['phase'] === 'ready', 'Retry prerequisite checks after fixing credentials.');
$f['catalog']->items[] = bundleEntry('new-entry');
check(is_wp_error($f['manager']->handle('install', $job['id'], $job['revision'])), 'A changed catalog requires fresh review.');

echo 'Passed ' . ($checks - $beforeBundleChecks) . " bundle checks.\n";

// Exercise the real persistence/lock adapter and the AJAX authorization boundary.
function wp_cache_delete($key, $group) { $GLOBALS['bundle_cache_deleted'][] = [$key, $group]; }
function get_current_network_id() { return $GLOBALS['bundle_network'] ?? 1; }
function get_network_option($network, $key, $default = false) { return $GLOBALS['network_storage'][$network][$key] ?? $default; }
function update_network_option($network, $key, $value) { $GLOBALS['network_storage'][$network][$key] = $value; return true; }
function current_user_can($capability) { return $GLOBALS['bundle_caps'][$capability] ?? false; }
function wp_is_file_mod_allowed($context) { return $GLOBALS['bundle_mods'] ?? true; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function wp_unslash($value) { return $value; }
function check_ajax_referer($action, $field) {
    if (($GLOBALS['_POST'][$field] ?? '') !== $action) { throw new RuntimeException('Bad nonce'); }
}
function wp_send_json_error($data, $status = null) { $GLOBALS['bundle_response'] = ['success' => false, 'status' => $status, 'data' => $data]; }
function wp_send_json_success($data) { $GLOBALS['bundle_response'] = ['success' => true, 'data' => $data]; }
if (!defined('DB_NAME')) { define('DB_NAME', 'fixture'); }
$wpdb = new class {
    public string $base_prefix = 'wp_';
    public bool $locked = false;
    public int $acquisitions = 0;
    public function prepare($query, $name) { return str_replace('%s', $name, $query); }
    public function get_var($query) {
        if (str_contains($query, 'RELEASE_LOCK')) { $this->locked = false; return '1'; }
        if ($this->locked) { return '0'; }
        $this->locked = true;
        $this->acquisitions++;
        return '1';
    }
};
$beforeBoundaryChecks = $checks;
$realStore = new JobStore();
$bundle_network = 1;
$realStore->save(['id' => 'first-network']);
$bundle_network = 2;
check($realStore->load() === null, 'Progress is scoped to its network.');
$realStore->save(['id' => 'second-network']);
$bundle_network = 1;
check($realStore->load()['id'] === 'first-network', 'Networks cannot overwrite each other’s job state.');
$realStore->withLock(function () use ($realStore) {
    $GLOBALS['bundle_network'] = 2;
    check(is_wp_error($realStore->withLock(fn() => true)), 'Lock covers shared files across networks.');
});
check(!$wpdb->locked, 'Database lock is released after success.');
check(in_array(['1:rrze_updater', 'site-options'], $bundle_cache_deleted, true), 'Discard settings cached before acquiring the lock.');
try { $realStore->withLock(function () { throw new RuntimeException('failure'); }); } catch (RuntimeException $e) {}
check(!$wpdb->locked, 'Database lock is released after exception.');

$admin = new BundleAdmin();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['operation' => 'status', 'network' => 2, 'nonce' => 'rrze_updater_bundle_2'];
$multisite = true;
$bundle_caps = [];
$admin->request();
check($bundle_response['status'] === 403, 'Deny ordinary users.');
$bundle_caps = ['manage_network_options' => true, 'install_plugins' => true, 'install_themes' => true];
$multisite = false;
$admin->request();
check($bundle_response['status'] === 403, 'Deny single-site requests.');
$multisite = true;
$_POST['network'] = 1;
$admin->request();
check($bundle_response['status'] === 403, 'Reject a forged network ID.');
$_POST['network'] = 2;
$_POST['nonce'] = 'bad';
try { $admin->request(); check(false, 'Bad nonce must stop request.'); } catch (RuntimeException $e) {}
$_POST['nonce'] = 'rrze_updater_bundle_2';
$_SERVER['REQUEST_METHOD'] = 'GET';
$admin->request();
check($bundle_response['status'] === 403, 'Mutations require POST.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$admin->request();
check($bundle_response['success'] && $bundle_response['data']['job']['id'] === 'second-network', 'Authorized status reads correct network.');
$bundle_mods = false;
$_POST['operation'] = 'check';
$before = $wpdb->acquisitions;
$admin->request();
check($bundle_response['status'] === 403 && $wpdb->acquisitions === $before, 'Respect disabled file modifications before creating a job.');
unset($bundle_caps['install_themes']);
$admin->request();
check($bundle_response['status'] === 403, 'Require theme installation permission as well as plugin permission.');
echo 'Passed ' . ($checks - $beforeBoundaryChecks) . " network/authorization/lock checks.\n";

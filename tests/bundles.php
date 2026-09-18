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
    public string $commit = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public function remoteBranchExists(string $repository, string $branch): bool { return in_array($branch, ['main', 'master'], true); }
    public function getRemoteBranches(string $repository): array|false { return ['main', 'master']; }
    public function getRemoteTag(string $repository): string|false {
        throw new RuntimeException('Recommended installation must not query tags.');
    }
    public function getRemoteCommit(string $repository, string $branch): string {
        $this->calls[] = ['commits', $branch];
        if (in_array($repository, $this->denied, true)) {
            $this->error = 'Denied access with ' . $this->token;
            return '';
        }
        return $this->commit;
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
        'folder' => $repo, 'type' => $type, 'branch' => 'main', 'updates' => 'commits'];
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
    $result = $fixture['manager']->handle($action, $job['id'] ?? '', $job['revision'] ?? -1, ['github' => 'bundle-github']);
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
    check($item['updates'] === 'commits' && $item['repository'] === $item['folder'], 'Use commits and preserve exact folder case.');
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
$f['connector']->commit = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
bundleAction($f, 'install');
$job = bundleAction($f, 'step');
check($f['installer']->installs === 1, 'One installation per request.');
// A fresh manager simulates reopening the page; job state survives independently.
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
$job = bundleDrain($f);
check($job['phase'] === 'complete' && $f['installer']->installs === 3, 'Resume finishes the remaining items.');
check(array_values(array_unique($f['installer']->refs)) === ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], 'Use reviewed commit hashes despite newer branch commits.');
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

foreach (['missing', 'token', 'access', 'host', 'owner'] as $failure) {
    $f = bundleFixture([bundleEntry('denied')]);
    switch ($failure) {
        case 'missing': $f['settings']->connectors = []; break;
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
(new RepositoryManager($f['settings'], $f['installer']))->register('plugin', 'removed', ['connector' => 'bundle-github', 'updates' => 'commits']);
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

// Bundle policy tracks the listed branch and freezes the reviewed commit.
$masterEntry = bundleEntry('master-repo');
$masterEntry['branch'] = 'master';
$f = bundleFixture([$masterEntry]);
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'ready' && end($f['connector']->calls) === ['commits', 'master'], 'Resolve commits from the manifest branch, including master.');
$f['connector']->commit = 'cccccccccccccccccccccccccccccccccccccccc';
bundleAction($f, 'install'); bundleDrain($f);
check($f['settings']->plugins[0]->updates === 'commits' && $f['settings']->plugins[0]->branch === 'master', 'Future updates retain commit mode and the selected branch.');
check($f['settings']->plugins[0]->localVersion === 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Persist the installed preflight commit, not the new branch head.');
$f = bundleFixture([bundleEntry('no-commit')]);
$f['connector']->commit = '';
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'blocked' && $f['installer']->installs === 0, 'Missing commit blocks installation without falling back to tags.');
$missingBranch = bundleEntry('no-branch');
$missingBranch['branch'] = 'missing';
$f = bundleFixture([$missingBranch]);
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'blocked', 'Missing configured branch blocks preflight.');

// Explicit selections distinguish otherwise identical connectors and remain
// attached to the job through checks, execution, reloads and retries.
class TwoProviderCatalogFixture extends BundleCatalogFixture {
    public function get(): array {
        $catalog = parent::get();
        $catalog['connectors']['gitlab'] = ['type' => 'gitlab', 'host' => 'gitlab.rrze.fau.de', 'owner' => 'rrze-webteam'];
        return $catalog;
    }
}
$f = bundleFixture([bundleEntry('github-repo'), bundleEntry('gitlab-repo')]);
$entries = $f['catalog']->items;
$entries[1]['provider'] = 'gitlab';
$f['catalog'] = new TwoProviderCatalogFixture($entries);
$chosenGithub = clone $f['connector'];
$chosenGithub->id = 'chosen-github';
$f['connector']->token = ''; // The unselected connector must not be used.
$chosenGitlab = new class extends BundleConnectorFixture {
    public function getType(): string { return 'gitlab'; }
    public function getUrl(string $repository): string { return 'https://gitlab.rrze.fau.de/rrze-webteam/' . $repository; }
};
$chosenGitlab->id = 'chosen-gitlab';
$chosenGitlab->owner = 'rrze-webteam';
$chosenGitlab->token = 'gitlab-secret';
$f['settings']->connectors[] = $chosenGithub;
$f['settings']->connectors[] = $chosenGitlab;
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
$choices = $f['manager']->connectorChoices();
check(count($choices['github']) === 2 && count($choices['gitlab']) === 1, 'Offer multiple compatible connectors separately by provider.');
check(!$choices['github'][0]['has_token'] && $choices['github'][1]['has_token'], 'Expose credential presence without exposing credentials.');
check(!str_contains(json_encode($choices), 'bundle-secret-token') && !str_contains(json_encode($choices), 'gitlab-secret'), 'Selector metadata excludes tokens.');
check(is_wp_error($f['manager']->handle('check')), 'Selections are required before a job starts.');
check(is_wp_error($f['manager']->handle('check', '', -1, ['github' => 'chosen-github'])), 'Require a GitLab selection as well.');
check(is_wp_error($f['manager']->handle('check', '', -1, ['github' => [], 'gitlab' => 'chosen-gitlab'])), 'Reject malformed selection values.');
check($f['store']->job === null, 'Invalid selections do not create a job.');
$selection = ['github' => 'chosen-github', 'gitlab' => 'chosen-gitlab'];
$result = $f['manager']->handle('check', '', -1, $selection + ['unrelated' => 'discard']);
check(!is_wp_error($result) && $result['job']['connectors'] === $selection, 'Store only required provider selections.');
$job = bundleDrain($f);
check($job['phase'] === 'ready', 'Explicit choice removes duplicate-connector ambiguity.');
check($job['items']['plugin/github-repo']['options']['connector'] === 'chosen-github'
    && $job['items']['plugin/gitlab-repo']['options']['connector'] === 'chosen-gitlab', 'Use the chosen connector for each provider.');
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
check($f['manager']->handle('status')['job']['connectors'] === $selection, 'Selections survive reopening.');
// Supplying alternative IDs at installation must not change the reviewed job.
$f['connector']->token = 'working-but-unselected';
$chosenGithub->token = '';
$result = $f['manager']->handle('install', $job['id'], $job['revision'], ['github' => 'bundle-github', 'gitlab' => 'chosen-gitlab']);
check(!is_wp_error($result), 'Start reviewed job.');
$job = bundleDrain($f);
check($job['items']['plugin/github-repo']['status'] === 'failed', 'Never switch to another connector when the selected token becomes unavailable.');
check($job['items']['plugin/gitlab-repo']['status'] === 'done', 'Independent selected provider still succeeds.');
$chosenGithub->token = 'repaired';
bundleAction($f, 'retry'); $job = bundleDrain($f);
check($job['connectors'] === $selection && $job['items']['plugin/github-repo']['status'] === 'done', 'Installation retry keeps the selected IDs.');
// Failed preflight retries likewise retain their selections.
$result = $f['manager']->handle('check', $job['id'], $job['revision'], $selection);
$chosenGitlab->token = '';
$job = bundleDrain($f);
check($job['phase'] === 'blocked', 'Selected missing token is a prerequisite failure.');
$chosenGitlab->token = 'repaired';
bundleAction($f, 'retry'); $job = bundleDrain($f);
check($job['phase'] === 'ready' && $job['connectors'] === $selection, 'Preflight retry keeps the selected IDs.');
$chosenGitlab->owner = 'different-group';
bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['plugin/gitlab-repo']['status'] === 'failed', 'Revalidate the selected connector’s owner before execution.');
check($f['manager']->connectorChoices()['gitlab'] === [], 'Do not offer incompatible owners in the selector.');
unset($f['store']->job['connectors']);
$job = $f['store']->job;
check(is_wp_error($f['manager']->handle('retry', $job['id'], $job['revision'])), 'Older jobs require explicit selections and fresh preflight.');

// GitHub organization names are case-insensitive in selection and execution.
foreach (['rrze-webteam', 'RRZE-WEBTEAM', 'RrZe-WeBtEaM'] as $owner) {
    $f = bundleFixture([bundleEntry('case-matching')]);
    $f['connector']->owner = $owner;
    check(count($f['manager']->connectorChoices()['github']) === 1, 'Offer GitHub connectors regardless of owner capitalization.');
    bundleAction($f, 'check'); $job = bundleDrain($f);
    check($job['phase'] === 'ready', 'GitHub owner casing also passes server-side preflight.');
    bundleAction($f, 'install'); $job = bundleDrain($f);
    check($job['items']['plugin/case-matching']['status'] === 'done', 'GitHub owner casing also passes installation validation.');
    check($f['connector']->owner === $owner, 'Matching does not rewrite stored connector settings.');
}
$f = bundleFixture([bundleEntry('host-case')]);
$hostConnector = new class extends BundleConnectorFixture {
    public function getUrl(string $repository): string { return 'https://GitHub.COM/RRZE-Webteam/' . $repository; }
};
$hostConnector->id = 'bundle-github';
$hostConnector->owner = 'rrze-webteam';
$hostConnector->token = 'fixture-token';
$f['settings']->connectors = [$hostConnector];
check(count($f['manager']->connectorChoices()['github']) === 1, 'Host capitalization does not exclude a matching connector.');
$hostConnector->owner = 'different-owner';
check($f['manager']->connectorChoices()['github'] === [], 'Different GitHub owners remain excluded.');

// Partial installation is explicit and never queues prerequisite failures.
$f = bundleFixture([bundleEntry('good'), bundleEntry('denied'), bundleEntry('retryable')]);
$f['connector']->denied = ['denied'];
$job = bundleAction($f, 'check');
check(is_wp_error($f['manager']->handle('install_anyways', $job['id'], $job['revision'])), 'Cannot bypass incomplete preflight.');
$job = bundleDrain($f);
$errorMessage = $job['items']['plugin/denied']['message'];
check(is_wp_error($f['manager']->handle('install', $job['id'], $job['revision'])), 'Standard install still requires successful preflight.');
$expired = $job;
$f['store']->job['created_at'] = time() - 86401;
check(is_wp_error($f['manager']->handle('install_anyways', $job['id'], $job['revision'])), 'Partial installation still rejects an expired plan.');
$f['store']->job = $expired;
$f['installer']->failRepositories = ['retryable'];
$job = bundleAction($f, 'install_anyways');
check($job['items']['plugin/denied']['status'] === 'prerequisite_skipped', 'Mark prerequisite failures as distinct skips.');
check($job['items']['plugin/denied']['message'] === $errorMessage, 'Preserve the original prerequisite error.');
$job = bundleAction($f, 'step');
check($f['installer']->installs === 1, 'Partial install still handles one entry per request.');
$f['manager'] = new BundleManager($f['catalog'], $f['store'], $f['settings'], $f['installer']);
$job = bundleDrain($f);
check($job['phase'] === 'complete' && $job['items']['plugin/good']['status'] === 'done', 'Partial installation can resume and complete.');
check(!isset($f['installer']->refs['denied']), 'Never send a prerequisite failure to the installer.');
$f['connector']->denied = [];
$f['installer']->failRepositories = [];
bundleAction($f, 'retry'); $job = bundleDrain($f);
check($job['items']['plugin/retryable']['status'] === 'done', 'Retry execution failures normally.');
check($job['items']['plugin/denied']['status'] === 'prerequisite_skipped' && !isset($f['installer']->refs['denied']), 'Retry cannot revive an unchecked prerequisite.');
bundleAction($f, 'check'); $job = bundleDrain($f);
check($job['phase'] === 'ready', 'Fresh preflight can approve a previously skipped entry.');
bundleAction($f, 'install'); $job = bundleDrain($f);
check($job['items']['plugin/denied']['status'] === 'done' && $f['installer']->installs === 3, 'Process repaired prerequisite without reinstalling successful entries.');

$f = bundleFixture([bundleEntry('child', 'theme'), bundleEntry('parent', 'theme'), bundleEntry('independent')]);
$f['connector']->parents['child'] = 'parent';
$f['connector']->denied = ['parent'];
bundleAction($f, 'check'); bundleDrain($f); $job = bundleAction($f, 'install_anyways');
check($job['items']['theme/parent']['status'] === 'prerequisite_skipped'
    && $job['items']['theme/child']['status'] === 'prerequisite_skipped', 'Skip dependents of failed prerequisites.');
$job = bundleDrain($f);
check($job['items']['plugin/independent']['status'] === 'done' && $f['installer']->installs === 1, 'Only independent passing entries reach installation.');
check(!isset($f['installer']->refs['child']), 'A prerequisite skip is never considered a successful parent.');

$f = bundleFixture([bundleEntry('cycle-a', 'theme'), bundleEntry('cycle-b', 'theme'), bundleEntry('valid')]);
$f['connector']->parents = ['cycle-a' => 'cycle-b', 'cycle-b' => 'cycle-a'];
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install_anyways'); $job = bundleDrain($f);
check($job['items']['plugin/valid']['status'] === 'done' && $f['installer']->installs === 1, 'Skip dependency cycles while processing independent entries.');
$f = bundleFixture([bundleEntry('only-error')]);
$f['connector']->denied = ['only-error'];
bundleAction($f, 'check'); $job = bundleDrain($f);
$result = $f['manager']->handle('install_anyways', $job['id'], $job['revision']);
check(is_wp_error($result) && $f['store']->job === $job && $f['installer']->installs === 0, 'No runnable entries leaves the blocked job unchanged.');

// Cancellation preserves completed work and permanently stops the remaining queue.
$f = bundleFixture([bundleEntry('finished'), bundleEntry('remaining')]);
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install');
$beforeStep = $f['store']->load();
$afterStep = bundleAction($f, 'step');
$completedItem = $afterStep['items']['plugin/finished'];
$result = $f['manager']->handle('cancel', $beforeStep['id'], $beforeStep['revision']);
check(is_wp_error($result) && $f['store']->load() === $afterStep, 'Stale cancellation must reconcile the in-flight result first.');
$f['store']->locked = true;
check(is_wp_error($f['manager']->handle('cancel', $afterStep['id'], $afterStep['revision']))
    && $f['store']->load() === $afterStep, 'Cancellation cannot race an active installation holding the lock.');
$f['store']->locked = false;
$cancelled = bundleAction($f, 'cancel');
check($cancelled['phase'] === 'cancelled' && isset($cancelled['cancelled_at']), 'Save a terminal cancelled phase.');
check($cancelled['items']['plugin/finished'] === $completedItem && $f['installer']->installs === 1, 'Keep completed installations and their results unchanged.');
check($cancelled['items']['plugin/remaining']['status'] === 'cancelled', 'Mark remaining entries cancelled.');
foreach (['step', 'install', 'install_anyways', 'retry'] as $action) {
    check(is_wp_error($f['manager']->handle($action, $cancelled['id'], $cancelled['revision']))
        && $f['store']->load() === $cancelled && $f['installer']->installs === 1,
        "Cancelled process cannot restart through $action.");
}
check(bundleAction($f, 'cancel') === $cancelled, 'Repeated cancellation is idempotent.');
check($f['manager']->handle('status')['job'] === $cancelled, 'Reloading retains cancellation.');
bundleAction($f, 'check'); $reviewed = bundleDrain($f);
check($reviewed['phase'] === 'ready' && $reviewed['id'] !== $cancelled['id'], 'A fresh review can follow cancellation.');
check(is_wp_error($f['manager']->handle('cancel', $cancelled['id'], $reviewed['revision']))
    && $f['store']->load() === $reviewed, 'An old cancellation cannot cancel a replacement job.');
bundleAction($f, 'install'); $complete = bundleDrain($f);
check($f['installer']->installs === 2 && $complete['items']['plugin/finished']['status'] === 'skipped', 'Fresh job recognizes completed work and only installs the remainder.');
check(bundleAction($f, 'cancel') === $complete, 'Cancellation arriving after completion preserves the completed result.');

$f = bundleFixture([bundleEntry('first'), bundleEntry('second')]);
bundleAction($f, 'check'); bundleAction($f, 'step');
$cancelled = bundleAction($f, 'cancel');
check($cancelled['phase'] === 'cancelled' && $f['installer']->installs === 0
    && count(array_filter($cancelled['items'], fn($item) => $item['status'] === 'cancelled')) === 2,
    'Checks can be cancelled before installation.');
$f = bundleFixture([bundleEntry('interrupted'), bundleEntry('remaining')]);
bundleAction($f, 'check'); bundleDrain($f); bundleAction($f, 'install');
$f['store']->job['items']['plugin/interrupted']['status'] = 'installing';
$f['store']->job['catalog'] = 'outdated-catalog';
unset($f['store']->job['connectors']);
$f['settings']->connectors = [];
$cancelled = bundleAction($f, 'cancel');
check($cancelled['items']['plugin/interrupted']['status'] === 'interrupted'
    && str_contains($cancelled['items']['plugin/interrupted']['message'], 'Inspect existing files'), 'An unknown filesystem outcome remains visible after cancellation.');
check($cancelled['phase'] === 'cancelled', 'Cancellation remains available after catalog or connector changes.');
$f = bundleFixture([]);
check(is_wp_error($f['manager']->handle('cancel')), 'Cancellation without a saved job is rejected.');

echo 'Passed ' . ($checks - $beforeBundleChecks) . " bundle checks.\n";

// Exercise the real persistence/lock adapter and the AJAX authorization boundary.
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
$wpdb = new SettingsDatabaseFixture();
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
$_POST['connectors'] = 'not-an-array';
$admin->request();
check($bundle_response['status'] === 400, 'AJAX rejects malformed connector maps.');
$_POST['connectors'] = ['github' => ['nested']];
$admin->request();
check($bundle_response['status'] === 400, 'AJAX rejects nested connector IDs.');
$_POST['connectors'] = [];
$bundle_mods = false;
$_POST['operation'] = 'check';
$before = $wpdb->acquisitions;
$admin->request();
check($bundle_response['status'] === 403 && $wpdb->acquisitions === $before, 'Respect disabled file modifications before creating a job.');
unset($bundle_caps['install_themes']);
$admin->request();
check($bundle_response['status'] === 403, 'Require theme installation permission as well as plugin permission.');
$bundle_caps['install_themes'] = true;
$bundle_mods = false;
$bundle_network = 2;
$realStore->save(['id' => 'cancel-with-mods-disabled', 'revision' => 1, 'phase' => 'running',
    'items' => ['one' => ['status' => 'queued', 'message' => '']]]);
$_POST = ['operation' => 'cancel', 'network' => 2, 'nonce' => 'rrze_updater_bundle_2',
    'job' => 'cancel-with-mods-disabled', 'revision' => 1];
$admin->request();
check($bundle_response['success'] && $bundle_response['data']['job']['phase'] === 'cancelled',
    'Authorized cancellation remains available when file modifications are disabled.');

echo 'Passed ' . ($checks - $beforeBoundaryChecks) . " network/authorization/lock checks.\n";

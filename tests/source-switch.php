<?php
// Read-only provider fixtures; no production WordPress options or files are used.
require_once __DIR__ . '/bundles.php';
require_once __DIR__ . '/update-outcomes.php';

use RRZE\Updater\{Config, FilesystemLock, ManagedUpgrader, Settings};
use RRZE\Updater\Core\{GithubConnector, GitlabConnector, Plugin, RepositoryManager, RepositorySource};

/** Supply deterministic remote refs for both supported provider implementations. */
trait SourceSwitchRemoteFixture
{
    public bool $deny = false;
    public bool $hasTag = true;
    public $duringCheck = null;

    /** Report whether the configured test branch exists. */
    public function remoteBranchExists(string $repository, string $branch): bool
    {
        if ($this->duringCheck) {
            ($this->duringCheck)();
            $this->duringCheck = null;
        }
        return $branch === 'main';
    }

    /** Return branch choices without issuing HTTP requests. */
    public function getRemoteBranches(string $repository): array|false { return ['main']; }

    /** Resolve the deterministic branch commit. */
    public function getRemoteCommit(string $repository, string $branch = 'main'): string|bool { return str_repeat('a', 40); }

    /** Simulate an accessible tag, denied request, or missing target version. */
    public function getRemoteTag(string $repository): string|bool
    {
        if ($this->deny) {
            $this->error = 'Access denied.';
            return false;
        }
        return $this->hasTag ? 'v1.2' : false;
    }

    /** Resolve published releases independently of branch commits. */
    public function getRemoteRelease(string $repository): string|false { return $this->getRemoteTag($repository); }

    /** Supply normal plugin and readme headers at every valid fixture ref. */
    public function getRemoteFile(string $repository, string $ref, string $file): string|bool
    {
        return str_ends_with($file, '.php') ? "<?php\n/* Plugin Name: Fixture\nVersion: 1.2\n*/"
            : (str_starts_with(strtolower($file), 'readme') ? "Stable tag: 1.2\n" : false);
    }
}

/** Exercise the GitHub source identity with deterministic remote requests. */
class SourceSwitchGithubFixture extends GithubConnector { use SourceSwitchRemoteFixture; }

/** Exercise the GitLab source identity with deterministic remote requests. */
class SourceSwitchGitlabFixture extends GitlabConnector { use SourceSwitchRemoteFixture; }

/**
 * Seed a managed installation and an alternative provider with the same tag.
 *
 * @param string $provider Initial provider name.
 * @return array Settings, manager, original plugin, new connector and installer.
 */
function sourceSwitchFixture(string $provider = 'github'): array
{
    FilesystemLock::releaseAll();
    $GLOBALS['storage'] = [];
    $GLOBALS['fail_save'] = false;
    $GLOBALS['wpdb']->deny = false;
    $GLOBALS['invalidated'] = [];
    $github = new SourceSwitchGithubFixture();
    $github->id = 'source-github'; $github->owner = 'owner'; $github->token = 'fixture-token';
    $github->display = 'GitHub';
    $gitlab = new SourceSwitchGitlabFixture();
    $gitlab->id = 'source-gitlab'; $gitlab->owner = 'owner'; $gitlab->token = 'fixture-token';
    $gitlab->display = 'GitLab'; $gitlab->host = 'gitlab.example.org'; $gitlab->apiUri = '/api/v4/projects/';
    $original = $provider === 'github' ? $github : $gitlab;
    $target = $provider === 'github' ? $gitlab : $github;
    $plugin = Plugin::createFromArray(['id' => 'source-plugin', 'connectorId' => $original->id,
        'repository' => 'package', 'installationFolder' => 'package', 'branch' => 'main',
        'updates' => 'tags', 'localVersion' => 'v1.2', 'remoteVersion' => 'v1.2', 'lastChecked' => 123]);
    $plugin->connector = $original;
    $settings = new Settings();
    $settings->connectors = [$github, $gitlab];
    $settings->plugins = [$plugin];
    $settings->themes = [];
    check($settings->save(), 'Seed source-switch fixture.');
    $installer = new InstallerFixture();
    $installer->installed['plugin/package'] = true;
    return [$settings, new RepositoryManager($settings, $installer), $plugin, $target, $installer];
}

$beforeSourceSwitch = $checks;
$savedSourceMultisite = $multisite;
foreach ([false, true] as $multisite) {
    foreach (['github', 'gitlab'] as $provider) {
        [$settings, $manager, $original, $target, $installer] = sourceSwitchFixture($provider);
        $result = $manager->changePluginSource($original->id, 'renamed-project', [
            'connector' => $target->id, 'branch' => 'main', 'updates' => 'tags', 'folder' => 'forged-folder',
        ]);
        $fresh = (new Settings())->plugins[0];
        check(is_string($result) && $fresh->connectorId === $target->id && $fresh->repository === 'renamed-project',
            'Both provider directions save the checked new repository.');
        check($fresh->id === $original->id && $fresh->installationFolder === 'package' && $installer->installs === 0,
            'Changing a source keeps identity and destination immutable and does not install files.');
        check($fresh->localVersion === '' && $fresh->remoteVersion === 'v1.2' && $fresh->remoteReadableVersion === '1.2',
            'An identical tag in another source remains an offered update with freshly checked metadata.');
        check(in_array('update_plugins', $invalidated, true), 'A successful source change invalidates the WordPress update cache.');

        foreach (['denied', 'missing-branch', 'missing-ref', 'invalid-policy', 'invalid-name', 'unknown-connector', 'duplicate', 'database', 'lock'] as $failure) {
            [$settings, $manager, $original, $target, $installer] = sourceSwitchFixture($provider);
            $repository = 'renamed-project';
            $options = ['connector' => $target->id, 'branch' => 'main', 'updates' => 'tags'];
            if ($failure === 'denied') $target->deny = true;
            if ($failure === 'missing-branch') $options['branch'] = 'missing';
            if ($failure === 'missing-ref') $target->hasTag = false;
            if ($failure === 'invalid-policy') $options['updates'] = ['tags'];
            if ($failure === 'invalid-name') $repository = '../escape';
            if ($failure === 'unknown-connector') $options['connector'] = 'unknown';
            if ($failure === 'duplicate') {
                $duplicate = clone $original;
                $duplicate->id = 'another-plugin'; $duplicate->connectorId = $target->id;
                $duplicate->connector = $target;
                $duplicate->repository = 'renamed-project'; $duplicate->installationFolder = 'another-folder';
                $settings->plugins[] = $duplicate;
                check($settings->save(), 'Seed a conflicting source association.');
            }
            $before = $storage;
            $fail_save = $failure === 'database';
            $wpdb->deny = $failure === 'lock';
            $result = $manager->changePluginSource($original->id, $repository, $options);
            $fail_save = $wpdb->deny = false;
            check(is_wp_error($result) && $storage === $before && $settings->asArray() === (new Settings())->asArray(),
                "$provider/$failure keeps the persisted source and shared settings intact.");
            check($installer->installs === 0 && $invalidated === [], 'Rejected source changes neither install nor invalidate update metadata.');
        }
    }

    foreach (['branch', 'repository', 'policy', 'connector', 'owner', 'host', 'api'] as $change) {
        [$settings] = sourceSwitchFixture('gitlab');
        $stale = new Settings();
        $fresh = new Settings();
        match ($change) {
            'branch' => $fresh->plugins[0]->branch = 'release',
            'repository' => $fresh->plugins[0]->repository = 'renamed',
            'policy' => $fresh->plugins[0]->updates = 'releases',
            'connector' => $fresh->plugins[0]->connectorId = 'source-github',
            'owner' => $fresh->connectors[1]->owner = 'another-owner',
            'host' => $fresh->connectors[1]->host = 'another.example.org',
            'api' => $fresh->connectors[1]->apiUri = '/different/api/',
        };
        check($fresh->save(), 'Persist effective source change: ' . $change);
        $before = $storage;
        $stale->plugins[0]->localVersion = 'old-installed-ref';
        $stale->plugins[0]->remoteVersion = 'old-remote-ref';
        $stale->plugins[0]->lastChecked = 999;
        check(!$stale->save() && $storage === $before, 'Late metadata cannot be attached to a changed ' . $change . '.');
        $fresh = new Settings();
        check($fresh->plugins[0]->localVersion === '' && $fresh->plugins[0]->remoteVersion === '',
            'Unvalidated source changes invalidate both installed and remote refs.');
    }
    [$settings] = sourceSwitchFixture();
    $stale = new Settings(); $fresh = new Settings();
    $identity = RepositorySource::fingerprint($fresh->plugins[0]);
    $fresh->connectors[0]->token = 'rotated-token'; $fresh->connectors[0]->display = 'Renamed service';
    check($fresh->save(), 'Credentials and display labels can change independently.');
    $stale->plugins[0]->lastChecked = 999;
    check($stale->save() && RepositorySource::fingerprint((new Settings())->plugins[0]) === $identity,
        'Credential rotation preserves source identity and allows concurrent check results.');

    [$settings, $manager, $original] = sourceSwitchFixture();
    check(is_string($manager->changePluginSource($original->id, 'package', [
        'connector' => $original->connectorId, 'branch' => 'main', 'updates' => 'tags',
    ])) && (new Settings())->plugins[0]->localVersion === 'v1.2',
        'Saving the same validated source preserves the known installed ref.');

    [$settings, $manager, $original, $target] = sourceSwitchFixture();
    $target->duringCheck = static function (): void {
        $concurrent = new Settings();
        $concurrent->plugins[0]->repository = 'concurrently-changed';
        check($concurrent->save(), 'A concurrent editor changes the source while validation is running.');
    };
    check(is_wp_error($manager->changePluginSource($original->id, 'new-project', [
        'connector' => $target->id, 'branch' => 'main', 'updates' => 'tags',
    ])) && $settings->plugins[0]->repository === 'concurrently-changed',
        'A source changed during HTTP validation is not overwritten and is reloaded in place.');
}

// Check immutable package snapshots at both file selection and metadata commit.
$multisite = true;
foreach (['branch', 'owner', 'removed'] as $change) {
    FilesystemLock::releaseAll();
    $f = outcomeFixture('plugin', false);
    // outcomeFixture already selected a verified package once. A new download
    // captures the same source before another request changes its association.
    $extension = $f['extension'];
    $managed = $f['managed'];
    $download = $managed->upgraderPreDownloadFilter(false,
        $extension->connector->downloadRepoZip('package', 'v2'), $f['upgrader'], $f['extra']);
    $current = new Settings();
    if ($change === 'branch') $current->plugins[0]->branch = 'changed';
    elseif ($change === 'owner') $current->connectors[0]->owner = 'changed-owner';
    else $current->plugins = [];
    check($current->save(), 'Simulate a source change after downloading.');
    $beforeMoves = count($wp_filesystem->moves);
    $result = $managed->upgraderSourceSelectionFilter('/tmp/old-source/', '/tmp/work/', $f['upgrader'], $f['extra']);
    check(is_wp_error($result) && count($wp_filesystem->moves) === $beforeMoves,
        'A stale downloaded package is rejected before any folder move: ' . $change);
    wp_delete_file($download);
    $managed->releaseFilesystemLock();
}

FilesystemLock::releaseAll();
$f = outcomeFixture('plugin', false);
$managed = $f['managed'];
$managed->upgraderPostInstallFilter(true, $f['extra'], ['destination_name' => 'package']);
$changed = new Settings(); $changed->connectors[0]->owner = 'changed-after-files';
check($changed->save(), 'Simulate an external source edit before final metadata persistence.');
$before = $storage;
$result = apply_filters('upgrader_install_package_result', ['destination_name' => 'package'], $f['extra']);
check(is_wp_error($result) && $storage === $before, 'Final update result cannot attach the old installed ref to a new source.');
$managed->releaseFilesystemLock();

// Existing activation entrypoints cannot disappear or lose their plugin header.
foreach ([false, '<?php // no plugin header'] as $entrypointContents) {
    FilesystemLock::releaseAll();
    $f = outcomeFixture('plugin', false);
    $oldFilesystem = $wp_filesystem;
    $wp_filesystem = new class($entrypointContents) {
        public int $moves = 0;
        /** Store the extracted entrypoint content for this fixture. */
        public function __construct(private string|false $contents) {}
        /** Simulate a missing file or a PHP file that is no longer a plugin. */
        public function get_contents($path) { return $this->contents; }
        /** Track a forbidden move after a failed entrypoint check. */
        public function move($source, $destination, $overwrite) { $this->moves++; return true; }
    };
    $extension = $f['extension'];
    $file = $f['managed']->upgraderPreDownloadFilter(false,
        $extension->connector->downloadRepoZip('package', 'v2'), $f['upgrader'], $f['extra']);
    $result = $f['managed']->upgraderSourceSelectionFilter('/tmp/extracted/', '/tmp/work/', $f['upgrader'], $f['extra']);
    check(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_missing_entrypoint'
        && $wp_filesystem->moves === 0, 'Missing entrypoint or header blocks replacement before files move.');
    wp_delete_file($file);
    $f['managed']->releaseFilesystemLock();
    $wp_filesystem = $oldFilesystem;
}
FilesystemLock::releaseAll();
$f = outcomeFixture('plugin', false);
$heldDuringRollback = false;
/** Simulate core's deferred restore while checking that no other writer can enter. */
$rollback = static function () use (&$heldDuringRollback): void {
    $heldDuringRollback = $GLOBALS['wpdb']->locked;
};
add_action('shutdown', $rollback, 10, 0);
check($wpdb->locked, 'A selected managed package retains its filesystem lock until request shutdown.');
do_action('shutdown');
remove_action('shutdown', $rollback, 10);
check($heldDuringRollback && !$wpdb->locked, 'The shared lock covers core rollback and is released afterwards.');

FilesystemLock::releaseAll();
$multisite = $savedSourceMultisite;

// Filtering future targets must not erase knowledge of already installed files.
[$settings, $manager, $original] = sourceSwitchFixture();
check(is_string($manager->changePluginSource($original->id, 'package', [
    'connector' => $original->connectorId, 'branch' => 'main', 'updates' => 'tags', 'ignore-prereleases' => true,
])), 'Save a validated prerelease policy change.');
$filtered = (new Settings())->plugins[0];
check($filtered->ignorePrereleases && $filtered->localVersion === 'v1.2' && $filtered->remoteVersion === 'v1.2',
    'A checked filter change preserves the installed ref and the fresh target.');
$staleFilterSettings = new Settings();
$freshFilterSettings = new Settings();
$freshFilterSettings->plugins[0]->ignorePrereleases = false;
check($freshFilterSettings->save(), 'Persist an unchecked policy change.');
$filtered = (new Settings())->plugins[0];
check($filtered->localVersion === 'v1.2' && $filtered->remoteVersion === '' && $filtered->remoteRelease === [],
    'An unchecked filter change invalidates the target and preview, but keeps the installed ref.');
$staleFilterSettings->plugins[0]->remoteVersion = 'v9';
check(!$staleFilterSettings->save(), 'A check using the previous prerelease policy cannot publish its stale result.');
echo 'Passed ' . ($checks - $beforeSourceSwitch) . " source migration and stale package checks.\n";

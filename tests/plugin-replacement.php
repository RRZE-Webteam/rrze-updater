<?php
/** Standalone replacement transaction tests using real temporary directories and ZIP packages. */
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    exit(1);
}
define('ABSPATH', dirname(__DIR__, 4) . '/');
define('DB_NAME', 'replacement-fixture');
define('HOUR_IN_SECONDS', 3600);
$replacementRoot = sys_get_temp_dir() . '/rrze-updater-replacement-' . bin2hex(random_bytes(8));
define('WP_CONTENT_DIR', $replacementRoot);
define('WP_PLUGIN_DIR', $replacementRoot . '/plugins');
mkdir(WP_PLUGIN_DIR, 0700, true);
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/plugin.php';
/** Autoload production plugin classes for the standalone fixture. */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'RRZE\\Updater\\')) {
        require dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, 13)) . '.php';
    }
});
/** Return untranslated fixture strings. */
function __($text, $domain = '') { return $text; }
/** Sanitize fixture identifiers the same way as simple WordPress text fields. */
function sanitize_text_field($value) { return trim((string) $value); }
/** Identify WordPress errors. */
function is_wp_error($result) { return $result instanceof WP_Error; }
/** Return the fixture's tenancy mode. */
function is_multisite() { return $GLOBALS['replacementMultisite']; }
/** Return the current fixture network. */
function get_current_network_id() { return $GLOBALS['replacementNetwork']; }
/** Return the current fixture site. */
function get_current_blog_id() { return $GLOBALS['replacementSite']; }
/** Return the current fixture administrator. */
function get_current_user_id() { return $GLOBALS['replacementUser']; }
/** Return the fixture's requested permission. */
function current_user_can($capability) { return $GLOBALS['replacementCaps'][$capability] ?? false; }
/** Return the fixture superadministrator flag. */
function is_super_admin() { return $GLOBALS['replacementSuper']; }
/** Return the fixture file modification policy. */
function wp_is_file_mod_allowed($context) { return $GLOBALS['replacementMods']; }
/** Normalize a trailing path separator. */
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
/** Remove trailing path separators. */
function untrailingslashit($path) { return rtrim($path, '/\\'); }
/** Delete a temporary downloaded archive. */
function wp_delete_file($path) { unlink($path); }
/** Record cache invalidation without a cache backend. */
function wp_cache_delete($key, $group = '') { return true; }
/** Record update cache invalidation without modifying activation options. */
function delete_site_transient($key) {
    if ($GLOBALS['replacementCacheThrows'] ?? false) {
        $GLOBALS['replacementCacheFailureSeen'] = true;
        throw new RuntimeException('secret-cache-hook');
    }
    return true;
}
/** Remove fixture trees without following symlinks. */
function replacementRemove(string $path): bool {
    if (!file_exists($path) && !is_link($path)) return true;
    if (!is_dir($path) || is_link($path)) return unlink($path);
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') replacementRemove($path . '/' . $name);
    return rmdir($path);
}
/** Assert a behavior and count successful checks. */
function replacementCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['replacementChecks']++;
}
/** Require a stable structured error code. */
function replacementError(mixed $result, string $code): void {
    replacementCheck(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_' . $code, $code . ': ' . (is_wp_error($result) ? $result->get_error_message() : 'unexpected success'));
}
/** Database lock fixture exercising the real shared lock wrapper. */
class ReplacementDatabaseFixture {
    public string $base_prefix = 'wp_';
    public bool $busy = false;
    /** Substitute the safe lock name. */
    public function prepare($query, $value) { return str_replace('%s', $value, $query); }
    /** Reject simulated parallel filesystem operations. */
    public function get_var($query) { return str_contains($query, 'RELEASE_LOCK') || !$this->busy ? '1' : '0'; }
}
$wpdb = new ReplacementDatabaseFixture();
$replacementMultisite = $replacementSuper = false;
$replacementNetwork = $replacementSite = $replacementUser = 1;
$replacementMods = true;
$replacementCaps = array_fill_keys(['install_plugins', 'update_plugins', 'manage_options', 'manage_network_options'], true);
$replacementChecks = 0;
$wp_version = '6.8';

use RRZE\Updater\Settings;
use RRZE\Updater\Core\{GithubConnector, Plugin, PluginReplacement, RepositoryManager};
use RRZE\Updater\Upgrader\PluginPackageReplacement;

/** Keep real service behavior isolated from external APIs and the database. */
class ReplacementSettingsFixture extends Settings {
    public bool $failSave = false;
    public bool $throwSave = false;
    public bool $throwAfterSave = false;
    public ?Closure $afterSave = null;
    public array $persisted = [];
    /** Initialize only the registry required by replacement. */
    public function __construct() { $this->plugins = $this->themes = $this->connectors = []; $this->options = []; }
    /** Reload stored associations while retaining the test connector transport. */
    public function reload(): void { $this->plugins = array_map(static fn($plugin) => clone $plugin, $this->persisted); }
    /** Persist current associations unless an injected database failure occurs. */
    public function save() {
        if ($this->throwSave) throw new RuntimeException('secret-database-detail');
        if ($this->failSave) return false;
        $this->persisted = array_map(static fn($plugin) => clone $plugin, $this->plugins);
        if ($this->afterSave) ($this->afterSave)();
        if ($this->throwAfterSave) throw new RuntimeException('secret-after-save-hook');
        return true;
    }
}
/** Pinned provider fixture copying a real ZIP rather than using HTTP. */
class ReplacementConnectorFixture extends GithubConnector {
    public string $archive = '';
    public string $downloadedCommit = '';
    public bool $downloadFails = false;
    public bool $downloadThrows = false;
    /** Resolve any selected policy ref to an immutable commit. */
    public function getRemoteCommit(string $repository, string $branch = 'main'): string|bool { return str_repeat('a', 40); }
    /** Copy the prepared ZIP to the temporary download consumed by production code. */
    public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool {
        $this->downloadedCommit = $branch;
        if ($this->downloadThrows) throw new RuntimeException('never-store-secret');
        if ($this->downloadFails) return false;
        $temporary = tempnam(WP_CONTENT_DIR, 'download-');
        copy($this->archive, $temporary);
        return $temporary;
    }
}
/** Isolate remote definition lookup; source validation has its own integration suite. */
class ReplacementManagerFixture extends RepositoryManager {
    /** Inject a fixture connector. */
    public function __construct(private ReplacementConnectorFixture $connector) {}
    /** Produce a policy ref while leaving all local-state checks to the real service. */
    public function preparePluginDefinition(string $repository, array $options): Plugin|WP_Error {
        $plugin = Plugin::createFromArray(['repository' => $repository, 'installationFolder' => $options['folder'],
            'connectorId' => $options['connector'], 'branch' => $options['branch'], 'updates' => $options['updates'],
            'remoteVersion' => 'v2', 'remoteReadableVersion' => '2.0']);
        $plugin->connector = $this->connector;
        return $plugin;
    }
}
/** Real filesystem operations with deterministic write and recovery failures. */
class ReplacementFilesystemFixture {
    public string $failure = '';
    /** Keep the maintenance file inside the disposable fixture root. */
    public function abspath() { return trailingslashit(WP_CONTENT_DIR); }
    /** Report directory existence. */
    public function is_dir($path) { return is_dir($path); }
    /** Report files or links. */
    public function exists($path) { return file_exists($path) || is_link($path); }
    /** Create a fixture directory with production permissions. */
    public function mkdir($path, $mode = 0755) { return mkdir($path, $mode, true); }
    /** Write fixture control files. */
    public function put_contents($path, $contents, $mode) { return file_put_contents($path, $contents) !== false; }
    /** Recursively remove only the requested fixture tree. */
    public function delete($path, $recursive = false) { return replacementRemove($path); }
    /** Rename directories, optionally failing before swap or during recovery. */
    public function move($source, $destination, $overwrite) {
        if (str_ends_with($destination, '/previous') && $this->failure === 'backup') return false;
        if (str_contains($source, '/package/') && in_array($this->failure, ['install', 'recovery'], true)) return false;
        if (str_ends_with($source, '/previous') && $this->failure === 'recovery') return false;
        return rename($source, $destination);
    }
}
/** Exercise all production checks and swaps while substituting only WordPress initialization. */
class ReplacementPackagesFixture extends PluginPackageReplacement {
    /** Inject the controllable local adapter. */
    public function __construct(public ReplacementFilesystemFixture $adapter) {}
    /** Use the real temp-directory adapter without loading a WordPress installation. */
    protected function filesystem(): object { return $this->adapter; }
    /** Extract the previously validated ZIP using PHP in the standalone process. */
    protected function extract(string $package, string $destination): true|WP_Error {
        mkdir($destination, 0755, true);
        $zip = new ZipArchive(); $zip->open($package);
        $result = $zip->extractTo($destination); $zip->close();
        return $result ? true : new WP_Error('extract_failed', 'Failed');
    }
}
/** Replace only transient storage and clock, preserving actual preparation/application logic. */
class ReplacementServiceFixture extends PluginReplacement {
    public array $reviews = [];
    public int $clock = 1000;
    public bool $failReviewWrite = false;
    public bool $failReviewDelete = false;
    /** Persist credential-free server-side reviews. */
    protected function writeReview(string $id, array $review): bool { if ($this->failReviewWrite) return false; $this->reviews[$id] = $review; return true; }
    /** Read the specified review. */
    protected function readReview(string $id): array|false { return $this->reviews[$id] ?? false; }
    /** Consume the review exactly once. */
    protected function deleteReview(string $id): bool { if ($this->failReviewDelete) return false; unset($this->reviews[$id]); return true; }
    /** Return a deterministic review clock. */
    protected function now(): int { return $this->clock; }
}
/** Build a fresh ZIP with the selected package contents. */
function replacementZip(array $files): string {
    $path = WP_CONTENT_DIR . '/source-' . bin2hex(random_bytes(5)) . '.zip';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    return $path;
}
/** Create an incomplete or recognized directory and all real-service collaborators. */
function replacementFixture(bool $managed = false, bool $recognized = true): array {
    $GLOBALS['replacementCacheThrows'] = $GLOBALS['replacementCacheFailureSeen'] = false;
    replacementRemove(WP_PLUGIN_DIR . '/sample');
    mkdir(WP_PLUGIN_DIR . '/sample');
    file_put_contents(WP_PLUGIN_DIR . '/sample/obsolete.txt', 'old extra file');
    if ($recognized) file_put_contents(WP_PLUGIN_DIR . '/sample/sample.php', "<?php\n/* Plugin Name: Sample\nVersion: 1.0\n*/");
    $connector = new ReplacementConnectorFixture(); $connector->id = 'source'; $connector->owner = 'owner'; $connector->token = 'never-store-secret';
    $connector->archive = replacementZip(['wrapper/sample.php' => "<?php\n/* Plugin Name: Sample\nVersion: 2.0\nRequires PHP: 8.3\nRequires at least: 6.8\n*/", 'wrapper/new.txt' => 'new']);
    $settings = new ReplacementSettingsFixture(); $settings->connectors = [$connector];
    $options = ['connector' => 'source', 'folder' => 'sample', 'branch' => 'main', 'updates' => 'tags'];
    if ($managed) {
        $plugin = Plugin::createFromArray(['id' => 'managed-plugin', 'connectorId' => 'source', 'repository' => 'sample',
            'installationFolder' => 'sample', 'branch' => 'main', 'updates' => 'tags', 'localVersion' => 'v1']);
        $plugin->connector = $connector; $settings->plugins = [$plugin]; $settings->save();
    }
    $adapter = new ReplacementFilesystemFixture(); $packages = new ReplacementPackagesFixture($adapter);
    $service = new ReplacementServiceFixture($settings, new ReplacementManagerFixture($connector), $packages);
    return compact('connector', 'settings', 'options', 'adapter', 'packages', 'service');
}

try {
    foreach ([false, true] as $multi) foreach ([false, true] as $managed) foreach ([false, true] as $recognized) {
        $replacementMultisite = $replacementSuper = $multi;
        $f = replacementFixture($managed, $recognized);
        $before = $f['packages']->snapshot('sample');
        $review = $f['service']->prepare('sample', $f['options'], $managed ? 'managed-plugin' : null);
        replacementCheck(is_array($review) && $review['mode'] === ($managed ? 'reinstall' : 'replace'), 'Prepare returns a usable review in single and multisite modes.');
        replacementCheck($f['packages']->snapshot('sample') === $before, 'Review changes no plugin files.');
        replacementCheck(!str_contains(json_encode($f['service']->reviews), 'never-store-secret'), 'Review storage contains no credentials.');
        replacementCheck(!isset($review['source'], $review['snapshot'], $review['options']), 'Browser receives only display fields.');
        $id = $f['service']->apply($review['id']);
        replacementCheck(is_string($id) && ($managed ? $id === 'managed-plugin' : $id !== ''), 'Apply returns a managed ID after successful commit.');
        replacementCheck(!file_exists(WP_PLUGIN_DIR . '/sample/obsolete.txt') && is_file(WP_PLUGIN_DIR . '/sample/new.txt'), 'Full replacement removes obsolete files.');
        replacementCheck($f['settings']->plugins[0]->localVersion === 'v2' && $f['connector']->downloadedCommit === str_repeat('a', 40), 'Downloads immutable commit while persisting the selected policy ref.');
        replacementCheck(!file_exists(WP_CONTENT_DIR . '/.maintenance'), 'Successful replacement removes its own maintenance window.');
        replacementError($f['service']->apply($review['id']), 'invalid_review');
    }
    $replacementMultisite = $replacementSuper = false;
    foreach (['owner', 'site', 'network', 'expiry', 'cancel', 'permission', 'filemods', 'parallel', 'files', 'source', 'association', 'download', 'save', 'backup', 'install', 'recovery', 'mainfile', 'wp', 'php', 'traversal', 'symlink', 'case', 'case-directory', 'download-exception', 'save-exception', 'corrupt', 'empty-package', 'maintenance', 'consume'] as $scenario) {
        $replacementUser = $replacementSite = $replacementNetwork = 1; $replacementMultisite = $replacementSuper = false;
        $replacementMods = true; $replacementCaps['update_plugins'] = true; $wpdb->busy = false;
        $f = replacementFixture(true);
        $before = $f['packages']->snapshot('sample');
        $review = $f['service']->prepare('sample', $f['options'], 'managed-plugin');
        replacementCheck(is_array($review), "$scenario review succeeds.");
        $expectedCode = match ($scenario) {
            'owner', 'site', 'network', 'expiry', 'cancel' => 'invalid_review',
            'permission', 'filemods' => 'replacement_forbidden', 'parallel' => 'filesystem_busy',
            'files', 'source', 'association' => 'replacement_changed', 'download' => 'download_failed',
            'save' => 'save_failed', 'backup' => 'backup_failed', 'install', 'download-exception', 'save-exception' => 'replacement_failed', 'corrupt' => 'invalid_archive', 'empty-package' => 'invalid_package', 'maintenance' => 'maintenance_unavailable', 'consume' => 'review_consume_failed', 'recovery' => 'recovery_failed',
            'mainfile' => 'missing_entrypoint', 'wp', 'php' => 'incompatible_package', 'traversal', 'symlink', 'case', 'case-directory' => 'unsafe_archive',
        };
        switch ($scenario) {
            case 'owner': $replacementUser = 2; break;
            case 'site': $replacementSite = 2; break;
            case 'network': $replacementMultisite = $replacementSuper = true; $replacementNetwork = 2; break;
            case 'expiry': $f['service']->clock += 900; break;
            case 'cancel': $f['service']->cancel($review['id']); break;
            case 'permission': $replacementCaps['update_plugins'] = false; break;
            case 'filemods': $replacementMods = false; break;
            case 'parallel': $wpdb->busy = true; break;
            case 'files': file_put_contents(WP_PLUGIN_DIR . '/sample/obsolete.txt', 'changed after review'); break;
            case 'source': $f['connector']->owner = 'changed-owner'; break;
            case 'association': $f['settings']->persisted = []; break;
            case 'download': $f['connector']->downloadFails = true; break;
            case 'download-exception': $f['connector']->downloadThrows = true; break;
            case 'save-exception': $f['settings']->throwSave = true; break;
            case 'consume': $f['service']->failReviewDelete = true; break;
            case 'maintenance': file_put_contents(WP_CONTENT_DIR . '/.maintenance', 'another-owner'); break;
            case 'corrupt': file_put_contents($f['connector']->archive, 'not a zip'); break;
            case 'empty-package': $f['connector']->archive = replacementZip(['readme.txt' => 'not a plugin']); break;
            case 'save': $f['settings']->failSave = true; break;
            case 'backup': case 'install': case 'recovery': $f['adapter']->failure = $scenario; break;
            case 'mainfile': $f['connector']->archive = replacementZip(['wrapper/renamed.php' => '<?php /* Plugin Name: Renamed */']); break;
            case 'wp': $f['connector']->archive = replacementZip(['wrapper/sample.php' => "<?php\n/* Plugin Name: Future\nRequires at least: 999.0\n*/"]); break;
            case 'php': $f['connector']->archive = replacementZip(['wrapper/sample.php' => "<?php\n/* Plugin Name: Future\nRequires PHP: 999.0\n*/"]); break;
            case 'traversal': $f['connector']->archive = replacementZip(['../escape.php' => 'unsafe']); break;
            case 'case': $f['connector']->archive = replacementZip(['wrapper/sample.php' => 'one', 'wrapper/Sample.php' => 'two']); break;
            case 'case-directory': $f['connector']->archive = replacementZip(['Wrapper/sample.php' => 'one', 'wrapper/other.php' => 'two']); break;
            case 'symlink':
                $f['connector']->archive = replacementZip(['link' => '/outside']);
                $zip = new ZipArchive(); $zip->open($f['connector']->archive);
                $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0120777 << 16)); $zip->close(); break;
        }
        $result = $f['service']->apply($review['id']);
        replacementError($result, $expectedCode);
        if ($scenario === 'maintenance') {
            replacementCheck(file_get_contents(WP_CONTENT_DIR . '/.maintenance') === 'another-owner', 'Existing maintenance files are neither overwritten nor deleted.');
            unlink(WP_CONTENT_DIR . '/.maintenance');
        } else replacementCheck(!file_exists(WP_CONTENT_DIR . '/.maintenance'), 'Failed replacement/recovery removes its own maintenance window.');
        replacementCheck(!str_contains($result->get_error_message(), 'never-store-secret') && !str_contains($result->get_error_message(), 'secret-database-detail'), 'Transport and persistence errors do not leak secrets.');
        replacementCheck(($f['settings']->persisted[0]->localVersion ?? '') !== 'v2', "$scenario never records an uninstalled Git ref.");
        if ($scenario === 'recovery') {
            $path = $result->get_error_data()['backup_path'];
            replacementCheck(is_dir($path) && file_get_contents($path . '/obsolete.txt') === 'old extra file', 'Failed recovery retains and identifies the old files.');
        } elseif ($scenario !== 'files') {
            replacementCheck($f['packages']->snapshot('sample') === $before, "$scenario leaves or restores all previous files.");
        }
    }
    $replacementUser = $replacementSite = $replacementNetwork = 1; $replacementMultisite = $replacementSuper = false;
    $replacementMods = true; $replacementCaps['update_plugins'] = true; $wpdb->busy = false;
    foreach (['after-save-hook', 'after-commit-cache'] as $scenario) {
        $f = replacementFixture(true);
        $review = $f['service']->prepare('sample', $f['options'], 'managed-plugin');
        $f['settings']->throwAfterSave = $scenario === 'after-save-hook';
        /** Simulate an unrelated registry addition in a post-write hook. */
        $f['settings']->afterSave = static function () use ($f, $scenario): void {
            $other = clone $f['settings']->persisted[0];
            $other->id = 'unrelated-plugin';
            $other->repository = $other->installationFolder = 'unrelated';
            $f['settings']->persisted[] = $other;
            $GLOBALS['replacementCacheThrows'] = $scenario === 'after-commit-cache';
        };
        $result = $f['service']->apply($review['id']);
        replacementCheck($result === 'managed-plugin', "$scenario reports the proven committed installation as success.");
        replacementCheck(is_file(WP_PLUGIN_DIR . '/sample/new.txt') && !is_file(WP_PLUGIN_DIR . '/sample/obsolete.txt'),
            "$scenario does not roll successful file replacement back to stale files.");
        replacementCheck($f['settings']->persisted[0]->localVersion === 'v2' && count($f['settings']->persisted) === 2,
            "$scenario keeps the installed ref and unrelated concurrent registry record.");
        replacementCheck(!file_exists(WP_CONTENT_DIR . '/.maintenance'), "$scenario removes the owned maintenance window.");
        if ($scenario === 'after-commit-cache') {
            replacementCheck($replacementCacheFailureSeen, 'The cleanup cache hook really threw after persistence.');
        }
    }
    $f = replacementFixture(true);
    $managedError = $f['service']->prepare('sample', $f['options']);
    replacementError($managedError, 'managed_destination');
    replacementCheck($managedError->get_error_data()['plugin_id'] === 'managed-plugin', 'Existing managed folder returns the edit destination.');
    replacementError($f['service']->prepare('other', $f['options'], 'managed-plugin'), 'replacement_changed');
    $caseOptions = $f['options']; $caseOptions['folder'] = 'SAMPLE';
    replacementError($f['service']->prepare('sample', $caseOptions), 'managed_destination');
    $caseOptions['folder'] = 'different';
    replacementError($f['service']->prepare('SAMPLE', $caseOptions), 'conflicting_repository');
    $f['service']->failReviewWrite = true;
    replacementError($f['service']->prepare('sample', $f['options'], 'managed-plugin'), 'review_save_failed');
    $f['service']->failReviewWrite = false;
    replacementError($f['packages']->snapshot('../sample'), 'invalid_destination');
    replacementError($f['packages']->snapshot('missing'), 'invalid_destination');
    $review = $f['service']->prepare('sample', $f['options'], 'managed-plugin');
    replacementCheck($f['service']->getReview($review['id']) === $review, 'Authorized confirmation can be reopened without changing its pinned version.');
    $replacementUser = 2;
    replacementError($f['service']->getReview($review['id']), 'invalid_review');
    $replacementUser = 1;
    symlink('/tmp', WP_PLUGIN_DIR . '/sample/link');
    replacementError($f['service']->prepare('sample', $f['options'], 'managed-plugin'), 'unsafe_files');
    echo 'Passed ' . $replacementChecks . " replacement review, archive and rollback checks.\n";
} finally {
    replacementRemove($replacementRoot);
}

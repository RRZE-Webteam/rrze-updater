<?php
// Loaded after token-status.php by discovery.php; all operations use isolated services.

use RRZE\Updater\Settings;
use RRZE\Updater\Core\{Plugin, PluginReplacement};
use RRZE\Updater\ListTable\{PluginsListTable, RepoListTable};

/**
 * Echo a translated and escaped string while rendering the actual confirmation view.
 *
 * @param string $text Message ID.
 * @param string $domain Translation domain.
 * @return void
 */
function esc_html_e($text, $domain = ''): void
{
    echo esc_html__($text, $domain);
}

/** Replacement service fixture that records calls without touching plugin files. */
class PluginRepairServiceFixture extends PluginReplacement
{
    /** @var array Recorded service operations. */
    public array $calls = [];
    /** @var array|WP_Error Prepared review or simulated failure. */
    public array|WP_Error $review;
    /** @var string|WP_Error Applied plugin ID or simulated failure. */
    public string|WP_Error $result = 'admin-plugin';
    /** @var bool Whether to simulate a transport exception. */
    public bool $throw = false;

    /** Initialize a review without real storage or filesystem dependencies. */
    public function __construct()
    {
        $this->review = ['id' => 'review-fixture', 'plugin_id' => 'admin-plugin',
            'repository' => 'package', 'folder' => 'package', 'source_url' => 'https://github.com/example/package',
            'ref' => 'v2', 'commit' => str_repeat('a', 40), 'version' => '2.0',
            'expires_at' => time() + 900, 'mode' => 'reinstall'];
    }

    /**
     * Record the proposed source and return the prepared review.
     *
     * @param string $repository Repository name.
     * @param array $options Source options.
     * @param string|null $pluginId Existing association ID.
     * @return array|WP_Error Fixture review.
     */
    public function prepare(string $repository, array $options, ?string $pluginId = null): array|WP_Error
    {
        $this->calls[] = ['prepare', $repository, $options, $pluginId];
        if ($this->throw) {
            throw new RuntimeException('Transport failure: private-fixture-token');
        }
        return $this->review;
    }

    /**
     * Record a server-side review lookup.
     *
     * @param string $id Review ID.
     * @return array|WP_Error Fixture review.
     */
    public function getReview(string $id): array|WP_Error
    {
        $this->calls[] = ['review', $id];
        return $this->review;
    }

    /**
     * Record confirmation without writing files.
     *
     * @param string $id Review ID.
     * @return string|WP_Error Managed plugin ID or failure.
     */
    public function apply(string $id): string|WP_Error
    {
        $this->calls[] = ['apply', $id];
        return $this->result;
    }

    /**
     * Record a cancellation.
     *
     * @param string $id Review ID.
     * @return void
     */
    public function cancel(string $id): void
    {
        $this->calls[] = ['cancel', $id];
    }
}

/**
 * Configure an authorized request with fresh settings and a recording service.
 *
 * @param string $operation Preparation, confirmation, or cancellation.
 * @param bool $network Whether to exercise the multisite capability boundary.
 * @return array Settings, controller, and service fixture.
 */
function pluginRepairAdminFixture(string $operation = 'prepare', bool $network = false): array
{
    RRZE\Updater\FilesystemLock::releaseAll();
    $GLOBALS['multisite'] = $network;
    [$settings] = adminSaveFixture();
    $GLOBALS['bundle_caps'] = ['manage_options' => true, 'manage_network_options' => true,
        'update_plugins' => true, 'install_plugins' => true];
    $GLOBALS['token_super_admin'] = true;
    $GLOBALS['bundle_mods'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['action' => 'replace'];
    $_POST = ['rrze-updater-nonce' => 'rrze-updater-plugin-replacement-' . $operation,
        'rrze-updater' => ['replacement_action' => $operation, 'plugin_id' => 'admin-plugin', 'review_id' => 'review-fixture']];
    $service = new PluginRepairServiceFixture();
    return [$settings, new AdminPageFixture($settings, null, $service), $service];
}

$beforePluginRepairAdmin = $checks;
$savedPluginRepairState = [$multisite, $bundle_caps, $token_super_admin ?? false, $bundle_mods,
    $_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET'];

foreach ([false, true] as $network) {
    [$settings, $controller, $service] = pluginRepairAdminFixture('prepare', $network);
    $_POST['rrze-updater'] += ['repository' => 'spoofed', 'connectorId' => 'other', 'installationFolder' => 'other'];
    $before = $settings->asArray();
    $controller->plugins->getPluginIndex();
    check($controller->renderer->shown['view'] === 'plugins/replace' && $service->calls === [
        ['prepare', 'package', ['connector' => 'admin-connector', 'folder' => 'package', 'branch' => 'master', 'updates' => 'commits', 'ignore-prereleases' => false], 'admin-plugin'],
    ], 'Reinstallation prepares only the saved source and immutable folder on single-site and multisite.');
    check($settings->asArray() === $before && (new Settings())->asArray() === $before, 'Preparing a review cannot change source settings.');

    foreach (['GET', 'missing-nonce', 'wrong-nonce', 'array-nonce', 'manage', 'install_plugins', 'update_plugins', 'superadmin'] as $denial) {
        if (!$network && $denial === 'superadmin') continue;
        [$settings, $controller, $service] = pluginRepairAdminFixture('prepare', $network);
        switch ($denial) {
            case 'GET': $_SERVER['REQUEST_METHOD'] = 'GET'; break;
            case 'missing-nonce': unset($_POST['rrze-updater-nonce']); break;
            case 'wrong-nonce': $_POST['rrze-updater-nonce'] = 'rrze-updater-plugin-replacement-apply'; break;
            case 'array-nonce': $_POST['rrze-updater-nonce'] = ['malformed']; break;
            case 'manage': $bundle_caps[$network ? 'manage_network_options' : 'manage_options'] = false; break;
            case 'superadmin': $token_super_admin = false; break;
            default: $bundle_caps[$denial] = false;
        }
        check(adminDeletionRejected(fn() => $controller->plugins->getPluginIndex()), 'Replacement rejects unauthorized or non-POST requests: ' . $denial);
        check($service->calls === [], 'Rejected replacement never reaches its service.');
    }
}

[$settings, $controller, $service] = pluginRepairAdminFixture();
$bundle_mods = false;
$controller->plugins->getPluginIndex();
check($service->calls === [] && is_wp_error($controller->notices()[0] ?? null), 'Disabled filesystem changes block review preparation.');

[$settings, $controller, $service] = pluginRepairAdminFixture();
$_POST['rrze-updater']['repository'] = ['malformed'];
$controller->plugins->getPluginIndex();
check($service->calls === [] && $controller->renderer->shown['view'] === '', 'Malformed replacement fields are rejected before the service.');

[$settings, $controller, $service] = pluginRepairAdminFixture();
$_POST['rrze-updater'] = ['replacement_action' => 'prepare', 'repository' => 'new-package',
    'connectorId' => 'admin-connector', 'installationFolder' => 'leftover', 'branch' => 'main', 'updates' => 'releases'];
$_POST['rrze-updater-replacement-nonce'] = $_POST['rrze-updater-nonce'];
$_POST['rrze-updater-nonce'] = 'rrze-updater-plugin-add';
$controller->plugins->getPluginIndex();
check($service->calls === [['prepare', 'new-package', ['connector' => 'admin-connector', 'folder' => 'leftover',
    'branch' => 'main', 'updates' => 'releases'], null]], 'The add form prepares its chosen destination using its own replacement nonce.');

foreach (['apply', 'cancel'] as $operation) {
    [$settings, $controller, $service] = pluginRepairAdminFixture($operation);
    $_POST['rrze-updater'] += ['repository' => 'spoofed', 'installationFolder' => 'spoofed', 'commit' => 'spoofed'];
    $controller->plugins->getPluginIndex();
    check($service->calls === [['review', 'review-fixture'], [$operation, 'review-fixture']], 'Confirmation and cancellation use only the server-side review ID.');
    check($controller->renderer->shown['view'] === 'plugins/edit', 'A completed request returns to the persisted plugin editor.');
    check(str_contains(implode(' ', $controller->notices()), $operation === 'cancel' ? 'cancelled' : 'successfully'), 'The result explains whether files changed.');

    [$settings, $controller, $service] = pluginRepairAdminFixture($operation);
    $service->review = new WP_Error('expired_review', 'Prepare a new review.');
    $controller->plugins->getPluginIndex();
    check($service->calls === [['review', 'review-fixture']], 'An invalid or expired review never reaches apply or cancel.');
    check(count(array_filter($controller->notices(), 'is_wp_error')) === 1, 'Invalid reviews produce an actionable failure.');
}

[$settings, $controller, $service] = pluginRepairAdminFixture('apply');
$service->result = new WP_Error('replacement_failed', 'Restored the original plugin files.');
$controller->plugins->getPluginIndex();
check(count($controller->notices()) === 1 && is_wp_error($controller->notices()[0]), 'Failed replacement never displays a success notice.');

[$settings, $controller, $service] = pluginRepairAdminFixture();
$service->review = new WP_Error('rrze_updater_managed_destination', 'Already managed.', ['plugin_id' => 'admin-plugin']);
$controller->plugins->getPluginIndex();
check($controller->renderer->shown['view'] === 'plugins/add' && str_contains($controller->renderer->shown['data']['managedPluginUrl'], 'id=admin-plugin'),
    'An existing association leads to its editor rather than silently changing its source.');

[$settings, $controller, $service] = pluginRepairAdminFixture();
$service->throw = true;
$controller->plugins->getPluginIndex();
check(!str_contains(serialize($controller->notices()), 'private-fixture-token'), 'Transport exception text is never exposed by the UI.');

[$settings, $controller, $service] = pluginRepairAdminFixture();
$html = adminOutput(static function () use ($service): void {
    $data = ['review' => $service->review];
    require dirname(__DIR__) . '/includes/views/plugins/replace.php';
});
check(str_contains($html, 'All files in the destination folder will be replaced')
    && str_contains($html, 'including custom changes and leftover files')
    && str_contains($html, 'settings in the database and its activation state will be preserved'),
    'The actual confirmation view explains file loss and preserved plugin state.');
check(substr_count($html, 'method="post"') === 2 && str_contains($html, str_repeat('a', 40))
    && str_contains($html, 'rrze-updater-plugin-replacement-apply')
    && str_contains($html, 'rrze-updater-plugin-replacement-cancel'),
    'Confirmation renders its pinned commit and separate POST forms/nonces without JavaScript.');

foreach (['invalid', ['malformed']] as $nonce) {
    [$settings, $controller] = pluginRepairAdminFixture();
    $before = $settings->asArray();
    $_GET = ['action' => 'edit', 'id' => 'admin-plugin'];
    $_POST = ['rrze-updater-nonce' => $nonce, 'rrze-updater' => ['id' => 'admin-plugin',
        'repository' => 'spoofed', 'connectorId' => 'admin-connector', 'branch' => 'main', 'updates' => 'commits']];
    $controller->plugins->getPluginIndex();
    check($settings->asArray() === $before && (new Settings())->asArray() === $before,
        'An invalid or malformed edit nonce cannot change the source.');
}

// The normal WordPress cache fixture recognizes target/target.php only.
[$settings, $controller] = pluginRepairAdminFixture();
$extension = $settings->plugins[0];
$extension->remoteVersion = 'new';
$extension->localVersion = 'old';
$row = $controller->presenter->addRepoUpdateData(['version' => '1.0'], $extension, 'plugin');
check($row['incomplete'] && !$row['hasUpdate'] && $row['updateUrl'] === '' && str_contains($row['repairUrl'], '#plugin-reinstall'),
    'Unrecognized plugins expose a repair action and no ordinary update link.');
foreach ([PluginsListTable::class, RepoListTable::class] as $tableClass) {
    $table = new $tableClass();
    $html = $table->column_version($row);
    check(str_contains($html, 'Installation incomplete') && str_contains($html, '#plugin-reinstall') && !str_contains($html, 'upgrade-plugin'),
        'Both overview tables show the incomplete installation with a repair link.');
}
check($controller->updates->items(['admin-plugin']) === [], 'Combined bulk update also skips incomplete plugins.');
$extension->installationFolder = 'target';
$row = $controller->presenter->addRepoUpdateData(['version' => '1.0'], $extension, 'plugin');
check(!$row['incomplete'] && $row['hasUpdate'], 'Recognized plugins retain their normal update action.');

// A private temporary directory exercises the synchronization boundary safely.
$repairTestRoot = sys_get_temp_dir() . '/rrze-repair-admin-' . bin2hex(random_bytes(6));
if (!defined('WP_PLUGIN_DIR')) define('WP_PLUGIN_DIR', $repairTestRoot);
$repairFolder = 'repair-admin-' . bin2hex(random_bytes(6));
$repairPath = trailingslashit(WP_PLUGIN_DIR) . $repairFolder;
$createdRoot = !is_dir(WP_PLUGIN_DIR);
if ($createdRoot) mkdir(WP_PLUGIN_DIR, 0700, true);
mkdir($repairPath, 0700);
try {
    [$settings, $controller] = pluginRepairAdminFixture();
    $settings->plugins[0]->installationFolder = $repairFolder;
    $missing = clone $settings->plugins[0];
    $missing->id = 'missing'; $missing->repository = $missing->installationFolder = $repairFolder . '-absent';
    $settings->plugins[] = $missing;
    $settings->themes = [];
    check($settings->save(), 'Save the damaged and missing plugin fixtures.');
    $beforeSynchronization = $settings->asArray();
    $wpdb->deny = true;
    check(!$controller->plugins->synchronizeSettings() && $settings->asArray() === $beforeSynchronization,
        'Synchronization cannot prune directory mappings while another filesystem operation holds the lock.');
    $wpdb->deny = false;
    check($controller->plugins->synchronizeSettings(), 'Synchronization persists its result.');
    check(count($settings->plugins) === 1 && reset($settings->plugins)->id === 'admin-plugin',
        'Synchronization retains a remaining directory but removes a completely absent installation.');

    foreach ([false, true] as $managed) {
        [$settings, $controller] = pluginRepairAdminFixture();
        if ($managed) {
            $settings->plugins[0]->installationFolder = $repairFolder;
        } else {
            $settings->plugins = [];
        }
        $settings->themes = [];
        check($settings->save(), 'Persist the existing-folder installation fixture.');
        $_GET = ['action' => 'add'];
        $_POST = ['rrze-updater-nonce' => 'rrze-updater-plugin-add', 'rrze-updater' => [
            'repository' => 'package', 'connectorId' => 'admin-connector', 'installationFolder' => $repairFolder,
            'branch' => 'main', 'updates' => 'commits',
        ]];
        $controller->plugins->getPluginIndex();
        check($controller->renderer->shown['view'] === 'plugins/add', 'An ordinary install never overwrites the existing directory.');
        $data = $controller->renderer->shown['data'];
        check($managed ? !empty($data['managedPluginUrl']) && empty($data['canReplace']) : !empty($data['canReplace']),
            'Existing folders offer explicit review; managed destinations lead to their existing editor.');
    }
} finally {
    rmdir($repairPath);
    if ($createdRoot) rmdir(WP_PLUGIN_DIR);
}

[$multisite, $bundle_caps, $token_super_admin, $bundle_mods, $_GET, $_POST, $_SERVER['REQUEST_METHOD']] = $savedPluginRepairState;
echo 'Passed ' . ($checks - $beforePluginRepairAdmin) . " plugin repair admin checks.\n";

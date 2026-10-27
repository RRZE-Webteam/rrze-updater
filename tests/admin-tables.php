<?php
// Loaded by discovery.php after the administrative fixtures and view helpers.

use RRZE\Updater\Admin\{AdminRenderer, RepositoryUpdateRunner};
use RRZE\Updater\Core\Plugin;
use RRZE\Updater\InstalledExtensions;
use RRZE\Updater\ListTable\{ConnListTable, PluginsListTable, RepoListTable, ThemesListTable};

if (!function_exists('wp_update_plugins')) {
    /**
     * Record a native plugin cache refresh without requesting WordPress.org.
     *
     * @return void
     */
    function wp_update_plugins(): void { $GLOBALS['admin_update_trace'][] = 'cache:plugin'; }
}
if (!function_exists('wp_update_themes')) {
    /**
     * Record a native theme cache refresh without requesting WordPress.org.
     *
     * @return void
     */
    function wp_update_themes(): void { $GLOBALS['admin_update_trace'][] = 'cache:theme'; }
}

/** Local metadata independent of the developer's plugin and theme directories. */
class AdminUpdateInstalledFixture extends InstalledExtensions
{
    /**
     * Resolve a predictable plugin entry point.
     *
     * @param string $installationFolder Installation folder.
     * @param string $repository Repository name.
     * @return string Plugin-relative main file.
     */
    public function getPluginFile(string $installationFolder, string $repository): string
    {
        return $installationFolder . '/main.php';
    }

    /**
     * Simulate an incomplete plugin independently from the host filesystem.
     *
     * @param Plugin $plugin Managed definition.
     * @return bool Whether this fixture represents a broken folder.
     */
    public function isPluginIncomplete(Plugin $plugin): bool
    {
        return $plugin->installationFolder === 'broken';
    }

    /**
     * Supply an installed plugin display name.
     *
     * @param string $installationFolder Installation folder.
     * @param string $repository Repository name.
     * @return string Plugin display name.
     */
    public function pluginName(string $installationFolder, string $repository): string
    {
        return 'Plugin ' . $installationFolder;
    }

    /**
     * Supply an installed theme display name.
     *
     * @param string $installationFolder Installation folder.
     * @return string Theme display name.
     */
    public function themeName(string $installationFolder): string
    {
        return 'Theme ' . $installationFolder;
    }
}

/** Renders the actual progress templates while recording their streaming order. */
class AdminUpdateRendererFixture extends AdminRenderer
{
    /**
     * Record and render a fragment directly from the repository under test.
     *
     * @param string $view Relative view name.
     * @param array $data Explicit view data.
     * @return void
     */
    public function partial(string $view, array $data = []): void
    {
        $GLOBALS['admin_update_trace'][] = 'render:' . $view;
        include dirname(__DIR__) . '/includes/views/' . $view . '.php';
    }
}

/** Keeps item resolution real but substitutes a deterministic single upgrader. */
class AdminUpdateRunnerFixture extends RepositoryUpdateRunner
{
    /**
     * Replace only file operations; preserve all selection and permission checks.
     *
     * @param array $ids Selected repository IDs.
     * @return array Eligible updates with observable upgrader calls.
     */
    public function items(array $ids): array
    {
        $items = parent::items($ids);
        foreach ($items as &$item) {
            $item['upgrader'] = new class($item['extension']) {
                /**
                 * Retain the extension whose warning the view will later read.
                 *
                 * @param object $extension Managed plugin or theme.
                 */
                public function __construct(private object $extension) {}

                /**
                 * Emulate skin output and a warning produced during an update.
                 *
                 * @param string $target Plugin main file or theme directory.
                 * @return bool Successful simulated update.
                 */
                public function upgrade(string $target): bool
                {
                    $GLOBALS['admin_update_trace'][] = 'upgrade:' . $target;
                    echo '<p>upgrading:' . esc_html($target) . '</p>';
                    $this->extension->lastWarning = 'updated:' . $target;
                    return true;
                }
            };
        }
        return $items;
    }
}

$beforeAdminTables = $checks;
$savedTableState = [$_GET, $_POST, $_REQUEST, $wp_filter, $bundle_caps, $invalidated];
foreach (['repo' => RepoListTable::class, 'plugin' => PluginsListTable::class, 'theme' => ThemesListTable::class] as $kind => $class) {
    $nameKey = $kind === 'repo' ? 'repository' : $kind;
    $displayKey = $kind === 'repo' ? 'displayName' : $kind . 'DisplayName';
    $rows = [];
    foreach (['current-z', 'update-z', 'current-a', 'update-a'] as $id) {
        $rows[] = ['id' => $id, $nameKey => 'raw-' . $id,
            $displayKey => str_ends_with($id, '-z') ? '<strong>Item 10</strong>' : 'Item 2',
            'hasUpdate' => str_starts_with($id, 'update'), 'version' => '1', 'installationFolder' => $id];
    }
    foreach (['asc', 'desc'] as $order) {
        $_GET = $_REQUEST = ['action' => 'delete', '_wpnonce' => 'invalid', 'order' => $order,
            'repositories' => ['update-a'], 'plugins' => ['update-a'], 'themes' => ['update-a'],
            'rrze-updater-bulk-delete-confirmed' => '1'];
        $beforeStorage = $storage; $beforeSaves = $saves; $beforeInvalidated = $invalidated;
        $table = new $class($rows);
        $table->prepare_items();
        $expected = $order === 'asc' ? ['update-a', 'update-z', 'current-a', 'current-z']
            : ['update-z', 'update-a', 'current-z', 'current-a'];
        check(array_column($table->items, 'id') === $expected,
            $kind . ' table keeps updates first and naturally sorts visible names in both directions.');
        check($storage === $beforeStorage && $saves === $beforeSaves && $invalidated === $beforeInvalidated,
            'Preparing a table ignores administrative action fields and leaves persistence and update caches unchanged.');
    }
    $_REQUEST = ['orderby' => ['malformed'], 'order' => ['malformed'], 'paged' => 2];
    $admin_table_per_page = 2;
    $table = new $class($rows);
    $table->prepare_items();
    check(array_column($table->items, 'id') === ['current-a', 'current-z'], 'Invalid sort fields use existing defaults before pagination.');
    check($table->get_pagination_arg('total_items') === 4, 'Pagination counts all supplied rows.');
    unset($admin_table_per_page);
}

$connectorRows = [
    ['id' => 'two', 'display' => 'Beta', 'owner' => 'a', 'repocount' => 2],
    ['id' => 'ten', 'display' => 'Alpha', 'owner' => 'b', 'repocount' => 10],
];
$_REQUEST = ['orderby' => 'repocount', 'order' => 'asc'];
$table = new ConnListTable($connectorRows); $table->prepare_items();
check(array_column($table->items, 'id') === ['ten', 'two'], 'Connector counts retain their established lexical ordering.');
$_REQUEST = ['orderby' => 'unsupported', 'order' => 'desc'];
$table = new ConnListTable($connectorRows); $table->prepare_items();
check(array_column($table->items, 'id') === ['two', 'ten'], 'Unsupported connector sort fields fall back to service display names.');

$_REQUEST = ['orderby' => 'version', 'order' => 'desc'];
$table = new RepoListTable([
    ['id' => 'a', 'repository' => 'alpha', 'version' => '1'],
    ['id' => 'z', 'repository' => 'zulu', 'version' => '1'],
]);
$table->prepare_items();
check(array_column($table->items, 'id') === ['z', 'a'], 'Equal alternate sort values retain the repository-name tie breaker.');

[$settings] = adminSaveFixture();
$bundle_caps = ['manage_options' => true, 'update_plugins' => true, 'update_themes' => true];
$settings->plugins[0]->remoteVersion = $settings->themes[0]->remoteVersion = 'next';
$settings->plugins[0]->localVersion = $settings->themes[0]->localVersion = 'old';
$broken = clone $settings->plugins[0];
$broken->id = $broken->installationFolder = 'broken';
$settings->plugins[] = $broken;
$renderer = new AdminUpdateRendererFixture();
$runner = new AdminUpdateRunnerFixture($settings, new AdminUpdateInstalledFixture(), $renderer);
$ids = ['admin-theme', 'broken', 'missing', 'admin-plugin', 'admin-theme'];
$items = $runner->items($ids);
check(array_column($items, 'type') === ['theme', 'plugin', 'theme'], 'Selections retain order and duplicates while excluding missing and incomplete installations.');
$admin_update_trace = [];
adminOutput(fn() => $renderer->partial('repositories/bulk-update-progress', ['updates' => $items]));
check($admin_update_trace === ['render:repositories/bulk-update-progress'], 'Rendering progress data alone cannot execute an attached upgrader.');
foreach (['plugin', 'theme'] as $kind) {
    $capability = 'update_' . $kind . 's';
    $bundle_caps[$capability] = false;
    check(adminDeletionRejected(fn() => $runner->items(['admin-' . $kind])), 'Eligible ' . $kind . ' updates enforce their WordPress capability.');
    $bundle_caps[$capability] = true;
    $extension = $kind === 'plugin' ? $settings->plugins[0] : $settings->themes[0];
    $extension->lastError = 'Remote check failed';
    check($runner->items(['admin-' . $kind]) === [], 'A failed check never becomes an update item.');
    $extension->lastError = '';
    $extension->localVersion = 'next';
    check($runner->items(['admin-' . $kind]) === [], 'An unchanged version never becomes an update item.');
    $extension->localVersion = 'old';
}

$admin_update_trace = []; $invalidated = [];
$html = adminOutput(fn() => $runner->run($ids, ['Existing notice']));
check($invalidated === ['update_plugins', 'update_themes'], 'The runner invalidates each selected update type exactly once.');
check($admin_update_trace === [
    'cache:plugin', 'cache:theme', 'render:partials/page-start', 'render:repositories/bulk-update-progress',
    'render:repositories/bulk-update-heading', 'upgrade:package', 'render:repositories/bulk-update-warning',
    'render:repositories/bulk-update-heading', 'upgrade:package/main.php', 'render:repositories/bulk-update-warning',
    'render:repositories/bulk-update-heading', 'upgrade:package', 'render:repositories/bulk-update-warning',
    'render:repositories/bulk-update-end', 'render:partials/page-end',
], 'Headings, individual upgrades and their resulting warnings stream in the original order.');
check(str_contains($html, 'Existing notice') && str_contains($html, 'updated:package/main.php')
    && str_contains($html, 'Zurück zum Updater'), 'Progress retains request notices, newly produced warnings and the return link.');

$admin_update_trace = []; $invalidated = [];
$html = adminOutput(fn() => $runner->run([]));
check($invalidated === [] && str_contains($html, 'Für die ausgewählten Repositories liegen keine Updates vor.'),
    'An empty selection shows the existing message without refreshing any cache or running an update.');

[$_GET, $_POST, $_REQUEST, $wp_filter, $bundle_caps, $invalidated] = $savedTableState;
echo 'Passed ' . ($checks - $beforeAdminTables) . " read-only table and update runner checks.\n";

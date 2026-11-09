<?php
// Exercise page action authorization while list tables remain read-only.
require_once __DIR__ . '/bundles.php';
require_once __DIR__ . '/admin-integration.php';

use RRZE\Updater\Settings;
use RRZE\Updater\ListTable\{RepoListTable, PluginsListTable, ThemesListTable};

class AdminDeletionRejected extends RuntimeException {}
function wp_die($message) { throw new AdminDeletionRejected($message); }
function convert_to_screen($screen) { return (object) ['id' => 'deletion-fixture', 'base' => 'deletion-fixture']; }
function submit_button(...$args) {}
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true) {
    // Match the deterministic wp_verify_nonce fixture while exercising core's
    // choice of nonce action/name in WP_List_Table::display_tablenav().
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($action) . '" />';
    if ($display) echo $field;
    return $field;
}

/** Keeps deletion page rendering independent from installed extension files. */
class AdminDeletionInstalledFixture extends AdminInstalledExtensionsFixture
{
    /**
     * Resolve a conventional entry point without inspecting the host filesystem.
     *
     * @param string $installationFolder Installation folder.
     * @param string $repository Repository name.
     * @return string Plugin-relative file.
     */
    public function getPluginFile(string $installationFolder, string $repository): string
    {
        return $installationFolder . '/' . $repository . '.php';
    }

    /**
     * Supply a stable name for the rendered plugin row.
     *
     * @param string $installationFolder Installation folder.
     * @param string $repository Repository name.
     * @return string Fixture display name.
     */
    public function pluginName(string $installationFolder, string $repository): string
    {
        return $repository;
    }

    /**
     * Supply a stable name for the rendered theme row.
     *
     * @param string $installationFolder Installation folder.
     * @return string Fixture display name.
     */
    public function themeName(string $installationFolder): string
    {
        return $installationFolder;
    }
}

/**
 * Dispatch a deletion through the composed page, before any table is prepared.
 *
 * @param AdminPageFixture $fixture Administrative dependencies for this request.
 * @param string $kind Repository, plugin, theme or connector scope.
 * @param bool $bulk Whether to exercise the full index route.
 * @return void
 */
function adminDeletionDispatch(AdminPageFixture $fixture, string $kind, bool $bulk = false): void {
    [$page, $method] = match ($kind) {
        'repo' => [$fixture->repositories, $bulk ? 'getRepoIndex' : 'getRepoAction'],
        'plugin' => [$fixture->plugins, $bulk ? 'getPluginIndex' : 'getPluginAction'],
        'theme' => [$fixture->themes, $bulk ? 'getThemeIndex' : 'getThemeAction'],
        'connector' => [$fixture->services, $bulk ? 'getConnectorIndex' : 'getConnectorAction'],
    };
    if ($bulk) $page->$method();
    else $page->$method('delete');
}

if (!function_exists('get_user_option')) {
    /**
     * Supply a configurable per-page preference for real WordPress table methods.
     *
     * @param string $option User option key.
     * @return int Number of fixture rows per page.
     */
    function get_user_option($option) { return $GLOBALS['admin_table_per_page'] ?? 20; }
}
if (!function_exists('get_hidden_columns')) {
    /**
     * Keep every fixture table column visible.
     *
     * @param object $screen Current admin screen.
     * @return array No hidden column names.
     */
    function get_hidden_columns($screen) { return []; }
}
if (!function_exists('get_column_headers')) {
    /**
     * Resolve the columns registered by the real WordPress table constructor.
     *
     * @param object $screen Current admin screen.
     * @return array Column names and labels.
     */
    function get_column_headers($screen) { return apply_filters('manage_' . $screen->id . '_columns', []); }
}
if (!function_exists('add_query_arg')) {
    /**
     * Add fixture query parameters without requiring WordPress URL utilities.
     *
     * @param array $args New query parameters.
     * @param string $url Existing URL.
     * @return string URL with merged query parameters.
     */
    function add_query_arg(array $args, string $url): string {
        $parts = explode('?', $url, 2);
        parse_str($parts[1] ?? '', $query);
        return $parts[0] . '?' . http_build_query(array_merge($query, $args));
    }
}
if (!function_exists('admin_url')) {
    /**
     * Supply the site administration base used by optional tool links.
     *
     * @param string $path Admin-relative path.
     * @return string Fixture administration URL.
     */
    function admin_url($path = '') { return '/wp-admin/' . $path; }
}
if (!function_exists('wp_create_nonce')) {
    /**
     * Match the deterministic nonce validator in the administrative fixtures.
     *
     * @param string $action Nonce action.
     * @return string Fixture nonce value.
     */
    function wp_create_nonce($action) { return $action; }
}

/**
 * Build independent registry entries and the composed administration for deletion.
 *
 * @param string $kind Deletion scope.
 * @return array Settings and page fixture.
 */
function adminDeletionFixture(string $kind): array {
    [$settings] = adminSaveFixture();
    $GLOBALS['bundle_caps'] = ['manage_options' => true, 'update_plugins' => true, 'update_themes' => true];
    if ($kind === 'connector') {
        $settings->plugins = $settings->themes = [];
        $other = clone $settings->connectors[0];
        $other->id = 'keep-connector';
        $settings->connectors[] = $other;
    } else {
        foreach (['plugins', 'themes'] as $property) {
            $settings->{$property}[0]->installationFolder = 'target';
            $other = clone $settings->{$property}[0];
            $other->id = 'keep-' . $property;
            $other->repository = 'keep';
            $other->installationFolder = 'first';
            $settings->{$property}[] = $other;
        }
        $GLOBALS['source_test_themes'] = ['target' => new stdClass(), 'first' => new stdClass()];
    }
    check($settings->save(), 'Persist the deletion fixture.');
    $_REQUEST = $_GET = $_POST = [];
    return [$settings, new AdminPageFixture($settings, null, null, new AdminDeletionInstalledFixture())];
}

function adminDeletionCases(string $nonce, string $wrongNonce, array $capabilities): array {
    $cases = [
        ['missing', null, null],
        ['empty', '', null],
        ['invalid', 'invalid-or-expired', null],
        ['wrong-action', $wrongNonce, null],
        ['malformed', [$nonce], null],
        ['valid', $nonce, null],
    ];
    foreach ($capabilities as $capability) $cases[] = ['denied-' . $capability, $nonce, $capability];
    return $cases;
}

function adminDeletionRejected(callable $request): bool {
    try { $request(); } catch (AdminDeletionRejected $e) { return true; }
    return false;
}

/**
 * Ensure an unauthorized deletion never changes managed definitions or caches.
 *
 * Full plugin/theme index requests already synchronize unchanged installations
 * before table actions. Count that established save separately from deletion.
 *
 * @param Settings $settings Shared registry after request handling.
 * @param array $before Registry contents before the request.
 * @param int $savesBefore Initial persistence call count.
 * @param int $synchronizationSaves Expected normal index synchronization saves.
 * @return void
 */
function checkDeletionUnchanged(Settings $settings, array $before, int $savesBefore, int $synchronizationSaves = 0): void {
    check($settings->asArray() === $before && (new Settings())->asArray() === $before,
        'Rejected or unconfirmed deletion preserves both shared settings and stored associations.');
    check($GLOBALS['saves'] === $savesBefore + $synchronizationSaves && $GLOBALS['invalidated'] === [],
        'Rejected or unconfirmed deletion adds no write beyond normal index synchronization and does not invalidate update caches.');
}

$beforeDeletions = $checks;
$savedDeletionHooks = $wp_filter;
$savedDeletionCaps = $bundle_caps;
$savedDeletionRequest = [$_GET, $_POST, $_REQUEST];
$savedDeletionThemes = $source_test_themes;
$deleteCapabilities = [
    'repo' => ['update_plugins', 'update_themes'],
    'plugin' => ['update_plugins'],
    'theme' => ['update_themes'],
    'connector' => ['manage_options'],
];

// Single row links must reject missing, empty, invalid and non-scalar nonces.
foreach ($deleteCapabilities as $kind => $capabilities) {
    $action = 'rrze-updater-' . $kind . '-delete';
    foreach (adminDeletionCases($action, 'bulk-plugins', $capabilities) as [$case, $nonce, $denied]) {
        [$settings, $controller] = adminDeletionFixture($kind);
        $targetKind = $kind === 'repo' ? 'plugin' : $kind;
        $_GET = ['action' => 'delete', 'id' => 'admin-' . $targetKind];
        if ($nonce !== null) $_GET['rrze-updater-nonce'] = $nonce;
        $_REQUEST = $_GET;
        if ($denied) $bundle_caps[$denied] = false;
        $before = $settings->asArray(); $savesBefore = $saves;
        $rejected = adminDeletionRejected(fn() => adminDeletionDispatch($controller, $kind));
        check($rejected === ($case !== 'valid'), "$kind/$case single deletion enforces nonce and capabilities.");
        if ($rejected) {
            checkDeletionUnchanged($settings, $before, $savesBefore);
        } else {
            $stored = new Settings();
            $property = $targetKind . 's';
            check(count($stored->$property) === 1 && str_starts_with($stored->{$property}[0]->id, 'keep-'),
                'Valid single deletion removes only the requested association.');
            foreach (['connectors', 'plugins', 'themes'] as $other) {
                if ($other !== $property) check(($stored->asArray()[$other] ?? []) === ($before[$other] ?? []),
                    'Single deletion preserves unrelated registry entries.');
            }
        }
    }
}

// Core still renders its nonce; the page controller owns the confirmed action.
foreach (['repo' => RepoListTable::class, 'plugin' => PluginsListTable::class, 'theme' => ThemesListTable::class] as $kind => $class) {
    $plural = $kind === 'repo' ? 'repositories' : $kind . 's';
    $cases = adminDeletionCases('bulk-' . $plural, 'rrze-updater-' . $kind . '-delete', $deleteCapabilities[$kind]);
    $cases[] = ['unconfirmed', 'bulk-' . $plural, null];
    $cases[] = ['secondary-action', 'bulk-' . $plural, null];
    $cases[] = ['primary-wins', 'bulk-' . $plural, null];
    $cases[] = ['filter-action', 'bulk-' . $plural, null];
    foreach ($cases as [$case, $nonce, $denied]) {
        [$settings, $controller] = adminDeletionFixture($kind);
        $ids = $kind === 'repo' ? ['admin-plugin', 'admin-theme'] : ['admin-' . $kind];
        $listData = array_map(static fn($id) => ['id' => $id], [...$ids, 'unselected']);
        $table = new $class($listData);
        $navigation = new ReflectionMethod(WP_List_Table::class, 'display_tablenav');
        $html = adminOutput(fn() => $navigation->invoke($table, 'top'));
        check(str_contains($html, 'name="_wpnonce" value="bulk-' . $plural . '"'),
            'Core renders the nonce field required for this bulk action.');
        $_GET = ['action' => 'delete', $plural => $ids];
        if ($case === 'secondary-action') $_GET = ['action' => '-1', 'action2' => 'delete', $plural => $ids];
        if ($case === 'primary-wins') $_GET = ['action' => 'unrelated', 'action2' => 'delete', $plural => $ids];
        if ($case === 'filter-action') $_GET['filter_action'] = 'Filter';
        if ($case !== 'unconfirmed') $_GET['rrze-updater-bulk-delete-confirmed'] = '1';
        if ($nonce !== null) $_GET['_wpnonce'] = $nonce;
        $_REQUEST = $_GET;
        if ($denied) $bundle_caps[$denied] = false;
        $before = $settings->asArray(); $savesBefore = $saves;
        $rejected = adminDeletionRejected(fn() => adminDeletionDispatch($controller, $kind, true));
        check($rejected === !in_array($case, ['valid', 'unconfirmed', 'secondary-action', 'primary-wins', 'filter-action'], true),
            "$kind/$case bulk deletion enforces its own nonce and capabilities.");
        if (!in_array($case, ['valid', 'secondary-action'], true)) {
            checkDeletionUnchanged($settings, $before, $savesBefore, $kind === 'repo' ? 0 : 1);
            check($table->listData === $listData, 'Rejected or unconfirmed bulk deletion preserves displayed rows.');
        } else {
            $stored = new Settings();
            foreach (['plugins', 'themes'] as $property) {
                $deleted = $kind === 'repo' || $property === $plural;
                check(count($stored->$property) === ($deleted ? 1 : 2), 'Bulk deletion removes only selected associations.');
                check(in_array('keep-' . $property, array_column($stored->$property, 'id'), true), 'Unselected associations remain managed.');
            }
            check($stored->asArray()['connectors'] === $before['connectors'], 'Bulk deletion preserves connectors.');
            check($table->listData === $listData, 'The independent display table never applies registry mutations to its input rows.');
        }
    }
}

$wp_filter = $savedDeletionHooks;
$bundle_caps = $savedDeletionCaps;
[$_GET, $_POST, $_REQUEST] = $savedDeletionRequest;
$source_test_themes = $savedDeletionThemes;
echo 'Passed ' . ($checks - $beforeDeletions) . " admin deletion authorization checks.\n";

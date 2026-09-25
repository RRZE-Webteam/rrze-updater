<?php
// Exercise request dispatch and real list-table deletion against fixture storage.
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

class AdminDeletionControllerFixture extends AdminSaveControllerFixture {
    public function dispatchDelete(string $kind): void {
        match ($kind) {
            'repo' => $this->getRepoAction('delete'),
            'plugin' => $this->getPluginAction('delete'),
            'theme' => $this->getThemeAction('delete'),
            'connector' => $this->getConnectorAction('delete'),
        };
    }
}

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
            $other = clone $settings->{$property}[0];
            $other->id = 'keep-' . $property;
            $other->repository = $other->installationFolder = 'keep';
            $settings->{$property}[] = $other;
        }
    }
    check($settings->save(), 'Persist the deletion fixture.');
    $_REQUEST = $_GET = $_POST = [];
    return [$settings, new AdminDeletionControllerFixture($settings)];
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

function checkDeletionUnchanged(Settings $settings, array $before, int $savesBefore): void {
    check($settings->asArray() === $before && (new Settings())->asArray() === $before,
        'Rejected or unconfirmed deletion preserves both shared settings and stored associations.');
    check($GLOBALS['saves'] === $savesBefore && $GLOBALS['invalidated'] === [],
        'Rejected or unconfirmed deletion does not write settings or invalidate update caches.');
}

$beforeDeletions = $checks;
$savedDeletionHooks = $wp_filter;
$savedDeletionCaps = $bundle_caps;
$savedDeletionRequest = [$_GET, $_POST, $_REQUEST];
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
        $rejected = adminDeletionRejected(fn() => $controller->dispatchDelete($kind));
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

// Use the actual WordPress table constructor, action resolution and nonce field.
foreach (['repo' => RepoListTable::class, 'plugin' => PluginsListTable::class, 'theme' => ThemesListTable::class] as $kind => $class) {
    $plural = $kind === 'repo' ? 'repositories' : $kind . 's';
    $cases = adminDeletionCases('bulk-' . $plural, 'rrze-updater-' . $kind . '-delete', $deleteCapabilities[$kind]);
    $cases[] = ['unconfirmed', 'bulk-' . $plural, null];
    foreach ($cases as [$case, $nonce, $denied]) {
        [$settings, $controller] = adminDeletionFixture($kind);
        $ids = $kind === 'repo' ? ['admin-plugin', 'admin-theme'] : ['admin-' . $kind];
        $listData = array_map(static fn($id) => ['id' => $id], [...$ids, 'unselected']);
        $table = new $class($controller, $listData);
        $navigation = new ReflectionMethod(WP_List_Table::class, 'display_tablenav');
        $html = adminOutput(fn() => $navigation->invoke($table, 'top'));
        check(str_contains($html, 'name="_wpnonce" value="bulk-' . $plural . '"'),
            'Core renders the nonce field required for this bulk action.');
        $_GET = ['action' => 'delete', $plural => $ids];
        if ($case !== 'unconfirmed') $_GET['rrze-updater-bulk-delete-confirmed'] = '1';
        if ($nonce !== null) $_GET['_wpnonce'] = $nonce;
        $_REQUEST = $_GET;
        if ($denied) $bundle_caps[$denied] = false;
        $before = $settings->asArray(); $savesBefore = $saves;
        $rejected = adminDeletionRejected(function () use ($controller, $kind, $table, $settings, $before) {
            $controller->dispatchDelete($kind);
            check($settings->asArray() === $before, 'Bulk dispatch does not attempt a single-row deletion.');
            $table->process_bulk_action();
        });
        check($rejected === !in_array($case, ['valid', 'unconfirmed'], true),
            "$kind/$case bulk deletion enforces its own nonce and capabilities.");
        if ($case !== 'valid') {
            checkDeletionUnchanged($settings, $before, $savesBefore);
            check($table->listData === $listData, 'Rejected or unconfirmed bulk deletion preserves displayed rows.');
        } else {
            $stored = new Settings();
            foreach (['plugins', 'themes'] as $property) {
                $deleted = $kind === 'repo' || $property === $plural;
                check(count($stored->$property) === ($deleted ? 1 : 2), 'Bulk deletion removes only selected associations.');
                check(in_array('keep-' . $property, array_column($stored->$property, 'id'), true), 'Unselected associations remain managed.');
            }
            check($stored->asArray()['connectors'] === $before['connectors'], 'Bulk deletion preserves connectors.');
            check(array_values($table->listData) === [['id' => 'unselected']], 'Successful bulk deletion removes selected rows.');
        }
    }
}

$wp_filter = $savedDeletionHooks;
$bundle_caps = $savedDeletionCaps;
[$_GET, $_POST, $_REQUEST] = $savedDeletionRequest;
echo 'Passed ' . ($checks - $beforeDeletions) . " admin deletion authorization checks.\n";

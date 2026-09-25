<?php
require_once __DIR__ . '/update-outcomes.php';

use RRZE\Updater\{Controller, Settings};
use RRZE\Updater\Core\{Plugin, Theme};

function absint($value) { return abs((int) $value); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function wp_verify_nonce($nonce, $action) { return $nonce === $action; }
function wp_clear_scheduled_hook($hook) {
    $GLOBALS['admin_schedule_calls'][] = ['clear', $hook];
    unset($GLOBALS['cron_events'][get_current_blog_id()][$hook]);
}
function wp_schedule_event($time, $schedule, $hook) {
    $GLOBALS['admin_schedule_calls'][] = ['schedule', $schedule, $hook];
    $GLOBALS['cron_events'][get_current_blog_id()][$hook] = ['time' => $time, 'schedule' => $schedule];
    return true;
}
function wp_get_schedule($hook) { return $GLOBALS['cron_events'][get_current_blog_id()][$hook]['schedule'] ?? false; }
function wp_next_scheduled($hook) { return $GLOBALS['cron_events'][get_current_blog_id()][$hook]['time'] ?? false; }

class AdminSaveConnectorFixture extends LegacyInstallConnectorFixture {
    public function asArray(): array { return RRZE\Updater\Core\GithubConnector::createFromArray(parent::asArray())->asArray(); }
}
class AdminSaveControllerFixture extends Controller {
    public array $shown = [];
    protected function display($view, $data = []) { $this->shown = compact('view', 'data'); }
    protected function pluginVersion($folder, $repository) { return '1.0'; }
    protected function themeVersion($folder) { return '1.0'; }
    protected function lastChecked($timestamp) { return (string) $timestamp; }
    public function notices(): array { return $this->messages; }
    public function submit(string $kind): void {
        match ($kind) {
            'connector' => $this->postConnectorEdit(),
            'plugin' => $this->postPluginEdit(),
            'theme' => $this->postThemeEdit(),
            'add' => $this->postConnectorAdd(),
            'settings' => $this->getSettingsIndex(),
        };
    }
}
function adminSaveFixture(): array {
    $GLOBALS['storage'] = []; $GLOBALS['fail_save'] = false; $GLOBALS['wpdb']->deny = false;
    $GLOBALS['admin_schedule_calls'] = []; $GLOBALS['invalidated'] = [];
    $_POST = $_GET = [];
    $settings = new Settings();
    $connector = new AdminSaveConnectorFixture();
    $connector->id = 'admin-connector'; $connector->owner = 'owner'; $connector->token = 'old-fixture-token';
    $settings->connectors = [$connector];
    $settings->plugins = [Plugin::createFromArray(['id' => 'admin-plugin', 'connectorId' => $connector->id,
        'repository' => 'package', 'installationFolder' => 'package', 'branch' => 'master', 'updates' => 'commits'])];
    $settings->themes = [Theme::createFromArray(['id' => 'admin-theme', 'connectorId' => $connector->id,
        'repository' => 'package', 'installationFolder' => 'package', 'branch' => 'master', 'updates' => 'commits'])];
    $settings->plugins[0]->connector = $settings->themes[0]->connector = $connector;
    $settings->save();
    return [$settings, new AdminSaveControllerFixture($settings)];
}
$beforeAdminSaves = $checks;
$bundle_caps = ['manage_options' => true, 'update_plugins' => true, 'update_themes' => true];
foreach (['connector', 'plugin', 'theme', 'settings'] as $kind) {
    foreach (['database', 'lock', 'conflict', 'success'] as $outcome) {
        [$settings, $controller] = adminSaveFixture();
        $_POST['rrze-updater'] = match ($kind) {
            'connector' => ['id' => 'admin-connector', 'token' => 'new-fixture-token'],
            'settings' => ['action' => 'save-settings', 'update_check_delay' => 17],
            default => ['id' => 'admin-' . $kind, 'repository' => 'package', 'connectorId' => 'admin-connector', 'branch' => 'main', 'updates' => 'commits'],
        };
        $_POST['rrze-updater-nonce'] = 'rrze-updater-settings';
        if ($outcome === 'conflict') {
            $concurrent = new Settings();
            match ($kind) {
                'connector' => $concurrent->connectors[0]->token = 'concurrent-fixture-token',
                'plugin' => $concurrent->plugins[0]->branch = 'concurrent-branch',
                'theme' => $concurrent->themes[0]->branch = 'concurrent-branch',
                'settings' => $concurrent->options['update_check_delay'] = 29,
            };
            check($concurrent->save(), 'Concurrent editor commits its change.');
        }
        $storedBefore = (new Settings())->asArray();
        $fail_save = $outcome === 'database';
        $wpdb->deny = $outcome === 'lock';
        if ($kind === 'settings' && $outcome !== 'success') $_POST['rrze-updater-send-now'] = '1';
        $controller->submit($kind);
        $fail_save = $wpdb->deny = false;
        $errors = array_values(array_filter($controller->notices(), 'is_wp_error'));
        check(count($errors) === ($outcome === 'success' ? 0 : 1), "$kind/$outcome reports failed persistence exactly once.");
        check($settings->asArray() === (new Settings())->asArray(), "$kind/$outcome: the shared Settings object matches persisted values after the request.");
        if ($outcome !== 'success') {
            check($settings->asArray() === $storedBefore, 'Rejected edits do not replace persisted or concurrent edits.');
            check($admin_schedule_calls === [] && $invalidated === [], 'Rejected edits do not reschedule jobs or clear update caches.');
            check(!in_array('Settings saved.', $controller->notices(), true), 'Failed save never reports success.');
        }
        $data = $controller->shown['data'];
        if ($kind === 'connector') check($data['connector']->token === $settings->connectors[0]->token, 'Connector form shows the persisted token.');
        elseif ($kind === 'settings') check($data['settings'] === $settings->options, 'General settings form shows persisted values.');
        else {
            $property = $kind === 'plugin' ? 'plugins' : 'themes';
            check($data[$kind]->branch === $settings->{$property}[0]->branch, 'Repository form shows the persisted branch.');
        }
        if ($kind === 'settings' && $outcome === 'success') check(count($admin_schedule_calls) === 3, 'Successful general settings save updates schedules.');
        if ($outcome !== 'success') {
            $settings->options['info_logging_enabled'] = !$settings->options['info_logging_enabled'];
            check($settings->save(), 'Reload also restores a usable merge baseline for later saves.');
        }
    }
}
// Validation failures are also save failures: the displayed association must
// remain the original one, and a concurrently deleted record must stay deleted.
foreach (['plugin', 'theme'] as $kind) {
    foreach (['duplicate', 'deleted'] as $outcome) {
        [$settings, $controller] = adminSaveFixture();
        $property = $kind === 'plugin' ? 'plugins' : 'themes';
        if ($outcome === 'duplicate') {
            $other = clone $settings->{$property}[0];
            $other->id = 'other'; $other->repository = $other->installationFolder = 'other';
            $settings->{$property}[] = $other;
            $settings->save();
        } else {
            $concurrent = new Settings();
            $concurrent->$property = [];
            $concurrent->save();
        }
        $_POST['rrze-updater'] = ['id' => 'admin-' . $kind, 'repository' => 'other',
            'connectorId' => 'admin-connector', 'branch' => 'main', 'updates' => 'commits'];
        $controller->submit($kind);
        check(count(array_filter($controller->notices(), 'is_wp_error')) === 1, 'Registry rejection is shown as a save error.');
        check($settings->asArray() === (new Settings())->asArray(), 'Rejected or deleted associations are reloaded in place.');
        if ($outcome === 'duplicate') check($controller->shown['data'][$kind]->repository === 'package', 'Duplicate edit does not change the displayed update source.');
        else check($controller->shown['view'] === '' && $settings->$property === [], 'Concurrent deletion is not resurrected or rendered as an editable record.');
    }
}
// Failed creation and deletions restore associations for all consumers of Settings.
foreach (['add', 'delete-plugin', 'delete-theme', 'delete-connector', 'synchronize', 'ajax-check'] as $operation) {
    [$settings, $controller] = adminSaveFixture();
    if ($operation === 'delete-connector') {
        $settings->plugins = $settings->themes = []; $settings->save();
    }
    $before = $settings->asArray();
    $fail_save = true;
    if ($operation === 'add') {
        $_POST['rrze-updater'] = ['owner' => 'new-owner', 'type' => 'github', 'token' => 'new-fixture-token'];
        $controller->submit('add');
        check($controller->shown['view'] === 'connectors/add', 'Failed creation stays on the form without a success redirect.');
    } elseif ($operation === 'synchronize') {
        $controller->synchronizeSettings();
    } elseif ($operation === 'ajax-check') {
        $_POST = ['nonce' => 'rrze-updater-check-updates', 'id' => 'admin-plugin', 'type' => 'plugin'];
        $controller->ajaxCheckRepositoryUpdate();
        check($bundle_response['success'] === false && $bundle_response['status'] === 409, 'AJAX returns an error when checked metadata cannot be persisted.');
    } else {
        $kind = substr($operation, strlen('delete-'));
        $method = $kind . 'Delete';
        $controller->$method('admin-' . $kind);
    }
    $fail_save = false;
    check($settings->asArray() === $before && (new Settings())->asArray() === $before, "$operation restores rejected registry mutations in place.");
    check(count(array_filter($controller->notices(), 'is_wp_error')) === 1, "$operation exposes the failed save.");
    check($invalidated === [], "$operation preserves caches after a failed save.");
}
$_POST = $_GET = [];
echo 'Passed ' . ($checks - $beforeAdminSaves) . " admin save failure checks.\n";

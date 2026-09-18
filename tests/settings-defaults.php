<?php
namespace RRZE\Updater {
    function plugin() {
        $GLOBALS['defaults_plugin_reads']++;
        return new class {
            public function getBasename(): string { return $GLOBALS['defaults_basename']; }
        };
    }
}

namespace {
require_once __DIR__ . '/managed-upgrader.php';

use RRZE\Updater\{Config, Main, Settings};
use RRZE\Updater\Core\GitlabConnector;

$beforeDefaults = $checks;
$savedDefaultsHooks = $wp_filter;
$savedDefaultsMultisite = $multisite;
$config = new Config();
foreach ([false, true] as $multisite) {
    foreach (['rrze-updater', 'renamed-updater'] as $folder) {
        $storage = [];
        $fail_save = $wpdb->deny = false;
        $defaults_basename = $folder . '/rrze-updater.php';
        $defaults_plugin_reads = 0;
        $beforeSaves = $saves;
        $settings = new Settings();
        check($settings->connectors === [] && $settings->plugins === [] && $saves === $beforeSaves,
            'Reading settings alone does not initialize or persist repositories.');
        $settings->options['update_check_delay'] = 23;
        $settings->initializeDefaults($config);
        check(count($settings->connectors) === 1 && count($settings->plugins) === 1 && $settings->themes === [],
            'Initialization creates one connector and the Updater association.');
        $connector = $settings->connectors[0];
        $extension = $settings->plugins[0];
        check($connector instanceof GitlabConnector && $connector->owner === 'rrze-webteam' && $connector->token === '',
            'Default connector retains the configured GitLab owner and empty token.');
        check($extension->repository === 'rrze-updater' && $extension->branch === 'master' && $extension->updates === 'commits',
            'Default repository retains its branch and commit update policy.');
        check($extension->installationFolder === $folder && $extension->connectorId === $connector->id,
            'The association references the created connector and actual installation folder.');
        check($saves === $beforeSaves + 1 && !$wpdb->locked, 'Initialization persists once and releases the settings lock.');
        $persisted = new Settings();
        check($persisted->asArray() === $settings->asArray() && $persisted->plugins[0]->connector === $persisted->connectors[0],
            'Reload restores the complete registry and connector relationship.');
        check($persisted->options['update_check_delay'] === 23, 'Repository initialization preserves general settings.');
        $snapshot = $storage;
        $settings->initializeDefaults($config);
        $persisted->initializeDefaults($config);
        check($storage === $snapshot && $saves === $beforeSaves + 1 && $defaults_plugin_reads === 1,
            'Repeated initialization preserves IDs, skips plugin lookup and performs no writes.');
    }

    // A configured connector suppresses seeding even when no repositories exist.
    foreach ([false, true] as $hasRepositories) {
        [$settings] = adminSaveFixture();
        if (!$hasRepositories) {
            $settings->plugins = $settings->themes = [];
            check($settings->save(), 'Persist a configured connector without repositories.');
        }
        $before = $settings->asArray();
        $snapshot = $storage;
        $beforeSaves = $saves;
        $defaults_plugin_reads = 0;
        $settings->initializeDefaults($config);
        check($settings->asArray() === $before && $storage === $snapshot && $saves === $beforeSaves,
            'Existing credentials, repositories and options remain untouched.');
        check($defaults_plugin_reads === 0, 'Existing installations do not resolve the bootstrap plugin path.');
    }

    // Keep persistence through the existing save path, including failed writes.
    foreach (['database', 'lock'] as $failure) {
        $storage = [];
        $settings = new Settings();
        $fail_save = $failure === 'database';
        $wpdb->deny = $failure === 'lock';
        $settings->initializeDefaults($config);
        check($storage === [] && !$wpdb->locked, 'Rejected initialization writes nothing and leaves no lock behind.');
        $fail_save = $wpdb->deny = false;
        $settings = new Settings();
        $settings->initializeDefaults($config);
        check(count((new Settings())->plugins) === 1, 'The next request can initialize after a failed save.');
    }

    // Production startup must still seed before the other components use settings.
    $wp_filter = [];
    $storage = [];
    $main = new Main();
    check($storage === [], 'Constructing Main does not seed the registry before loaded().');
    $main->loaded();
    check(count($main->settings->plugins) === 1 && (new Settings())->asArray() === $main->settings->asArray(),
        'Main delegates startup initialization to its shared Settings object.');
    check(has_filter('upgrader_pre_download') && has_filter('site_transient_update_plugins'),
        'Update components remain registered after first-time initialization.');
    $snapshot = $storage;
    $beforeSaves = $saves;
    $wp_filter = [];
    (new Main())->loaded();
    check($storage === $snapshot && $saves === $beforeSaves, 'Later startup leaves the initialized registry unchanged.');
}
$multisite = $savedDefaultsMultisite;
$wp_filter = $savedDefaultsHooks;
echo 'Passed ' . ($checks - $beforeDefaults) . " settings initialization checks.\n";
}

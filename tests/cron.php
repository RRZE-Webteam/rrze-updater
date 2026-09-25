<?php
// Site-local cron storage fixtures with the production scheduler and hook dispatcher.
require_once __DIR__ . '/bundles.php';
require_once __DIR__ . '/admin-integration.php';

use RRZE\Updater\{Config, Controller, Cron, Settings};

define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);

class CronControllerFixture extends Controller {
    public int $synchronizations = 0;
    public function synchronizeSettings() { $this->synchronizations++; }
}

// Rehydrate real saved settings while retaining fixture-only HTTP connectors.
class CronSettingsFixture extends Settings {
    public array $fixtureConnectors = [];
    public function reload(): void {
        parent::reload();
        foreach ($this->connectors as $key => $connector) {
            $this->connectors[$key] = $this->fixtureConnectors[$connector->id] ?? $connector;
        }
        foreach (array_merge($this->plugins, $this->themes) as $extension) {
            $extension->connector = $this->getConnectorById($extension->connectorId);
        }
    }
    public static function fromSettings(Settings $settings): self {
        $fresh = new self();
        $fresh->fixtureConnectors = array_column($settings->connectors, null, 'id');
        $fresh->reload();
        return $fresh;
    }
}

$beforeCron = $checks;
$cronGlobalNames = ['wp_filter', 'multisite', 'bundle_network', 'fixture_blog_id', 'fixture_main_sites',
    'cron_events', 'admin_schedule_calls', 'storage', 'invalidated', 'saves'];
$savedCronGlobals = [];
foreach ($cronGlobalNames as $name) {
    if (array_key_exists($name, $GLOBALS)) $savedCronGlobals[$name] = $GLOBALS[$name];
}
$config = new Config();
$checkHook = $config->getCronActionHook();
$emailHook = $config->getCronEmailActionHook();
$continuationHook = $config->getCronContinuationHook();

foreach ([
    ['first network', true, 1, 1, 1, true],
    ['additional network', true, 2, 42, 42, true],
    ['relocated main site', true, 1, 7, 7, true],
    ['first-network subsite', true, 1, 2, 1, true],
    ['additional-network subsite', true, 2, 43, 42, true],
    ['single site', false, 1, 1, 1, true],
    ['single site with non-default ID', false, 1, 7, 7, true],
    ['additional network without email', true, 2, 42, 42, false],
] as [$label, $isMultisite, $network, $site, $mainSite, $emailEnabled]) {
    foreach ([false, true] as $existingJobs) {
        $wp_filter = [];
        $multisite = $isMultisite;
        $bundle_network = $network;
        $fixture_blog_id = $site;
        $fixture_main_sites = [1 => 1, 2 => 42];
        $fixture_main_sites[$network] = $mainSite;
        [$settings] = adminSaveFixture();
        $settings->themes = [];
        $settings->options['update_check_schedule'] = $network === 2 ? 'hourly' : 'daily';
        $settings->options['email_updates_enabled'] = $emailEnabled;
        $settings->options['email_schedule'] = $network === 2 ? 'rrze_updater_weekly' : 'rrze_updater_monthly';
        check($settings->save(), 'Persist this network’s configured schedules.');
        $settings = CronSettingsFixture::fromSettings($settings);
        $controller = new CronControllerFixture($settings);
        $otherSite = $network === 2 ? 1 : 42;
        $oldEvent = ['time' => 1234567890, 'schedule' => 'twicedaily'];
        $cron_events = [
            $site => ['unrelated_hook' => $oldEvent],
            $otherSite => [$checkHook => $oldEvent, $emailHook => $oldEvent],
        ];
        if ($existingJobs) {
            $cron_events[$site][$checkHook] = $cron_events[$site][$emailHook] = $oldEvent;
            $cron_events[$site][$continuationHook] = ['time' => 1234567890, 'schedule' => false];
        }
        $otherEvents = $cron_events[$otherSite];
        $cron = new Cron($settings, $controller);
        $shouldRun = !$isMultisite || $site === $mainSite;
        check((has_action($checkHook, [$cron, 'runEvents']) !== false) === $shouldRun,
            "$label registers update checks only on its own main site.");
        check((has_action($emailHook, [$cron, 'sendUpdateEmail']) !== false) === $shouldRun,
            "$label registers email callbacks only on its own main site.");
        check((has_action('init', [$cron, 'activateScheduledEvents']) !== false) === $shouldRun,
            "$label activates schedules only on its own main site.");
        check((has_action($continuationHook, [$cron, 'resumeEvents']) !== false) === $shouldRun,
            "$label resumes queued checks only on its own main site.");

        do_action('init');
        if ($shouldRun) {
            check(wp_get_schedule($checkHook) === $settings->options['update_check_schedule'],
                "$label creates or repairs its update schedule using its settings.");
            check(wp_get_schedule($emailHook) === ($emailEnabled ? $settings->options['email_schedule'] : false),
                "$label honors its email schedule and enablement setting.");
            $schedules = apply_filters('cron_schedules', []);
            check($schedules['rrze_updater_weekly']['interval'] === WEEK_IN_SECONDS
                && $schedules['rrze_updater_monthly']['interval'] === 30 * DAY_IN_SECONDS,
                "$label registers the custom email recurrence intervals.");
            $scheduledEvents = $cron_events;
            $scheduledCalls = count(array_filter($admin_schedule_calls, static fn($call) => $call[0] === 'schedule'));
            do_action('init');
            check($cron_events === $scheduledEvents
                && count(array_filter($admin_schedule_calls, static fn($call) => $call[0] === 'schedule')) === $scheduledCalls,
                'Repeated initialization preserves due times and does not schedule duplicate jobs.');
        } else {
            check(wp_get_schedule($checkHook) === false && wp_get_schedule($emailHook) === false,
                "$label removes its stale jobs without recreating them.");
            check(wp_next_scheduled($continuationHook) === false, 'Subsite cleanup also removes stale single continuation events.');
        }

        // The real update callback uses a fixture connector: no HTTP or file changes.
        do_action($checkHook);
        check($controller->synchronizations === ($shouldRun ? 1 : 0),
            "$label dispatches update work only when it owns the network’s jobs.");
        $stored = new Settings();
        check(($stored->plugins[0]->lastChecked > 0) === $shouldRun,
            "$label persists update-check results only on the scheduling site.");
        check($cron_events[$otherSite] === $otherEvents && $cron_events[$site]['unrelated_hook'] === $oldEvent,
            'Scheduling and cleanup leave other sites and unrelated jobs untouched.');
    }
}

foreach ($cronGlobalNames as $name) {
    if (array_key_exists($name, $savedCronGlobals)) $GLOBALS[$name] = $savedCronGlobals[$name];
    else unset($GLOBALS[$name]);
}
echo 'Passed ' . ($checks - $beforeCron) . " network cron scheduling checks.\n";

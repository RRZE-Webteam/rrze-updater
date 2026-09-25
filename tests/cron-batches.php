<?php
require_once __DIR__ . '/cron.php';

use RRZE\Updater\{Config, Cron, Settings, UpdateCheckBatch};
use RRZE\Updater\Core\{Plugin, Theme};

class BatchConnectorFixture extends AdminSaveConnectorFixture {
    public array $checked = [];
    public $beforeCheck = null;
    public function getRemoteCommit(string $repository, string $branch): string {
        $this->checked[] = $repository;
        check(wp_next_scheduled((new Config())->getCronContinuationHook()) !== false,
            'Recovery is scheduled before any remote request.');
        $pending = $GLOBALS['storage']['rrze_updater_check_batch']['pending'];
        check(end($pending)['attempts'] > 0, 'The attempt is checkpointed before HTTP.');
        if ($this->beforeCheck) ($this->beforeCheck)($repository);
        return 'checked-' . $repository;
    }
}

class BatchCheckpointSettingsFixture extends CronSettingsFixture {
    public bool $failCheckpoint = false;
    public function save() {
        $result = parent::save();
        if ($result && $this->failCheckpoint) $GLOBALS['fail_save'] = true;
        return $result;
    }
}

function checkBatchFixture(int $plugins = 3, int $themes = 0): array {
    $GLOBALS['wp_filter'] = $GLOBALS['storage'] = $GLOBALS['cron_events'] = $GLOBALS['batch_errors'] = [];
    $GLOBALS['multisite'] = true; $GLOBALS['bundle_network'] = $GLOBALS['fixture_blog_id'] = 1;
    $GLOBALS['fixture_main_sites'] = [1 => 1, 2 => 42];
    $GLOBALS['fail_save'] = $GLOBALS['wpdb']->deny = false;
    $GLOBALS['cron_schedule_failure'] = '';
    $connector = new BatchConnectorFixture();
    $connector->id = 'batch-connector'; $connector->owner = 'owner'; $connector->token = 'fixture-token';
    $settings = new BatchCheckpointSettingsFixture();
    $settings->connectors = [$connector];
    $settings->fixtureConnectors = [$connector->id => $connector];
    $settings->options['update_check_delay'] = 5;
    foreach (['plugins' => $plugins, 'themes' => $themes] as $property => $count) {
        for ($index = 1; $index <= $count; $index++) {
            $name = $property . '-' . $index;
            $class = $property === 'plugins' ? Plugin::class : Theme::class;
            $extension = $class::createFromArray(['id' => $name, 'repository' => $name,
                'installationFolder' => $name, 'connectorId' => $connector->id, 'updates' => 'commits']);
            $extension->connector = $connector;
            $settings->{$property}[] = $extension;
        }
    }
    check($settings->save(), 'Persist the batch fixture.');
    $controller = new CronControllerFixture($settings);
    add_action('rrze.log.error', static function ($message, $context) { $GLOBALS['batch_errors'][] = $context['error'] ?? $message; }, 10, 2);
    return ['settings' => $settings, 'controller' => $controller, 'connector' => $connector,
        'batch' => new UpdateCheckBatch($settings, $controller)];
}

function resumeCheckBatch(array $fixture): void {
    // A fresh request rehydrates settings; WordPress consumes single events before dispatch.
    unset($GLOBALS['cron_events'][get_current_blog_id()][(new Config())->getCronContinuationHook()]);
    $settings = CronSettingsFixture::fromSettings($fixture['settings']);
    (new UpdateCheckBatch($settings, new CronControllerFixture($settings)))->run(false);
}

function drainCheckBatch(array $fixture): void {
    for ($request = 0; !empty($GLOBALS['storage']['rrze_updater_check_batch']['pending']); $request++) {
        check($request < 20, 'The batch must finish without an endless retry loop.');
        resumeCheckBatch($fixture);
    }
}

$beforeBatches = $checks;
$continuation = (new Config())->getCronContinuationHook();
$f = checkBatchFixture();
new Cron($f['settings'], $f['controller']);
do_action($continuation); // A stale empty continuation must not consume the regular cycle.
do_action((new Config())->getCronActionHook());
do_action($continuation);
do_action((new Config())->getCronActionHook());
check($f['connector']->checked === ['plugins-1'], 'Recurring and continuation hooks due together still check only one repository per request.');
drainCheckBatch($f);

$f = checkBatchFixture(3, 1);
$f['batch']->run();
check($f['connector']->checked === ['plugins-1'], 'One cron request checks exactly one repository.');
check((new Settings())->plugins[0]->lastChecked > 0 && (new Settings())->plugins[1]->lastChecked === 0,
    'Each completed result is persisted before the next repository is checked.');
check(count($storage['rrze_updater_check_batch']['pending']) === 3, 'The remaining queue is persisted.');
check(wp_next_scheduled($continuation) >= time() + 4, 'The configured delay is scheduled instead of sleeping.');
drainCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2', 'plugins-3', 'themes-1'], 'Fresh requests resume both plugins and themes without repeating successes.');
check(wp_next_scheduled($continuation) === false && $f['controller']->synchronizations === 1, 'Completion clears recovery and only the initial request synchronizes installed files.');
$f['batch']->run(false);
check(count($f['connector']->checked) === 4, 'A stale continuation does not start a new cycle.');

// Preserve the durable checkpoint as it stood during HTTP, simulating a killed worker.
$f = checkBatchFixture(); $f['batch']->run();
$snapshot = null;
$f['connector']->beforeCheck = static function () use (&$snapshot) { $snapshot = $GLOBALS['storage']; };
resumeCheckBatch($f);
$storage = $snapshot;
$f['connector']->beforeCheck = null;
resumeCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2', 'plugins-3'], 'Recovery advances independent work ahead of an interrupted repository.');
drainCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2', 'plugins-3', 'plugins-2'], 'The interrupted item is retried without losing earlier results.');

$f = checkBatchFixture(); $f['batch']->run();
$f['connector']->beforeCheck = static function () { $GLOBALS['fail_save'] = true; };
resumeCheckBatch($f);
check((new Settings())->plugins[0]->lastChecked > 0 && (new Settings())->plugins[1]->lastChecked === 0,
    'A failed result save preserves earlier results and never claims that the failed save completed.');
check(count($storage['rrze_updater_check_batch']['pending']) === 2 && $batch_errors !== [], 'Failed saves retain pending work and are logged.');
$fail_save = false; $f['connector']->beforeCheck = null;
drainCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2', 'plugins-3', 'plugins-2'], 'A failed save is retried after independent repositories.');

$f = checkBatchFixture(1);
$f['settings']->failCheckpoint = true;
$f['batch']->run();
check((new Settings())->plugins[0]->lastChecked > 0 && count($storage['rrze_updater_check_batch']['pending']) === 1,
    'A checkpoint failure can leave a saved result in the queue.');
$fail_save = false;
resumeCheckBatch($f);
check($f['connector']->checked === ['plugins-1'] && $storage['rrze_updater_check_batch']['pending'] === [],
    'Resume recognizes already persisted results after a checkpoint failure.');

$f = checkBatchFixture(); $fail_save = true;
$f['batch']->run();
check($f['connector']->checked === [] && $batch_errors !== [], 'Queue creation failures are reported before remote work starts.');
$fail_save = false;
$f['batch']->run();
check($f['connector']->checked === ['plugins-1'], 'Queue creation can be retried after storage recovers.');

foreach (['single', 'clear'] as $failure) {
    $f = checkBatchFixture(); $cron_schedule_failure = $failure;
    $f['batch']->run();
    check($f['connector']->checked === [] && $batch_errors !== [], 'No HTTP starts without a persisted recovery event.');
    $cron_schedule_failure = '';
    $f['batch']->ensureContinuation();
    check(wp_next_scheduled($continuation) !== false, 'A later main-site request repairs missing continuation scheduling.');
    resumeCheckBatch($f);
    check($f['connector']->checked === ['plugins-1'], 'Scheduling recovery resumes the existing queue.');
}

$f = checkBatchFixture(); $f['batch']->run();
$lock = 'rrze_checks_' . md5(DB_NAME . '|' . $wpdb->base_prefix . '|network:1');
$wpdb->locks[$lock] = true;
$snapshot = $storage;
resumeCheckBatch($f);
check($storage === $snapshot && $f['connector']->checked === ['plugins-1'], 'Overlapping cron workers cannot advance or process the same queue.');
unset($wpdb->locks[$lock]);
resumeCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2'], 'A released connection lock allows the next worker to resume.');

$f = checkBatchFixture(); $f['batch']->run();
$concurrent = new Settings();
$concurrent->plugins = array_values(array_filter($concurrent->plugins, static fn($extension) => $extension->id !== 'plugins-2'));
check($concurrent->save(), 'Remove a queued repository in another request.');
drainCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-3'], 'Queued entries removed by an administrator are skipped.');

$f = checkBatchFixture(2);
$f['connector']->beforeCheck = static function ($repository) {
    if ($repository === 'plugins-1') throw new RuntimeException('Exception with secret-token');
};
$f['batch']->run(); drainCheckBatch($f);
check($f['connector']->checked === ['plugins-1', 'plugins-2'], 'A repository exception does not prevent later checks.');
check((new Settings())->plugins[0]->lastError !== '' && !str_contains(serialize([$storage, $batch_errors]), 'secret-token'),
    'Unexpected errors are persisted and logged without exposing exception credentials.');

$f = checkBatchFixture(2);
$storage['rrze_updater_check_batch'] = ['started' => time(), 'pending' => [
    ['type' => 'plugin', 'id' => 'plugins-1', 'attempts' => 3],
    ['type' => 'plugin', 'id' => 'plugins-2', 'attempts' => 0],
]];
resumeCheckBatch($f); drainCheckBatch($f);
check($f['connector']->checked === ['plugins-2'] && $batch_errors !== [], 'Repeated interruptions are reported and cannot block the queue forever.');
check((new Settings())->plugins[0]->lastChecked === 0, 'Exhausting retries does not invent a successful check.');
$f['batch']->run();
check($f['connector']->checked === ['plugins-2', 'plugins-1'], 'The next regular cycle retries an item that exhausted its previous attempts.');

$f = checkBatchFixture(); $f['batch']->run();
$snapshot = $storage['rrze_updater_check_batch'];
Cron::clearSchedule();
check(wp_next_scheduled($continuation) === false && $storage['rrze_updater_check_batch'] === $snapshot,
    'Clearing schedules removes continuation events while retaining resumable progress.');
$f['batch']->ensureContinuation();
check(wp_next_scheduled($continuation) !== false, 'Reactivation or settings changes can resume the retained queue.');

// WordPress selects a different option store for each network/site request.
$firstStorage = $storage;
$bundle_network = 2; $fixture_blog_id = 42; $storage = [];
$other = new CronSettingsFixture();
(new UpdateCheckBatch($other, new CronControllerFixture($other)))->run(false);
check($storage === [], 'A continuation in another network cannot adopt the first network’s queue.');
$bundle_network = $fixture_blog_id = 1; $storage = $firstStorage;
drainCheckBatch($f);
check(count($f['connector']->checked) === 3, 'The original network resumes its own remaining work.');

$fail_save = false; $cron_schedule_failure = '';
unset($fixture_blog_id, $fixture_main_sites);
echo 'Passed ' . ($checks - $beforeBatches) . " resumable cron batch checks.\n";

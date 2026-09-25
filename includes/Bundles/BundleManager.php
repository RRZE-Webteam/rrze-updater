<?php

namespace RRZE\Updater\Bundles;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\RepositoryManager;
use RRZE\Updater\Core\Connector;
use RRZE\Updater\Core\RepositoryDiscovery;
use RRZE\Updater\Core\RepositoryInspector;
use RRZE\Updater\Settings;
use RRZE\Updater\Upgrader\RepositoryInstaller;
use WP_Error;

/** One state transition or one repository operation per request. */
class BundleManager
{
    public function __construct(
        private Catalog $catalog = new Catalog(),
        private JobStore $store = new JobStore(),
        private ?Settings $settings = null,
        private RepositoryInstaller $installer = new RepositoryInstaller()
    ) {}

    public function handle(string $action, string $jobId = '', int $revision = -1, array $connectors = [], array $selection = [], array $registrations = []): array|WP_Error
    {
        if ($action === 'status') {
            return ['job' => $this->store->load()];
        }
        if (!in_array($action, ['check', 'check_custom', 'step', 'install', 'install_anyways', 'retry', 'cancel'], true)) {
            return $this->error('invalid_action', 'Unknown bundle action.');
        }
        return $this->store->withLock(function () use ($action, $jobId, $revision, $connectors, $selection, $registrations) {
            $job = $this->store->load();
            if ($job && ($job['id'] !== $jobId || $job['revision'] !== $revision)) {
                return $this->error('stale_job', 'The bundle changed in another request. Reload its progress before continuing.');
            }
            // Cancellation only changes queue state. It remains available even
            // when credentials, the shipped catalog, or file permissions changed.
            // The shared lock ensures an in-flight installation finishes first.
            if ($action === 'cancel') {
                if (!$job) {
                    return $this->error('missing_job', 'There is no installation process to cancel.');
                }
                if (in_array($job['phase'], ['complete', 'cancelled'], true)) {
                    return ['job' => $job];
                }
                foreach ($job['items'] as &$item) {
                    if (in_array($item['status'], ['pending', 'checking', 'ready', 'queued'], true)) {
                        $item['status'] = 'cancelled';
                    } elseif ($item['status'] === 'installing') {
                        // With the lock held, this is an interrupted request, not
                        // an active installer. Its filesystem outcome is unknown.
                        $item['status'] = 'interrupted';
                        $item['message'] = __('Processing was interrupted. Inspect existing files before starting a new installation.', 'rrze-updater');
                    }
                }
                unset($item);
                $job['phase'] = 'cancelled';
                $job['cancelled_at'] = time();
                $job['revision']++;
                $this->store->save($job);
                return ['job' => $job];
            }
            if (($job['phase'] ?? '') === 'cancelled' && !in_array($action, ['check', 'check_custom'], true)) {
                return $this->error('cancelled_job', 'This process was cancelled. Start a new review to install more repositories.');
            }
            // Read settings after acquiring the lock, never before a concurrent
            // installation has finished saving its association.
            $settings = $this->settings ?? new Settings();
            $repositories = new RepositoryManager($settings, $this->installer);
            if ($action === 'check_custom') {
                $job = $this->newCustomJob($settings, $connectors['browse'] ?? '', $selection);
                if (is_wp_error($job)) {
                    return $job;
                }
            } elseif ($action === 'check') {
                $selected = [];
                foreach ($this->catalog->get()['connectors'] as $provider => $requirement) {
                    if (!isset($connectors[$provider]) || !is_string($connectors[$provider]) || trim($connectors[$provider]) === '') {
                        return $this->error('selection_required', 'Select a connector for each provider before checking prerequisites.');
                    }
                    $selected[$provider] = trim($connectors[$provider]);
                }
                $job = $this->newJob($selected);
            } elseif (!$job || (($job['source'] ?? 'recommended') !== 'custom' && $job['catalog'] !== $this->catalog->fingerprint())) {
                return $this->error('missing_job', 'Run prerequisite checks for the current bundle first.');
            } elseif (!isset($job['connectors'])) {
                return $this->error('selection_required', 'Select connectors and run prerequisite checks again for this older job.');
            } elseif (in_array($action, ['install', 'install_anyways'], true)) {
                $started = $this->startInstallation($job, $action === 'install_anyways', $registrations);
                if (is_wp_error($started)) {
                    return $started;
                }
            } elseif ($action === 'retry') {
                if ($job['phase'] === 'blocked') {
                    if (($job['source'] ?? '') === 'custom') {
                        unset($job['registrations']);
                        $job['id'] = wp_generate_uuid4();
                        $job['created_at'] = time();
                        $job['phase'] = 'checking';
                        foreach ($job['items'] as &$item) {
                            unset($item['plan'], $item['options']);
                            $item['status'] = 'pending';
                            $item['message'] = '';
                            $item['dependencies'] = [];
                            $item['type'] = '';
                        }
                        unset($item);
                    } else {
                        $job = $this->newJob($job['connectors']);
                    }
                } elseif ($job['phase'] === 'complete') {
                    foreach ($job['items'] as &$item) {
                        if ($item['status'] === 'failed') {
                            $item['status'] = 'queued';
                            $item['message'] = '';
                        }
                    }
                    unset($item);
                    $job['phase'] = 'running';
                } else {
                    return $this->error('invalid_retry', 'Resume the current job before retrying failed entries.');
                }
            } elseif ($action === 'step') {
                if ($job['phase'] === 'checking') {
                    $this->checkNext($job, $settings, $repositories);
                } elseif ($job['phase'] === 'running') {
                    $this->installNext($job, $settings, $repositories);
                }
            }
            $job['revision']++;
            $this->store->save($job);
            return ['job' => $job];
        });
    }

    private function startInstallation(array &$job, bool $skipErrors, array $registrations): true|WP_Error
    {
        if ($job['phase'] !== ($skipErrors ? 'blocked' : 'ready')) {
            return $this->error('not_ready', 'Complete prerequisite checks before installing. Use Install anyways to skip prerequisite errors.');
        }
        if ($job['created_at'] < time() - DAY_IN_SECONDS) {
            return $this->error('expired_plan', 'The prerequisite checks are older than a day. Run them again.');
        }
        if (!array_is_list($registrations) || count($registrations) > count($job['items'])) {
            return $this->error('invalid_registrations', 'Select only existing, unmanaged entries from this review.');
        }
        foreach ($registrations as $id) {
            if (!is_string($id) || ($job['items'][$id]['status'] ?? '') !== 'ready'
                || ($job['items'][$id]['plan']['action'] ?? '') !== 'register') {
                return $this->error('invalid_registrations', 'Select only existing, unmanaged entries from this review.');
            }
        }
        $job['registrations'] = array_values(array_unique($registrations));
        foreach ($job['items'] as &$item) {
            $item['status'] = $item['status'] === 'ready' ? 'queued' : 'prerequisite_skipped';
            if ($item['status'] === 'queued' && $item['plan']['action'] === 'register'
                && !in_array($item['id'], $job['registrations'], true)) {
                $this->skipRegistration($item);
            }
        }
        unset($item);
        // Propagate skipped prerequisites to all dependents. Such skips must not
        // count as successful "already managed" entries during execution.
        do {
            $changed = false;
            foreach ($job['items'] as &$item) {
                if ($item['status'] !== 'queued') {
                    continue;
                }
                foreach ($item['dependencies'] as $dependency) {
                    if (($job['items'][$dependency]['status'] ?? 'prerequisite_skipped') === 'prerequisite_skipped') {
                        $item['status'] = 'prerequisite_skipped';
                        $item['message'] = sprintf('Skipped because required parent %s did not pass prerequisite checks.', $dependency);
                        $changed = true;
                        break;
                    }
                }
            }
            unset($item);
        } while ($changed);
        if (!$this->hasStatus($job, ['queued', 'registration_skipped'])) {
            return $this->error('nothing_to_install', 'No entries can be processed. Resolve prerequisite errors and run the checks again.');
        }
        $job['phase'] = $this->hasStatus($job, ['queued']) ? 'running' : 'complete';
        return true;
    }

    private function newJob(array $connectors): array
    {
        $items = [];
        foreach ($this->catalog->get()['items'] as $entry) {
            $items[$entry['id']] = $entry + ['status' => 'pending', 'message' => '', 'dependencies' => []];
        }
        return [
            'id' => wp_generate_uuid4(), 'revision' => 0, 'created_at' => time(),
            'catalog' => $this->catalog->fingerprint(), 'phase' => 'checking', 'items' => $items,
            'connectors' => $connectors, 'source' => 'recommended',
        ];
    }

    private function newCustomJob(Settings $settings, string $connectorId, array $selection): array|WP_Error
    {
        $connector = $settings->getConnectorById($connectorId);
        if (!$connector || !in_array($connector->getType(), ['github', 'gitlab'], true) || trim((string) $connector->token) === '') {
            return $this->error('connector', 'Select a connector with an access token.');
        }
        if (!$selection || count($selection) > 100) {
            return $this->error('selection', 'Select between 1 and 100 repositories.');
        }
        $items = [];
        foreach ($selection as $selected) {
            if (!is_array($selected)) {
                return $this->error('selection', 'Invalid repository selection.');
            }
            $repo = $selected['repository'] ?? null;
            $folder = $selected['folder'] ?? $repo;
            $branch = $selected['branch'] ?? null;
            foreach ([$repo, $folder] as $name) {
                if (!is_string($name) || strlen($name) > 200 || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $name) || str_contains($name, '..')) {
                    return $this->error('selection', 'Repository and installation folder must be single names without path separators.');
                }
            }
            if (!is_string($branch) || $branch === '' || strlen($branch) > 255 || preg_match('/[\x00-\x20\x7f]/', $branch)) {
                return $this->error('selection', 'Select a valid branch for every repository.');
            }
            $id = hash('sha256', $connectorId . '/' . strtolower($repo));
            if (isset($items[$id])) {
                return $this->error('selection', 'A repository can only be selected once.');
            }
            $items[$id] = ['id' => $id, 'provider' => 'browse', 'repository' => $repo, 'folder' => $folder,
                'branch' => $branch, 'updates' => 'commits', 'type' => '', 'status' => 'pending', 'message' => '', 'dependencies' => []];
        }
        return ['id' => wp_generate_uuid4(), 'revision' => 0, 'created_at' => time(), 'catalog' => '',
            'source' => 'custom', 'phase' => 'checking', 'items' => $items, 'connectors' => ['browse' => $connectorId],
            'requirements' => ['browse' => ['type' => $connector->getType(), 'owner' => $connector->owner,
                'host' => (string) wp_parse_url($connector->getUrl(''), PHP_URL_HOST),
                'apiUri' => $connector->apiUri ?? '']],
        ];
    }

    /** Only identifiers and display metadata leave the settings layer. */
    public function connectorChoices(): array
    {
        $settings = $this->settings ?? new Settings();
        $choices = [];
        foreach ($this->catalog->get()['connectors'] as $provider => $requirement) {
            $choices[$provider] = [];
            foreach ($settings->connectors as $connector) {
                if ($this->matchesConnector($connector, $requirement)) {
                    $choices[$provider][] = [
                        'id' => $connector->id, 'name' => $connector->display ?: $requirement['host'],
                        'has_token' => is_string($connector->token) && trim($connector->token) !== '',
                    ];
                }
            }
        }
        return $choices;
    }

    private function matchesConnector(Connector $connector, array $requirement): bool
    {
        // GitHub owner names are case-insensitive. Preserve the configured
        // spelling and keep other providers' owner matching unchanged.
        $ownerMatches = $requirement['type'] === 'github'
            ? strcasecmp((string) $connector->owner, $requirement['owner']) === 0
            : $connector->owner === $requirement['owner'];
        return $connector->getType() === $requirement['type']
            && strcasecmp((string) wp_parse_url($connector->getUrl(''), PHP_URL_HOST), $requirement['host']) === 0
            && $ownerMatches;
    }

    private function connector(array $entry, Settings $settings, array $selected, ?array $requirements = null): string|WP_Error
    {
        $requirement = ($requirements ?? $this->catalog->get()['connectors'])[$entry['provider']];
        $connector = $settings->getConnectorById($selected[$entry['provider']] ?? '');
        if (!$connector || !$this->matchesConnector($connector, $requirement)
            || (isset($requirement['apiUri']) && ($connector->apiUri ?? '') !== $requirement['apiUri'])) {
            return $this->error('connector', sprintf('The selected connector must match %s / %s. Select a compatible connector and run prerequisite checks again.', $requirement['host'], $requirement['owner']));
        }
        if (!is_string($connector->token) || trim($connector->token) === '') {
            return $this->error('token', sprintf('Configure an access token for the selected %s / %s connector in Services.', $requirement['host'], $requirement['owner']));
        }
        return $connector->id;
    }

    private function checkNext(array &$job, Settings $settings, RepositoryManager $repositories): void
    {
        foreach ($job['items'] as &$item) {
            if (!in_array($item['status'], ['pending', 'checking'], true)) {
                continue;
            }
            $item['status'] = 'checking';
            $this->store->save($job);
            $connector = $this->connector($item, $settings, $job['connectors'], $job['requirements'] ?? null);
            if (is_wp_error($connector)) {
                $result = $connector;
            } else {
                $item['options'] = [
                    'connector' => $connector, 'folder' => $item['folder'],
                    'branch' => $item['branch'], 'updates' => $item['updates'],
                ];
                try {
                    if (($job['source'] ?? '') === 'custom') {
                        $inspection = (new RepositoryInspector())->inspect(
                            new RepositoryDiscovery($settings->getConnectorById($connector)), $item['repository'], $item['branch']
                        );
                        if (is_wp_error($inspection)) {
                            $result = $inspection;
                        } else {
                            $item['type'] = $inspection['type'];
                            $result = $repositories->prepareDiscovered($item['repository'], $item['options'], $inspection);
                        }
                    } else {
                        $result = $repositories->prepare($item['type'], $item['repository'], $item['options']);
                    }
                } catch (\Throwable $exception) {
                    $result = $this->error('check_failed', 'The repository check was interrupted. Check access and retry.');
                }
            }
            if (is_wp_error($result)) {
                $item['status'] = 'error';
                $item['message'] = $this->safeMessage($result, $settings);
            } else {
                $item['status'] = 'ready';
                $result['warning'] = $this->redact((string) $result['warning'], $settings);
                $item['plan'] = $result;
                $item['message'] = $this->redact((string) $result['warning'], $settings);
            }
            break;
        }
        unset($item);
        if (!$this->hasStatus($job, ['pending', 'checking'])) {
            $this->checkDependencies($job);
            $job['phase'] = $this->hasStatus($job, ['error']) ? 'blocked' : 'ready';
        }
    }

    private function checkDependencies(array &$job): void
    {
        $destinations = [];
        foreach ($job['items'] as $id => &$item) {
            if ($item['status'] !== 'ready') {
                continue;
            }
            $destination = strtolower($item['type'] . '/' . $item['folder']);
            if (isset($destinations[$destination])) {
                $other = $destinations[$destination];
                $job['items'][$other]['status'] = $item['status'] = 'error';
                $job['items'][$other]['message'] = $item['message'] = 'Multiple selected repositories use the same installation folder.';
            } else {
                $destinations[$destination] = $id;
            }
        }
        unset($item);
        $folders = [];
        foreach ($job['items'] as $id => $item) {
            if ($item['type'] === 'theme') {
                $folders[$item['folder']] = $id;
            }
        }
        foreach ($job['items'] as $id => &$item) {
            if ($item['status'] !== 'ready' || empty($item['plan']['parent'])) {
                continue;
            }
            $parent = $item['plan']['parent'];
            if (isset($folders[$parent])) {
                $item['dependencies'] = [$folders[$parent]];
            } elseif (!$this->installer->isInstalled('theme', $parent)) {
                $item['status'] = 'error';
                $item['message'] = sprintf('Required parent theme %s is not installed or included in this bundle.', $parent);
            }
        }
        unset($item);
        // Topological ordering also detects cycles before any files are installed.
        $ordered = [];
        $remaining = $job['items'];
        do {
            $progress = false;
            foreach ($remaining as $id => $item) {
                if (array_diff($item['dependencies'], array_keys($ordered))) {
                    continue;
                }
                $ordered[$id] = $item;
                unset($remaining[$id]);
                $progress = true;
            }
        } while ($remaining && $progress);
        foreach ($remaining as &$item) {
            $item['status'] = 'error';
            $item['message'] = 'Theme dependencies contain a cycle. Correct the bundle or theme headers.';
        }
        unset($item);
        $job['items'] = $ordered + $remaining;
    }

    private function installNext(array &$job, Settings $settings, RepositoryManager $repositories): void
    {
        foreach ($job['items'] as &$item) {
            if (!in_array($item['status'], ['queued', 'installing'], true)) {
                continue;
            }
            // Also protect jobs queued before registration consent was introduced.
            if ($item['plan']['action'] === 'register' && !in_array($item['id'], $job['registrations'] ?? [], true)) {
                $this->skipRegistration($item);
                break;
            }
            $item['status'] = 'installing';
            $this->store->save($job);
            $result = null;
            foreach ($item['dependencies'] as $dependency) {
                if (!in_array($job['items'][$dependency]['status'], ['done', 'skipped', 'registration_skipped'], true)) {
                    $result = $this->error('dependency_failed', 'A required parent theme failed. Retry after resolving its error.');
                    break;
                }
            }
            $connector = $this->connector($item, $settings, $job['connectors'], $job['requirements'] ?? null);
            if (!$result && (is_wp_error($connector) || $connector !== $item['options']['connector'])) {
                $result = is_wp_error($connector) ? $connector : $this->error('connector_changed', 'The connector changed. Run prerequisite checks again.');
            }
            // A parent can have been removed since preflight. Never let core
            // fetch an unreviewed parent from WordPress.org as a side effect.
            $parent = $item['plan']['parent'];
            if (!$result && $parent !== '' && !$this->installer->isInstalled('theme', $parent)) {
                $result = $this->error('parent_missing', sprintf('Required parent theme %s is no longer installed.', $parent));
            }
            if (!$result) {
                try {
                    $result = $repositories->applyPrepared($item['type'], $item['repository'], $item['options'], $item['plan']);
                } catch (\Throwable $exception) {
                    $result = $this->error('install_failed', 'The operation was interrupted. Inspect the destination and retry; existing files will not be overwritten.');
                }
            }
            $item['status'] = is_wp_error($result) ? 'failed' : ($item['plan']['action'] === 'skip' ? 'skipped' : 'done');
            $item['message'] = $this->redact(is_wp_error($result) ? $result->get_error_message() : $result, $settings);
            if (!is_wp_error($result) && $repositories->getWarnings()) {
                $item['message'] .= ' ' . $this->redact(implode(' ', $repositories->getWarnings()), $settings);
            }
            break;
        }
        unset($item);
        if (!$this->hasStatus($job, ['queued', 'installing'])) {
            $job['phase'] = 'complete';
        }
    }

    private function hasStatus(array $job, array $statuses): bool
    {
        return (bool) array_filter($job['items'], static fn($item) => in_array($item['status'], $statuses, true));
    }

    private function skipRegistration(array &$item): void
    {
        $item['status'] = 'registration_skipped';
        $item['message'] = __('Left unmanaged. Existing files were kept; registration was not selected.', 'rrze-updater');
    }

    private function safeMessage(WP_Error $error, Settings $settings): string
    {
        return $this->redact($error->get_error_message(), $settings);
    }

    private function redact(string $message, Settings $settings): string
    {
        foreach ($settings->connectors as $connector) {
            if (is_string($connector->token) && $connector->token !== '') {
                $message = str_replace([$connector->token, rawurlencode($connector->token)], '[redacted]', $message);
            }
        }
        return $message;
    }

    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error('rrze_updater_bundle_' . $code, $message);
    }
}

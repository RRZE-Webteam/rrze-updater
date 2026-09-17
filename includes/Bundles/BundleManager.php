<?php

namespace RRZE\Updater\Bundles;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\RepositoryManager;
use RRZE\Updater\Core\Connector;
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

    public function handle(string $action, string $jobId = '', int $revision = -1, array $connectors = []): array|WP_Error
    {
        if ($action === 'status') {
            return ['job' => $this->store->load()];
        }
        if (!in_array($action, ['check', 'step', 'install', 'install_anyways', 'retry'], true)) {
            return $this->error('invalid_action', 'Unknown bundle action.');
        }
        return $this->store->withLock(function () use ($action, $jobId, $revision, $connectors) {
            $job = $this->store->load();
            if ($job && ($job['id'] !== $jobId || $job['revision'] !== $revision)) {
                return $this->error('stale_job', 'The bundle changed in another request. Reload its progress before continuing.');
            }
            // Read settings after acquiring the lock, never before a concurrent
            // installation has finished saving its association.
            $settings = $this->settings ?? new Settings();
            $repositories = new RepositoryManager($settings, $this->installer);
            if ($action === 'check') {
                $selected = [];
                foreach ($this->catalog->get()['connectors'] as $provider => $requirement) {
                    if (!isset($connectors[$provider]) || !is_string($connectors[$provider]) || trim($connectors[$provider]) === '') {
                        return $this->error('selection_required', 'Select a connector for each provider before checking prerequisites.');
                    }
                    $selected[$provider] = trim($connectors[$provider]);
                }
                $job = $this->newJob($selected);
            } elseif (!$job || $job['catalog'] !== $this->catalog->fingerprint()) {
                return $this->error('missing_job', 'Run prerequisite checks for the current bundle first.');
            } elseif (!isset($job['connectors'])) {
                return $this->error('selection_required', 'Select connectors and run prerequisite checks again for this older job.');
            } elseif (in_array($action, ['install', 'install_anyways'], true)) {
                $started = $this->startInstallation($job, $action === 'install_anyways');
                if (is_wp_error($started)) {
                    return $started;
                }
            } elseif ($action === 'retry') {
                if ($job['phase'] === 'blocked') {
                    $job = $this->newJob($job['connectors']);
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

    private function startInstallation(array &$job, bool $skipErrors): true|WP_Error
    {
        if ($job['phase'] !== ($skipErrors ? 'blocked' : 'ready')) {
            return $this->error('not_ready', 'Complete prerequisite checks before installing. Use Install anyways to skip prerequisite errors.');
        }
        if ($job['created_at'] < time() - DAY_IN_SECONDS) {
            return $this->error('expired_plan', 'The prerequisite checks are older than a day. Run them again.');
        }
        foreach ($job['items'] as &$item) {
            $item['status'] = $item['status'] === 'ready' ? 'queued' : 'prerequisite_skipped';
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
        if (!$this->hasStatus($job, ['queued'])) {
            return $this->error('nothing_to_install', 'No entries can be processed. Resolve prerequisite errors and run the checks again.');
        }
        $job['phase'] = 'running';
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
            'connectors' => $connectors,
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

    private function connector(array $entry, Settings $settings, array $selected): string|WP_Error
    {
        $requirement = $this->catalog->get()['connectors'][$entry['provider']];
        $connector = $settings->getConnectorById($selected[$entry['provider']] ?? '');
        if (!$connector || !$this->matchesConnector($connector, $requirement)) {
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
            $connector = $this->connector($item, $settings, $job['connectors']);
            if (is_wp_error($connector)) {
                $result = $connector;
            } else {
                $item['options'] = [
                    'connector' => $connector, 'folder' => $item['folder'],
                    'branch' => $item['branch'], 'updates' => $item['updates'],
                ];
                try {
                    $result = $repositories->prepare($item['type'], $item['repository'], $item['options']);
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
            $item['status'] = 'installing';
            $this->store->save($job);
            $result = null;
            foreach ($item['dependencies'] as $dependency) {
                if (!in_array($job['items'][$dependency]['status'], ['done', 'skipped'], true)) {
                    $result = $this->error('dependency_failed', 'A required parent theme failed. Retry after resolving its error.');
                    break;
                }
            }
            $connector = $this->connector($item, $settings, $job['connectors']);
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

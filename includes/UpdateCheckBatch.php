<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

/** Persist a network's queue and check one repository per cron request. */
class UpdateCheckBatch
{
    private const OPTION = 'rrze_updater_check_batch';
    private const RETRY_DELAY = 60;
    private const MAX_ATTEMPTS = 3;

    public function __construct(private Settings $settings, private Controller $controller) {}

    public function hasPending(): bool
    {
        return $this->load() !== null;
    }

    /** Repair a missing continuation on the next main-site request. */
    public function ensureContinuation(): void
    {
        if ($this->hasPending()) {
            $this->scheduleContinuation($this->delay());
        }
    }

    /** A continuation can resume an existing queue, but cannot start a new cycle. */
    public function run(bool $start = true): void
    {
        global $wpdb;
        $scope = is_multisite() ? 'network:' . get_current_network_id() : 'site:' . get_current_blog_id();
        $lock = 'rrze_checks_' . md5(DB_NAME . '|' . $wpdb->base_prefix . '|' . $scope);
        // Connection-owned, so a terminated worker cannot leave a stale lock.
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            $this->logError(__('Another scheduled update check is running, or the check lock is unavailable.', 'rrze-updater'));
            $this->scheduleContinuation(self::RETRY_DELAY);
            return;
        }
        try {
            $this->clearCache();
            $this->settings->reload();
            $job = $this->load();
            if (!$job && $start) {
                if ($this->controller->synchronizeSettings() === false) {
                    $this->logError(__('Could not synchronize repository settings before checking for updates.', 'rrze-updater'));
                    return;
                }
                $job = ['started' => time(), 'pending' => []];
                foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $property => $type) {
                    foreach ($this->settings->$property as $extension) {
                        if (in_array($extension->updates, ['commits', 'tags', 'releases'], true)
                            && (!$extension->lastChecked || $job['started'] - $extension->lastChecked > (new Config())->getCronMinimumCheckInterval())) {
                            $job['pending'][] = ['type' => $type, 'id' => $extension->id, 'attempts' => 0];
                        }
                    }
                }
                if (!$job['pending'] || !$this->save($job)) {
                    return;
                }
            }
            if (!$job) {
                return;
            }

            // Schedule recovery BEFORE HTTP or settings writes. If PHP is killed,
            // this event resumes the persisted queue after the database releases the lock.
            if (!$this->scheduleContinuation(self::RETRY_DELAY, true)) {
                return;
            }
            $item = array_shift($job['pending']);
            $extension = $item['type'] === 'plugin'
                ? $this->settings->getPluginById($item['id']) : $this->settings->getThemeById($item['id']);
            if (!$extension || $extension->lastChecked >= $job['started']) {
                // Removed or already checked (including a saved result whose queue
                // checkpoint failed). Do not repeat the remote request.
                $this->finishStep($job);
                return;
            }
            if ($item['attempts'] >= self::MAX_ATTEMPTS) {
                $this->logError(__('Scheduled update checking was interrupted or could not be saved three times. This repository will be retried in the next scheduled cycle.', 'rrze-updater'), $item);
                $this->finishStep($job);
                return;
            }
            // Rotate before checking, so repeated timeouts cannot starve other repositories.
            $item['attempts']++;
            $job['pending'][] = $item;
            if (!$this->save($job)) {
                return;
            }
            try {
                if (!$extension->connector) {
                    throw new \RuntimeException('Missing connector');
                }
                $extension->checkForUpdates();
            } catch (\Throwable $e) {
                // Do not persist raw exception messages, which may contain credentials.
                $extension->lastChecked = time();
                $extension->lastError = __('The scheduled repository check failed unexpectedly. Check the service configuration and try again.', 'rrze-updater');
                $extension->remoteVersion = $extension->remoteReadableVersion = '';
                $this->logError($extension->lastError, $item);
            }
            if (!$this->settings->save()) {
                $this->settings->reload();
                $this->logError(__('Could not save a scheduled update-check result. The repository remains queued for retry.', 'rrze-updater'), $item);
                return;
            }
            delete_site_transient('update_' . ($item['type'] === 'plugin' ? 'plugins' : 'themes'));
            array_pop($job['pending']);
            $this->finishStep($job);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function finishStep(array $job): void
    {
        if (!$this->save($job)) {
            return;
        }
        if ($job['pending']) {
            $this->scheduleContinuation($this->delay(), true);
        } else {
            $result = wp_clear_scheduled_hook((new Config())->getCronContinuationHook(), [], true);
            if ($result === false || is_wp_error($result)) {
                $this->logError(__('Could not clear the completed update-check continuation.', 'rrze-updater'));
            }
        }
    }

    private function delay(): int
    {
        return max(1, (int) ($this->settings->options['update_check_delay'] ?? 1));
    }

    private function scheduleContinuation(int $delay, bool $replace = false): bool
    {
        $hook = (new Config())->getCronContinuationHook();
        if ($replace) {
            $result = wp_clear_scheduled_hook($hook, [], true);
            if ($result === false || is_wp_error($result)) {
                $this->logError(__('Could not reschedule the update-check continuation.', 'rrze-updater'));
                return false;
            }
        } elseif (wp_next_scheduled($hook)) {
            return true;
        }
        $result = wp_schedule_single_event(time() + $delay, $hook, [], true);
        if ($result !== true) {
            $this->logError(__('Could not schedule the next update-check batch. It will be retried on the next main-site request.', 'rrze-updater'));
            return false;
        }
        return true;
    }

    private function load(): ?array
    {
        $job = is_multisite() ? get_site_option(self::OPTION) : get_option(self::OPTION);
        return is_array($job) && !empty($job['pending']) ? $job : null;
    }

    private function save(array $job): bool
    {
        $saved = is_multisite() ? update_site_option(self::OPTION, $job) : update_option(self::OPTION, $job, false);
        $stored = is_multisite() ? get_site_option(self::OPTION) : get_option(self::OPTION);
        if ($saved || $stored === $job) {
            return true;
        }
        $this->logError(__('Could not save scheduled update-check progress. The previous checkpoint is retained.', 'rrze-updater'));
        return false;
    }

    private function clearCache(): void
    {
        if (is_multisite()) {
            foreach ([self::OPTION, 'notoptions'] as $key) {
                wp_cache_delete(get_current_network_id() . ':' . $key, 'site-options');
            }
        } else {
            foreach ([self::OPTION, 'alloptions', 'notoptions'] as $key) {
                wp_cache_delete($key, 'options');
            }
        }
    }

    private function logError(string $message, array $item = []): void
    {
        do_action('rrze.log.error', 'Scheduled update check: {error}', [
            'plugin' => (new Config())->getLogPlugin(), 'error' => $message,
            'network' => get_current_network_id(), 'extension-type' => $item['type'] ?? '', 'extension-id' => $item['id'] ?? '',
        ]);
    }
}

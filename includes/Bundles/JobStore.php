<?php

namespace RRZE\Updater\Bundles;

defined('ABSPATH') || exit;

use WP_Error;
use RRZE\Updater\Config;
use RRZE\Updater\FilesystemLock;

/** Network-local progress; the database lock covers all networks sharing files. */
class JobStore
{
    private const OPTION = 'rrze_updater_bundle_job';

    public function load(): ?array
    {
        $job = get_network_option(get_current_network_id(), self::OPTION, null);
        return is_array($job) ? $job : null;
    }

    public function save(array $job): void
    {
        if (!update_network_option(get_current_network_id(), self::OPTION, $job) && $this->load() !== $job) {
            throw new \RuntimeException('Could not save bundle progress.');
        }
    }

    /**
     * Run a job step while excluding other repository filesystem operations.
     *
     * @param callable $operation Job step, including its settings reload.
     * @return mixed Job result or a retryable error when files are in use.
     */
    public function withLock(callable $operation): mixed
    {
        $result = FilesystemLock::withLock(static function () use ($operation) {
            // Plugin bootstrap may have primed these caches before the lock was
            // acquired. Reload current data before checking revisions or saving.
            $network = get_current_network_id();
            foreach ([self::OPTION, (new Config())->getOptionName(), 'notoptions'] as $option) {
                wp_cache_delete("$network:$option", 'site-options');
            }
            return $operation();
        });
        if (is_wp_error($result) && $result->get_error_code() === 'rrze_updater_filesystem_busy') {
            return new WP_Error('bundle_busy', __('A repository operation is still running. Wait, then resume.', 'rrze-updater'));
        }
        return $result;
    }
}

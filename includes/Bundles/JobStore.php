<?php

namespace RRZE\Updater\Bundles;

defined('ABSPATH') || exit;

use WP_Error;
use RRZE\Updater\Config;

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

    public function withLock(callable $operation): mixed
    {
        global $wpdb;
        // GET_LOCK is connection-owned: a crashed worker cannot leave an expired
        // lease behind or keep writing after another worker takes over its lease.
        $name = 'rrze_bundle_' . md5(DB_NAME . '|' . $wpdb->base_prefix);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            return new WP_Error('bundle_busy', __('A bundle request is still running. Wait, then resume.', 'rrze-updater'));
        }
        try {
            // Plugin bootstrap may have primed these caches before the lock was
            // acquired. Reload current data before checking revisions or saving.
            $network = get_current_network_id();
            foreach ([self::OPTION, (new Config())->getOptionName(), 'notoptions'] as $option) {
                wp_cache_delete("$network:$option", 'site-options');
            }
            return $operation();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}

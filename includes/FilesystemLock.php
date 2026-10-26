<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use WP_Error;

/** Serialize operations on plugin/theme files shared by every network. */
final class FilesystemLock
{
    /** @var int Number of nested holders in this PHP request. */
    private static int $depth = 0;

    /** @var object|null Database connection that owns the advisory lock. */
    private static ?object $connection = null;

    /** @var string Connection-owned lock name, compatible with existing bundle jobs. */
    private static string $name = '';

    /** @var string Site/network scope allowed to reuse this request's lock. */
    private static string $scope = '';

    /** @var bool Whether the final PHP shutdown fallback has been registered. */
    private static bool $shutdownRegistered = false;

    /**
     * Acquire the shared lock without waiting for another request.
     *
     * Nested operations in this request share one database acquisition. MySQL
     * releases the lock if the connection dies, so no expiring lease can permit
     * a second worker to write while the first worker is still running.
     *
     * @return true|WP_Error True when acquired, otherwise a retryable busy error.
     */
    public static function acquire(): true|WP_Error
    {
        global $wpdb;

        $scope = is_multisite() ? 'network:' . get_current_network_id() : 'site:' . get_current_blog_id();
        if (self::$depth > 0) {
            if (self::$connection !== $wpdb || self::$scope !== $scope) {
                return new WP_Error('rrze_updater_filesystem_busy', __('Another repository operation is running. Wait for it to finish, then try again.', 'rrze-updater'));
            }
            self::$depth++;
            return true;
        }

        $name = 'rrze_bundle_' . md5(DB_NAME . '|' . $wpdb->base_prefix);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            return new WP_Error('rrze_updater_filesystem_busy', __('Another repository operation is running. Wait for it to finish, then try again.', 'rrze-updater'));
        }

        self::$name = $name;
        self::$scope = $scope;
        self::$connection = $wpdb;
        self::$depth = 1;
        // Core restores/deletes upgrade backups earlier in WordPress shutdown.
        add_action('shutdown', [self::class, 'releaseAll'], PHP_INT_MAX);
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'releaseAll']);
            self::$shutdownRegistered = true;
        }
        return true;
    }

    /**
     * Release one nested holder, leaving other operations protected.
     *
     * @return void
     */
    public static function release(): void
    {
        if (self::$depth === 0 || --self::$depth > 0) {
            return;
        }
        $connection = self::$connection;
        $name = self::$name;
        self::$connection = null;
        self::$name = '';
        self::$scope = '';
        $connection->get_var($connection->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Release remaining holders after core has finished its shutdown rollbacks.
     *
     * @return void
     */
    public static function releaseAll(): void
    {
        if (self::$depth > 0) {
            self::$depth = 1;
            self::release();
        }
    }

    /**
     * Run an operation under the shared lock, releasing it on every exit path.
     *
     * @param callable $operation Operation to run after acquiring the lock.
     * @return mixed Operation result, or WP_Error when another request holds the lock.
     */
    public static function withLock(callable $operation): mixed
    {
        $locked = self::acquire();
        if (is_wp_error($locked)) {
            return $locked;
        }
        try {
            return $operation();
        } finally {
            self::release();
        }
    }
}

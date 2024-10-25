<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

/**
 * Class Cron
 *
 * This class manages scheduled events related to checking for updates.
 */
class Cron
{
    /**
     * The action hook used for scheduling updates.
     */
    const ACTION_HOOK = 'rrze_updater_check_for_updates';

    /**
     * @var Settings The settings object for managing extension data.
     */
    private $settings;

    /**
     * @var Controller The controller object for handling updates and synchronization.
     */
    private $controller;

    /**
     * Cron constructor.
     *
     * Initializes the Cron object with settings and controller.
     *
     * @param Settings   $settings   The settings object.
     * @param Controller $controller The controller object.
     */
    public function __construct(Settings $settings, Controller $controller)
    {
        $blogId = get_current_blog_id();

        // Check if this is not the main blog (blogId != 1).
        if ($blogId != 1) {
            // If there is a scheduled hook, clear it for non-main blogs.
            if (wp_get_schedule(self::ACTION_HOOK) !== false) {
                wp_clear_scheduled_hook(self::ACTION_HOOK);
            }
            return;
        }

        // Initialize the settings and controller for the main blog.
        $this->settings = $settings;
        $this->controller = $controller;

        // Add action hooks to run events and activate scheduled events.
        add_action(self::ACTION_HOOK, [$this, 'runEvents']);
        add_action('init', [$this, 'activateScheduledEvents']);
    }

    /**
     * Activate scheduled events.
     *
     * Schedule the 'rrze_updater_check_for_updates' event to run twicedaily.
     */
    public function activateScheduledEvents()
    {
        if (!wp_next_scheduled(self::ACTION_HOOK)) {
            // Schedule the event to run twicedaily.
            wp_schedule_event(
                time(),
                'twicedaily',
                self::ACTION_HOOK
            );
        }
    }

    /**
     * Run scheduled events.
     *
     * This method is called when the scheduled event is triggered.
     * It synchronizes settings, checks for updates, and saves settings.
     */
    public function runEvents()
    {
        // Synchronize settings with installed extensions.
        $this->controller->synchronizeSettings();

        // Get the current timestamp.
        $now = time();

        // Iterate through plugins and themes to check for updates.
        foreach (array_merge($this->settings->plugins, $this->settings->themes) as $extension) {
            if (!$extension->lastChecked || ($now - $extension->lastChecked) > HOUR_IN_SECONDS) {
                // Check for updates if last checked more than an hour ago.
                $extension->checkForUpdates();
            }
        }

        // Save updated settings.
        $this->settings->save();
    }

    /**
     * Clear all scheduled events for 'rrze_updater_check_for_updates'.
     */
    public static function clearSchedule()
    {
        wp_clear_scheduled_hook(self::ACTION_HOOK);
    }
}

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
        $actionHook = $this->getActionHook();

        // Check if this is not the main blog (blogId != 1).
        if ($blogId != $this->getMainBlogId()) {
            // If there is a scheduled hook, clear it for non-main blogs.
            if (wp_get_schedule($actionHook) !== false) {
                wp_clear_scheduled_hook($actionHook);
            }
            return;
        }

        // Initialize the settings and controller for the main blog.
        $this->settings = $settings;
        $this->controller = $controller;

        // Add action hooks to run events and activate scheduled events.
        add_action($actionHook, [$this, 'runEvents']);
        add_action('init', [$this, 'activateScheduledEvents']);
    }

    /**
     * Activate scheduled events.
     *
     * Schedule the configured update check event.
     */
    public function activateScheduledEvents()
    {
        $actionHook = $this->getActionHook();

        if (!wp_next_scheduled($actionHook)) {
            // Schedule the event to run on the configured schedule.
            wp_schedule_event(
                time(),
                $this->getSchedule(),
                $actionHook
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
            if (!$extension->lastChecked || ($now - $extension->lastChecked) > $this->getMinimumCheckInterval()) {
                // Check for updates if last checked more than an hour ago.
                $extension->checkForUpdates();
            }
        }

        // Save updated settings.
        $this->settings->save();
    }

    /**
     * Clear all scheduled update check events.
     */
    public static function clearSchedule()
    {
        $actionHook = (new Config())->getCronActionHook();

        wp_clear_scheduled_hook($actionHook);
    }

    private function getActionHook(): string
    {
        return (new Config())->getCronActionHook();
    }

    private function getSchedule(): string
    {
        return (new Config())->getCronSchedule();
    }

    private function getMinimumCheckInterval(): int
    {
        return (new Config())->getCronMinimumCheckInterval();
    }

    private function getMainBlogId(): int
    {
        return (new Config())->getCronMainBlogId();
    }
}

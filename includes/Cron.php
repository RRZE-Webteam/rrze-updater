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
     * @var SettingsSynchronizer The service that reconciles installed repositories.
     */
    private SettingsSynchronizer $synchronizer;

    /** WordPress can dispatch both the recurring and continuation hooks in one request. */
    private bool $batchRan = false;

    /**
     * Cron constructor.
     *
     * Initializes scheduled jobs with settings and installation synchronization.
     *
     * @param Settings $settings The settings object.
     * @param SettingsSynchronizer $synchronizer Installation synchronization service.
     */
    public function __construct(Settings $settings, SettingsSynchronizer $synchronizer)
    {
        $blogId = get_current_blog_id();
        $actionHook = $this->getActionHook();

        // Only the current network's main site runs its scheduled jobs.
        if ($blogId != $this->getMainBlogId()) {
            // If there is a scheduled hook, clear it for non-main blogs.
            if (wp_get_schedule($actionHook) !== false) {
                wp_clear_scheduled_hook($actionHook);
            }
            if (wp_get_schedule($this->getEmailActionHook()) !== false) {
                wp_clear_scheduled_hook($this->getEmailActionHook());
            }
            $continuationHook = (new Config())->getCronContinuationHook();
            if (wp_next_scheduled($continuationHook)) {
                wp_clear_scheduled_hook($continuationHook);
            }
            return;
        }

        // Initialize the shared services for this network's main site.
        $this->settings = $settings;
        $this->synchronizer = $synchronizer;

        // Add action hooks to run events and activate scheduled events.
        add_action($actionHook, [$this, 'runEvents']);
        add_action((new Config())->getCronContinuationHook(), [$this, 'resumeEvents']);
        add_action($this->getEmailActionHook(), [$this, 'sendUpdateEmail']);
        add_filter('cron_schedules', [$this, 'addCronSchedules']);
        add_action('init', [$this, 'activateScheduledEvents']);
    }

    public function addCronSchedules(array $schedules): array
    {
        if (!isset($schedules['rrze_updater_weekly'])) {
            $schedules['rrze_updater_weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Once weekly', 'rrze-updater')
            ];
        }

        if (!isset($schedules['rrze_updater_monthly'])) {
            $schedules['rrze_updater_monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display' => __('Once monthly', 'rrze-updater')
            ];
        }

        return $schedules;
    }

    /**
     * Activate scheduled events.
     *
     * Schedule the configured update check event.
     *
     * @return void
     */
    public function activateScheduledEvents()
    {
        $actionHook = $this->getActionHook();
        $schedule = $this->getSchedule();

        if (wp_get_schedule($actionHook) !== $schedule) {
            wp_clear_scheduled_hook($actionHook);
        }

        if (!wp_next_scheduled($actionHook)) {
            wp_schedule_event(time(), $schedule, $actionHook);
        }

        (new UpdateCheckBatch($this->settings, $this->synchronizer))->ensureContinuation();

        $emailActionHook = $this->getEmailActionHook();
        if (!$this->isEmailEnabled()) {
            wp_clear_scheduled_hook($emailActionHook);
            return;
        }

        $emailSchedule = $this->getEmailSchedule();
        if (wp_get_schedule($emailActionHook) !== $emailSchedule) {
            wp_clear_scheduled_hook($emailActionHook);
        }

        if (!wp_next_scheduled($emailActionHook)) {
            wp_schedule_event(time(), $emailSchedule, $emailActionHook);
        }
    }

    /**
     * Run scheduled events.
     *
     * Start or resume a persisted queue, checking one repository in this request.
     *
     * @return void
     */
    public function runEvents()
    {
        if ($this->batchRan) {
            return;
        }
        $this->batchRan = true;
        (new UpdateCheckBatch($this->settings, $this->synchronizer))->run();
    }

    /**
     * Resume a pending batch once per request without starting another cycle.
     *
     * @return void
     */
    public function resumeEvents(): void
    {
        $batch = new UpdateCheckBatch($this->settings, $this->synchronizer);
        if ($this->batchRan || !$batch->hasPending()) {
            return;
        }
        $this->batchRan = true;
        $batch->run(false);
    }

    public function sendUpdateEmail()
    {
        if (!$this->isEmailEnabled()) {
            wp_clear_scheduled_hook($this->getEmailActionHook());
            return;
        }

        self::sendUpdateEmailForSettings($this->settings);
    }

    public static function sendUpdateEmailForSettings(Settings $settings, bool $force = false): string
    {
        if (!$force && empty($settings->options['email_updates_enabled'])) {
            return 'disabled';
        }

        $updates = self::getOpenUpdatesForSettings($settings);
        if (empty($updates)) {
            return 'no_updates';
        }

        $serverName = self::getServerName();
        $recipient = $settings->options['email_address'] ?? '';
        $subjectPrefix = $settings->options['email_subject_prefix'] ?? (new Config())->getDefaultSettings()['email_subject_prefix'];

        if (!$recipient || !is_email($recipient)) {
            return 'invalid_recipient';
        }

        $subject = sprintf(
            '%1$s %2$s: %3$d Updates liegen vor',
            trim($subjectPrefix),
            $serverName,
            count($updates)
        );

        $message = sprintf(
            'RRZE-Updater auf %s hat folgende offene Updates festgestellt:' . "\n\n",
            $serverName
        );

        foreach ($updates as $update) {
            $message .= sprintf(
                '- %1$s: %2$s -> %3$s' . "\n",
                $update['repository'],
                $update['current-version'],
                $update['new-version']
            );
        }

        $message .= sprintf(
            "\n" . 'Direkt zur Repository-Übersicht: %s' . "\n",
            self::getRepositoryOverviewUrl()
        );

        if (!wp_mail($recipient, $subject, $message)) {
            return 'failed';
        }

        Logger::info(
            $settings,
            'Update notice email sent to {recipient}. Open updates: {update-count}.',
            [
                'plugin' => (new Config())->getLogPlugin(),
                'recipient' => $recipient,
                'update-count' => count($updates),
                'server' => $serverName
            ]
        );

        return 'sent';
    }

    /**
     * Clear all scheduled update check events.
     */
    public static function clearSchedule()
    {
        $actionHook = (new Config())->getCronActionHook();

        wp_clear_scheduled_hook($actionHook);
        wp_clear_scheduled_hook((new Config())->getCronContinuationHook());
    }

    public static function clearEmailSchedule()
    {
        $actionHook = (new Config())->getCronEmailActionHook();

        wp_clear_scheduled_hook($actionHook);
    }

    private function getActionHook(): string
    {
        return (new Config())->getCronActionHook();
    }

    private function getSchedule(): string
    {
        return (string) ($this->settings->options['update_check_schedule'] ?? (new Config())->getCronSchedule());
    }

    private function getEmailActionHook(): string
    {
        return (new Config())->getCronEmailActionHook();
    }

    private function getEmailSchedule(): string
    {
        return (string) ($this->settings->options['email_schedule'] ?? (new Config())->getDefaultSettings()['email_schedule']);
    }

    private function isEmailEnabled(): bool
    {
        return !empty($this->settings->options['email_updates_enabled']);
    }

    private function getMainBlogId(): int
    {
        return (new Config())->getCronMainBlogId();
    }

    /**
     * Prepare email rows using the established email-specific local version fallback.
     *
     * @param Settings $settings Managed extension definitions.
     * @return array[] Repositories with pending updates and their version labels.
     */
    private static function getOpenUpdatesForSettings(Settings $settings): array
    {
        $updates = [];
        $installed = new InstalledExtensions();

        foreach (array_merge($settings->plugins, $settings->themes) as $extension) {
            if (!self::extensionHasUpdate($extension)) {
                continue;
            }

            $updates[] = [
                'repository' => $extension->repository,
                'current-version' => $installed->emailVersion($extension),
                'new-version' => method_exists($extension, 'getRemoteVersionLabel') ? $extension->getRemoteVersionLabel() : $extension->remoteVersion
            ];
        }

        return $updates;
    }

    private static function extensionHasUpdate(object $extension): bool
    {
        return !empty($extension->remoteVersion)
            && $extension->remoteVersion != $extension->localVersion
            && empty($extension->lastError);
    }

    private static function getServerName(): string
    {
        $host = parse_url(network_home_url('/'), PHP_URL_HOST);
        if ($host) {
            return $host;
        }

        if (is_multisite()) {
            $network = get_network();
            return (string) $network->domain;
        }

        return (string) parse_url(home_url('/'), PHP_URL_HOST);
    }

    private static function getRepositoryOverviewUrl(): string
    {
        $path = 'admin.php?page=rrze-updater';

        return is_multisite() ? network_admin_url($path) : self_admin_url($path);
    }
}

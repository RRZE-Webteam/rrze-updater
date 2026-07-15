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
            if (wp_get_schedule($this->getEmailActionHook()) !== false) {
                wp_clear_scheduled_hook($this->getEmailActionHook());
            }
            return;
        }

        // Initialize the settings and controller for the main blog.
        $this->settings = $settings;
        $this->controller = $controller;

        // Add action hooks to run events and activate scheduled events.
        add_action($actionHook, [$this, 'runEvents']);
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
     * This method is called when the scheduled event is triggered.
     * It synchronizes settings, checks for updates, and saves settings.
     */
    public function runEvents()
    {
        // Synchronize settings with installed extensions.
        $this->controller->synchronizeSettings();

        // Get the current timestamp.
        $now = time();
        $delay = $this->getUpdateCheckDelay();
        $checked = false;

        // Iterate through plugins and themes to check for updates.
        foreach (array_merge($this->settings->plugins, $this->settings->themes) as $extension) {
            if (!$extension->lastChecked || ($now - $extension->lastChecked) > $this->getMinimumCheckInterval()) {
                if ($checked) {
                    sleep($delay);
                }

                // Check for updates if last checked more than an hour ago.
                $extension->checkForUpdates();
                $checked = true;
            }
        }

        // Save updated settings.
        $this->settings->save();
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

        do_action(
            'rrze.log.info',
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

    private function getMinimumCheckInterval(): int
    {
        return (new Config())->getCronMinimumCheckInterval();
    }

    private function getUpdateCheckDelay(): int {
        $default = (int) (new Config())->getDefaultSettings()['update_check_delay'];

        return max(1, absint($this->settings->options['update_check_delay'] ?? $default));
    }

    private function getMainBlogId(): int
    {
        return (new Config())->getCronMainBlogId();
    }

    private static function getOpenUpdatesForSettings(Settings $settings): array
    {
        $updates = [];

        foreach (array_merge($settings->plugins, $settings->themes) as $extension) {
            if (!self::extensionHasUpdate($extension)) {
                continue;
            }

            $updates[] = [
                'repository' => $extension->repository,
                'current-version' => self::getCurrentVersion($extension),
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

    private static function getCurrentVersion(object $extension): string
    {
        if ($extension instanceof Core\Plugin) {
            return self::getPluginVersion($extension->installationFolder, $extension->repository);
        }

        if ($extension instanceof Core\Theme) {
            $theme = wp_get_theme($extension->installationFolder);
            return $theme->exists() ? ($theme->get('Version') ?: 'n/a') : 'n/a';
        }

        return 'n/a';
    }

    private static function getPluginVersion(string $installationFolder, string $repository): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $candidates = self::getPluginFileCandidates($installationFolder, $repository);

        foreach ($candidates as $candidate) {
            $pluginFile = WP_PLUGIN_DIR . '/' . $candidate;
            if (!file_exists($pluginFile)) {
                continue;
            }

            $pluginData = get_plugin_data($pluginFile);
            if (!empty($pluginData['Version'])) {
                return $pluginData['Version'];
            }
        }

        $packageVersion = self::getPackageVersion(WP_PLUGIN_DIR . '/' . $installationFolder);
        if ($packageVersion !== '') {
            return $packageVersion;
        }

        return 'n/a';
    }

    private static function getPluginFileCandidates(string $installationFolder, string $repository): array {
        $candidates = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (array_keys(get_plugins()) as $pluginFile) {
            if (dirname($pluginFile) == $installationFolder) {
                $candidates[] = $pluginFile;
            }
        }

        $candidates[] = $installationFolder . '/' . $repository . '.php';
        $candidates[] = $installationFolder . '/' . $installationFolder . '.php';

        $headerPluginFile = self::findPluginFileByHeader($installationFolder);
        if ($headerPluginFile !== '') {
            $candidates[] = $headerPluginFile;
        }

        return array_values(array_unique($candidates));
    }

    private static function findPluginFileByHeader(string $installationFolder): string {
        $directory = trailingslashit(WP_PLUGIN_DIR) . $installationFolder;

        if (!is_dir($directory) || !is_readable($directory)) {
            return '';
        }

        $files = scandir($directory);
        if (!is_array($files)) {
            return '';
        }

        foreach ($files as $file) {
            if ($file === '' || $file[0] === '.' || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $relativeFile = trailingslashit($installationFolder) . $file;
            $pluginData = get_plugin_data(trailingslashit(WP_PLUGIN_DIR) . $relativeFile);
            if (!empty($pluginData['Name'])) {
                return $relativeFile;
            }
        }

        return '';
    }

    private static function getPackageVersion(string $directory): string {
        $file = trailingslashit($directory) . (new Config())->getPackageFile();

        if (!is_readable($file)) {
            return '';
        }

        $content = file_get_contents($file);
        if (!is_string($content)) {
            return '';
        }

        $package = json_decode($content, true);
        if (!is_array($package) || empty($package['version']) || !is_string($package['version'])) {
            return '';
        }

        return sanitize_text_field(trim($package['version']));
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

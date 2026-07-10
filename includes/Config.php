<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

class Config {
    private array $config = [];

    public function __construct() {
        $this->config = [
            'option_name' => 'rrze_updater',
            'constants' => [
                'plugin_slug' => 'rrze-updater',
                'log_plugin' => 'rrze-updater',
                'screen_option_per_page' => 'rrze_updater_per_page',
            ],
            'cron' => [
                'action_hook' => 'rrze_updater_check_for_updates',
                'email_action_hook' => 'rrze_updater_send_update_email',
                'schedule' => 'twicedaily',
                'minimum_check_interval' => HOUR_IN_SECONDS,
                'main_blog_id' => 1,
            ],
            'settings' => [
                'update_check_schedule' => 'twicedaily',
                'email_updates_enabled' => false,
                'email_address' => '',
                'email_subject_prefix' => '[RRZE-Updater]',
                'email_schedule' => 'rrze_updater_monthly',
            ],
            'menu' => [
                'capability' => 'manage_options',
                'repositories_slug' => 'rrze-updater',
                'connectors_slug' => 'rrze-updater-connectors',
                'plugins_slug' => 'rrze-updater-plugins',
                'themes_slug' => 'rrze-updater-themes',
                'settings_slug' => 'rrze-updater-settings',
                'admin_bar_repositories_id' => 'rrze-updater-network-repositories',
                'admin_bar_network_parent' => 'network-admin',
            ],
            'connectors' => [
                'github' => [
                    'display' => 'GitHub.com',
                    'web_host' => 'github.com',
                    'api_host' => 'api.github.com',
                    'api_accept_header' => 'application/vnd.github.v3.full+json',
                ],
                'gitlab-rrze' => [
                    'default_host' => 'gitlab.rrze.fau.de',
                    'default_api_uri' => '/api/v4/projects/',
                    'default_display' => 'GitLab RRZE',
                ],
                'gitlab-custom' => [
                    'default_api_uri' => '/api/v4/projects/',
                    'custom_display_format' => 'GitLab (%s)',
                    'custom_host_placeholder' => 'gitlab.example.org',
                ],
            ],
            'default_repository' => [
                'connector_type' => 'gitlab',
                'owner' => 'rrze-webteam',
                'repository' => 'rrze-updater',
                'branch' => 'master',
                'updates' => 'commits',
            ],
            'version_detection' => [
                'readme_file' => 'readme.txt',
                'readme_files' => [
                    'readme.txt',
                    'README.md',
                ],
                'package_file' => 'package.json',
                'plugin_main_file_pattern' => '%s.php',
                'theme_main_file' => 'style.css',
            ],
            'fields' => [
                'connector_types' => [
                    'github' => [
                        'connector_type' => 'github',
                        'label' => 'GitHub.com',
                    ],
                    'gitlab-rrze' => [
                        'connector_type' => 'gitlab',
                        'label' => 'GitLab RRZE',
                    ],
                    'gitlab-custom' => [
                        'connector_type' => 'gitlab',
                        'label' => 'Custom GitLab Server',
                    ],
                ],
                'update_modes' => [
                    'commits',
                    'tags',
                ],
                'cron_schedules' => [
                    'hourly' => 'hourly',
                    'twicedaily' => 'twicedaily',
                    'daily' => 'daily',
                ],
                'email_schedules' => [
                    'daily' => '1x pro Tag',
                    'rrze_updater_weekly' => '1x pro Woche',
                    'rrze_updater_monthly' => '1x pro Monat',
                ],
            ],
        ];
    }

    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    public function getOptionName(): string {
        return (string) $this->get('option_name', 'rrze_updater');
    }

    public function getConstants(): array {
        return $this->get('constants', []);
    }

    public function getFields(): array {
        return $this->get('fields', []);
    }

    public function getCronSettings(): array {
        return $this->get('cron', []);
    }

    public function getMenuSettings(): array {
        return $this->get('menu', []);
    }

    public function getDefaultSettings(): array {
        $settings = $this->get('settings', []);
        if (empty($settings['email_address'])) {
            $settings['email_address'] = $this->getDefaultEmailAddress();
        }

        return $settings;
    }

    public function getConnectorSettings(string $type): array {
        return $this->get('connectors.' . $type, []);
    }

    public function getDefaultRepository(): array {
        return $this->get('default_repository', []);
    }

    public function getVersionDetectionSettings(): array {
        return $this->get('version_detection', []);
    }

    public function getReadmeFile(): string {
        return (string) $this->get('version_detection.readme_file', 'readme.txt');
    }

    public function getReadmeFiles(): array {
        return $this->get('version_detection.readme_files', [$this->getReadmeFile()]);
    }

    public function getPackageFile(): string {
        return (string) $this->get('version_detection.package_file', 'package.json');
    }

    public function getPluginMainFilePattern(): string {
        return (string) $this->get('version_detection.plugin_main_file_pattern', '%s.php');
    }

    public function getThemeMainFile(): string {
        return (string) $this->get('version_detection.theme_main_file', 'style.css');
    }

    public function getLogPlugin(): string {
        return (string) $this->get('constants.log_plugin', 'rrze-updater');
    }

    public function getScreenOptionPerPage(): string {
        return (string) $this->get('constants.screen_option_per_page');
    }

    public function getCronActionHook(): string {
        return (string) $this->get('cron.action_hook');
    }

    public function getCronSchedule(): string {
        return (string) $this->get('cron.schedule');
    }

    public function getCronEmailActionHook(): string {
        return (string) $this->get('cron.email_action_hook');
    }

    public function getCronMinimumCheckInterval(): int {
        return (int) $this->get('cron.minimum_check_interval');
    }

    public function getCronMainBlogId(): int {
        return (int) $this->get('cron.main_blog_id');
    }

    public function getGitlabDefaultHost(): string {
        return (string) $this->get('connectors.gitlab-rrze.default_host');
    }

    public function getGitlabDefaultApiUri(): string {
        return (string) $this->get('connectors.gitlab-rrze.default_api_uri');
    }

    public function getGitlabCustomDefaultApiUri(): string {
        return (string) $this->get('connectors.gitlab-custom.default_api_uri');
    }

    public function getGitlabCustomHostPlaceholder(): string {
        return (string) $this->get('connectors.gitlab-custom.custom_host_placeholder');
    }

    public function getGithubWebHost(): string {
        return (string) $this->get('connectors.github.web_host');
    }

    public function getGithubApiHost(): string {
        return (string) $this->get('connectors.github.api_host');
    }

    public function getDefaultEmailAddress(): string {
        if (is_multisite()) {
            return (string) get_site_option('admin_email', get_option('admin_email'));
        }

        return (string) get_option('admin_email');
    }
}

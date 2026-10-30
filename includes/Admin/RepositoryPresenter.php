<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Settings, TokenStatus, MaintenanceNotice, InstalledExtensions};
use RRZE\Updater\Core\{Plugin, Theme};

/** Builds display data without applying repository or filesystem changes. */
class RepositoryPresenter
{
    /**
     * Inject the concrete dependencies used by this administrative component.
     *
     * @param Settings $settings Shared registry and user settings.
     * @param InstalledExtensions $installed Local extension metadata reader.
     * @param AdminLinks $links Administrative URLs and optional tools.
     */
    public function __construct(
        private Settings $settings,
        private InstalledExtensions $installed,
        private AdminLinks $links
    ) {
    }

    /**
     * Build masked service rows with cached token status and optional search.
     *
     * @param string $search Sanitized search phrase, or empty for all rows.
     * @return array Prepared display data.
     */
    public function getConnectorListData(string $search = ''): array
    {
        $config = $this->settings->asArray();
        $connectors = $config['connectors'] ?? [];

        $tokenStatuses = TokenStatus::getAll($this->settings);

        foreach ($connectors as $key => $connector) {
            $repos = $this->settings->getConnectorRepos($connector['id']);
            $connectors[$key]['repocount'] = count($repos);
            $connectors[$key]['token'] = empty($connector['token'])
                ? __('Not configured', 'rrze-updater')
                : __('Configured', 'rrze-updater');
        }

        foreach ($connectors as $key => $value) {
            if ($search && empty(preg_grep('/' . preg_quote($search, '/') . '/i', $value))) {
                unset($connectors[$key]);
                continue;
            }
            $connectors[$key]['token_status'] = $tokenStatuses[$value['id']];
        }

        return $connectors;
    }

    /**
     * Add safe update or repair links to a repository display row.
     *
     * @param array $repo Repository display row.
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @param string $type Managed extension type: plugin or theme.
     * @return array Prepared display data.
     */
    public function addRepoUpdateData(array $repo, $extension, string $type): array
    {
        $repo['hasUpdate'] = false;
        $repo['updateVersion'] = '';
        $repo['updateUrl'] = '';
        $repo['maintenanceNotice'] = '';
        $repo['incomplete'] = $type === 'plugin' && $extension && $this->installed->isPluginIncomplete($extension);
        $repo['repairUrl'] = $repo['incomplete'] ? $this->links->getPluginEditUrl($extension->id) . '#plugin-reinstall' : '';

        if ($repo['incomplete'] || !$extension || !$this->extensionHasUpdate($extension)) {
            return $repo;
        }

        $repo['hasUpdate'] = true;
        $repo['updateVersion'] = method_exists($extension, 'getRemoteVersionLabel') ? $extension->getRemoteVersionLabel() : $extension->remoteVersion;
        $repo['updateUrl'] = $type == 'plugin'
            ? $this->links->getPluginUpdateUrl($extension)
            : $this->links->getThemeUpdateUrl($extension);
        $repo['maintenanceNotice'] = MaintenanceNotice::render('manual', $type, $extension->installationFolder, true);

        return $repo;
    }

    /**
     * Explain maintenance only when the selectable single updates can require it.
     *
     * @param array $repos Filtered repository rows available for selection.
     * @return string Formatted value or empty when unavailable.
     */
    public function getBulkMaintenanceNotice(array $repos): string
    {
        foreach ($repos as $repo) {
            if (!empty($repo['maintenanceNotice'])) {
                return MaintenanceNotice::render('updater-bulk', 'theme', $repo['installationFolder']);
            }
        }
        return '';
    }

    /**
     * Format the service, owner and repository label for the overview.
     *
     * @param array $repo Repository display row.
     * @return string Formatted value or empty when unavailable.
     */
    public function getServiceOwnerLabel(array $repo): string
    {
        $service = trim((string) ($repo['display'] ?? ''));
        $owner = trim((string) ($repo['owner'] ?? ''));
        $repository = trim((string) ($repo['repository'] ?? ''));
        $parts = [];

        if ($service !== '') {
            $parts[] = $service;
        }

        if ($owner !== '') {
            $parts[] = $owner;
        }

        if ($repository !== '') {
            $parts[] = $repository;
        }

        if (empty($parts)) {
            return '&mdash;';
        }

        return implode(' / ', $parts);
    }

    /**
     * Build the sequential AJAX check queue for all managed extensions.
     *
     * @return array Prepared display data.
     */
    public function getRepositoryUpdateCheckItems(): array
    {
        $items = [];

        foreach ($this->settings->plugins as $extension) {
            $items[] = $this->getRepositoryUpdateCheckItem($extension, 'plugin');
        }

        foreach ($this->settings->themes as $extension) {
            $items[] = $this->getRepositoryUpdateCheckItem($extension, 'theme');
        }

        return $items;
    }

    /**
     * Build the display fields for one AJAX check item.
     *
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @param string $type Managed extension type: plugin or theme.
     * @return array Prepared display data.
     */
    public function getRepositoryUpdateCheckItem($extension, string $type): array
    {
        return [
            'id' => $extension->id,
            'type' => $type,
            'name' => $this->getExtensionDisplayName($extension),
            'repository' => $this->getExtensionRepositoryPath($extension),
            'branch' => $extension->branch,
        ];
    }

    /**
     * Read the installed display name with repository fallback.
     *
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @return string Formatted value or empty when unavailable.
     */
    public function getExtensionDisplayName($extension): string
    {
        if ($extension instanceof Plugin) {
            return $this->installed->pluginName($extension->installationFolder, $extension->repository);
        }

        if ($extension instanceof Theme) {
            return $this->installed->themeName($extension->installationFolder);
        }

        return (string) ($extension->repository ?: $extension->installationFolder);
    }

    /**
     * Format the source label used by update-check results.
     *
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @return string Formatted value or empty when unavailable.
     */
    public function getExtensionRepositoryPath($extension): string
    {
        $parts = [];
        $service = trim((string) ($extension->connector->display ?? ''));
        $owner = trim((string) ($extension->connector->owner ?? ''));
        $repository = trim((string) ($extension->repository ?? ''));

        if ($service !== '') {
            $parts[] = $service;
        }

        if ($owner !== '') {
            $parts[] = $owner;
        }

        if ($repository !== '') {
            $parts[] = $repository;
        }

        return implode('/', $parts);
    }

    /**
     * Display the branch or last checked release tag for a repository.
     *
     * @param array $repo Repository display row.
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @return string Formatted value or empty when unavailable.
     */
    public function getRepositoryRefLabel(array $repo, $extension): string
    {
        if ($extension && in_array($extension->updates ?? '', ['tags', 'releases'], true)) {
            return (string) (($extension->remoteVersion ?? '') ?: __('Release tag not checked yet', 'rrze-updater'));
        }

        return (string) ($repo['branch'] ?? '');
    }

    /**
     * Compare installed and remote references only after a successful check.
     *
     * @param object $extension Extension with installed and remote references.
     * @return bool Whether the condition or operation succeeds.
     */
    public function extensionHasUpdate(object $extension): bool
    {
        if ($extension instanceof \RRZE\Updater\Core\Extension) {
            return $extension->hasUpdate();
        }
        return !empty($extension->remoteVersion)
            && $extension->remoteVersion != $extension->localVersion
            && empty($extension->lastError);
    }

    /**
     * Format the last check timestamp for administrative display.
     *
     * @param int $timestamp Unix timestamp of the last update check.
     * @return string Escaped date markup.
     */
    public function lastChecked($timestamp)
    {
        $localLastCheckedTimestamp = strtotime(get_date_from_gmt(date('Y-m-d H:i:s', $timestamp)));

        $localLastCheckedDateTime = date(esc_html__('Y/m/d', 'rrze-updater') . ' H:i:s', $localLastCheckedTimestamp);

        $timeDiff = time() - $timestamp;

        if ($timeDiff >= 0 && $timeDiff < DAY_IN_SECONDS) {
            $lastChecked = sprintf(
                /* translators: %s: Human-readable time difference */
                esc_html__('%s ago', 'rrze-updater'),
                human_time_diff($timestamp)
            );
        } else {
            $lastChecked = date(esc_html__('Y/m/d', 'rrze-updater'), $localLastCheckedTimestamp);
        }

        return '<abbr title="' . esc_attr($localLastCheckedDateTime) . '">' . esc_html($lastChecked) . '</abbr>';
    }

    /**
     * Build plugin overview rows with local metadata and optional tools.
     *
     * @param string $search Sanitized search phrase, or empty for all rows.
     * @return array Prepared display data.
     */
    public function pluginRows(string $search = ''): array
    {
        $data = [];
        $pluginCheckAvailable = $this->links->isPluginCheckAvailable();

        foreach ($this->settings->connectors as $connector) {
            foreach ($this->settings->getConnectorRepos($connector->id) as $repo) {
                if (empty($repo['plugin'])) {
                    continue;
                }

                $extension = $this->settings->getPluginById($repo['id']);
                if (!$extension) {
                    continue;
                }

                $repo['connector'] = $repo['display'] ?? '';
                $repo['pluginDisplayName'] = $this->installed->pluginName($repo['installationFolder'], $repo['repository']);
                $repo['lastChecked'] = $this->lastChecked($extension->lastChecked);
                $repo['version'] = $this->installed->pluginVersion($repo['installationFolder'], $repo['repository']);
                $repo['repositoryUrl'] = $this->links->getExtensionRepositoryUrl($extension);
                $repo['pluginCheckUrl'] = $pluginCheckAvailable ? $this->links->getPluginCheckUrl($extension) : '';
                $repo['multisiteManagerPluginsUrl'] = $this->links->getMultisiteManagerPluginDetailsUrl($extension);
                $data[] = $this->addRepoUpdateData($repo, $extension, 'plugin');
            }
        }

        return $this->filterRows($data, $search);
    }

    /**
     * Build theme overview rows with local metadata and optional tools.
     *
     * @param string $search Sanitized search phrase, or empty for all rows.
     * @return array Prepared display data.
     */
    public function themeRows(string $search = ''): array
    {
        $data = [];
        $multisiteManagerThemesUrl = $this->links->getMultisiteManagerThemesUrl();
        $themeCheckAvailable = $this->links->isThemeCheckAvailable();

        foreach ($this->settings->connectors as $connector) {
            foreach ($this->settings->getConnectorRepos($connector->id) as $repo) {
                if (empty($repo['theme'])) {
                    continue;
                }

                $extension = $this->settings->getThemeById($repo['id']);
                if (!$extension) {
                    continue;
                }

                $repo['connector'] = $repo['display'] ?? '';
                $repo['themeDisplayName'] = $this->installed->themeName($repo['installationFolder']);
                $repo['lastChecked'] = $this->lastChecked($extension->lastChecked);
                $repo['version'] = $this->installed->themeVersion($repo['installationFolder']);
                $repo['repositoryUrl'] = $this->links->getExtensionRepositoryUrl($extension);
                $repo['multisiteManagerThemesUrl'] = $multisiteManagerThemesUrl !== ''
                    ? $this->links->getMultisiteManagerThemeDetailsUrl($extension)
                    : '';
                $repo['themeCheckUrl'] = $themeCheckAvailable ? $this->links->getThemeCheckUrl($extension) : '';
                $data[] = $this->addRepoUpdateData($repo, $extension, 'theme');
            }
        }

        return $this->filterRows($data, $search);
    }

    /**
     * Build combined plugin and theme overview rows.
     *
     * @param string $search Sanitized search phrase, or empty for all rows.
     * @return array Prepared display data.
     */
    public function repositoryRows(string $search = ''): array
    {
        $repos = [];

        foreach ($this->settings->connectors as $connector) {
            $repos = array_merge($repos, $this->settings->getConnectorRepos($connector->id));
        }

        foreach ($repos as $key => $value) {
            if (isset($value['plugin'])) {
                $extension = $this->settings->getPluginById($value['id']);
                $value['type'] = __('Plugin', 'rrze-updater');
                $value['displayName'] = $this->installed->pluginName($value['installationFolder'], $value['repository']);
                $value['version'] = $this->installed->pluginVersion($value['installationFolder'], $value['repository']);
                $value['serviceOwner'] = $this->getServiceOwnerLabel($value);
                $value['ref'] = $this->getRepositoryRefLabel($value, $extension);
                $value['repositoryUrl'] = $this->links->getExtensionRepositoryUrl($extension);
                $value = $this->addRepoUpdateData($value, $extension, 'plugin');
            } elseif (isset($value['theme'])) {
                $extension = $this->settings->getThemeById($value['id']);
                $value['type'] = __('Theme', 'rrze-updater');
                $value['displayName'] = $this->installed->themeName($value['installationFolder']);
                $value['version'] = $this->installed->themeVersion($value['installationFolder']);
                $value['serviceOwner'] = $this->getServiceOwnerLabel($value);
                $value['ref'] = $this->getRepositoryRefLabel($value, $extension);
                $value['repositoryUrl'] = $this->links->getExtensionRepositoryUrl($extension);
                $value = $this->addRepoUpdateData($value, $extension, 'theme');
            } else {
                $value['type'] = '&mdash;';
                $value['version'] = '&mdash;';
                $value['serviceOwner'] = '&mdash;';
                $value['ref'] = $value['branch'] ?? '&mdash;';
                $value['repositoryUrl'] = '';
                $value['hasUpdate'] = false;
                $value['updateVersion'] = '';
                $value['updateUrl'] = '';
            }
            $repos[$key] = $value;
        }

        return $this->filterRows($repos, $search);
    }

    /**
     * Filter row values while excluding generated maintenance notice markup.
     *
     * @param array $rows Prepared display rows.
     * @param string $search Sanitized search phrase, or empty for all rows.
     * @return array Prepared display data.
     */
    private function filterRows(array $rows, string $search): array
    {
        if (!$search) {
            return $rows;
        }
        return array_filter($rows, static function (array $row) use ($search): bool {
            return !empty(preg_grep('/' . preg_quote($search, '/') . '/i', array_diff_key($row, ['maintenanceNotice' => true])));
        });
    }

    /**
     * Build the persisted plugin editor data and repair availability.
     *
     * @param Plugin $plugin Persisted managed plugin.
     * @param bool $canReplace Whether file replacement is permitted for this request.
     * @return array Prepared display data.
     */
    public function pluginEditData(Plugin $plugin, bool $canReplace): array
    {
        return [
            'connectors' => $this->settings->connectors,
            'plugin' => $plugin,
            'lastChecked' => $this->lastChecked($plugin->lastChecked),
            'installedVersion' => $this->installed->pluginVersion($plugin->installationFolder, $plugin->repository),
            'repositoryUrl' => $this->links->getExtensionRepositoryUrl($plugin),
            'pluginCheckUrl' => $this->links->getPluginCheckUrl($plugin),
            'multisiteManagerPluginsUrl' => $this->links->getMultisiteManagerPluginsUrl(),
            'incomplete' => $this->installed->isPluginIncomplete($plugin),
            'canReplace' => $canReplace,
        ];
    }

    /**
     * Build the shared theme editor data for all theme form outcomes.
     *
     * @param Theme $theme Persisted managed theme.
     * @return array Prepared display data.
     */
    public function themeEditData(Theme $theme): array
    {
        return [
            'connectors' => $this->settings->connectors,
            'theme' => $theme,
            'lastChecked' => $this->lastChecked($theme->lastChecked),
            'installedVersion' => $this->installed->themeVersion($theme->installationFolder),
            'repositoryUrl' => $this->links->getExtensionRepositoryUrl($theme),
            'multisiteManagerThemesUrl' => $this->links->getMultisiteManagerThemeDetailsUrl($theme),
            'themeCheckUrl' => $this->links->getThemeCheckUrl($theme)
        ];
    }
}

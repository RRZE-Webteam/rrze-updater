<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use stdClass;

/** Projects managed repository versions into WordPress update metadata. */
class UpdateProvider
{
    public function __construct(private readonly Settings $settings) {}

    public function register(): void
    {
        add_filter('site_transient_update_plugins', [$this, 'filterPluginUpdates']);
        add_filter('site_transient_update_themes', [$this, 'filterThemeUpdates']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filterPluginUpdates']);
        add_filter('pre_set_site_transient_update_themes', [$this, 'filterThemeUpdates']);
    }

    /** Use managed Git versions on both transient reads and writes. */
    public function filterPluginUpdates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }
        foreach (get_plugins() as $pluginFile => $data) {
            foreach ($this->settings->plugins as $extension) {
                if ($extension->installationFolder !== dirname($pluginFile)) {
                    continue;
                }
                // A managed installation must never inherit a same-slug .org offer,
                // including during an API outage or when its Git ref is current.
                unset($transient->response[$pluginFile], $transient->no_update[$pluginFile]);
                $hasUpdate = $extension->connector && $extension->remoteVersion
                    && $extension->remoteVersion !== $extension->localVersion;
                $response = (object) [
                    'id' => $pluginFile, 'slug' => $extension->installationFolder, 'plugin' => $pluginFile,
                    'new_version' => $hasUpdate ? $extension->getRemoteVersionLabel() : ($data['Version'] ?? ''),
                    'url' => $extension->connector ? $extension->connector->getUrl($extension->repository) : '',
                    'package' => $hasUpdate ? $extension->connector->downloadRepoZip($extension->repository, $extension->remoteVersion) : '',
                    'icons' => [], 'banners' => [], 'banners_rtl' => [], 'tested' => '',
                    'requires_php' => '', 'compatibility' => new stdClass(),
                ];
                $bucket = $hasUpdate ? 'response' : 'no_update';
                $transient->{$bucket}[$pluginFile] = $response;
                break;
            }
        }
        return $transient;
    }

    /** Keep theme update and no-update entries tied to the managed repository. */
    public function filterThemeUpdates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }
        foreach (wp_get_themes() as $themeFolder => $theme) {
            foreach ($this->settings->themes as $extension) {
                if ($extension->installationFolder !== $themeFolder) {
                    continue;
                }
                unset($transient->response[$themeFolder], $transient->no_update[$themeFolder]);
                $hasUpdate = $extension->connector && $extension->remoteVersion
                    && $extension->remoteVersion !== $extension->localVersion;
                $response = [
                    'theme' => $themeFolder,
                    'new_version' => $hasUpdate ? $extension->getRemoteVersionLabel() : $theme->get('Version'),
                    'url' => $extension->connector ? $extension->connector->getUrl($extension->repository) : '',
                    'package' => $hasUpdate ? $extension->connector->downloadRepoZip($extension->repository, $extension->remoteVersion) : '',
                    'requires' => '', 'requires_php' => '',
                ];
                $bucket = $hasUpdate ? 'response' : 'no_update';
                $transient->{$bucket}[$themeFolder] = $response;
                break;
            }
        }
        return $transient;
    }
}

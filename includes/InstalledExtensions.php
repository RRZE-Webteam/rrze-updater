<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\Plugin;
use RRZE\Updater\Core\Theme;

/** Read installed extension metadata without changing files or repository definitions. */
class InstalledExtensions
{
    private Config $config;

    /**
     * Configure the readme and package filenames used by local metadata fallbacks.
     *
     * @param Config|null $config Plugin configuration, using the defaults when omitted.
     */
    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    /**
     * Select the administrative entry point, preferring WordPress's first match.
     *
     * @param string $installationFolder Plugin installation directory name.
     * @param string $repository Repository name used by conventional entry points.
     * @return string Plugin-relative path, or the first conventional candidate if absent.
     */
    public function getPluginFile(string $installationFolder, string $repository): string
    {
        $installed = $this->getInstalledPluginFile($installationFolder);
        if ($installed !== '') {
            return $installed;
        }

        $candidates = $this->conventionalPluginFiles($installationFolder, $repository);
        foreach ($candidates as $candidate) {
            if (file_exists(WP_PLUGIN_DIR . '/' . $candidate)) {
                return $candidate;
            }
        }
        return $this->findPluginFileByHeader($installationFolder) ?: $candidates[0];
    }

    /**
     * Find the first entry point recognized by WordPress in exactly this directory.
     *
     * @param string $installationFolder Plugin installation directory name.
     * @return string Plugin-relative path, or an empty string when unrecognized.
     */
    public function getInstalledPluginFile(string $installationFolder): string
    {
        return $this->installedPluginFiles($installationFolder)[0] ?? '';
    }

    /**
     * Check whether a safe, single-component plugin destination still exists.
     *
     * @param string $folder Plugin installation directory name.
     * @return bool Whether files or a symlink remain at the destination.
     */
    public function pluginDestinationExists(string $folder): bool
    {
        if (!defined('WP_PLUGIN_DIR') || $folder === '' || $folder === '.' || $folder === '..'
            || strpbrk($folder, "/\\\0") !== false) {
            return false;
        }
        $path = trailingslashit(WP_PLUGIN_DIR) . $folder;
        return file_exists($path) || is_link($path);
    }

    /**
     * Detect a managed plugin whose entry point is unrecognized by WordPress.
     *
     * @param Plugin $plugin Managed plugin definition.
     * @return bool Whether the entry point needs repair.
     */
    public function isPluginIncomplete(Plugin $plugin): bool
    {
        return $this->getInstalledPluginFile($plugin->installationFolder) === '';
    }

    /**
     * Read the selected admin entry point, then package.json, for its version.
     *
     * @param string $installationFolder Plugin installation directory name.
     * @param string $repository Repository name used by conventional entry points.
     * @return string Installed version or the existing administrative em-dash placeholder.
     */
    public function pluginVersion(string $installationFolder, string $repository): string
    {
        $data = $this->pluginData($this->getPluginFile($installationFolder, $repository));
        if (!empty($data['Version'])) {
            return $data['Version'];
        }
        $version = $this->packageField(WP_PLUGIN_DIR . '/' . $installationFolder, 'version');
        return $version !== '' ? $version : '&mdash;';
    }

    /**
     * Resolve a plugin's name using its header, readme, package, then repository.
     *
     * @param string $installationFolder Plugin installation directory name.
     * @param string $repository Repository name used by conventional entry points.
     * @return string Installed display name.
     */
    public function pluginName(string $installationFolder, string $repository): string
    {
        $data = $this->pluginData($this->getPluginFile($installationFolder, $repository));
        if (!empty($data['Name'])) {
            return sanitize_text_field($data['Name']);
        }
        $directory = WP_PLUGIN_DIR . '/' . $installationFolder;
        $name = $this->getNameFromReadme($directory);
        if ($name !== '') {
            return $name;
        }
        $name = $this->packageField($directory, 'name');
        return $name !== '' ? $name : ($repository ?: $installationFolder);
    }

    /**
     * Read the WordPress theme version used by administrative views.
     *
     * @param string $installationFolder Theme stylesheet directory name.
     * @return string Theme version, or an em dash when the theme is absent.
     */
    public function themeVersion(string $installationFolder): string
    {
        $theme = wp_get_theme($installationFolder);
        return $theme->exists() ? $theme->get('Version') : '&mdash;';
    }

    /**
     * Resolve a theme's name using WordPress, its readme, package, then directory.
     *
     * @param string $installationFolder Theme stylesheet directory name.
     * @return string Installed display name.
     */
    public function themeName(string $installationFolder): string
    {
        $theme = wp_get_theme($installationFolder);
        if ($theme->exists() && $theme->get('Name')) {
            return sanitize_text_field($theme->get('Name'));
        }
        $directory = get_theme_root($installationFolder) . '/' . $installationFolder;
        $name = $this->getNameFromReadme($directory);
        if ($name !== '') {
            return $name;
        }
        $name = $this->packageField($directory, 'name');
        return $name !== '' ? $name : $installationFolder;
    }

    /**
     * Read the email version, whose established fallback differs from admin views.
     *
     * Email checks every recognized plugin entry point before conventional files
     * and package.json; an unknown version is rendered as plain text "n/a".
     *
     * @param object $extension Managed plugin or theme definition.
     * @return string Installed version suitable for an update-notification email.
     */
    public function emailVersion(object $extension): string
    {
        if ($extension instanceof Theme) {
            $theme = wp_get_theme($extension->installationFolder);
            return $theme->exists() ? ($theme->get('Version') ?: 'n/a') : 'n/a';
        }
        if (!$extension instanceof Plugin) {
            return 'n/a';
        }

        $folder = $extension->installationFolder;
        $candidates = array_merge(
            $this->installedPluginFiles($folder),
            $this->conventionalPluginFiles($folder, $extension->repository)
        );
        $header = $this->findPluginFileByHeader($folder);
        if ($header !== '') {
            $candidates[] = $header;
        }
        foreach (array_unique($candidates) as $candidate) {
            $data = $this->pluginData($candidate);
            if (!empty($data['Version'])) {
                return $data['Version'];
            }
        }
        $version = $this->packageField(WP_PLUGIN_DIR . '/' . $folder, 'version');
        return $version !== '' ? $version : 'n/a';
    }

    /**
     * Read installed entry points in WordPress's original discovery order.
     *
     * @param string $folder Plugin installation directory name.
     * @return string[] Recognized plugin-relative paths from this exact directory.
     */
    private function installedPluginFiles(string $folder): array
    {
        $this->loadPluginFunctions();
        return array_values(array_filter(array_keys(get_plugins()), static fn($file) => dirname($file) == $folder));
    }

    /**
     * Build conventional filenames in the same precedence as classic admin views.
     *
     * @param string $folder Plugin installation directory name.
     * @param string $repository Repository basename.
     * @return string[] Unique plugin-relative filenames.
     */
    private function conventionalPluginFiles(string $folder, string $repository): array
    {
        return array_values(array_unique([$folder . '/' . $repository . '.php', $folder . '/' . $folder . '.php']));
    }

    /**
     * Find the first visible PHP file with a plugin name header, alphabetically.
     *
     * @param string $folder Plugin installation directory name.
     * @return string Plugin-relative path, or an empty string when unavailable.
     */
    private function findPluginFileByHeader(string $folder): string
    {
        $directory = trailingslashit(WP_PLUGIN_DIR) . $folder;
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
            $relative = trailingslashit($folder) . $file;
            if (!empty($this->pluginData($relative)['Name'])) {
                return $relative;
            }
        }
        return '';
    }

    /**
     * Read a plugin header, preserving WordPress's normal metadata processing.
     *
     * @param string $relative Plugin path relative to WP_PLUGIN_DIR.
     * @return array Plugin headers, or an empty array for absent files.
     */
    private function pluginData(string $relative): array
    {
        $this->loadPluginFunctions();
        $file = WP_PLUGIN_DIR . '/' . $relative;
        return file_exists($file) ? get_plugin_data($file) : [];
    }

    /**
     * Load WordPress plugin discovery and metadata functions when needed.
     *
     * @return void
     */
    private function loadPluginFunctions(): void
    {
        if (!function_exists('get_plugins') || !function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * Select the first configured readable readme containing a supported name.
     *
     * @param string $directory Absolute extension directory.
     * @return string Sanitized name, or an empty string when none is available.
     */
    private function getNameFromReadme(string $directory): string
    {
        foreach ($this->config->getReadmeFiles() as $readme) {
            $file = trailingslashit($directory) . $readme;
            if (!is_readable($file)) {
                continue;
            }
            $content = file_get_contents($file);
            if (is_string($content)) {
                $name = $this->extractNameFromReadme($content);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return '';
    }

    /**
     * Recognize WordPress readme titles, Markdown headings, and name fields.
     *
     * @param string $content Readme text.
     * @return string Sanitized name, or an empty string when no pattern matches.
     */
    private function extractNameFromReadme(string $content): string
    {
        $content = str_replace("\xc2\xa0", ' ', $content);
        foreach (['/^\s*===\s*(.+?)\s*===\s*$/m', '/^\s*#\s+(.+?)\s*$/m', '/^[\s\/\*#@]*(?:Plugin|Theme)?\s*Name\s*:\s*(.+)$/mi'] as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return sanitize_text_field(trim($matches[1]));
            }
        }
        return '';
    }

    /**
     * Read a nonempty string from the configured package metadata file.
     *
     * @param string $directory Absolute extension directory.
     * @param string $field Package field, either name or version.
     * @return string Sanitized value, or an empty string for missing or invalid data.
     */
    private function packageField(string $directory, string $field): string
    {
        $file = trailingslashit($directory) . $this->config->getPackageFile();
        if (!is_readable($file)) {
            return '';
        }
        $content = file_get_contents($file);
        $package = is_string($content) ? json_decode($content, true) : null;
        return is_array($package) && !empty($package[$field]) && is_string($package[$field])
            ? sanitize_text_field(trim($package[$field])) : '';
    }
}

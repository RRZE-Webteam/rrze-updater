<?php

namespace RRZE\Updater\Upgrader;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\{GithubConnector, GitlabConnector, Plugin, RepositoryInspector};
use WP_Error;

/** Replace a complete plugin directory while retaining a recoverable previous copy. */
class PluginPackageReplacement
{
    /**
     * Inspect all target files and the root PHP entrypoints without executing plugin code.
     *
     * @param string $folder Validated single directory name under WP_PLUGIN_DIR.
     * @return array|WP_Error Content fingerprint and recognized entrypoints, or an unsafe-path error.
     */
    public function snapshot(string $folder): array|WP_Error
    {
        clearstatcache(true);
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $folder) || str_contains($folder, '..')) {
            return $this->error('invalid_destination', __('The installation folder is invalid.', 'rrze-updater'));
        }
        $root = realpath(WP_PLUGIN_DIR);
        $target = trailingslashit(WP_PLUGIN_DIR) . $folder;
        if (!$root || is_link($target) || !is_dir($target) || dirname(realpath($target) ?: '') !== $root) {
            return $this->error('invalid_destination', __('The existing plugin folder is missing, unsafe, or a symbolic link. Check the folder before trying again.', 'rrze-updater'));
        }
        try {
            $files = $this->tree($target);
            if (is_wp_error($files)) {
                return $files;
            }
            $identity = stat($target);
            return ['fingerprint' => hash('sha256', serialize([realpath($target), $identity['dev'], $identity['ino'], $identity['mode'], $files])),
                'entrypoints' => array_keys($this->headers($target)), 'path' => $target];
        } catch (\Throwable) {
            return $this->error('unreadable_destination', __('The existing plugin folder could not be inspected completely.', 'rrze-updater'));
        }
    }

    /**
     * Download and validate a pinned package, then exchange files and persist its association.
     *
     * @param Plugin $plugin Definition to install; localVersion is saved by the supplied callback.
     * @param string $commit Immutable reviewed Git commit.
     * @param array $expected Snapshot taken when the administrator reviewed the replacement.
     * @param callable $persist Callback returning true or WP_Error; must not activate or uninstall plugins.
     * @return true|WP_Error True only after files and association are persisted; otherwise restore old files.
     */
    public function replace(Plugin $plugin, string $commit, array $expected, callable $persist): true|WP_Error
    {
        $connector = $plugin->connector;
        if (!$connector instanceof GithubConnector && !$connector instanceof GitlabConnector) {
            return $this->error('unsupported_provider', __('Reinstallation requires a GitHub or GitLab service.', 'rrze-updater'));
        }
        $filesystem = $this->filesystem();
        if (is_wp_error($filesystem)) {
            return $filesystem;
        }
        $package = '';
        $workspace = '';
        $backedUp = false;
        $committed = false;
        $preserve = false;
        $target = trailingslashit(WP_PLUGIN_DIR) . $plugin->installationFolder;
        $backup = '';
        $maintenance = null;
        try {
            $package = $connector->downloadRepoZipToTempFile($plugin->repository, $commit);
            if (!is_string($package) || !is_file($package)) {
                return $this->error('download_failed', __('Could not download the reviewed plugin commit. Existing files were kept.', 'rrze-updater'));
            }
            $archive = $this->validateArchive($package);
            if (is_wp_error($archive)) {
                return $archive;
            }
            $workspace = $this->workspace($filesystem);
            if (is_wp_error($workspace)) {
                $error = $workspace;
                $workspace = '';
                return $error;
            }
            $backup = $workspace . '/previous';
            $unpacked = $workspace . '/package';
            $result = $this->extract($package, $unpacked);
            if (is_wp_error($result)) {
                return $this->error('invalid_archive', __('The plugin archive could not be extracted. Existing files were kept.', 'rrze-updater'));
            }
            $source = $this->packageRoot($unpacked);
            if (is_wp_error($source)) {
                return $source;
            }
            $validated = $this->validatePackage($source, $expected['entrypoints']);
            if (is_wp_error($validated)) {
                return $validated;
            }
            // Downloading can take a while. Recheck every existing file immediately before the swap.
            $current = $this->snapshot($plugin->installationFolder);
            if (is_wp_error($current) || $current !== $expected) {
                return $this->error('replacement_changed', __('The plugin files changed since review. Please check the installation again.', 'rrze-updater'));
            }
            $maintenance = $this->beginMaintenance($filesystem);
            if (is_wp_error($maintenance)) {
                return $maintenance;
            }
            if (!$filesystem->move($target, $backup, false)) {
                return $this->error('backup_failed', __('The existing plugin folder could not be backed up. No replacement was started.', 'rrze-updater'));
            }
            $backedUp = true;
            if (!$filesystem->move($source, $target, false)) {
                $result = $this->error('replacement_failed', __('The replacement files could not be installed.', 'rrze-updater'));
            } else {
                $this->clearCaches();
                $result = $persist();
                if ($result !== true && !is_wp_error($result)) {
                    $result = $this->error('save_failed', __('The plugin association could not be saved.', 'rrze-updater'));
                }
            }
            if ($result !== true) {
                $restored = $this->restore($filesystem, $target, $backup);
                $backedUp = false;
                if (is_wp_error($restored)) {
                    $preserve = true;
                    return $restored;
                }
                return $result;
            }
            $committed = true;
            $backedUp = false;
            return true;
        } catch (\Throwable) {
            if ($backedUp && !$committed) {
                $restored = $this->restore($filesystem, $target, $backup);
                $backedUp = false;
                if (is_wp_error($restored)) {
                    $preserve = true;
                    return $restored;
                }
            }
            return $this->error('replacement_failed', __('The plugin replacement was interrupted. The previous files were kept or restored.', 'rrze-updater'));
        } finally {
            try {
                if (is_string($package) && is_file($package)) {
                    wp_delete_file($package);
                }
                if ($workspace !== '' && !$preserve) {
                    $filesystem->delete($workspace, true);
                }
                $this->clearCaches();
            } catch (\Throwable) {
                // Cleanup failures must never mask the transaction or recovery outcome.
            }
            if (is_array($maintenance)) {
                $this->endMaintenance($filesystem, $maintenance);
            }
        }
    }

    /**
     * Initialize the WordPress filesystem and require paths that can be independently inspected.
     *
     * @return object|WP_Error WordPress filesystem adapter, or an unsupported-filesystem error.
     */
    protected function filesystem(): object
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return $this->error('filesystem_unavailable', __('WordPress cannot access the filesystem for plugin replacement.', 'rrze-updater'));
        }
        global $wp_filesystem;
        if (untrailingslashit($wp_filesystem->wp_plugins_dir()) !== untrailingslashit(WP_PLUGIN_DIR)
            || untrailingslashit($wp_filesystem->wp_content_dir()) !== untrailingslashit(WP_CONTENT_DIR)
            || untrailingslashit($wp_filesystem->abspath()) !== untrailingslashit(ABSPATH)) {
            return $this->error('filesystem_unavailable', __('Plugin replacement requires filesystem paths that WordPress can inspect locally.', 'rrze-updater'));
        }
        return $wp_filesystem;
    }

    /**
     * Exclusively claim a short WordPress maintenance window before removing active plugin files.
     *
     * The filesystem has already been checked for locally inspectable paths. An
     * exclusive create avoids overwriting maintenance state owned by another updater.
     * Core automatically expires the timestamp after a killed request.
     *
     * @param object $filesystem Initialized WordPress filesystem adapter.
     * @return array|WP_Error Owned path and exact content, or a failure before any plugin files move.
     */
    protected function beginMaintenance(object $filesystem): array|WP_Error
    {
        $path = trailingslashit($filesystem->abspath()) . '.maintenance';
        $content = '<?php $upgrading = ' . time() . '; // rrze-updater:' . bin2hex(random_bytes(16)) . "\n";
        $handle = @fopen($path, 'x');
        if (!$handle) {
            return $this->error('maintenance_unavailable', __('The maintenance window could not be started. Another update may be running, or the WordPress root is not writable. Existing plugin files were kept.', 'rrze-updater'));
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content)) {
            $partial = $written === false ? '' : substr($content, 0, $written);
            if (@file_get_contents($path) === $partial) {
                $filesystem->delete($path);
            }
            return $this->error('maintenance_unavailable', __('The maintenance window could not be started. Existing plugin files were kept.', 'rrze-updater'));
        }
        return ['path' => $path, 'content' => $content];
    }

    /**
     * Remove only the exact maintenance file owned by this replacement operation.
     *
     * @param object $filesystem WordPress filesystem adapter.
     * @param array $maintenance Owned maintenance path and exact content.
     */
    private function endMaintenance(object $filesystem, array $maintenance): void
    {
        try {
            if (@file_get_contents($maintenance['path']) === $maintenance['content']) {
                $filesystem->delete($maintenance['path']);
            }
        } catch (\Throwable) {
            // Core's timestamp expiry remains a fallback if cleanup becomes impossible.
        }
    }

    /**
     * Create a unique staging directory outside WordPress's disposable upgrade directories.
     *
     * @param object $filesystem WordPress filesystem adapter.
     * @return string|WP_Error Protected working directory or an error.
     */
    protected function workspace(object $filesystem): string|WP_Error
    {
        $base = trailingslashit(WP_CONTENT_DIR) . 'rrze-updater-recovery';
        if (is_link($base) || (file_exists($base) && !is_dir($base))) {
            return $this->error('unsafe_staging', __('The plugin recovery directory is unsafe.', 'rrze-updater'));
        }
        if (!$filesystem->is_dir($base) && !$filesystem->mkdir($base, 0700)) {
            return $this->error('staging_failed', __('The plugin recovery directory could not be created.', 'rrze-updater'));
        }
        if (dirname(realpath($base) ?: '') !== realpath(WP_CONTENT_DIR)) {
            return $this->error('unsafe_staging', __('The plugin recovery directory is unsafe.', 'rrze-updater'));
        }
        // The random per-operation folder also protects stored sources on non-Apache servers.
        $filesystem->put_contents($base . '/index.php', "<?php\n// No direct access.\n", 0600);
        $filesystem->put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n", 0600);
        $path = $base . '/' . bin2hex(random_bytes(24));
        if (!$filesystem->mkdir($path, 0700)) {
            return $this->error('staging_failed', __('The plugin staging directory could not be created.', 'rrze-updater'));
        }
        return $path;
    }

    /**
     * Reject traversal, absolute paths, symlinks and non-directory/non-file ZIP entries.
     *
     * @param string $package Downloaded ZIP filename.
     * @return true|WP_Error Whether archive entries are safe to extract.
     */
    protected function validateArchive(string $package): true|WP_Error
    {
        if (!class_exists(\ZipArchive::class)) {
            return $this->error('zip_unavailable', __('The PHP ZIP extension is required to safely inspect a replacement archive.', 'rrze-updater'));
        }
        $zip = new \ZipArchive();
        if ($zip->open($package) !== true) {
            return $this->error('invalid_archive', __('The downloaded file is not a readable ZIP archive.', 'rrze-updater'));
        }
        try {
            if ($zip->numFiles === 0) {
                return $this->error('invalid_archive', __('The plugin archive is empty.', 'rrze-updater'));
            }
            $seen = $spellings = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $system = $attributes = 0;
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                $type = ($attributes >> 16) & 0170000;
                $path = rtrim((string) $name, '/');
                if ($path === '' || preg_match('/[\\\\\x00-\x1f\x7f:]/', $path) || str_starts_with($path, '/')
                    || array_intersect(explode('/', $path), ['.', '..', '']) || isset($seen[strtolower($path)])
                    || ($system === \ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true))) {
                    return $this->error('unsafe_archive', __('The plugin archive contains unsafe or ambiguous file paths.', 'rrze-updater'));
                }
                $prefix = '';
                foreach (explode('/', $path) as $part) {
                    $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
                    $key = strtolower($prefix);
                    if (isset($spellings[$key]) && $spellings[$key] !== $prefix) {
                        return $this->error('unsafe_archive', __('The plugin archive contains unsafe or ambiguous file paths.', 'rrze-updater'));
                    }
                    $spellings[$key] = $prefix;
                }
                $seen[strtolower($path)] = true;
            }
            return true;
        } finally {
            $zip->close();
        }
    }

    /**
     * Extract through the WordPress filesystem without cleaning unrelated upgrade directories.
     *
     * @param string $package Downloaded ZIP file.
     * @param string $destination Isolated empty extraction directory.
     * @return true|WP_Error WordPress extraction result.
     */
    protected function extract(string $package, string $destination): true|WP_Error
    {
        return unzip_file($package, $destination);
    }

    /**
     * Identify either a root-level plugin package or its single provider-generated wrapper.
     *
     * @param string $directory Extracted archive directory.
     * @return string|WP_Error Actual plugin source directory, or a structure error.
     */
    protected function packageRoot(string $directory): string|WP_Error
    {
        if ($this->headers($directory)) {
            return $directory;
        }
        $entries = array_values(array_filter(scandir($directory) ?: [], static fn(string $name): bool => $name !== '.' && $name !== '..'));
        if (count($entries) === 1 && is_dir($directory . '/' . $entries[0])) {
            return $directory . '/' . $entries[0];
        }
        return $this->error('invalid_package', __('The archive must contain a plugin at its root or inside one enclosing directory.', 'rrze-updater'));
    }

    /**
     * Check every recognized entrypoint, platform requirement and the complete extracted tree.
     *
     * @param string $source Extracted plugin directory.
     * @param array $entrypoints Existing recognized main filenames that must remain present.
     * @return true|WP_Error Whether the replacement can safely retain the existing plugin identity.
     */
    protected function validatePackage(string $source, array $entrypoints): true|WP_Error
    {
        $tree = $this->tree($source);
        if (is_wp_error($tree)) {
            return $tree;
        }
        $headers = $this->headers($source);
        if (!$headers || array_diff($entrypoints, array_keys($headers))) {
            return $this->error('missing_entrypoint', __('The replacement must contain a valid plugin and preserve all existing plugin main filenames.', 'rrze-updater'));
        }
        global $wp_version;
        foreach ($headers as $content) {
            $requiresWp = RepositoryInspector::header($content, 'Requires at least');
            $requiresPhp = RepositoryInspector::header($content, 'Requires PHP');
            if (($requiresWp !== '' && version_compare($wp_version, $requiresWp, '<'))
                || ($requiresPhp !== '' && version_compare(PHP_VERSION, $requiresPhp, '<'))) {
                return $this->error('incompatible_package', __('The replacement requires a newer WordPress or PHP version.', 'rrze-updater'));
            }
        }
        return true;
    }

    /**
     * Read root PHP plugin headers using the same byte limit and parser as WordPress.
     *
     * @param string $directory Plugin directory.
     * @return array<string,string> PHP filenames mapped to their first 8 KiB.
     */
    private function headers(string $directory): array
    {
        $headers = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            if (is_link($file) || !is_file($file)) {
                continue;
            }
            $content = file_get_contents($file, false, null, 0, 8192);
            if (is_string($content) && RepositoryInspector::header($content, 'Plugin Name') !== '') {
                $headers[basename($file)] = $content;
            }
        }
        ksort($headers);
        return $headers;
    }

    /**
     * Hash a directory without following any links or accepting special filesystem entries.
     *
     * @param string $directory Absolute directory to inspect.
     * @return array|WP_Error Sorted path/type/content records, or an unsafe/unreadable-file error.
     */
    private function tree(string $directory): array|WP_Error
    {
        $records = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink() || (!$file->isFile() && !$file->isDir())) {
                return $this->error('unsafe_files', __('Plugin replacement does not support symbolic links or special filesystem entries.', 'rrze-updater'));
            }
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $hash = $file->isDir() ? 'directory' : @hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                return $this->error('unreadable_files', __('A plugin file could not be read completely.', 'rrze-updater'));
            }
            $records[$relative] = [$hash, $file->getPerms() & 0777];
        }
        ksort($records);
        return $records;
    }

    /**
     * Restore a previous directory and retain its location whenever recovery is incomplete.
     *
     * @param object $filesystem WordPress filesystem adapter.
     * @param string $target Destination of the failed installation.
     * @param string $backup Previous directory retained until association persistence succeeds.
     * @return true|WP_Error Whether rollback succeeded, otherwise an error carrying the backup path.
     */
    private function restore(object $filesystem, string $target, string $backup): true|WP_Error
    {
        try {
            if ((!$filesystem->exists($target) || $filesystem->delete($target, true)) && $filesystem->move($backup, $target, false)) {
                return true;
            }
        } catch (\Throwable) {
            // A retained backup is more useful than leaking adapter exception details.
        }
        return new WP_Error('rrze_updater_recovery_failed', sprintf(
            /* translators: %s: Absolute path to the retained previous plugin directory. */
            __('Automatic recovery failed. The previous plugin files are retained at %s. Restore this directory before trying again.', 'rrze-updater'), $backup
        ), ['backup_path' => $backup]);
    }

    /** Invalidate plugin discovery and update metadata after either replacement or recovery. */
    private function clearCaches(): void
    {
        wp_cache_delete('plugins', 'plugins');
        delete_site_transient('update_plugins');
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(false);
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Build an error with a stable public code and a credential-free localized message.
     *
     * @param string $code Error suffix.
     * @param string $message Safe user-facing message.
     * @return WP_Error Replacement error.
     */
    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error('rrze_updater_' . $code, $message);
    }
}

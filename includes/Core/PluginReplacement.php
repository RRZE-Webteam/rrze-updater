<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\{FilesystemLock, Settings};
use RRZE\Updater\Upgrader\PluginPackageReplacement;
use WP_Error;

/** Server-side review and explicit, single-use confirmation of plugin file replacement. */
class PluginReplacement
{
    /** @var RepositoryManager Validates proposed plugin definitions without changing their files. */
    private RepositoryManager $manager;

    /**
     * Construct the replacement coordinator with independently testable collaborators.
     *
     * @param Settings $settings Registry shared with the calling controller.
     * @param RepositoryManager|null $manager Optional definition validator.
     * @param PluginPackageReplacement $packages File inspection and rollback transaction.
     */
    public function __construct(
        private Settings $settings,
        ?RepositoryManager $manager = null,
        private PluginPackageReplacement $packages = new PluginPackageReplacement()
    ) {
        $this->manager = $manager ?? new RepositoryManager($settings);
    }

    /**
     * Review an existing plugin directory without downloading or replacing plugin files.
     *
     * @param string $repository Repository name, matching the stored source for reinstallation.
     * @param array $options Connector, folder, branch and updates configuration.
     * @param string|null $pluginId Existing association ID, or null for an unmanaged leftover directory.
     * @return array|WP_Error Credential-free review data, valid for this administrator for 15 minutes.
     */
    public function prepare(string $repository, array $options, ?string $pluginId = null): array|WP_Error
    {
        return FilesystemLock::withLock(function () use ($repository, $options, $pluginId): array|WP_Error {
            $allowed = $this->authorize();
            if (is_wp_error($allowed)) {
                return $allowed;
            }
            $this->settings->reload();
            $stored = $pluginId !== null ? $this->findPlugin($pluginId) : null;
            if ($pluginId !== null && (!$stored || !$this->matches($stored, $repository, $options))) {
                return $this->changed();
            }
            $folder = $options['folder'] ?? $repository;
            foreach ($this->settings->plugins as $candidate) {
                if ($candidate->id === $pluginId) {
                    continue;
                }
                if (is_string($folder) && strcasecmp($candidate->installationFolder, $folder) === 0) {
                    return new WP_Error('rrze_updater_managed_destination', __('This folder already belongs to a managed plugin. Open its settings to reinstall it or change its source.', 'rrze-updater'), ['plugin_id' => $candidate->id]);
                }
                if ($candidate->connectorId === ($options['connector'] ?? '') && $this->sameRepository($candidate, $repository)) {
                    return $this->error('conflicting_repository', __('This repository is already assigned to another plugin folder.', 'rrze-updater'));
                }
            }
            try {
                $definition = $this->manager->preparePluginDefinition($repository, $options);
                if (is_wp_error($definition)) {
                    return $this->safeRemoteError();
                }
                if (!$definition->connector instanceof GithubConnector && !$definition->connector instanceof GitlabConnector) {
                    return $this->error('unsupported_provider', __('Reinstallation requires a GitHub or GitLab service.', 'rrze-updater'));
                }
                $snapshot = $this->packages->snapshot($definition->installationFolder);
                if (is_wp_error($snapshot)) {
                    return $snapshot;
                }
                $commit = $definition->connector->getRemoteCommit($repository, $definition->remoteVersion);
                if (!is_string($commit) || !preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/i', $commit)) {
                    return $this->safeRemoteError();
                }
                $id = bin2hex(random_bytes(24));
                $review = [
                    'id' => $id, 'plugin_id' => $pluginId, 'repository' => $repository,
                    'folder' => $definition->installationFolder,
                    'source_url' => $definition->connector->getUrl($repository),
                    'ref' => $definition->remoteVersion, 'commit' => $commit,
                    'version' => $definition->remoteReadableVersion ?: $definition->remoteVersion,
                    'expires_at' => $this->now() + 900, 'mode' => $pluginId === null ? 'replace' : 'reinstall',
                    'owner' => get_current_user_id(), 'scope' => $this->scope(),
                    'options' => $this->options($definition), 'source' => RepositorySource::fingerprint($definition),
                    'snapshot' => $snapshot,
                ];
                if (!$this->writeReview($id, $review)) {
                    return $this->error('review_save_failed', __('The confirmation could not be saved. No plugin files were changed.', 'rrze-updater'));
                }
                return $this->publicReview($review);
            } catch (\Throwable) {
                return $this->safeRemoteError();
            }
        });
    }

    /**
     * Read a confirmation after checking administrator identity, installation scope and expiry.
     *
     * @param string $reviewId Opaque ID from prepare(), never configuration posted by the browser.
     * @return array|WP_Error Public review fields or an expired/unauthorized review error.
     */
    public function getReview(string $reviewId): array|WP_Error
    {
        $review = $this->validatedReview($reviewId);
        return is_wp_error($review) ? $review : $this->publicReview($review);
    }

    /**
     * Consume an authorized confirmation once and replace its pinned plugin files transactionally.
     *
     * @param string $reviewId Opaque server-side review ID.
     * @return string|WP_Error Managed plugin ID only after files and settings are saved.
     */
    public function apply(string $reviewId): string|WP_Error
    {
        return FilesystemLock::withLock(function () use ($reviewId): string|WP_Error {
            $review = $this->validatedReview($reviewId);
            if (is_wp_error($review)) {
                return $review;
            }
            // Consume before any remote or filesystem work. Failed attempts require fresh review too.
            if (!$this->deleteReview($reviewId)) {
                return $this->error('review_consume_failed', __('The confirmation could not be consumed safely. No files were changed.', 'rrze-updater'));
            }
            try {
                $this->settings->reload();
                $plugin = $this->reviewedDefinition($review);
                if (is_wp_error($plugin)) {
                    return $plugin;
                }
                $state = $this->packages->snapshot($plugin->installationFolder);
                if (is_wp_error($state) || $state !== $review['snapshot']) {
                    return $this->changed();
                }
                $result = $this->packages->replace($plugin, $review['commit'], $review['snapshot'], function () use ($plugin, $review): true|WP_Error {
                    // Reload under the shared filesystem lock so unrelated settings edits are retained.
                    $this->settings->reload();
                    $current = $this->reviewedDefinition($review);
                    if (is_wp_error($current)) {
                        return $current;
                    }
                    $plugin->localVersion = $review['ref'];
                    $plugin->remoteVersion = $review['ref'];
                    $plugin->remoteReadableVersion = $review['version'];
                    $plugin->lastChecked = $this->now();
                    $plugin->lastError = $plugin->lastWarning = '';
                    if ($review['plugin_id'] === null) {
                        $this->settings->plugins[] = $plugin;
                    } else {
                        foreach ($this->settings->plugins as $index => $candidate) {
                            if ($candidate->id === $review['plugin_id']) {
                                $this->settings->plugins[$index] = $plugin;
                            }
                        }
                    }
                    $expected = $plugin->asArray();
                    $expectedSource = RepositorySource::fingerprint($plugin);
                    $saveThrew = false;
                    try {
                        $saved = $this->settings->save();
                    } catch (\Throwable) {
                        // WordPress runs option hooks after its database write.
                        // An exception therefore cannot prove that saving failed.
                        $saved = false;
                        $saveThrew = true;
                    }
                    if (!$saved) {
                        $this->settings->reload();
                        $stored = $this->findPlugin($plugin->id);
                        if ($stored && $stored->asArray() === $expected
                            && hash_equals($expectedSource, RepositorySource::fingerprint($stored))) {
                            return true;
                        }
                        return $this->error($saveThrew ? 'replacement_failed' : 'save_failed', __('The plugin association could not be saved. Previous plugin files were restored.', 'rrze-updater'));
                    }
                    return true;
                });
                if (is_wp_error($result)) {
                    $this->settings->reload();
                    return $result;
                }
                // The package transaction already invalidates caches, including
                // after recovery. Post-commit cleanup must not reverse success.
                return $plugin->id;
            } catch (\Throwable) {
                $this->settings->reload();
                return $this->error('replacement_failed', __('The plugin replacement could not be completed. Please review the installation again.', 'rrze-updater'));
            }
        });
    }

    /**
     * Discard only a confirmation belonging to the current administrator and installation scope.
     *
     * @param string $reviewId Server-side confirmation ID.
     */
    public function cancel(string $reviewId): void
    {
        FilesystemLock::withLock(function () use ($reviewId): void {
            if (!is_wp_error($this->validatedReview($reviewId))) {
                $this->deleteReview($reviewId);
            }
        });
    }

    /**
     * Reconstruct a reviewed source from current trusted connector configuration.
     *
     * @param array $review Stored review including its source fingerprint.
     * @return Plugin|WP_Error Current definition if no source or association preconditions changed.
     */
    private function reviewedDefinition(array $review): Plugin|WP_Error
    {
        $existing = $review['plugin_id'] !== null ? $this->findPlugin($review['plugin_id']) : null;
        if ($review['plugin_id'] !== null && (!$existing || !$this->matches($existing, $review['repository'], $review['options']))) {
            return $this->changed();
        }
        foreach ($this->settings->plugins as $candidate) {
            if ($candidate->id !== $review['plugin_id'] && (strcasecmp($candidate->installationFolder, $review['folder']) === 0
                || ($candidate->connectorId === $review['options']['connector'] && $this->sameRepository($candidate, $review['repository'])))) {
                return $this->changed();
            }
        }
        $connector = $this->settings->getConnectorById($review['options']['connector']);
        if (!$connector) {
            return $this->changed();
        }
        $plugin = $existing ? clone $existing : Plugin::createFromArray([
            'connectorId' => $connector->id, 'repository' => $review['repository'],
            'installationFolder' => $review['folder'], 'branch' => $review['options']['branch'],
            'updates' => $review['options']['updates'],
        ]);
        $plugin->connector = $connector;
        return hash_equals($review['source'], RepositorySource::fingerprint($plugin)) ? $plugin : $this->changed();
    }

    /**
     * Check review ownership without exposing the presence of another administrator's confirmation.
     *
     * @param string $id Opaque review ID.
     * @return array|WP_Error Internal review or a generic expired/invalid confirmation error.
     */
    private function validatedReview(string $id): array|WP_Error
    {
        $allowed = $this->authorize();
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        $review = preg_match('/\A[a-f0-9]{48}\z/', $id) ? $this->readReview($id) : false;
        if (!is_array($review) || ($review['id'] ?? '') !== $id || ($review['owner'] ?? 0) !== get_current_user_id()
            || ($review['scope'] ?? '') !== $this->scope() || ($review['expires_at'] ?? 0) <= $this->now()) {
            return $this->error('invalid_review', __('This confirmation has expired, was already used, or belongs to another administrator. Please review the installation again.', 'rrze-updater'));
        }
        return $review;
    }

    /**
     * Enforce the same administrator and filesystem capabilities on every entrypoint.
     *
     * @return true|WP_Error Whether the current user may replace plugin files.
     */
    private function authorize(): true|WP_Error
    {
        if (!get_current_user_id() || !current_user_can('install_plugins') || !current_user_can('update_plugins')
            || (is_multisite() ? (!is_super_admin() || !current_user_can('manage_network_options')) : !current_user_can('manage_options'))
            || !wp_is_file_mod_allowed('rrze_updater_replace_plugin')) {
            return $this->error('replacement_forbidden', __('You are not allowed to replace plugin files on this installation.', 'rrze-updater'));
        }
        return true;
    }

    /**
     * Identify the precise site and network where the administrator reviewed the operation.
     *
     * @return string Stable installation scope including the current site on multisite.
     */
    private function scope(): string
    {
        return (is_multisite() ? 'network:' . get_current_network_id() : 'single') . ':site:' . get_current_blog_id();
    }

    /**
     * Fetch an association without relying on its current array index.
     *
     * @param string $id Plugin association ID.
     * @return Plugin|null Matching managed plugin.
     */
    private function findPlugin(string $id): ?Plugin
    {
        foreach ($this->settings->plugins as $plugin) {
            if ($plugin->id === $id) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Match repository identity using the provider's naming rules.
     *
     * @param Plugin $plugin Existing association with its current connector.
     * @param string $repository Candidate repository name.
     * @return bool Whether the names identify the same repository.
     */
    private function sameRepository(Plugin $plugin, string $repository): bool
    {
        return $plugin->connector instanceof GithubConnector
            ? strcasecmp($plugin->repository, $repository) === 0
            : $plugin->repository === $repository;
    }

    /**
     * Compare posted values with the persisted source instead of permitting an implicit source edit.
     *
     * @param Plugin $plugin Stored association.
     * @param string $repository Posted repository.
     * @param array $options Posted definition.
     * @return bool Whether all source and folder values match.
     */
    private function matches(Plugin $plugin, string $repository, array $options): bool
    {
        return $plugin->repository === $repository && $this->options($plugin) === [
            'connector' => $options['connector'] ?? '', 'folder' => $options['folder'] ?? $repository,
            'branch' => $options['branch'] ?? 'main', 'updates' => $options['updates'] ?? 'tags',
            'ignore-prereleases' => !empty($options['ignore-prereleases']),
        ];
    }

    /**
     * Select only credential-free configuration needed to reconstruct the plugin definition.
     *
     * @param Plugin $plugin Validated plugin.
     * @return array Connector ID, folder, branch and update policy.
     */
    private function options(Plugin $plugin): array
    {
        return ['connector' => $plugin->connectorId, 'folder' => $plugin->installationFolder,
            'branch' => $plugin->branch, 'updates' => $plugin->updates, 'ignore-prereleases' => $plugin->ignorePrereleases];
    }

    /**
     * Keep server-side conditions out of the browser while providing all confirmation labels.
     *
     * @param array $review Stored review.
     * @return array Public confirmation fields.
     */
    private function publicReview(array $review): array
    {
        return array_intersect_key($review, array_flip(['id', 'plugin_id', 'repository', 'folder', 'source_url', 'ref', 'commit', 'version', 'expires_at', 'mode']));
    }

    /**
     * Store a short-lived review in the WordPress installation's network-aware transient storage.
     *
     * @param string $id Opaque review ID.
     * @param array $review Credential-free server-side conditions.
     * @return bool Whether storage succeeded.
     */
    protected function writeReview(string $id, array $review): bool
    {
        return set_site_transient('rrze_updater_replace_' . $id, $review, 900);
    }

    /**
     * Read a review from network-aware transient storage.
     *
     * @param string $id Opaque review ID.
     * @return array|false Stored review, or false when expired/missing.
     */
    protected function readReview(string $id): array|false
    {
        $review = get_site_transient('rrze_updater_replace_' . $id);
        return is_array($review) ? $review : false;
    }

    /**
     * Delete a review and verify that it cannot be replayed even if a cache adapter reports failure.
     *
     * @param string $id Opaque review ID.
     * @return bool Whether the review is absent after consumption.
     */
    protected function deleteReview(string $id): bool
    {
        delete_site_transient('rrze_updater_replace_' . $id);
        return $this->readReview($id) === false;
    }

    /** @return int Current Unix timestamp, overridable for deterministic expiry tests. */
    protected function now(): int
    {
        return time();
    }

    /** @return WP_Error Generic changed-preconditions error requiring a new administrator review. */
    private function changed(): WP_Error
    {
        return $this->error('replacement_changed', __('The plugin source, assignment or files changed since review. Please review the installation again.', 'rrze-updater'));
    }

    /** @return WP_Error Safe lookup failure without provider responses, URLs or credentials. */
    private function safeRemoteError(): WP_Error
    {
        return $this->error('replacement_source_unavailable', __('The selected plugin source or commit could not be verified. Check the service, repository and update policy.', 'rrze-updater'));
    }

    /**
     * Create a stable replacement error without exposing transport exception details.
     *
     * @param string $code Public error suffix.
     * @param string $message Safe localized explanation.
     * @return WP_Error Structured operation failure.
     */
    private function error(string $code, string $message): WP_Error
    {
        return new WP_Error('rrze_updater_' . $code, $message);
    }
}

<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\Extension;
use WP_Error;

/** Persist and remove classic repository definitions independently of HTTP requests. */
class RepositoryActions
{
    /**
     * Share the settings instance used by the requesting component.
     *
     * @param Settings $settings Repository settings and merge baseline.
     */
    public function __construct(private Settings $settings) {}

    /**
     * Persist changes, restoring shared state in place when the write is rejected.
     *
     * @return true|WP_Error True on persistence, otherwise a recoverable save error.
     */
    public function save(): true|WP_Error
    {
        if ($this->settings->save()) {
            return true;
        }

        // Other consumers retain this object, so replacing it would leave them
        // with rejected edits and an obsolete concurrency baseline.
        $this->settings->reload();
        return new WP_Error('rrze_updater_save_failed', __('Could not save the changes. Settings may have changed in another request, or the database may be unavailable. The stored values have been reloaded; review them and try again.', 'rrze-updater'));
    }

    /**
     * Check a repository and persist its result, including remote error metadata.
     *
     * Cache invalidation belongs to the caller because AJAX and editor checks
     * have different response and cache behavior.
     *
     * @param Extension $extension Repository attached to the shared settings.
     * @return true|WP_Error Persistence outcome; remote errors remain on the extension.
     */
    public function check(Extension $extension): true|WP_Error
    {
        $extension->checkForUpdates();
        return $this->save();
    }

    /**
     * Remove a plugin mapping without deleting its installed files.
     *
     * @param string $id Managed plugin ID.
     * @return true|WP_Error True when absent or removed, otherwise a save error.
     */
    public function deletePlugin(string $id): true|WP_Error
    {
        return $this->deleteExtension('plugins', sanitize_text_field($id));
    }

    /**
     * Remove a theme mapping without deleting its installed files.
     *
     * @param string $id Managed theme ID.
     * @return true|WP_Error True when absent or removed, otherwise a save error.
     */
    public function deleteTheme(string $id): true|WP_Error
    {
        return $this->deleteExtension('themes', sanitize_text_field($id));
    }

    /**
     * Remove an unused connector, preserving connectors referenced by repositories.
     *
     * @param string $id Connector ID.
     * @return true|WP_Error True when absent or the current state was persisted.
     */
    public function deleteConnector(string $id): true|WP_Error
    {
        $connector = $this->settings->getConnectorById(sanitize_text_field($id));
        if (!$connector) {
            return true;
        }

        foreach ($this->settings->connectors as $key => $candidate) {
            if ($connector->id == $candidate->id && !$this->settings->isConnectorUsed($candidate->id)) {
                unset($this->settings->connectors[$key]);
            }
        }
        return $this->save();
    }

    /**
     * Remove a known extension and invalidate its update cache only after saving.
     *
     * @param string $property Settings collection, either plugins or themes.
     * @param string $id Sanitized extension ID.
     * @return true|WP_Error Persistence outcome; absent entries are successful no-ops.
     */
    private function deleteExtension(string $property, string $id): true|WP_Error
    {
        $extension = $property === 'plugins'
            ? $this->settings->getPluginById($id)
            : $this->settings->getThemeById($id);
        if (!$extension) {
            return true;
        }

        foreach ($this->settings->$property as $key => $candidate) {
            if ($extension->id == $candidate->id) {
                unset($this->settings->{$property}[$key]);
            }
        }

        $result = $this->save();
        if ($result === true) {
            delete_site_transient('update_' . $property);
        }
        return $result;
    }
}

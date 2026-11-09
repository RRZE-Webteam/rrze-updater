<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Config, Settings, RepositoryActions};
use RRZE\Updater\ListTable\RepoListTable;
use RRZE\Updater\Core\{Plugin, Theme};
use WP_Error;

/** Handles repository overview requests and explicit update actions. */
class RepositoryController
{
    /** @var AdminMessages Notices shared by the current administrative request. */
    private AdminMessages $messages;

    /**
     * Inject the concrete dependencies used by this administrative component.
     *
     * @param Settings $settings Shared registry and user settings.
     * @param Config $config Plugin configuration and hook names.
     * @param RepositoryActions $actions Shared persistence, checking and deletion operations.
     * @param RepositoryPresenter $presenter Repository display-data builder.
     * @param AdminRenderer $renderer Explicit-data page renderer.
     * @param RepositoryUpdateRunner $updates Sequential single-update runner.
     * @param AdminMessages|null $messages Optional request-wide notice collection.
     */
    public function __construct(
        private Settings $settings,
        private Config $config,
        private RepositoryActions $actions,
        private RepositoryPresenter $presenter,
        private AdminRenderer $renderer,
        private RepositoryUpdateRunner $updates,
        ?AdminMessages $messages = null
    ) {
        $this->messages = $messages ?? new AdminMessages();
    }

    /**
     * Process overview actions before presenting repository rows.
     *
     * @return void
     */
    public function getRepoIndex(): void
    {
        if ($action = $this->getAction()) {
            $this->getRepoAction($action);
            if ($action !== 'delete') {
                return;
            }
        }
        $this->processBulkAction();
        $repos = $this->presenter->repositoryRows(sanitize_text_field($_GET['s'] ?? ''));
        $table = new RepoListTable($repos);
        $table->prepare_items();
        $this->display('repositories/index', [
            'listTable' => $table,
            'bulkMaintenanceNotice' => $this->presenter->getBulkMaintenanceNotice($repos),
            'updateCheckItems' => $this->presenter->getRepositoryUpdateCheckItems(),
            'updateCheckDelay' => $this->getUpdateCheckDelay(),
            'updateCheckNonce' => wp_create_nonce('rrze-updater-check-updates'),
        ]);
    }

    /**
     * Check one repository and return the established AJAX response payload.
     *
     * @return void
     */
    public function ajaxCheckRepositoryUpdate(): void
    {
        check_ajax_referer('rrze-updater-check-updates', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You need a higher level of permission.', 'rrze-updater')
            ], 403);
        }

        $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
        $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
        $extension = $this->getUpdateCheckExtension($type, $id);

        if (!$extension) {
            do_action(
                'rrze.log.error',
                'Repository update check failed: resource not found. Type: {extension-type} ID: {extension-id}',
                [
                    'plugin' => $this->config->getLogPlugin(),
                    'extension-type' => $type,
                    'extension-id' => $id
                ]
            );

            wp_send_json_error([
                'message' => __('Repository not found.', 'rrze-updater')
            ], 404);
        }

        $result = $this->actions->check($extension);
        if (is_wp_error($result)) {
            $this->messages->add($result);
            wp_send_json_error(['message' => $this->messages->last()->get_error_message()], 409);
            return;
        }

        if ($type == 'plugin') {
            delete_site_transient('update_plugins');
        } elseif ($type == 'theme') {
            delete_site_transient('update_themes');
        }

        $status = $this->presenter->extensionHasUpdate($extension)
            ? __('Neues Update vorhanden', 'rrze-updater')
            : __('Aktuell', 'rrze-updater');
        $isError = !empty($extension->lastError);

        if ($isError) {
            $status = sprintf(
                /* translators: %s: Update check error */
                __('Fehler: %s', 'rrze-updater'),
                $this->formatExtensionMessage($extension->lastError)
            );

            if ($this->isResourceNotFoundError($extension->lastError)) {
                $this->logResourceNotFoundUpdateCheck($extension, $type);
            }
        }

        wp_send_json_success([
            'id' => $extension->id,
            'type' => $type,
            'name' => $this->presenter->getExtensionDisplayName($extension),
            'repository' => $this->presenter->getExtensionRepositoryPath($extension),
            'branch' => $extension->branch,
            'status' => $status,
            'isError' => $isError,
            'hasUpdate' => $this->presenter->extensionHasUpdate($extension),
        ]);
    }

    /**
     * Resolve the managed extension for the requested type and ID.
     *
     * @param string $type Managed extension type: plugin or theme.
     * @param string $id Managed association ID.
     * @return Plugin|Theme|false Matching extension or false.
     */
    private function getUpdateCheckExtension(string $type, string $id): Plugin|Theme|false
    {
        if ($type == 'plugin') {
            return $this->settings->getPluginById($id);
        }

        if ($type == 'theme') {
            return $this->settings->getThemeById($id);
        }

        return false;
    }

    /**
     * Read the configured check delay with the established minimum.
     *
     * @return int Operation result.
     */
    private function getUpdateCheckDelay(): int
    {
        $default = (int) $this->config->getDefaultSettings()['update_check_delay'];

        return max(1, absint($this->settings->options['update_check_delay'] ?? $default));
    }

    /**
     * Normalize update errors for safe status and log messages.
     *
     * @param mixed $message Provider message, message list or WordPress error.
     * @return string Formatted value or empty when unavailable.
     */
    private function formatExtensionMessage($message): string
    {
        if (is_wp_error($message)) {
            return $message->get_error_message();
        }

        if (is_array($message)) {
            return implode(', ', array_map('sanitize_text_field', $message));
        }

        return sanitize_text_field((string) $message);
    }

    /**
     * Recognize existing provider not-found messages for structured logging.
     *
     * @param mixed $message Provider message, message list or WordPress error.
     * @return bool Whether the remote failure represents an absent repository.
     */
    private function isResourceNotFoundError($message): bool
    {
        $message = strtolower($this->formatExtensionMessage($message));

        return str_contains($message, 'resource not found')
            || str_contains($message, 'not found')
            || str_contains($message, 'nicht gefunden');
    }

    /**
     * Log a provider lookup failure with its source context.
     *
     * @param Plugin|Theme|false $extension Managed extension when available.
     * @param string $type Managed extension type: plugin or theme.
     * @return void
     */
    private function logResourceNotFoundUpdateCheck($extension, string $type): void
    {
        do_action(
            'rrze.log.error',
            'Repository update check failed: resource not found for {extension-type} {repository} on {branch}.',
            [
                'plugin' => $this->config->getLogPlugin(),
                'extension-type' => $type,
                'repository' => $extension->repository ?? '',
                'branch' => $extension->branch ?? '',
                'service' => $extension->connector->display ?? '',
                'owner' => $extension->connector->owner ?? '',
                'error' => $this->formatExtensionMessage($extension->lastError ?? '')
            ]
        );
    }

    /**
     * Dispatch explicit overview actions before table preparation.
     *
     * @param string $action Selected administrative action.
     * @return void
     */
    public function getRepoAction(string $action): void
    {
        switch ($action) {
            case 'delete':
                if (isset($_GET['id'])) {
                    $this->getRepoDelete();
                }
                break;

            case 'bulk-update':
                $this->getRepoBulkUpdate();
                break;

            default:

                break;
        }
    }

    /**
     * Authorize a single repository association deletion.
     *
     * @return void
     */
    public function getRepoDelete(): void
    {
        $repoId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        if (!is_string($nonceField) || !wp_verify_nonce($nonceField, 'rrze-updater-repo-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        if (
            !current_user_can('update_plugins')
            || !current_user_can('update_themes')
        ) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $this->repoDelete($repoId);
    }

    /**
     * Authorize an explicit bulk-update URL and read its selected IDs.
     *
     * @return void
     */
    public function getRepoBulkUpdate(): void
    {
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';
        if (!$nonceField || !wp_verify_nonce($nonceField, 'rrze-updater-repo-bulk-update')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        $repoIds = $_GET['repositories'] ?? [];
        if (!is_array($repoIds)) {
            $repoIds = [];
        }

        $this->repoBulkUpdate($repoIds);
    }

    /**
     * Pass page data and the accumulated notices to the renderer.
     *
     * @param string $view Template name relative to the views directory.
     * @param array $data Explicit template data.
     * @return void
     */
    private function display(string $view, array $data = []): void
    {
        $this->renderer->display($view, $data, $this->messages->all());
    }

    /**
     * Read the supported page action without performing it.
     *
     * @return string Formatted value or empty when unavailable.
     */
    public function getAction(): string
    {
        return AdminRequest::action();
    }

    /**
     * Register the repository list pagination preference.
     *
     * @return void
     */
    public function repoListScreenOptions(): void
    {
        new RepoListTable();
        AdminRequest::screenOptions();
    }

    /**
     * Delegate sequential single updates and their progress output to the runner.
     *
     * @param array $repoIds Selected managed repository IDs.
     * @return void
     */
    public function repoBulkUpdate(array $repoIds): void
    {
        $this->updates->run($repoIds, $this->messages->all());
    }

    /**
     * Delete matching plugin and theme associations through the shared service.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function repoDelete($id): void
    {
        foreach (['deletePlugin', 'deleteTheme'] as $method) {
            $result = $this->actions->$method(sanitize_text_field($id));
            if (is_wp_error($result)) {
                $this->messages->add($result);
            }
        }
    }

    /**
     * Apply confirmed bulk deletions or dispatch selected updates before rendering.
     *
     * The existing bulk-update response terminates after its progress output.
     *
     * @return void
     */
    private function processBulkAction(): void
    {
        foreach (AdminRequest::deleteIds('repositories', ['update_plugins', 'update_themes']) as $id) {
            $this->repoDelete($id);
        }
        $ids = $_GET['repositories'] ?? [];
        if (AdminRequest::bulkAction() === 'update' && is_array($ids) && $ids !== []) {
            $this->repoBulkUpdate($ids);
            // Preserve the table action's response boundary and avoid extra admin-footer hooks.
            exit;
        }
    }

    /**
     * Return notices accumulated by this request before or after rendering.
     *
     * @return array<string|WP_Error> Request notices.
     */
    public function getMessages(): array
    {
        return $this->messages->all();
    }

}

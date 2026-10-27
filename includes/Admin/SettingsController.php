<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\{Config, Settings, Cron, TokenStatus, RepositoryActions};
use RRZE\Updater\Core\Connector;
use RRZE\Updater\ListTable\ConnListTable;
use WP_Error;

/** Handles service and general settings requests. */
class SettingsController
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
     * @param AdminMessages|null $messages Optional request-wide notice collection.
     */
    public function __construct(
        private Settings $settings,
        private Config $config,
        private RepositoryActions $actions,
        private RepositoryPresenter $presenter,
        private AdminRenderer $renderer,
        ?AdminMessages $messages = null
    ) {
        $this->messages = $messages ?? new AdminMessages();
    }

    /**
     * Handle service or general settings requests and render their tab.
     *
     * @return void
     */
    public function getSettingsIndex(): void
    {
        $tab = $this->getSettingsTab();

        if ($tab == 'services') {
            $this->addSettingsServicesMessages();

            if ($action = $this->getAction()) {
                $this->getConnectorAction($action);
                if ($action != 'delete') {
                    return;
                }
            }

            $data = [
                'tab' => $tab,
                'listTable' => $this->getConnectorListTable()
            ];

            $this->display('settings/index', $data);
            return;
        }

        if ($this->isSettingsSaveRequest()) {
            if ($this->postSettingsSave() && $this->isSettingsSendNowRequest()) {
                $this->sendUpdateEmailNow();
            }
        }

        $data = [
            'tab' => 'general',
            'settings' => $this->settings->options,
            'cronSchedules' => $this->config->get('fields.cron_schedules', []),
            'emailSchedules' => $this->config->get('fields.email_schedules', [])
        ];

        $this->display('settings/index', $data);
    }

    /**
     * Read the successful service-creation redirect notice.
     *
     * @return void
     */
    private function addSettingsServicesMessages(): void
    {
        $notice = isset($_GET['rrze-updater-notice']) ? sanitize_key((string) $_GET['rrze-updater-notice']) : '';

        if ($notice == 'connector-added') {
            $this->messages->add(__('Service created.', 'rrze-updater'));
        }
    }

    /**
     * Render the services tab with the current request notices.
     *
     * @return void
     */
    private function displaySettingsServicesIndex(): void
    {
        $data = [
            'tab' => 'services',
            'listTable' => $this->getConnectorListTable()
        ];

        $this->display('settings/index', $data);
    }

    /**
     * Register list options only when the services tab is selected.
     *
     * @return void
     */
    public function settingsScreenOptions(): void
    {
        if ($this->getSettingsTab() != 'services') {
            return;
        }

        $this->connListScreenOptions();
    }

    /**
     * Resolve the supported settings tab, defaulting to general settings.
     *
     * @return string Formatted value or empty when unavailable.
     */
    private function getSettingsTab(): string
    {
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $allowedTabs = [
            'general',
            'services'
        ];

        return in_array($tab, $allowedTabs, true) ? $tab : 'general';
    }

    /**
     * Check the general settings form action and nonce.
     *
     * @return bool Whether the request selects saving and carries a valid settings nonce.
     */
    private function isSettingsSaveRequest(): bool
    {
        $request = $_POST['rrze-updater'] ?? '';

        return is_array($request)
            && ($request['action'] ?? '') == 'save-settings'
            && wp_verify_nonce($_POST['rrze-updater-nonce'] ?? '', 'rrze-updater-settings');
    }

    /**
     * Check whether the settings form requests an immediate update email.
     *
     * @return bool Whether immediate email delivery was requested.
     */
    private function isSettingsSendNowRequest(): bool
    {
        return isset($_POST['rrze-updater-send-now']);
    }

    /**
     * Persist normalized options before rescheduling jobs and reporting success.
     *
     * @return bool Whether the normalized settings were persisted.
     */
    public function postSettingsSave(): bool
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $this->settings->options = $this->normalizedOptions($_POST['rrze-updater'] ?? []);

        if (!$this->saveSettings()) {
            return false;
        }
        $this->rescheduleUpdates();

        $this->messages->add(__('Settings saved.', 'rrze-updater'));
        return true;
    }

    /**
     * Normalize submitted settings while retaining the established defaults.
     *
     * @param array $request Submitted settings fields.
     * @return array Sanitized options ready for persistence.
     */
    private function normalizedOptions(array $request): array
    {
        $defaults = $this->config->getDefaultSettings();
        $cronSchedules = array_keys($this->config->get('fields.cron_schedules', []));
        $emailSchedules = array_keys($this->config->get('fields.email_schedules', []));
        $updateCheckSchedule = sanitize_key($request['update_check_schedule'] ?? $defaults['update_check_schedule']);
        $updateCheckDelay = max(1, absint($request['update_check_delay'] ?? $defaults['update_check_delay']));
        $emailSchedule = sanitize_key($request['email_schedule'] ?? $defaults['email_schedule']);

        if (!in_array($updateCheckSchedule, $cronSchedules, true)) {
            $updateCheckSchedule = $defaults['update_check_schedule'];
        }

        if (!in_array($emailSchedule, $emailSchedules, true)) {
            $emailSchedule = $defaults['email_schedule'];
        }

        $options = [
            'update_check_schedule' => $updateCheckSchedule,
            'update_check_delay' => $updateCheckDelay,
            'info_logging_enabled' => !empty($request['info_logging_enabled']),
            'email_updates_enabled' => !empty($request['email_updates_enabled']),
            'email_address' => sanitize_email($request['email_address'] ?? $defaults['email_address']),
            'email_subject_prefix' => sanitize_text_field($request['email_subject_prefix'] ?? $defaults['email_subject_prefix']),
            'email_schedule' => $emailSchedule
        ];

        if (!$options['email_address']) {
            $options['email_address'] = $defaults['email_address'];
        }

        if (!$options['email_subject_prefix']) {
            $options['email_subject_prefix'] = $defaults['email_subject_prefix'];
        }

        return $options;
    }

    /**
     * Reschedule recurring events only after settings have been persisted.
     *
     * @return void
     */
    private function rescheduleUpdates(): void
    {
        Cron::clearSchedule();
        Cron::clearEmailSchedule();
        wp_schedule_event(time(), $this->settings->options['update_check_schedule'], $this->config->getCronActionHook());

        if ($this->settings->options['email_updates_enabled']) {
            wp_schedule_event(time(), $this->settings->options['email_schedule'], $this->config->getCronEmailActionHook());
        }

    }

    /**
     * Send the requested update email and translate its outcome into a notice.
     *
     * @return void
     */
    private function sendUpdateEmailNow(): void
    {
        $result = Cron::sendUpdateEmailForSettings($this->settings, true);

        switch ($result) {
            case 'sent':
                $this->messages->add(__('Update notice email sent.', 'rrze-updater'));
                break;
            case 'no_updates':
                $this->messages->add(__('Es liegen keine Updates vor, über die berichtet werden kann.', 'rrze-updater'));
                break;
            case 'invalid_recipient':
                $this->messages->add(new WP_Error('error', __('No valid email address is configured.', 'rrze-updater')));
                break;
            case 'failed':
                $this->messages->add(new WP_Error('error', __('The update notice email could not be sent.', 'rrze-updater')));
                break;
            default:
                $this->messages->add(new WP_Error('error', __('The update notice email is disabled.', 'rrze-updater')));
                break;
        }
    }

    /**
     * Refresh authorized token checks before preparing the service table.
     *
     * @return ConnListTable Operation result.
     */
    private function getConnectorListTable(): ConnListTable
    {
        if (current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            TokenStatus::refresh($this->settings);
        }
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $listTable = new ConnListTable($this->presenter->getConnectorListData($search));
        $listTable->prepare_items();
        return $listTable;
    }

    /**
     * Process service actions before presenting the service overview.
     *
     * @return void
     */
    public function getConnectorIndex(): void
    {
        if ($action = $this->getAction()) {
            $this->getConnectorAction($action);
            if ($action != 'delete') {
                return;
            }
        }

        $data = [
            'listTable' => $this->getConnectorListTable()
        ];

        $this->display('connectors/index', $data);
    }

    /**
     * Dispatch a service form action with its existing nonce rules.
     *
     * @param string $action Selected administrative action.
     * @return void
     */
    public function getConnectorAction(string $action): void
    {
        $nonceField = $_POST['rrze-updater-nonce'] ?? '';

        switch ($action) {
            case 'add':
                // Check if the nonce field is valid and choose the appropriate method accordingly.
                if (wp_verify_nonce($nonceField, 'rrze-updater-connector-add')) {
                    $this->postConnectorAdd();
                } else {
                    $this->getConnectorAdd();
                }
                break;

            case 'edit':
                // Check if the nonce field is valid and choose the appropriate method accordingly.
                if (wp_verify_nonce($nonceField, 'rrze-updater-connector-edit')) {
                    $this->postConnectorEdit();
                } else {
                    $this->getConnectorEdit();
                }
                break;

            case 'delete':
                $this->getConnectorDelete();
                break;

            default:

                break;
        }
    }

    /**
     * Render the form for adding a service.
     *
     * @return void
     */
    public function getConnectorAdd(): void
    {
        $this->display('connectors/add');
    }

    /**
     * Render the persisted service selected by the request.
     *
     * @return void
     */
    public function getConnectorEdit(): void
    {
        $connectorId = $_GET['id'] ?? '';
        $connectorId = sanitize_text_field($connectorId);
        if ($connector = $this->settings->getConnectorById($connectorId)) {
            $data = [
                'connector' => $connector
            ];

            $this->display('connectors/edit', $data);
        }
    }

    /**
     * Authorize a single service deletion.
     *
     * @return void
     */
    public function getConnectorDelete(): void
    {
        $connectorId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        if (!is_string($nonceField) || !wp_verify_nonce($nonceField, 'rrze-updater-connector-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $this->connectorDelete($connectorId);
    }

    /**
     * Create a service and redirect only after successful persistence.
     *
     * @return void
     */
    public function postConnectorAdd(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        $owner = $request['owner'] ?? '';
        $owner = sanitize_text_field($owner);
        if (!$owner) {
            $this->messages->add(new WP_Error('error', __('The User/Group field must not be empty.', 'rrze-updater')));
            $this->display('connectors/add');
            return;
        }

        $service = sanitize_text_field($request['type'] ?? 'github');
        if ($service == 'gitlab-rrze') {
            $request['type'] = 'gitlab';
            $request['host'] = $this->config->getGitlabDefaultHost();
            $request['apiUri'] = $this->config->getGitlabDefaultApiUri();
        } elseif ($service == 'gitlab-custom') {
            $request['type'] = 'gitlab';
        } else {
            $request['type'] = 'github';
        }

        if (!$connector = Connector::createFromArray($request)) {
            return;
        }

        $this->settings->connectors[] = $connector;
        if (!$this->saveSettings()) {
            $this->display('connectors/add');
            return;
        }

        $menuSettings = $this->config->getMenuSettings();
        $settingsSlug = $menuSettings['settings_slug'] ?? 'rrze-updater-settings';
        $redirectUrl = add_query_arg(
            [
                'page' => $settingsSlug,
                'tab' => 'services',
                'rrze-updater-notice' => 'connector-added'
            ],
            self_admin_url('admin.php')
        );

        if (!headers_sent() && wp_safe_redirect($redirectUrl)) {
            exit;
        }

        $this->messages->add(__('Service created.', 'rrze-updater'));
        $this->displaySettingsServicesIndex();
    }

    /**
     * Save service credentials and render the persisted result.
     *
     * @return void
     */
    public function postConnectorEdit(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        $connectorId = $request['id'] ?? '';
        $connectorId = sanitize_text_field($connectorId);

        if (!$connector = $this->settings->getConnectorById($connectorId)) {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        $connectorToken = $request['token'] ?? '';
        $connector->token = sanitize_text_field($connectorToken);

        if (method_exists($connector, 'updateServerSettings')) {
            $connector->updateServerSettings($request);
        }

        if (!$this->saveSettings()) {
            $connector = $this->settings->getConnectorById($connectorId);
            if (!$connector) {
                $this->display('');
                return;
            }
        }

        $data = [
            'connector' => $connector
        ];

        $this->display('connectors/edit', $data);
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
     * Persist settings and retain failures for the current administrative response.
     *
     * @return bool Whether persistence succeeded; failures remain available as notices.
     */
    private function saveSettings(): bool
    {
        $result = $this->actions->save();
        if (is_wp_error($result)) {
            $this->messages->add($result);
            return false;
        }
        return true;
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
     * Delete an unused service and retain any persistence error for display.
     *
     * @param string $id Managed association ID.
     * @return void
     */
    public function connectorDelete($id): void
    {
        $result = $this->actions->deleteConnector(sanitize_text_field($id));
        if (is_wp_error($result)) {
            $this->messages->add($result);
        }
    }

    /**
     * Register the service list pagination preference.
     *
     * @return void
     */
    public function connListScreenOptions(): void
    {
        new ConnListTable();
        AdminRequest::screenOptions();
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

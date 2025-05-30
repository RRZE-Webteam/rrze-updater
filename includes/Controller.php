<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\{Connector, Plugin, Theme};
use RRZE\Updater\Upgrader\{PluginUpgraderSkin, ThemeUpgraderSkin};
use RRZE\Updater\ListTable\{RepoListTable, ConnListTable, PluginsListTable, ThemesListTable};
use Plugin_Upgrader;
use Theme_Upgrader;
use WP_Error;

/**
 * Class Controller
 *
 * This class serves as a controller for managing repositories, connectors, 
 * plugins, and themes.
 */
class Controller
{
    /**
     * An array of available actions that can be performed.
     *
     * @var array
     */
    const AVAILABLE_ACTIONS = [
        'add',
        'check-updates',
        'edit',
        'delete'
    ];

    /**
     * Settings object for managing plugin settings.
     *
     * @var Settings
     */
    protected $settings;

    /**
     * An array to store messages or errors.
     *
     * @var array
     */
    protected $messages = [];

    /**
     * Constructor for the Controller class.
     *
     * @param Settings $settings An instance of the Settings class.
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Get the current action from the query parameters.
     *
     * @return string The current action or an empty string if not found.
     */
    public function getAction()
    {
        $action = $_GET['action'] ?? '';
        if (in_array($action, self::AVAILABLE_ACTIONS)) {
            return $action;
        }
        return '';
    }

    /**
     * Retrieves and displays a list of repositories in the WordPress admin.
     *
     * This function is responsible for fetching a list of repositories from various connectors,
     * filtering them based on a search query (if provided), and displaying them in a custom
     * admin list table. It also handles different types of repositories such as plugins and themes.
     */
    public function getRepoIndex()
    {
        // Check if there is a specific action to perform on a repository.
        if ($action = $this->getAction()) {
            // If an action is specified, process it using 'getRepoAction'.
            $this->getRepoAction($action);

            // If the action is not 'delete', return early.
            if ($action != 'delete') {
                return;
            }
        }

        // Initialize an empty array to store repository information.
        $repos = [];

        // Iterate through each connector in the settings and retrieve their repositories.
        foreach ($this->settings->connectors as $connector) {
            $repos = array_merge($repos, $this->settings->getConnectorRepos($connector->id));
        }

        // Get the search query from the 's' parameter in the URL (if provided) and sanitize it.
        $search = $_GET['s'] ?? '';
        $search = sanitize_text_field($search);

        // Filter and modify the repositories based on the search query and repository type.
        foreach ($repos as $key => $value) {
            if ($search && empty(preg_grep('/' . $search . '/i', $value))) {
                // If a search query is provided and doesn't match, remove the repository.
                unset($repos[$key]);
                continue;
            }
            if (isset($value['plugin'])) {
                // If it's a plugin repository, set type and version information.
                $value['type'] = __('Plugin', 'rrze-updater');
                $value['version'] = $this->pluginVersion($value['installationFolder'], $value['repository']);
            } elseif (isset($value['theme'])) {
                // If it's a theme repository, set type and version information.
                $value['type'] = __('Theme', 'rrze-updater');
                $value['version'] = $this->themeVersion($value['installationFolder']);
            } else {
                // For other types, set type and version as placeholders.
                $value['type'] = '&mdash;';
                $value['version'] = '&mdash;';
            }
            $repos[$key] = $value;
        }

        // Create an instance of the 'RepoListTable' class and prepare the list table data.
        $listTable = new RepoListTable($this, $repos);
        $listTable->prepare_items();

        // Prepare the data for rendering.
        $data = [
            'listTable' => $listTable,
        ];

        // Display the repository list.
        $this->display('repositories/index', $data);
    }

    /**
     * Set screen options for the repository list table in the WordPress admin.
     *
     * This function creates a new instance of the 'RepoListTable' class and sets screen options
     * for the admin list table. It determines the number of items to display per page and
     * provides options to customize the list table's behavior.
     *
     * @since 1.0.0  // Replace with the actual version of your plugin or theme.
     */
    public function repoListScreenOptions()
    {
        // Create a new instance of the 'RepoListTable' class, passing $this as a reference.
        new RepoListTable($this);

        // Define the option name for controlling the number of items per page.
        $option = 'per_page';

        // Define an array of arguments to configure the screen option.
        $args = [
            'label'   => __('Number of items per page:', 'rrze-updater'), // Label displayed next to the option.
            'default' => 20, // Default number of items to display per page.
            'option'  => 'rrze_updater_per_page', // The option name to store the value.
        ];

        // Register the screen option with WordPress using 'add_screen_option'.
        add_screen_option($option, $args);
    }

    /**
     * Handles specific repository actions based on the provided action.
     *
     * This method is responsible for processing actions related to repositories, such as deletion,
     * based on the provided action parameter. Additional actions can be added as needed.
     *
     * @param string $action The action to be performed on a repository.
     * @since 1.0.0  // Replace with the actual version of your plugin or theme.
     */
    protected function getRepoAction(string $action)
    {
        // Use a switch statement to determine the action to perform.
        switch ($action) {
            case 'delete':
                // If the action is 'delete', invoke the 'getRepoDelete' method to handle deletion.
                $this->getRepoDelete();
                break;

            default:
                // Nothing to do here.
                break;
        }
    }

    /**
     * Handles the deletion of a repository based on the provided repository ID and nonce validation.
     *
     * This method is responsible for processing the deletion of a repository when the 'delete' action
     * is triggered. It performs nonce validation, checks user permissions, and delegates the deletion
     * process to the 'repoDelete' method.
     */
    public function getRepoDelete()
    {
        // Get the repository ID and nonce field value from the query parameters.
        $repoId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        // Verify the nonce to ensure the request is legitimate.
        if ($nonceField && !wp_verify_nonce($nonceField, 'rrze-updater-repo-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        // Check user permissions to ensure they have the required capabilities.
        if (
            !current_user_can('update_plugins')
            || !current_user_can('update_themes')
        ) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Delegate the repository deletion to the 'repoDelete' method.
        $this->repoDelete($repoId);
    }

    /**
     * Deletes a repository identified by its ID.
     *
     * This method is responsible for deleting a repository based on the provided repository ID. It may
     * delegate the deletion process to other methods like 'pluginDelete' or 'themeDelete,' depending
     * on your application's logic.
     *
     * @param string $id The ID of the repository to be deleted.
     */
    public function repoDelete($id)
    {
        // Sanitize the repository ID.
        $repoId = sanitize_text_field($id);

        // Perform the deletion of the repository. You can implement specific deletion logic here
        // or delegate it to other methods based on your application's requirements.
        $this->pluginDelete($repoId);
        $this->themeDelete($repoId);
    }

    /**
     * Handles the display and management of connector items in the admin panel.
     *
     * This method is responsible for rendering the list of connectors and their associated repositories
     * in the admin panel. It also handles actions related to connectors, such as deletion. Depending
     * on the action, it may delegate further processing to the 'getConnectorAction' method.
     */
    public function getConnectorIndex()
    {
        // Check if there's a specific action requested and delegate to the corresponding method.
        if ($action = $this->getAction()) {
            $this->getConnectorAction($action);
            if ($action != 'delete') {
                return;
            }
        }

        // Retrieve the configuration data for connectors.
        $config = $this->settings->asArray();
        $connectors = $config['connectors'];

        // Calculate and add the count of repositories for each connector.
        foreach ($connectors as $key => $connector) {
            $repos = $this->settings->getConnectorRepos($connector['id']);
            $connectors[$key]['repocount'] = count($repos);
        }

        // Perform a search operation if a search query is provided.
        $search = isset($_GET['s']) ? $_GET['s'] : '';
        foreach ($connectors as $key => $value) {
            if ($search && empty(preg_grep('/' . $search . '/i', $value))) {
                unset($connectors[$key]);
                continue;
            }
        }

        // Create an instance of 'ConnListTable' and prepare the items for display.
        $listTable = new ConnListTable($connectors);
        $listTable->prepare_items();

        // Prepare the data for rendering.
        $data = [
            'listTable' => $listTable
        ];

        // Display the connectors list.
        $this->display('connectors/index', $data);
    }

    /**
     * Handles screen options for the connector list table.
     *
     * This method is responsible for setting up screen options for the connector list table in the admin panel.
     * It defines the label and default value for the 'per_page' option and registers it using 'add_screen_option'.
     */
    public function connListScreenOptions()
    {
        // Create an instance of 'ConnListTable' (assuming it's required for screen options).
        new ConnListTable();

        // Define the 'per_page' screen option label and default value.
        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-updater'),
            'default' => 20,
            'option' => 'rrze_updater_per_page'
        ];

        // Register the 'per_page' screen option using 'add_screen_option'.
        add_screen_option($option, $args);
    }

    /**
     * Handles connector-related actions based on the provided action parameter.
     *
     * This method is responsible for processing various actions related to connectors, such as adding, editing, or deleting connectors.
     * The action parameter determines the specific action to be taken, and the corresponding methods are called accordingly.
     *
     * @param string $action The action to be performed, e.g., 'add', 'edit', 'delete'.
     */
    protected function getConnectorAction(string $action)
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
                // Call the method to handle connector deletion.
                $this->getConnectorDelete();
                break;

            default:
                // Nothing to do here.
                break;
        }
    }

    /**
     * Displays the connector add form.
     *
     * This method is responsible for rendering the connector add form in the admin panel.
     * Users can use this form to add new connectors.
     */
    protected function getConnectorAdd()
    {
        $this->display('connectors/add');
    }

    /**
     * Displays the connector edit form.
     *
     * This method is responsible for rendering the connector edit form in the admin panel.
     * Users can use this form to edit existing connectors, and the form is pre-filled with connector data.
     */
    protected function getConnectorEdit()
    {
        $connectorId = $_GET['id'] ?? '';
        $connectorId = sanitize_text_field($connectorId);
        if ($connector = $this->settings->getConnectorById($connectorId)) {
            // Prepare the data for rendering.
            $data = [
                'connector' => $connector
            ];

            // Display the connector edit form.
            $this->display('connectors/edit', $data);
        }
    }

    /**
     * Handles connector deletion.
     *
     * This method is responsible for processing the deletion of a connector based on the provided connector ID.
     * It performs necessary checks, including nonce verification and user permissions, before deleting the connector.
     */
    public function getConnectorDelete()
    {
        $connectorId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        // Verify the nonce field to ensure the request is legitimate.
        if ($nonceField && !wp_verify_nonce($nonceField, 'rrze-updater-connector-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        // Check user permissions before proceeding with deletion.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Call the 'connectorDelete' method to perform the actual deletion.
        $this->connectorDelete($connectorId);
    }

    /**
     * Deletes a connector based on the provided ID.
     *
     * This method is responsible for deleting a connector by its ID. It first sanitizes the connector ID,
     * checks if the connector is not in use, and then removes it from the settings.
     *
     * @param string $id The ID of the connector to be deleted.
     */
    public function connectorDelete($id)
    {
        $connectorId = sanitize_text_field($id);
        if ($connector = $this->settings->getConnectorById($connectorId)) {
            foreach ($this->settings->connectors as $key => $_connector) {
                if ($connector->id == $_connector->id && !$this->settings->isConnectorUsed($_connector->id)) {
                    unset($this->settings->connectors[$key]);
                }
            }
            $this->settings->save();
        }
    }

    /**
     * Handles the addition of a new connector.
     *
     * This method is responsible for processing the addition of a new connector based on the POST request data.
     * It performs data validation, checks user permissions, and then adds the new connector to the settings.
     */
    protected function postConnectorAdd()
    {
        // Check user permissions before proceeding with adding a connector.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Retrieve and validate the POST request data.
        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Data validation for owner field.
        $owner = $request['owner'] ?? '';
        $owner = sanitize_text_field($owner);
        if (!$owner) {
            $this->messages[] = new WP_Error('error', __('The User/Group field must not be empty.', 'rrze-updater'));
            $this->display('connectors/add');
            return;
        }

        // Create a new Connector instance from the request data.
        if (!$connector = Connector::createFromArray($request)) {
            return;
        }

        // Add the new connector to the settings and save them.
        $this->settings->connectors[] = $connector;
        $this->settings->save();

        // Prepare the data for rendering.
        $data = [
            'connector' => $connector
        ];

        // Display the connector edit form.
        $this->display('connectors/edit', $data);
    }

    /**
     * Handles the editing of an existing connector.
     *
     * This method is responsible for processing the editing of an existing connector based on the POST request data.
     * It performs data validation, checks user permissions, and updates the connector's information in the settings.
     */
    protected function postConnectorEdit()
    {
        // Check user permissions before proceeding with editing a connector.
        if (!current_user_can('manage_options')) {
            wp_die(__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Retrieve and validate the POST request data.
        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Retrieve the connector ID from the request and sanitize it.
        $connectorId = $request['id'] ?? '';
        $connectorId = sanitize_text_field($connectorId);

        // Check if the connector with the provided ID exists in the settings.
        if (!$connector = $this->settings->getConnectorById($connectorId)) {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        // Update the connector's token field with the sanitized token from the request.
        $connectorToken = $request['token'] ?? '';
        $connector->token = sanitize_text_field($connectorToken);

        // Save the updated settings.
        $this->settings->save();

        // Prepare the data for rendering.
        $data = [
            'connector' => $connector
        ];

        // Display the connector edit form page.
        $this->display('connectors/edit', $data);
    }

    /**
     * Displays the index page for managing WordPress plugins and performs actions based on the requested action.
     *
     * If an action is specified, it processes that action using the 'getPluginAction' method, and if the action is not 'delete',
     * it returns, avoiding further processing. Otherwise, it continues to display the plugin index page.
     * 
     * The method also synchronizes plugin settings, retrieves plugin information, and filters plugins based on search criteria.
     * It prepares the data and passes it to the 'PluginsListTable' for rendering.
     *
     * @return void
     */
    public function getPluginIndex()
    {
        if ($action = $this->getAction()) {
            $this->getPluginAction($action);
            if ($action != 'delete') {
                return;
            }
        }

        // Synchronize plugin settings with the currently installed plugins.
        $this->synchronizeSettings();

        // Retrieve the list of plugins from settings.
        $plugins = $this->settings->plugins;
        $data = [];

        // Iterate through each plugin and prepare data for display.
        foreach ($plugins as $key => $value) {
            $data[$key]['plugin'] = $value->repository;
            $data[$key]['repository'] = $value->repository;
            $data[$key]['id'] = $value->id;
            $data[$key]['connector'] = $value->connector->display;
            $data[$key]['branch'] = $value->branch;
            $data[$key]['installationFolder'] = $value->installationFolder;
            $data[$key]['lastChecked'] = $this->lastChecked($value->lastChecked);
            $data[$key]['version'] = $this->pluginVersion($value->installationFolder, $value->repository);
        }

        // Retrieve the search criteria from the request.
        $search = isset($_GET['s']) ? $_GET['s'] : '';

        // Filter the data based on the search criteria.
        foreach ($data as $key => $value) {
            if ($search && empty(preg_grep('/' . $search . '/i', $value))) {
                unset($data[$key]);
                continue;
            }
        }

        // Create a 'PluginsListTable' instance and prepare items for display.
        $listTable = new PluginsListTable($this, $data);
        $listTable->prepare_items();

        // Prepare the data for rendering.
        $data = [
            'listTable' => $listTable
        ];

        // Display the plugin list.
        $this->display('plugins/index', $data);
    }

    /**
     * Sets up and displays screen options for the plugins list page.
     *
     * This method initializes the 'PluginsListTable', which handles the display and management of the plugins list.
     * It also defines screen options such as the number of items per page and sets their default values.
     *
     * @return void
     */
    public function pluginsListScreenOptions()
    {
        // Initialize the 'PluginsListTable' to manage and display the plugins list.
        new PluginsListTable($this);

        // Define screen options for the number of items per page.
        $option = 'per_page';
        $args = [
            'label'   => __('Number of items per page:', 'rrze-updater'),
            'default' => 20,
            'option'  => 'rrze_updater_per_page',
        ];

        // Add screen options with the specified arguments.
        add_screen_option($option, $args);
    }

    /**
     * Process the requested action for managing plugins.
     *
     * This method is responsible for handling different actions related to managing plugins,
     * such as adding, checking for updates, editing, or deleting plugins. It checks the requested
     * action and processes it accordingly by invoking the corresponding methods based on the
     * provided nonce field and action type.
     *
     * @param string $action The requested action, such as 'add', 'check-updates', 'edit', or 'delete'.
     */
    protected function getPluginAction(string $action)
    {
        // Retrieve the nonce field from the POST data to verify the request's authenticity.
        $nonceField = $_POST['rrze-updater-nonce'] ?? '';

        switch ($action) {
            case 'add':
                // Check the nonce field to verify the action, then proceed with adding a new plugin.
                if (wp_verify_nonce($nonceField, 'rrze-updater-plugin-add')) {
                    $this->postPluginAdd();
                } else {
                    // If nonce verification fails, display the plugin add form.
                    $this->getPluginAdd();
                }
                break;

            case 'check-updates':
                // Trigger the method to check for updates for the specified plugin.
                $this->getPluginUpdates();
                break;

            case 'edit':
                // Check the nonce field to verify the action, then proceed with editing an existing plugin.
                if (wp_verify_nonce($nonceField, 'rrze-updater-plugin-edit')) {
                    $this->postPluginEdit();
                } else {
                    // If nonce verification fails, display the plugin edit form.
                    $this->getPluginEdit();
                }
                break;

            case 'delete':
                // Execute the method to delete the specified plugin.
                $this->getPluginDelete();
                break;
        }
    }

    /**
     * Display the form for adding a new plugin.
     *
     * This method is responsible for displaying the form used to add a new plugin.
     * It prepares the necessary data, such as available connectors, and then renders the
     * 'plugins/add' view to allow users to input information for the new plugin.
     */
    protected function getPluginAdd()
    {
        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors
        ];

        // Display the plugin add form.
        $this->display('plugins/add', $data);
    }

    /**
     * Display the form for editing an existing plugin.
     *
     * This method is responsible for displaying the form used to edit an existing plugin's information.
     * It retrieves the plugin based on the provided ID, prepares the necessary data, and then renders the
     * 'plugins/edit' view, allowing users to modify the details of the selected plugin.
     */
    protected function getPluginEdit()
    {
        // Retrieve the ID of the plugin to be edited.
        $pluginId = $_GET['id'] ?? '';
        $pluginId = sanitize_text_field($pluginId);

        // Check if the plugin with the given ID exists.
        if ($plugin = $this->settings->getPluginById($pluginId)) {
            // Prepare the data for rendering.
            $data = [
                'connectors' => $this->settings->connectors,
                'plugin' => $plugin,
                'lastChecked' => $this->lastChecked($plugin->lastChecked)
            ];

            // Display the plugin edit form.
            $this->display('plugins/edit', $data);
        }
    }

    /**
     * Check for updates for a specific plugin and display the results.
     *
     * This method is responsible for checking for updates for a specific plugin and displaying
     * the results of the update check. It first retrieves the ID of the plugin to check for updates,
     * performs the update check, and updates the settings. If there are updates available, it
     * refreshes the 'update_plugins' transient to ensure that the WordPress update system detects
     * the available updates. Finally, it prepares the necessary data for the 'plugins/edit' view
     * and displays the results.
     */
    protected function getPluginUpdates()
    {
        // Retrieve the ID of the plugin for which updates should be checked.
        $pluginId = $_GET['id'] ?? '';
        $pluginId = sanitize_text_field($pluginId);

        // Check if the plugin with the given ID exists.
        if ($plugin = $this->settings->getPluginById($pluginId)) {
            // Check for updates for the selected plugin.
            $plugin->checkForUpdates();

            // Update the settings to reflect the latest update check.
            $this->settings->save();

            // If local and remote versions are different, refresh the 'update_plugins' transient.
            if ($plugin->localVersion != $plugin->remoteVersion) {
                delete_site_transient('update_plugins');
            }

            // Prepare the data for rendering.
            $data = [
                'connectors' => $this->settings->connectors,
                'plugin' => $plugin,
                'lastChecked' => $this->lastChecked($plugin->lastChecked)
            ];

            // Display the plugin edit form.
            $this->display('plugins/edit', $data);
        }
    }

    /**
     * Handle the deletion of a specific plugin.
     *
     * This method is responsible for handling the deletion of a plugin. It first retrieves the ID
     * of the plugin to be deleted and the associated nonce field for security verification. If the
     * nonce is valid, it proceeds to check the user's permissions. If the user has the required
     * permission to delete plugins, it calls the 'pluginDelete' method to perform the deletion.
     * Otherwise, it displays an error message indicating insufficient permission.
     */
    public function getPluginDelete()
    {
        // Retrieve the ID of the plugin to be deleted.
        $pluginId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        // Verify the nonce field for security purposes.
        if ($nonceField && !wp_verify_nonce($nonceField, 'rrze-updater-plugin-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        // Check if the user has sufficient permissions to delete plugins.
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Call the 'pluginDelete' method to perform the deletion.
        $this->pluginDelete($pluginId);
    }

    /**
     * Delete a plugin from the settings.
     *
     * This method is responsible for deleting a plugin from the plugin settings. It takes the ID
     * of the plugin to be deleted as a parameter and proceeds to locate and remove the plugin from
     * the settings. Additionally, it clears the site transient for plugin updates, ensuring that
     * the update status is recalculated after the plugin is deleted. Finally, it saves the updated
     * settings.
     *
     * @param string $id The ID of the plugin to be deleted.
     */
    public function pluginDelete($id)
    {
        // Sanitize the provided plugin ID.
        $pluginId = sanitize_text_field($id);

        // Retrieve the plugin by its ID.
        if ($plugin = $this->settings->getPluginById($pluginId)) {
            // Loop through the existing plugins and remove the one with matching ID.
            foreach ($this->settings->plugins as $key => $_plugin) {
                if ($plugin->id == $_plugin->id) {
                    unset($this->settings->plugins[$key]);
                }
            }

            // Clear the site transient for plugin updates.
            delete_site_transient('update_plugins');

            // Save the updated settings.
            $this->settings->save();
        }
    }

    /**
     * Handle the addition of a new plugin definition.
     *
     * This method is responsible for processing the addition of a new plugin definition based on the
     * data submitted through a form. It performs data validation, creates a new plugin instance,
     * checks for available updates, and initiates the installation process if required. The method
     * also handles error conditions and displays relevant messages.
     */
    protected function postPluginAdd()
    {
        // Check the user's permissions.
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Data validation
        $repository = $request['repository'] ?? '';
        $repository = sanitize_text_field($repository);
        if (!$repository) {
            // Handle empty repository field with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository field must not be empty.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors
            ];
            $this->display('plugins/add', $data);
            return;
        } elseif ($this->settings->getPluginByRepository($repository)) {
            // Handle duplicate repository with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository already exists.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors
            ];
            $this->display('plugins/add', $data);
            return;
        }

        // Create a new plugin definition
        $extension = new Plugin();

        $extension->id = Utility::uniqid();
        $extension->connectorId = $request['connectorId'];
        $extension->connector = $this->settings->getConnectorById($extension->connectorId);
        $extension->repository = $request['repository'];
        $extension->branch = $request['branch'] ?: 'main';
        $extension->installationFolder = $request['installationFolder'];
        if (!$extension->installationFolder) {
            $extension->installationFolder = $extension->repository;
        }
        $extension->updates = $request['updates'];
        $extension->lastChecked = time();

        // Add the new plugin definition to the settings
        $this->settings->plugins[] = $extension;

        // If updates are configured for tags or commits, check if updates are available
        $extension->checkForUpdates();

        // Install the plugin
        $repoZip = $extension->connector->downloadRepoZip($request['repository'], $request['branch']);
        if ($extension->remoteVersion) {
            $extension->localVersion = $extension->remoteVersion;
            $repoZip = $extension->connector->downloadRepoZip($request['repository'], $extension->remoteVersion);
        }

        if ($extension->connector->error) {
            // Handle connector error with an error message.
            $this->messages[] = new WP_Error('error', $extension->connector->error);
        } elseif ($repoZip) {
            // Initialize the plugin installation process.
            $upgrader = new Plugin_Upgrader(new PluginUpgraderSkin($extension));
            $data = [
                'upgrader' => $upgrader,
                'repoZip' => $repoZip
            ];
            $this->display('plugins/add-progress', $data);
            $this->settings->save();
            $this->deleteIfLocalFile($repoZip);
            return;
        }

        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors
        ];

        // Display the plugin add form.
        $this->display('plugins/add', $data);
    }

    /**
     * If $input is an existing local file path, deletes it.
     * If it’s a URL, does nothing.
     *
     * @param string $input URL or file path.
     * @return bool True if a file was deleted, false otherwise.
     */
    function deleteIfLocalFile(string $input): bool
    {
        if (filter_var($input, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (file_exists($input) && is_file($input)) {
            return unlink($input);
        }

        return false;
    }

    /**
     * Handle the editing of an existing plugin definition.
     *
     * This method is responsible for processing the modification of an existing plugin definition based on the
     * data submitted through a form. It performs data validation, updates the plugin's properties, checks for
     * available updates, and saves the changes. The method also clears cached plugin updates and displays
     * relevant messages.
     */
    protected function postPluginEdit()
    {
        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Check the user's permissions.
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $extensionId = $request['id'];

        if (!$extension = $this->settings->getPluginById($extensionId)) {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        if (!$request['repository']) {
            // Handle empty repository field with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository field must not be empty.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors,
                'plugin' => $extension,
                'lastChecked' => $this->lastChecked($extension->lastChecked)
            ];
            $this->display('plugins/edit', $data);
            return;
        }

        // Update the plugin definition's properties
        $extension->connectorId = $request['connectorId'];
        $extension->connector = $this->settings->getConnectorById($extension->connectorId);
        $extension->repository = $request['repository'];
        $extension->branch = $request['branch'] ?: 'main';
        $extension->updates = $request['updates'];
        $extension->remoteVersion = $request['branch'];

        // If updates are configured for tags or commits, check if updates are available
        $extension->checkForUpdates();

        // Save the changes and clear cached plugin updates
        $this->settings->save();
        delete_site_transient('update_plugins');

        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors,
            'plugin' => $extension,
            'lastChecked' => $this->lastChecked($extension->lastChecked)
        ];

        // Display the plugin edit form.
        $this->display('plugins/edit', $data);
    }

    /**
     * Display the theme index page or perform an action based on the request.
     *
     * This method handles displaying the theme index page or performing a specific action based on the request.
     * If an action is provided, it delegates the action processing to the appropriate method. Otherwise, it
     * retrieves and prepares the theme data, including last checked timestamps and versions. It also handles
     * searching and filtering themes, prepares the theme list table, and displays the theme index page.
     */
    public function getThemeIndex()
    {
        if ($action = $this->getAction()) {
            // If an action is provided in the request, delegate action processing
            $this->getThemeAction($action);

            // If the action is not 'delete,' return without further processing
            if ($action != 'delete') {
                return;
            }
        }

        // Synchronize settings to ensure accurate theme data
        $this->synchronizeSettings();
        $themes = $this->settings->themes;
        $data = [];

        // Prepare theme data for display, including repository, last checked timestamps, and versions
        foreach ($themes as $key => $value) {
            $data[$key]['theme'] = $value->repository;
            $data[$key]['repository'] = $value->repository;
            $data[$key]['id'] = $value->id;
            $data[$key]['connector'] = $value->connector->display;
            $data[$key]['branch'] = $value->branch;
            $data[$key]['installationFolder'] = $value->installationFolder;
            $data[$key]['lastChecked'] = $this->lastChecked($value->lastChecked);
            $data[$key]['version'] = $this->themeVersion($value->installationFolder);
        }

        // Handle theme searching and filtering
        $search = isset($_GET['s']) ? $_GET['s'] : '';
        foreach ($data as $key => $value) {
            if ($search && empty(preg_grep('/' . $search . '/i', $value))) {
                unset($data[$key]);
                continue;
            }
        }

        // Prepare and display the theme list table
        $listTable = new ThemesListTable($this, $data);
        $listTable->prepare_items();

        // Prepare the data for rendering.
        $data = [
            'listTable' => $listTable
        ];

        // Display the theme list.
        $this->display('themes/index', $data);
    }

    /**
     * Set screen options for the themes list table.
     *
     * This method configures the screen options for the themes list table, allowing users to customize
     * the number of items displayed per page. It creates a ThemesListTable instance to manage the table
     * display and sets options for the number of items per page with default values.
     */
    public function themesListScreenOptions()
    {
        // Create a ThemesListTable instance to manage the table display
        new ThemesListTable($this);

        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-updater'),
            'default' => 20, // Default number of items per page
            'option' => 'rrze_updater_per_page'
        ];

        // Add the screen options for the number of items per page
        add_screen_option($option, $args);
    }

    /**
     * Process the specified action for themes.
     *
     * This method processes the specified action for themes based on the provided action name. It checks the
     * nonce field to verify the request authenticity and dispatches the appropriate action accordingly.
     *
     * @param string $action The name of the action to perform.
     */
    protected function getThemeAction(string $action)
    {
        $nonceField = $_POST['rrze-updater-nonce'] ?? '';

        // Determine the action to perform based on the provided action name
        switch ($action) {
            case 'add':
                // Check nonce and perform theme addition
                if (wp_verify_nonce($nonceField, 'rrze-updater-theme-add')) {
                    $this->postThemeAdd();
                } else {
                    $this->getThemeAdd();
                }
                break;

            case 'check-updates':
                // Perform checking for theme updates
                $this->getThemeUpdates();
                break;

            case 'edit':
                // Check nonce and perform theme editing
                if (wp_verify_nonce($nonceField, 'rrze-updater-theme-edit')) {
                    $this->postThemeEdit();
                } else {
                    $this->getThemeEdit();
                }
                break;

            case 'delete':
                // Perform theme deletion
                $this->getThemeDelete();
                break;
        }
    }

    /**
     * Display the theme addition form.
     *
     * This method is responsible for displaying the form for adding a new theme. It prepares the necessary data,
     * including available connectors, and then invokes the 'display' method to render the 'themes/add' view.
     *
     * @return void
     */
    public function getThemeAdd()
    {
        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors
        ];

        // Display the plugin edit form.
        $this->display('themes/add', $data);
    }

    /**
     * Display the theme editing form.
     *
     * This method is responsible for displaying the form for editing an existing theme. It retrieves the theme
     * to be edited, along with the necessary data like available connectors and the last checked timestamp.
     * Subsequently, it invokes the 'display' method to render the 'themes/edit' view.
     *
     * @return void
     */
    protected function getThemeEdit()
    {
        // Retrieve the theme ID from the request
        $themeId = $_GET['id'] ?? '';
        $themeId = sanitize_text_field($themeId);

        // Check if the theme with the given ID exists
        if ($theme = $this->settings->getThemeById($themeId)) {
            // Prepare the data for rendering.
            $data = [
                'connectors' => $this->settings->connectors,
                'theme' => $theme,
                'lastChecked' => $this->lastChecked($theme->lastChecked)
            ];

            // Display the theme edit form.
            $this->display('themes/edit', $data);
        }
    }

    /**
     * Check for updates and display the theme update status.
     *
     * This method checks for updates for the selected theme and displays the theme's update status.
     * It retrieves the theme to be checked, performs the update check, and updates the last checked timestamp.
     * If the local version is different from the remote version, it clears the 'update_themes' transient.
     * The theme update status, along with related data, is then displayed using the 'themes/edit' view.
     *
     * @return void
     */
    protected function getThemeUpdates()
    {
        // Retrieve the theme ID from the request
        $themeId = $_GET['id'] ?? '';
        $themeId = sanitize_text_field($themeId);

        // Check if the theme with the given ID exists
        if ($theme = $this->settings->getThemeById($themeId)) {
            // Check for updates for the selected theme
            $theme->checkForUpdates();
            // Save the settings to update the last checked timestamp
            $this->settings->save();

            // If local version is different from remote version, clear the 'update_themes' transient
            if ($theme->localVersion != $theme->remoteVersion) {
                delete_site_transient('update_themes');
            }

            // Prepare the data for rendering.
            $data = [
                'connectors' => $this->settings->connectors,
                'theme' => $theme,
                'lastChecked' => $this->lastChecked($theme->lastChecked)
            ];

            // Display the theme edit form.
            $this->display('themes/edit', $data);
        }
    }

    /**
     * Delete a theme and its related data.
     *
     * This method handles the deletion of a theme along with its associated data. It first retrieves
     * the theme's ID and nonce field from the request. It then checks if the provided nonce is valid
     * to ensure the request's authenticity. If the nonce is valid and the current user has the necessary
     * permission to delete themes, the theme deletion process continues. The `themeDelete` method is
     * called to perform the actual deletion of the theme and its related data.
     *
     * @return void
     */
    public function getThemeDelete()
    {
        // Retrieve the theme ID and nonce field from the request
        $themeId = $_GET['id'] ?? '';
        $nonceField = $_GET['rrze-updater-nonce'] ?? '';

        // Check if the provided nonce is valid to ensure the request's authenticity
        if ($nonceField && !wp_verify_nonce($nonceField, 'rrze-updater-theme-delete')) {
            wp_die(esc_html__('Unable to submit this form, please refresh and try again.', 'rrze-updater'));
        }

        // Check if the current user has the necessary permission to delete themes
        if (!current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Call the `themeDelete` method to perform the actual theme deletion
        $this->themeDelete($themeId);
    }

    /**
     * Delete a theme and its associated data.
     *
     * This method is responsible for deleting a theme and its related data. It takes a theme ID as a parameter
     * and performs the following actions:
     *
     * 1. Sanitizes the provided theme ID for security.
     * 2. Retrieves the theme associated with the given ID from the settings.
     * 3. Iterates through the list of themes in the settings and removes the theme with a matching ID.
     * 4. Deletes the update information transient for themes to trigger a recheck of theme updates.
     * 5. Saves the updated settings to persist the changes.
     *
     * @param string $id The ID of the theme to be deleted.
     * @return void
     */
    public function themeDelete($id)
    {
        // Sanitize the provided theme ID for security
        $themeId = sanitize_text_field($id);

        // Retrieve the theme associated with the given ID from the settings
        if ($theme = $this->settings->getThemeById($themeId)) {
            // Iterate through the list of themes in the settings and remove the theme with a matching ID
            foreach ($this->settings->themes as $key => $_theme) {
                if ($theme->id == $_theme->id) {
                    unset($this->settings->themes[$key]);
                }
            }

            // Delete the update information transient for themes to trigger a recheck of theme updates
            delete_site_transient('update_themes');

            // Save the updated settings to persist the changes
            $this->settings->save();
        }
    }

    /**
     * Handle the addition of a new theme definition.
     *
     * This method is responsible for processing the addition of a new theme definition based on the
     * data submitted through a form. It performs data validation, creates a new theme instance,
     * checks for available updates, and initiates the installation process if required. The method
     * also handles error conditions and displays relevant messages.
     */
    protected function postThemeAdd()
    {
        // Check the user's permissions.
        if (!current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Data validation
        $repository = $request['repository'] ?? '';
        $repository = sanitize_text_field($repository);
        if (!$repository) {
            // Handle empty repository field with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository field must not be empty.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors
            ];
            $this->display('themes/add', $data);
            return;
        } elseif ($this->settings->getThemeByRepository($repository)) {
            // Handle duplicate repository with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository already exists.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors
            ];
            $this->display('themes/add', $data);
            return;
        }

        // Add a theme definition
        $extension = new Theme();

        $extension->id = Utility::uniqid();
        $extension->connectorId = $request['connectorId'];
        $extension->connector = $this->settings->getConnectorById($extension->connectorId);
        $extension->repository = $request['repository'];
        $extension->branch = $request['branch'] ?: 'main';
        $extension->installationFolder = $request['installationFolder'];
        if (!$extension->installationFolder) {
            $extension->installationFolder = $extension->repository;
        }
        $extension->updates = $request['updates'];
        $extension->lastChecked = time();

        // Add the new theme definition to the settings
        $this->settings->themes[] = $extension;

        // If update on tags or commits, check if there is a tag or commit available
        $extension->checkForUpdates();

        // Install the theme
        $repoZip = $extension->connector->downloadRepoZip($request['repository'], $request['branch']);
        if ($extension->remoteVersion) {
            $extension->localVersion = $extension->remoteVersion;
            $repoZip = $extension->connector->downloadRepoZip($request['repository'], $extension->remoteVersion);
        }

        if ($extension->connector->error) {
            // Handle connector error with an error message.
            $this->messages[] = new WP_Error('error', $extension->connector->error);
        } elseif ($repoZip) {
            // Initialize the theme installation process.
            $upgrader = new Theme_Upgrader(new ThemeUpgraderSkin($extension));
            $data = [
                'upgrader' => $upgrader,
                'repoZip' => $repoZip
            ];
            $this->display('themes/add-progress', $data);
            $this->settings->save();
            return;
        }

        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors
        ];

        // Display the theme add form.
        $this->display('themes/add', $data);
    }

    /**
     * Handle the editing of an existing theme definition.
     *
     * This method is responsible for processing the modification of an existing theme definition based on the
     * data submitted through a form. It performs data validation, updates the theme's properties, checks for
     * available updates, and saves the changes. The method also clears cached theme updates and displays
     * relevant messages.
     */
    protected function postThemeEdit()
    {
        $request = $_POST['rrze-updater'] ?? '';
        if (!$request || !is_array($request)) {
            return;
        }

        // Check the user's permissions.
        if (!current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        $extensionId = $request['id'];

        if (!$extension = $this->settings->getThemeById($extensionId)) {
            wp_die(esc_html__('An error occurred. Please try again.', 'rrze-updater'));
        }

        if (!$request['repository']) {
            // Handle empty repository field with an error message.
            $this->messages[] = new WP_Error('error', __('The Repository field must not be empty.', 'rrze-updater'));
            $data = [
                'connectors' => $this->settings->connectors,
                'theme' => $extension,
                'lastChecked' => $this->lastChecked($extension->lastChecked)
            ];
            $this->display('themes/edit', $data);
            return;
        }

        // Update the plugin definition's properties
        $extension->connectorId = $request['connectorId'];
        $extension->connector = $this->settings->getConnectorById($extension->connectorId);
        $extension->repository = $request['repository'];
        $extension->branch = $request['branch'] ?: 'main';
        $extension->updates = $request['updates'];
        $extension->remoteVersion = $request['branch'];

        // If update on tags or commits, check if there is a tag or commit available
        $extension->checkForUpdates();

        // Save the changes and clear cached themes updates
        $this->settings->save();
        delete_site_transient('update_themes');

        // Prepare the data for rendering.
        $data = [
            'connectors' => $this->settings->connectors,
            'theme' => $extension
        ];

        // Display the theme edit form.
        $this->display('themes/edit', $data);
    }

    /**
     * Retrieve the version of a plugin based on its installation folder and repository name.
     *
     * This method constructs the path to the plugin file, retrieves its data using 'get_plugin_data',
     * and returns the version of the plugin. If the version is not available, it returns an em dash.
     *
     * @param string $installationFolder The folder where the plugin is installed.
     * @param string $repository The name of the plugin's repository.
     *
     * @return string The version of the plugin or an em dash if not available.
     */
    protected function pluginVersion($installationFolder, $repository)
    {
        // Construct the full path to the plugin file.
        $pluginFile = sprintf(
            '%1$s/%2$s/%3$s.php',
            WP_PLUGIN_DIR,
            $installationFolder,
            $repository
        );

        // Retrieve plugin data using 'get_plugin_data'.
        $pluginData = get_plugin_data($pluginFile);

        // Return the version of the plugin, or an em dash if the version is not available.
        return $pluginData['Version'] ?: '&mdash;';
    }

    /**
     * Retrieve the version of a theme based on its installation folder.
     *
     * This method uses WordPress's 'wp_get_theme' function to retrieve theme information based on
     * the provided installation folder. It checks if the theme exists and returns its version.
     * If the theme doesn't exist, it returns an em dash.
     *
     * @param string $installationFolder The folder where the theme is installed.
     *
     * @return string The version of the theme or an em dash if the theme doesn't exist.
     */
    protected function themeVersion($installationFolder)
    {
        // Retrieve theme information using 'wp_get_theme'.
        $theme = wp_get_theme($installationFolder);

        // Check if the theme exists and return its version, or return an em dash if it doesn't exist.
        return $theme->exists() ? $theme->get('Version') : '&mdash;';
    }

    /**
     * Format and return the last checked timestamp in a human-readable format.
     *
     * This method takes a timestamp, converts it to the local time zone, and formats it as a human-readable
     * string. It includes the time difference relative to the current time if the last checked time is within
     * the same day. The formatted date includes an abbreviation with a tooltip showing the full date and time.
     *
     * @param int $timestamp The timestamp to be formatted.
     *
     * @return string A formatted string representing the last checked timestamp.
     */
    protected function lastChecked($timestamp)
    {
        // Convert the timestamp to the local time zone.
        $localLastCheckedTimestamp = strtotime(get_date_from_gmt(date('Y-m-d H:i:s', $timestamp)));

        // Format the local timestamp with a specific date and time format.
        $localLastCheckedDateTime = date(__('Y/m/d') . ' H:i:s', $localLastCheckedTimestamp);

        // Calculate the time difference between the last checked time and the current time.
        $timeDiff = time() - $timestamp;

        // Check if the last checked time is within the same day.
        if ($timeDiff >= 0 && $timeDiff < DAY_IN_SECONDS) {
            // Format the time difference in a human-readable format.
            $lastChecked = sprintf(__('%s ago'), human_time_diff($timestamp));
        } else {
            // Format the date using a different format for dates older than a day.
            $lastChecked = date(__('Y/m/d'), $localLastCheckedTimestamp);
        }

        // Create an abbreviation with a tooltip showing the full date and time.
        return '<abbr title="' . $localLastCheckedDateTime . '">' . $lastChecked;
    }

    /**
     * Display a specific view and associated data, subject to user permissions.
     *
     * This method checks if the current user has the necessary permissions to view plugin or theme-related
     * information. If the user does not have sufficient permissions, it terminates the script execution
     * and displays a message indicating the need for higher-level permissions. The method includes a view
     * file and passes associated data to it.
     *
     * @param string $view The name of the view to be displayed.
     * @param array $data (Optional) An array of data to be passed to the view.
     *
     * @return void The method outputs the view content to the screen.
     */
    protected function display($view, $data = [])
    {
        // Check if the current user has the required permissions to view plugin or theme information.
        if (!current_user_can('update_plugins') || !current_user_can('update_themes')) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }

        // Add the messages array to the data for use in the view.
        $data['messages'] = $this->messages;

        // Construct the file path for the specified view.
        $viewFile = plugin()->getPath('includes') . 'views/' . $view . '.php';

        // Add the view.
        $data['view'] = file_exists($viewFile) ? $view . '.php' : '';

        // Include the main view file, which will display the content.
        include 'views/main.php';
    }

    /**
     * Synchronize plugin and theme settings based on installed plugins and themes.
     *
     * This method updates the plugin and theme settings in the application based on the currently
     * installed plugins and themes in the WordPress environment. It checks the list of installed
     * plugins and themes and ensures that the settings are consistent with the current state of
     * the WordPress installation. Settings for plugins or themes that are no longer installed
     * are removed to maintain an accurate configuration.
     */
    public function synchronizeSettings()
    {
        // Get a list of installed plugins.
        $installedPlugins = get_plugins();
        $pluginFolders = [];

        // Extract plugin folder paths from the list of installed plugins.
        foreach (array_keys($installedPlugins) as $pluginFile) {
            $pluginFolders[] = dirname($pluginFile);
        }

        // Loop through the plugin settings and remove any that don't match currently installed plugins.
        foreach ($this->settings->plugins as $key => $plugin) {
            if (!$plugin->installationFolder || !in_array($plugin->installationFolder, $pluginFolders)) {
                unset($this->settings->plugins[$key]);
            }
        }

        // Get a list of installed themes.
        $installedThemes = wp_get_themes();
        $themeFolders = array_keys($installedThemes);

        // Loop through the theme settings and remove any that don't match currently installed themes.
        foreach ($this->settings->themes as $key => $theme) {
            if (!$theme->installationFolder || !in_array($theme->installationFolder, $themeFolders)) {
                unset($this->settings->themes[$key]);
            }
        }

        // Save the updated settings to maintain consistency.
        $this->settings->save();
    }
}

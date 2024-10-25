<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use RRZE\Updater\Controller;

use WP_List_Table;

/**
 * PluginsListTable Class for Managing Plugin List Table
 *
 * The `PluginsListTable` class extends the WordPress `WP_List_Table` class
 * to create a customized table for managing plugins. It provides methods for
 * rendering, filtering, and interacting with a list of plugins in the WordPress admin.
 */
class PluginsListTable extends WP_List_Table
{
    /**
     * @var Controller $controller The controller object for plugin management.
     */
    protected $controller;

    /**
     * @var array $listData An array of plugin data to be displayed in the table.
     */
    public $listData;

    /**
     * Constructor to Initialize Plugin List Table
     *
     * @param Controller $controller The controller object for plugin management.
     * @param array $listData An array of plugin data to be displayed in the table.
     */
    public function __construct(Controller $controller, $listData = [])
    {
        $this->controller = $controller;
        $this->listData = $listData;

        parent::__construct([
            'singular' => 'plugin',
            'plural' => 'plugins',
            'ajax' => false
        ]);
    }

    /**
     * Default Column Rendering Method
     *
     * This method renders default columns for the table and performs data validation.
     *
     * @param array $item The plugin item data.
     * @param string $columnName The name of the column.
     * @return string The rendered column content.
     */
    public function column_default($item, $columnName)
    {
        switch ($columnName) {
            case 'connector':
            case 'version':
            case 'installationFolder':
            case 'repository':
            case 'branch':
            case 'lastChecked':
            default:
                $item[$columnName] = !empty($item[$columnName]) ? $item[$columnName] : '';
        }

        return $item[$columnName];
    }

    /**
     * Render the 'plugin' Column
     *
     * This method renders the 'plugin' column with action links.
     *
     * @param array $item The plugin item data.
     * @return string The rendered 'plugin' column content.
     */
    public function column_plugin($item)
    {
        $page = $_REQUEST['page'] ?? '';
        $id = $item['id'];

        $actions = [
            'edit' => sprintf(
                '<a href="%1$s">%2$s</a>',
                add_query_arg(
                    [
                        'page' => $page,
                        'action' => 'edit',
                        'id' => $id
                    ],
                    self_admin_url('admin.php')
                ),
                __('Edit', 'rrze-updater')
            ),
            'delete' => sprintf(
                '<a href="%1$s">%2$s</a>',
                add_query_arg(
                    [
                        'page' => $page,
                        'action' => 'delete',
                        'id' => $id,
                        'rrze-updater-nonce' => wp_create_nonce('rrze-updater-plugin-delete')
                    ],
                    self_admin_url('admin.php')
                ),
                __('Delete', 'rrze-updater')
            ),
        ];

        return sprintf(
            '%1$s %2$s',
            $item['plugin'],
            $this->row_actions($actions)
        );
    }

    /**
     * Render the 'cb' Column
     *
     * This method renders the 'cb' (checkbox) column for bulk actions.
     *
     * @param array $item The plugin item data.
     * @return string The rendered 'cb' column content.
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s">',
            $this->_args['plural'],
            $item['id']
        );
    }

    /**
     * Get Column Definitions
     *
     * This method defines the columns to be displayed in the table.
     *
     * @return array An array of column names and their labels.
     */
    public function get_columns()
    {
        return [
            'cb' => '<input type="checkbox">',
            'plugin' => __('Plugin', 'rrze-updater'),
            'version' => __('Version', 'rrze-updater'),
            'installationFolder' => __('Folder', 'rrze-updater'),
            'connector' => __('Service', 'rrze-updater'),
            'repository' => __('Repository', 'rrze-updater'),
            'branch' => __('Branch', 'rrze-updater'),
            'lastChecked' => __('Last checked')
        ];
    }

    /**
     * Get Sortable Columns
     *
     * This method defines which columns can be sorted and their default sorting order.
     *
     * @return array An array of sortable columns and their sorting order.
     */
    public function get_sortable_columns()
    {
        return [
            'plugin' => ['plugin', false],
            'installationFolder' => ['installationFolder', false]
        ];
    }

    /**
     * Get Bulk Actions
     *
     * This method defines the available bulk actions.
     *
     * @return array An array of bulk action names.
     */
    public function get_bulk_actions()
    {
        return [
            'delete' => __('Delete', 'rrze-updater')
        ];
    }

    /**
     * Process Bulk Actions
     *
     * This method processes bulk actions, such as deleting plugins.
     */
    public function process_bulk_action()
    {
        if ('delete' === $this->current_action()) {
            $plugins = $_GET[$this->_args['plural']] ?? '';
            if (!empty($plugins) && is_array($plugins)) {
                foreach ($plugins as $id) {
                    foreach ($this->listData as $key => $subary) {
                        if ($subary['id'] == $id) {
                            unset($this->listData[$key]);
                        }
                    }
                    $this->controller->pluginDelete($id);
                }
            }
        }
    }

    /**
     * Prepare Table Items
     *
     * This method prepares the items to be displayed in the table,
     * including sorting, pagination, and bulk action processing.
     */
    public function prepare_items()
    {
        $this->process_bulk_action();

        usort($this->listData, function ($a, $b) {
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'plugin'; // If no sort, default to plugin
            $orderby = isset($a[$orderby]) || isset($b[$orderby]) ? $orderby : 'plugin';
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc'; // If no order, default to asc
            $result = strcmp($a[$orderby], $b[$orderby]); // Determine sort order
            return ($order === 'asc') ? $result : -$result; // Send final sort direction to usort
        });

        $this->_column_headers = $this->get_column_info();

        $perPage = $this->get_items_per_page('rrze_updater_per_page', 20);
        $currentPage = $this->get_pagenum();
        $totalItems = count($this->listData);

        $this->items = array_slice($this->listData, (($currentPage - 1) * $perPage), $perPage);

        $this->set_pagination_args([
            'total_items' => $totalItems, // Total number of items
            'per_page' => $perPage, // How many items to show on a page
            'total_pages' => ceil($totalItems / $perPage)   // Total number of pages
        ]);
    }
}

<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use RRZE\Updater\Controller;
use RRZE\Updater\Config;

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
     * @var bool Whether the optional tools column should be shown.
     */
    protected $showTools;

    /**
     * Constructor to Initialize Plugin List Table
     *
     * @param Controller $controller The controller object for plugin management.
     * @param array $listData An array of plugin data to be displayed in the table.
     */
    public function __construct(Controller $controller, $listData = [], bool $showTools = false)
    {
        $this->controller = $controller;
        $this->listData = $listData;
        $this->showTools = $showTools;

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
            case 'serviceRepository':
            case 'repository':
            case 'branch':
            case 'lastChecked':
            case 'tools':
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
            'repository' => !empty($item['repositoryUrl']) ? sprintf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url($item['repositoryUrl']),
                __('Repository', 'rrze-updater')
            ) : '',
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
        $actions = array_filter($actions);

        return sprintf(
            '%1$s %2$s',
            esc_html($item['plugin']),
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

    public function column_version($item)
    {
        $version = !empty($item['version']) ? $item['version'] : '';

        if (empty($item['hasUpdate']) || empty($item['updateUrl']) || empty($item['updateVersion'])) {
            return $version;
        }

        return sprintf(
            '%1$s <span class="rrze-updater-update-link">(<a href="%2$s">%3$s</a>)</span>',
            $version,
            esc_url($item['updateUrl']),
            sprintf(
                /* translators: %s: New extension version */
                esc_html__('Update auf %s', 'rrze-updater'),
                esc_html($item['updateVersion'])
            )
        );
    }

    public function single_row($item)
    {
        $class = !empty($item['hasUpdate']) ? 'rrze-updater-has-update' : '';

        echo $class ? '<tr class="' . esc_attr($class) . '">' : '<tr>';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    public function column_tools($item) {
        $links = [];

        if (!empty($item['pluginCheckUrl'])) {
            $links[] = sprintf(
                '<a class="button button-small" href="%1$s">%2$s</a>',
                esc_url($item['pluginCheckUrl']),
                esc_html__('Plugin Check', 'rrze-updater')
            );
        }

        if (!empty($item['multisiteManagerPluginsUrl'])) {
            $links[] = sprintf(
                '<a class="button button-small" href="%1$s">%2$s</a>',
                esc_url($item['multisiteManagerPluginsUrl']),
                esc_html__('Multisite Manager', 'rrze-updater')
            );
        }

        if (empty($links)) {
            return '&mdash;';
        }

        return implode(' ', $links);
    }

    public function column_serviceRepository($item) {
        $service = !empty($item['connector']) ? $item['connector'] : '&mdash;';
        $repository = !empty($item['repository']) ? $item['repository'] : '&mdash;';

        return sprintf(
            '%1$s / %2$s',
            esc_html($service),
            esc_html($repository)
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
        $columns = [
            'cb' => '<input type="checkbox">',
            'plugin' => __('Plugin', 'rrze-updater'),
            'version' => __('Version', 'rrze-updater'),
            'installationFolder' => __('Folder', 'rrze-updater'),
            'serviceRepository' => __('Service / Repository', 'rrze-updater'),
            'branch' => __('Branch', 'rrze-updater'),
            'lastChecked' => __('Last checked', 'rrze-updater')
        ];

        $columns['tools'] = __('Tools', 'rrze-updater');

        return $columns;
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

    protected function get_primary_column_name()
    {
        return 'plugin';
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
                if (($_GET['rrze-updater-bulk-delete-confirmed'] ?? '') !== '1') {
                    return;
                }

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

        $perPage = $this->get_items_per_page((new Config())->getScreenOptionPerPage(), 20);
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

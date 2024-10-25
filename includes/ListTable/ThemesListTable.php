<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use RRZE\Updater\Controller;

use WP_List_Table;

/**
 * ThemesListTable Class for Managing Themes List Table
 *
 * The `ThemesListTable` class extends the WordPress `WP_List_Table` class
 * to create a customized table for managing themes. It provides methods for
 * rendering, filtering, and interacting with a list of themes in the WordPress admin.
 */
class ThemesListTable extends WP_List_Table
{
    /**
     * @var Controller $controller The controller object for theme management.
     */
    protected $controller;

    /**
     * @var array $listData An array of theme data to be displayed in the table.
     */
    public $listData;

    /**
     * Constructor to Initialize Themes List Table
     *
     * @param Controller $controller The controller object for theme management.
     * @param array $listData An array of theme data to be displayed in the table.
     */
    public function __construct(Controller $controller, $listData = [])
    {
        $this->controller = $controller;
        $this->listData = $listData;

        parent::__construct([
            'singular' => 'theme',
            'plural' => 'themes',
            'ajax' => false
        ]);
    }

    /**
     * Default Column Rendering Method
     *
     * This method renders default columns for the table.
     *
     * @param array $item The theme item data.
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
     * Render the 'theme' Column
     *
     * This method renders the 'theme' column with action links.
     *
     * @param array $item The theme item data.
     * @return string The rendered 'theme' column content.
     */
    public function column_theme($item)
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
                        'rrze-updater-nonce' => wp_create_nonce('rrze-updater-theme-delete')
                    ],
                    self_admin_url('admin.php')
                ),
                __('Delete', 'rrze-updater')
            ),
        ];

        return sprintf(
            '%1$s %2$s',
            $item['theme'],
            $this->row_actions($actions)
        );
    }

    /**
     * Render the 'cb' Column
     *
     * This method renders the 'cb' (checkbox) column for bulk actions.
     *
     * @param array $item The theme item data.
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
            'cb' => '<input type="checkbox" />', // Render a checkbox instead of text
            'theme' => __('Theme', 'rrze-updater'),
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
            'theme' => ['theme', false],
            'installationFolder' => ['installationFolder', false]
        ];
    }

    /**
     * Get Bulk Actions
     *
     * This method defines the bulk actions that can be performed on themes.
     *
     * @return array An array of bulk actions.
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
     * This method processes bulk actions performed on themes.
     */
    public function process_bulk_action()
    {
        if ('delete' === $this->current_action()) {
            $themes = $_GET[$this->_args['plural']] ?? '';
            if (!empty($themes) && is_array($themes)) {
                foreach ($themes as $id) {
                    foreach ($this->listData as $key => $subary) {
                        if ($subary['id'] == $id) {
                            unset($this->listData[$key]);
                        }
                    }
                    $this->controller->themeDelete($id);
                }
            }
        }
    }

    /**
     * Prepare Items for Display
     *
     * This method prepares the items to be displayed in the table, including sorting
     * and pagination.
     */
    public function prepare_items()
    {
        $this->process_bulk_action();

        usort($this->listData, function ($a, $b) {
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'theme'; // If no sort, default to theme
            $orderby = isset($a[$orderby]) || isset($b[$orderby]) ? $orderby : 'theme';
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

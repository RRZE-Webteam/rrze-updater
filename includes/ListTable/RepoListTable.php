<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use RRZE\Updater\Controller;
use WP_List_Table;

/**
 * RepoListTable Class for Managing Repository List Table
 *
 * The `RepoListTable` class extends the WordPress `WP_List_Table` class
 * to create a customized table for managing repositories. It provides methods for
 * rendering, filtering, and interacting with a list of repositories in the WordPress admin.
 */
class RepoListTable extends WP_List_Table
{
    /**
     * @var Controller $controller The controller object for repository management.
     */
    protected $controller;

    /**
     * @var array $listData An array of repository data to be displayed in the table.
     */
    public $listData;

    /**
     * Constructor to Initialize Repository List Table
     *
     * @param Controller $controller The controller object for repository management.
     * @param array $listData An array of repository data to be displayed in the table.
     */
    public function __construct(Controller $controller, $listData = [])
    {
        $this->controller = $controller;
        $this->listData = $listData;

        parent::__construct([
            'singular' => 'repository',
            'plural' => 'repositories',
            'ajax' => false
        ]);
    }

    /**
     * Default Column Rendering Method
     *
     * This method renders default columns for the table and performs data trimming.
     *
     * @param array $item The repository item data.
     * @param string $columnName The name of the column.
     * @return string The rendered column content.
     */
    public function column_default($item, $columnName)
    {
        switch ($columnName) {
            case 'branch':
            case 'display':
            case 'owner':
                $item[$columnName] = !empty($item[$columnName]) ? wp_trim_words($item[$columnName], 10) : '';
                break;
            default:
                $item[$columnName] = !empty($item[$columnName]) ? $item[$columnName] : '';
        }

        return $item[$columnName];
    }

    /**
     * Render the 'repository' Column
     *
     * This method renders the 'repository' column with action links.
     *
     * @param array $item The repository item data.
     * @return string The rendered 'repository' column content.
     */
    public function column_repository($item)
    {
        $page = $_REQUEST['page'] ?? '';
        $type = !empty($item['plugin']) ? 'plugin' : 'theme';
        $id = $item['id'];

        $actions = [
            'edit' => sprintf(
                '<a href="%1$s">%2$s</a>',
                add_query_arg(
                    [
                        'page' => sprintf(
                            '%1$s-%2$ss',
                            $page,
                            $type
                        ),
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
                        'type' => $type,
                        'action' => 'delete',
                        'id' => $id,
                        'rrze-updater-nonce' => wp_create_nonce('rrze-updater-repo-delete')
                    ],
                    self_admin_url('admin.php')
                ),
                __('Delete', 'rrze-updater')
            ),
        ];

        return sprintf(
            '%1$s %2$s',
            $item['repository'],
            $this->row_actions($actions)
        );
    }

    /**
     * Render the 'cb' Column
     *
     * This method renders the 'cb' (checkbox) column for bulk actions.
     *
     * @param array $item The repository item data.
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
            'repository' => __('Repository', 'rrze-updater'),
            'branch' => __('Branch', 'rrze-updater'),
            'display' => __('Service', 'rrze-updater'),
            'owner' => __('User/Group', 'rrze-updater'),
            'type' => __('Type', 'rrze-updater'),
            'version' => __('Version', 'rrze-updater')
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
            'repository' => ['repository', false],
            'display' => ['display', false],
            'type' => ['type', false]
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
     * This method processes bulk actions, such as deleting repositories.
     */
    public function process_bulk_action()
    {
        if ('delete' === $this->current_action()) {
            $repos = $_GET['repositories'] ?? '';
            if (!empty($repos) && is_array($repos)) {
                foreach ($repos as $id) {
                    foreach ($this->listData as $key => $subary) {
                        if ($subary['id'] == $id) {
                            unset($this->listData[$key]);
                        }
                    }
                    $this->controller->pluginDelete($id);
                    $this->controller->themeDelete($id);
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
        usort($this->listData, function ($a, $b) {
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'repository'; // If no sort, default to repository
            $orderby = isset($a[$orderby]) || isset($b[$orderby]) ? $orderby : 'repository';
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc'; // If no order, default to asc
            $result = strcmp($a[$orderby], $b[$orderby]); // Determine sort order
            return ($order === 'asc') ? $result : -$result; // Send final sort direction to usort
        });

        $this->process_bulk_action();

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

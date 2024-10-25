<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use WP_List_Table;

/**
 * ConnListTable Class for Managing Connector List Table
 *
 * The `ConnListTable` class extends the WordPress `WP_List_Table` class
 * to create a customized table for managing connectors. It provides methods for
 * rendering, filtering, and interacting with a list of connectors in the WordPress admin.
 */
class ConnListTable extends WP_List_Table
{
    /**
     * @var array $listData An array of connector data to be displayed in the table.
     */
    public $listData;

    /**
     * Constructor to Initialize Connector List Table
     *
     * @param array $listData An array of connector data to be displayed in the table.
     */
    public function __construct($listData = [])
    {
        $this->listData = $listData;

        parent::__construct([
            'singular' => 'connector',
            'plural' => 'connectors',
            'ajax' => false
        ]);
    }

    /**
     * Default Column Rendering Method
     *
     * This method renders default columns for the table and performs data validation.
     *
     * @param array $item The connector item data.
     * @param string $columnName The name of the column.
     * @return string The rendered column content.
     */
    public function column_default($item, $columnName)
    {
        switch ($columnName) {
            case 'owner':
            case 'token':
            case 'repocount':
                return isset($item[$columnName]) ? $item[$columnName] : '';
            default:
                return '';
        }
    }

    /**
     * Render the 'display' Column
     *
     * This method renders the 'display' column with action links.
     *
     * @param array $item The connector item data.
     * @return string The rendered 'display' column content.
     */
    public function column_display($item)
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
            )
        ];

        if (!$item['repocount']) {
            $actions = array_merge(
                $actions,
                [
                    'delete' => sprintf(
                        '<a href="%1$s">%2$s</a>',
                        add_query_arg(
                            [
                                'page' => $page,
                                'action' => 'delete',
                                'id' => $id,
                                'rrze-updater-nonce' => wp_create_nonce('rrze-updater-connector-delete')
                            ],
                            self_admin_url('admin.php')
                        ),
                        __('Delete', 'rrze-updater')
                    )
                ]
            );
        }

        return sprintf(
            '%1$s %2$s',
            $item['display'],
            $this->row_actions($actions)
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
            'display' => __('Service', 'rrze-updater'),
            'owner' => __('User/Group', 'rrze-updater'),
            'token' => __('Token', 'rrze-updater'),
            'repocount' => __('Repositories', 'rrze-updater')
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
            'display' => ['display', false],
            'owner' => ['owner', false],
            'repocount' => ['repocount', false]
        ];
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
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'display'; // If no sort, default to display
            $orderby = isset($a[$orderby]) || isset($b[$orderby]) ? $orderby : 'display';
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

<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

use RRZE\Updater\Controller;
use RRZE\Updater\Config;
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
            case 'ref':
            case 'serviceOwner':
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
        $actions = array_filter($actions);

        return sprintf(
            '%1$s %2$s',
            esc_html($item['displayName'] ?? $item['repository']),
            $this->row_actions($actions)
        );
    }

    public function column_serviceOwner($item) {
        $parts = $this->getServiceOwnerParts($item);

        if (empty($parts)) {
            return '&mdash;';
        }

        $repositoryUrl = !empty($item['repositoryUrl']) ? (string) $item['repositoryUrl'] : '';
        $output = [];

        foreach ($parts as $part) {
            if ($repositoryUrl !== '') {
                $output[] = sprintf(
                    '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                    esc_url($repositoryUrl),
                    esc_html($part)
                );
                continue;
            }

            $output[] = esc_html($part);
        }

        return implode(' / ', $output);
    }

    private function getServiceOwnerParts(array $item): array {
        $fields = [
            'display',
            'owner',
            'repository'
        ];
        $parts = [];

        foreach ($fields as $field) {
            $value = trim((string) ($item[$field] ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts;
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
            'repository' => __('Name', 'rrze-updater'),
            'version' => __('Version', 'rrze-updater'),
            'type' => __('Type', 'rrze-updater'),
            'serviceOwner' => __('Repository', 'rrze-updater'),
            'ref' => __('Branch / Release tag', 'rrze-updater')
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
            'version' => ['version', false],
            'type' => ['type', false],
            'serviceOwner' => ['serviceOwner', false],
            'ref' => ['ref', false]
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
            'update' => __('Update', 'rrze-updater'),
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
                if (($_GET['rrze-updater-bulk-delete-confirmed'] ?? '') !== '1') {
                    return;
                }

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

        if ('update' === $this->current_action()) {
            $repos = $_GET['repositories'] ?? '';
            if (empty($repos) || !is_array($repos)) {
                return;
            }

            $this->controller->repoBulkUpdate($repos);
            exit;
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
        usort($this->listData, [$this, 'sortItems']);

        $this->process_bulk_action();

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

    private function sortItems(array $a, array $b): int {
        $aHasUpdate = !empty($a['hasUpdate']);
        $bHasUpdate = !empty($b['hasUpdate']);

        if ($aHasUpdate !== $bHasUpdate) {
            return $aHasUpdate ? -1 : 1;
        }

        $orderby = (!empty($_REQUEST['orderby']) && is_string($_REQUEST['orderby']))
            ? sanitize_key($_REQUEST['orderby'])
            : 'repository';
        $sortableColumns = array_keys($this->get_sortable_columns());

        if (!in_array($orderby, $sortableColumns, true)) {
            $orderby = 'repository';
        }

        $order = (!empty($_REQUEST['order']) && $_REQUEST['order'] === 'desc') ? 'desc' : 'asc';
        $aValue = $this->getSortValue($a, $orderby);
        $bValue = $this->getSortValue($b, $orderby);
        $result = strnatcasecmp($aValue, $bValue);

        if ($result === 0 && $orderby !== 'repository') {
            $result = strnatcasecmp((string) ($a['repository'] ?? ''), (string) ($b['repository'] ?? ''));
        }

        return ($order === 'asc') ? $result : -$result;
    }

    private function getSortValue(array $item, string $orderby): string {
        if ($orderby == 'repository' && !empty($item['displayName'])) {
            return wp_strip_all_tags((string) $item['displayName']);
        }

        return isset($item[$orderby]) ? wp_strip_all_tags((string) $item[$orderby]) : '';
    }
}

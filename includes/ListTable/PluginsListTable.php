<?php

namespace RRZE\Updater\ListTable;

defined('ABSPATH') || exit;

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
     * @var array $listData An array of plugin data to be displayed in the table.
     */
    public array $listData;

    /** @var string Validated column used by the current sort. */
    private string $sortColumn = 'plugin';

    /** @var bool Whether rows use ascending order within each update group. */
    private bool $sortAscending = true;

    /**
     * @var bool Whether the optional tools column should be shown.
     */
    protected bool $showTools;

    /**
     * Constructor to Initialize Plugin List Table
     *
     * @param array $listData An array of plugin data to be displayed in the table.
     * @param bool $showTools Whether optional tools were detected by the caller.
     */
    public function __construct(array $listData = [], bool $showTools = false)
    {
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
            esc_html($item['pluginDisplayName'] ?? $item['plugin']),
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
     * Render the installed version, or a repair action for incomplete plugins.
     *
     * @param array $item Repository display data.
     * @return string Escaped version and action markup.
     */
    public function column_version($item)
    {
        if (!empty($item['incomplete'])) {
            $status = '<strong>' . esc_html__('Installation incomplete', 'rrze-updater') . '</strong>';
            if (!empty($item['repairUrl'])) {
                $status .= sprintf('<br><a href="%1$s">%2$s</a>', esc_url($item['repairUrl']), esc_html__('Reinstall plugin files', 'rrze-updater'));
            }
            return $status;
        }
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
        $parts = $this->getRepositoryParts($item);

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

    private function getRepositoryParts(array $item): array {
        $service = trim((string) ($item['display'] ?? $item['connector'] ?? ''));
        $owner = trim((string) ($item['owner'] ?? ''));
        $repository = trim((string) ($item['repository'] ?? ''));
        $parts = [];

        if ($service !== '') {
            $parts[] = $service;
        }

        if ($owner !== '') {
            $parts[] = $owner;
        }

        if ($repository !== '') {
            $parts[] = $repository;
        }

        return $parts;
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
            'serviceRepository' => __('Repository', 'rrze-updater'),
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
     * Prepare Table Items
     *
     * This method prepares the items to be displayed in the table,
     * including sorting and pagination only.
     *
     * @return void
     */
    public function prepare_items()
    {
        $this->readSortParameters();
        usort($this->listData, [$this, 'sortItems']);

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

    /**
     * Read the request once so comparisons operate on a stable sort definition.
     *
     * @return void
     */
    private function readSortParameters(): void
    {
        $orderby = (!empty($_REQUEST['orderby']) && is_string($_REQUEST['orderby']))
            ? sanitize_key($_REQUEST['orderby'])
            : 'plugin';
        $sortableColumns = array_keys($this->get_sortable_columns());

        if (!in_array($orderby, $sortableColumns, true)) {
            $orderby = 'plugin';
        }

        $this->sortColumn = $orderby;
        $this->sortAscending = empty($_REQUEST['order']) || $_REQUEST['order'] !== 'desc';
    }

    /**
     * Keep available updates first, then compare the selected display values.
     *
     * @param array $a First display row.
     * @param array $b Second display row.
     * @return int Natural, case-insensitive comparison within each update group.
     */
    private function sortItems(array $a, array $b): int {
        $aHasUpdate = !empty($a['hasUpdate']);
        $bHasUpdate = !empty($b['hasUpdate']);

        if ($aHasUpdate !== $bHasUpdate) {
            return $aHasUpdate ? -1 : 1;
        }

        $aValue = $this->getSortValue($a, $this->sortColumn);
        $bValue = $this->getSortValue($b, $this->sortColumn);
        $result = strnatcasecmp($aValue, $bValue);

        if ($result === 0 && $this->sortColumn !== 'plugin') {
            $result = strnatcasecmp((string) ($a['plugin'] ?? ''), (string) ($b['plugin'] ?? ''));
        }

        return $this->sortAscending ? $result : -$result;
    }

    /**
     * Extract visible text while preserving the existing display-name fallback.
     *
     * @param array $item Display row.
     * @param string $orderby Validated column key.
     * @return string Plain text used for sorting.
     */
    private function getSortValue(array $item, string $orderby): string {
        if ($orderby == 'plugin' && !empty($item['pluginDisplayName'])) {
            return wp_strip_all_tags((string) $item['pluginDisplayName']);
        }

        return isset($item[$orderby]) ? wp_strip_all_tags((string) $item[$orderby]) : '';
    }
}

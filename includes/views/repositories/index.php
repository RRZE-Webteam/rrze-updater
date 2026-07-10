<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<style>
    .wp-list-table tr.rrze-updater-has-update {
        background: #fff8e5;
    }

    .wp-list-table tr.rrze-updater-has-update th,
    .wp-list-table tr.rrze-updater-has-update td {
        border-left-color: #dba617;
    }

    .wp-list-table .rrze-updater-update-link {
        font-weight: 600;
    }
</style>
<h2>
    <?php esc_html_e('Updater', 'rrze-updater'); ?>
</h2>

<form method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>">
    <?php
    $data['listTable']->search_box(__('Search', 'rrze-updater'), 's');
    ?>
</form>

<form method="get" class="rrze-updater-bulk-action-form">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
    <?php $data['listTable']->display(); ?>
</form>
<?php
$bulkDeleteConfirmTitle = __('Delete selected repositories?', 'rrze-updater');
$bulkDeleteConfirmMessage = __('This action deletes the selected repository definitions from RRZE Updater. Installed plugins or themes are not removed by this bulk action.', 'rrze-updater');
$bulkDeleteConfirmCheckboxLabel = __('I understand that the selected repository definitions will be deleted.', 'rrze-updater');
require __DIR__ . '/../partials/bulk-delete-confirm.php';
?>

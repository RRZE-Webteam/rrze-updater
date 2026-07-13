<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$pluginCheckOverviewUrl = $data['pluginCheckOverviewUrl'] ?? '';
$multisiteManagerPluginsUrl = $data['multisiteManagerPluginsUrl'] ?? '';
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

    .wp-list-table .column-tools .button {
        display: inline-block;
        margin: 0 4px 4px 0;
        white-space: nowrap;
    }
</style>
<h2>
    <?php esc_html_e('Plugins', 'rrze-updater'); ?>
    <a href="?page=<?php echo esc_attr($_REQUEST['page']); ?>&action=add" class="add-new-h2"><?php esc_html_e('Install', 'rrze-updater'); ?></a>
</h2>

<?php if ($pluginCheckOverviewUrl || $multisiteManagerPluginsUrl) : ?>
    <p>
        <?php if ($pluginCheckOverviewUrl) : ?>
            <a href="<?php echo esc_url($pluginCheckOverviewUrl); ?>" class="button"><?php esc_html_e('Plugin Check', 'rrze-updater'); ?></a>
        <?php endif; ?>
        <?php if ($multisiteManagerPluginsUrl) : ?>
            <a href="<?php echo esc_url($multisiteManagerPluginsUrl); ?>" class="button"><?php esc_html_e('Multisite Manager', 'rrze-updater'); ?></a>
        <?php endif; ?>
    </p>
<?php endif; ?>

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
$bulkDeleteConfirmTitle = __('Delete selected plugin repositories?', 'rrze-updater');
$bulkDeleteConfirmMessage = __('This action deletes the selected plugin repository definitions from RRZE Updater. Installed plugins are not removed by this bulk action.', 'rrze-updater');
$bulkDeleteConfirmCheckboxLabel = __('I understand that the selected plugin repository definitions will be deleted.', 'rrze-updater');
require __DIR__ . '/../partials/bulk-delete-confirm.php';
?>

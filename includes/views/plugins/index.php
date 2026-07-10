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
    <?php _e('Plugins', 'rrze-updater'); ?>
    <a href="?page=<?php echo $_REQUEST['page'] ?>&action=add" class="add-new-h2"><?php _e('Install', 'rrze-updater'); ?></a>
</h2>

<form method="get">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
    <?php
    $data['listTable']->search_box(__('Search', 'rrze-updater'), 's');
    ?>
</form>

<form method="get">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
    <?php $data['listTable']->display(); ?>
</form>

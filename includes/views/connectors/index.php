<?php

namespace RRZE\Updater;
defined('ABSPATH') || exit;
?>
<h2>
    <?php _e('Dienste', 'rrze-updater'); ?>
    <a href="?page=<?php echo esc_attr($_REQUEST['page']); ?>&tab=services&action=add" class="add-new-h2"><?php _e('Add new', 'rrze-updater'); ?></a>
</h2>

<form method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>">
    <input type="hidden" name="tab" value="services">
    <?php
    $data['listTable']->search_box(__('Search', 'rrze-updater'), 's');
    ?>
</form>

<form method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
    <input type="hidden" name="tab" value="services">
    <?php $data['listTable']->display(); ?>
</form>

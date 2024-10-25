<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<h2>
    <?php _e('Repositories', 'rrze-updater'); ?>
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
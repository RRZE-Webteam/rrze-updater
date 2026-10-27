<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

?>
<div class="wrap">
    <h1><?php esc_html_e('Repository-Updates', 'rrze-updater'); ?></h1>
    <?php if (empty($data['updates'])) : ?>
        <p><?php esc_html_e('Für die ausgewählten Repositories liegen keine Updates vor.', 'rrze-updater'); ?></p>
    <?php endif; ?>

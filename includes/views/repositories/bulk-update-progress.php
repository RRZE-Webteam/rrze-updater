<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$updates = $data['updates'] ?? [];
$returnUrl = $data['returnUrl'] ?? self_admin_url('admin.php?page=rrze-updater');
?>
<div class="wrap">
    <h1><?php esc_html_e('Repository-Updates', 'rrze-updater'); ?></h1>

    <?php if (empty($updates)) : ?>
        <p><?php esc_html_e('Für die ausgewählten Repositories liegen keine Updates vor.', 'rrze-updater'); ?></p>
    <?php else : ?>
        <?php foreach ($updates as $update) : ?>
            <h2><?php echo esc_html($update['name']); ?></h2>
            <?php
            if ($update['type'] == 'plugin') {
                $update['upgrader']->upgrade($update['target']);
            } elseif ($update['type'] == 'theme') {
                $update['upgrader']->upgrade($update['target']);
            }
            ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <p>
        <a class="button button-primary" href="<?php echo esc_url($returnUrl); ?>">
            <?php esc_html_e('Zurück zum Updater', 'rrze-updater'); ?>
        </a>
    </p>
</div>

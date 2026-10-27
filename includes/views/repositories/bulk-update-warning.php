<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<?php if (!empty($data['warning'])) : ?>
    <div class="notice notice-warning">
        <p><?php printf(
            /* translators: %s: Notice message */
            esc_html__('Hinweis: %s', 'rrze-updater'),
            esc_html($data['warning'])
        ); ?></p>
    </div>
<?php endif; ?>

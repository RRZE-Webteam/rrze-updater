<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <?php
    foreach ($data['messages'] as $message) :
        if (is_wp_error($message)) : ?>
            <div class="notice notice-error">
                <p>
                    <?php printf(
                        /* translators: %s: Error message */
                        esc_html__('Error: %s', 'rrze-updater'),
                        esc_html($message->get_error_message())
                    ); ?>
                </p>
            </div>
        <?php else : ?>
            <div class="updated">
                <p><?php echo esc_html($message); ?></p>
            </div>
    <?php endif;
    endforeach;
    if ($data['view']) :
        include $data['view'];
    endif;
    ?>
    <hr>
</div>

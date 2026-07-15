<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <?php
    foreach ($data['messages'] as $message) :
        if (is_wp_error($message)) :
            $messageCode = $message->get_error_code();
            $isWarning = in_array($messageCode, ['rrze_updater_missing_plugin_main_file', 'rrze_updater_missing_plugin_name_header', 'rrze_updater_missing_plugin_readme'], true);
            ?>
            <div class="notice <?php echo esc_attr($isWarning ? 'notice-warning' : 'notice-error'); ?>">
                <p>
                    <?php printf(
                        /* translators: %s: Error message */
                        $isWarning ? esc_html__('Hinweis: %s', 'rrze-updater') : esc_html__('Error: %s', 'rrze-updater'),
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

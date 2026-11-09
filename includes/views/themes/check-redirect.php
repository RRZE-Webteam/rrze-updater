<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$stylesheet = $data['stylesheet'];
$themeCheckUrl = $data['themeCheckUrl'];
$themeCheckNonce = $data['themeCheckNonce'];
?>
<!doctype html>
        <html>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <title><?php esc_html_e('Theme Check', 'rrze-updater'); ?></title>
        </head>
        <body>
            <form id="rrze-updater-theme-check-form" action="<?php echo esc_url($themeCheckUrl); ?>" method="post">
                <input type="hidden" name="themename" value="<?php echo esc_attr($stylesheet); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($themeCheckNonce); ?>">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(admin_url('themes.php?page=themecheck')); ?>">
                <noscript>
                    <p><?php esc_html_e('Theme Check requires a form submission. Continue with the button below.', 'rrze-updater'); ?></p>
                    <button type="submit"><?php esc_html_e('Theme Check', 'rrze-updater'); ?></button>
                </noscript>
            </form>
            <script>
                document.getElementById('rrze-updater-theme-check-form').submit();
            </script>
        </body>
        </html>

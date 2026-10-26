<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$review = $data['review'];
$actionUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'replace'], self_admin_url('admin.php'));
?>
<h2><?php esc_html_e('Confirm plugin file replacement', 'rrze-updater'); ?></h2>
<div class="notice notice-warning inline">
    <p><strong><?php esc_html_e('All files in the destination folder will be replaced, including custom changes and leftover files.', 'rrze-updater'); ?></strong></p>
    <p><?php esc_html_e('The plugin will not be uninstalled. Plugin settings in the database and its activation state will be preserved.', 'rrze-updater'); ?></p>
</div>
<table class="widefat striped">
    <tbody>
        <tr>
            <th scope="row"><?php esc_html_e('Repository', 'rrze-updater'); ?></th>
            <td><a href="<?php echo esc_url($review['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($review['source_url'] ?: $review['repository']); ?></a></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Plugin folder', 'rrze-updater'); ?></th>
            <td><code><?php echo esc_html($review['folder']); ?></code></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Target version', 'rrze-updater'); ?></th>
            <td><?php echo esc_html($review['version'] ?: $review['ref']); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Git reference', 'rrze-updater'); ?></th>
            <td><code><?php echo esc_html($review['ref']); ?></code></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Commit', 'rrze-updater'); ?></th>
            <td><code><?php echo esc_html($review['commit']); ?></code></td>
        </tr>
    </tbody>
</table>
<p><?php esc_html_e('This confirmation is valid once for 15 minutes. If the source or installation changes, prepare a new review.', 'rrze-updater'); ?></p>
<form action="<?php echo esc_url($actionUrl); ?>" method="post">
    <?php wp_nonce_field('rrze-updater-plugin-replacement-apply', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[replacement_action]" value="apply">
    <input type="hidden" name="rrze-updater[review_id]" value="<?php echo esc_attr($review['id']); ?>">
    <?php submit_button(__('Confirm and replace plugin files', 'rrze-updater')); ?>
</form>
<form action="<?php echo esc_url($actionUrl); ?>" method="post">
    <?php wp_nonce_field('rrze-updater-plugin-replacement-cancel', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[replacement_action]" value="cancel">
    <input type="hidden" name="rrze-updater[review_id]" value="<?php echo esc_attr($review['id']); ?>">
    <?php submit_button(__('Cancel', 'rrze-updater'), 'secondary'); ?>
</form>

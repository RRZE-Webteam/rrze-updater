<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$review = $data['review'];
$selectRelease = $review['mode'] === 'release';
$actionUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'replace'], self_admin_url('admin.php'));
?>
<h2><?php echo $selectRelease ? esc_html__('Confirm release installation', 'rrze-updater') : esc_html__('Confirm plugin file replacement', 'rrze-updater'); ?></h2>
<?php if ($selectRelease) : ?>
    <div class="notice notice-info inline">
        <p><strong><?php esc_html_e('After installation, this release will be held until you resume release updates. Automatic updates will not undo your selection.', 'rrze-updater'); ?></strong></p>
        <p><?php esc_html_e('Only plugin files are restored. Database changes made by newer versions are not rolled back; the selected version must be compatible with the existing data.', 'rrze-updater'); ?></p>
        <?php if (!empty($review['release']['prerelease'])) : ?>
            <p><strong><?php esc_html_e('You selected a pre-release.', 'rrze-updater'); ?></strong></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="notice notice-warning inline">
    <p><strong><?php esc_html_e('All files in the destination folder will be replaced, including custom changes and leftover files.', 'rrze-updater'); ?></strong></p>
    <p><?php esc_html_e('The plugin will not be uninstalled. Plugin settings in the database and its activation state will be preserved.', 'rrze-updater'); ?></p>
</div>
<?php echo MaintenanceNotice::render('replacement'); ?>
<table class="widefat striped">
    <tbody>
        <?php if ($selectRelease) : ?>
            <tr>
                <th scope="row"><?php esc_html_e('Installed version', 'rrze-updater'); ?></th>
                <td><?php echo esc_html($data['installedVersion'] ?? ($review['installed_ref'] ?: '—')); ?> (<?php echo esc_html($review['installed_ref'] ?: '—'); ?>)</td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Selected release', 'rrze-updater'); ?></th>
                <td><a href="<?php echo esc_url($review['release']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($review['release']['title'] ?: $review['ref']); ?></a></td>
            </tr>
        <?php endif; ?>
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
    <?php submit_button($selectRelease ? __('Install and hold this release', 'rrze-updater') : __('Confirm and replace plugin files', 'rrze-updater')); ?>
</form>
<form action="<?php echo esc_url($actionUrl); ?>" method="post">
    <?php wp_nonce_field('rrze-updater-plugin-replacement-cancel', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[replacement_action]" value="cancel">
    <input type="hidden" name="rrze-updater[review_id]" value="<?php echo esc_attr($review['id']); ?>">
    <?php submit_button(__('Cancel', 'rrze-updater'), 'secondary'); ?>
</form>

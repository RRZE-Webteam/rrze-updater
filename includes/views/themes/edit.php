<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$extension = $data['theme'];
$lastChecked = $data['lastChecked'];
$installedVersion = $data['installedVersion'] ?? '&mdash;';
$repositoryUrl = $data['repositoryUrl'] ?? '';
$multisiteManagerThemesUrl = $data['multisiteManagerThemesUrl'] ?? '';
$themeCheckUrl = $data['themeCheckUrl'] ?? '';
$searchTheme = add_query_arg(['s' => $extension->repository], self_admin_url('themes.php'));
?>
<?php if (($extension->localVersion != $extension->remoteVersion) && ($extension->lastError == "")) : ?>
    <div class="notice notice-info">
        <p><a href="<?php echo esc_url($searchTheme); ?>"><?php printf(
            /* translators: %s: Remote extension version */
            esc_html__('A new version is available: %s.', 'rrze-updater'),
            esc_html($extension->getRemoteVersionLabel())
        ); ?></a></p>
    </div>
<?php endif; ?>
<?php if ($extension->lastWarning) : ?>
    <div class="notice notice-warning">
        <p><?php printf(
            /* translators: %s: Warning message */
            esc_html__('Warning: %s', 'rrze-updater'),
            esc_html($extension->lastWarning)
        ); ?></p>
    </div>
<?php endif; ?>
<?php if ($extension->lastError) : ?>
    <div class="notice notice-error">
        <p><?php printf(
            /* translators: %s: Last extension error */
            esc_html__('Last error: %s', 'rrze-updater'),
            esc_html($extension->lastError)
        ); ?></p>
    </div>
<?php endif; ?>

<h2><?php esc_html_e('Theme', 'rrze-updater'); ?>
    <a href="<?php echo esc_url(add_query_arg(['page' => 'rrze-updater-themes', 'action' => 'check-updates', 'id' => $extension->id], self_admin_url('admin.php'))); ?>" class="add-new-h2"><?php esc_html_e('Check for updates', 'rrze-updater'); ?></a>
    <?php if ($themeCheckUrl) : ?>
        <a href="<?php echo esc_url($themeCheckUrl); ?>" class="add-new-h2"><?php esc_html_e('Theme Check', 'rrze-updater'); ?></a>
    <?php endif; ?>
    <?php if ($multisiteManagerThemesUrl) : ?>
        <a href="<?php echo esc_url($multisiteManagerThemesUrl); ?>" class="add-new-h2"><?php esc_html_e('Multisite Manager', 'rrze-updater'); ?></a>
    <?php endif; ?>
</h2>

<p><?php echo wp_kses_post(sprintf(
        /* translators: 1: Installed theme version, 2: Local git reference */
        __('Local Version: <code>%1$s</code> (Git Version: <code>%2$s</code>)', 'rrze-updater'),
        esc_html(wp_strip_all_tags($installedVersion)),
        $extension->localVersion ? esc_html($extension->localVersion) : '&mdash;'
    )); ?></p>
<p><?php echo wp_kses_post(sprintf(
        /* translators: 1: Remote theme version, 2: Remote git reference */
        __('Remote Version: <code>%1$s</code> (Git Version: <code>%2$s</code>)', 'rrze-updater'),
        esc_html(wp_strip_all_tags($extension->getRemoteVersionLabel() ?: '&mdash;')),
        $extension->remoteVersion ? esc_html($extension->remoteVersion) : '&mdash;'
    )); ?></p>
<p><?php echo wp_kses_post(sprintf(
        /* translators: %s: Last checked date */
        __('Last checked on: %s', 'rrze-updater'),
        $lastChecked
    )); ?></p>
<?php if ($repositoryUrl) : ?>
    <p><?php echo wp_kses_post(sprintf(
            /* translators: %s: Repository URL */
            __('Repository: %s', 'rrze-updater'),
            sprintf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url($repositoryUrl),
                esc_html($repositoryUrl)
            )
        )); ?>
    </p>
<?php endif; ?>

<form action="<?php echo esc_url(add_query_arg(['page' => 'rrze-updater-themes', 'action' => 'edit', 'id' => $extension->id], self_admin_url('admin.php'))); ?>" method="POST">
    <?php wp_nonce_field('rrze-updater-theme-edit', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="edit-theme" />
    <input type="hidden" name="rrze-updater[id]" value="<?php echo esc_attr($extension->id); ?>" />
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <select name="rrze-updater[connectorId]">
                        <?php
                        foreach ($data['connectors'] as $connector) {
                            printf(
                                '<option value="%1$s" %2$s>%3$s</option>',
                                esc_attr($connector->id),
                                selected($extension->connectorId, $connector->id, false),
                                esc_html(sprintf('%1$s [%2$s]', $connector->display, $connector->owner))
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Repository', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[repository]" type="text" class="regular-text" value="<?php echo esc_attr($extension->repository); ?>">
                    <p class="description"><?php esc_html_e('The name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Branch', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[branch]" type="text" class="regular-text" placeholder="main" value="<?php echo esc_attr($extension->branch); ?>">
                    <p class="description"><?php esc_html_e('By default the main branch will be used.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Check for updates', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <select name="rrze-updater[updates]">
                        <?php
                        $updates = [
                            ['value' => 'commits', 'display' => __('Commits', 'rrze-updater')],
                            ['value' => 'tags', 'display' => __('Tags', 'rrze-updater')]
                        ];

                        foreach ($updates as $update) {
                            printf(
                                '<option value="%1$s" %2$s>%3$s</option>',
                                esc_attr($update['value']),
                                selected($extension->updates, $update['value'], false),
                                esc_html($update['display'])
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Theme folder', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[installationFolder]" type="text" class="regular-text" placeholder="" value="<?php echo esc_attr($extension->installationFolder); ?>">
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Save Changes', 'rrze-updater')); ?>
</form>

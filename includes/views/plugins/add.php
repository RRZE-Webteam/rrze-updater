<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$request = isset($_POST['rrze-updater']) && is_array($_POST['rrze-updater']) ? wp_unslash($_POST['rrze-updater']) : [];
?>
<h2><?php esc_html_e('Install Plugin', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-plugin-add', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="add-plugin">
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
                                selected($request['connectorId'] ?? '', $connector->id, false),
                                esc_html(sprintf('%1$s [%2$s]', $connector->display, $connector->owner))
                            );
                        }
                        ?>
                    </select>
                    <p class="description"><a href="<?php echo esc_url(admin_url('admin.php?page=rrze-updater-settings&tab=services&action=add')); ?>"><?php esc_html_e('Add a New Service', 'rrze-updater'); ?></a></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Repository', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[repository]" type="text" class="regular-text" value="<?php echo esc_attr($request['repository'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('The name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Branch', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[branch]" type="text" class="regular-text" placeholder="main" value="<?php echo esc_attr($request['branch'] ?? ''); ?>">
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
                                selected($request['updates'] ?? '', $update['value'], false),
                                esc_html($update['display'])
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Plugin folder', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[installationFolder]" type="text" class="regular-text" placeholder="" value="<?php echo esc_attr($request['installationFolder'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('By default, the name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Install Plugin', 'rrze-updater')); ?>
</form>

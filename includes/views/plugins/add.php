<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$request = isset($_POST['rrze-updater']) && is_array($_POST['rrze-updater']) ? array_filter(wp_unslash($_POST['rrze-updater']), 'is_string') : [];
$replacementUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'replace'], self_admin_url('admin.php'));
?>
<h2><?php esc_html_e('Install Plugin', 'rrze-updater'); ?></h2>

<form action="<?php echo esc_url(add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'add'], self_admin_url('admin.php'))); ?>" method="POST">
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
                    <p class="description"><a href="<?php echo esc_url(self_admin_url('admin.php?page=rrze-updater-settings&tab=services&action=add')); ?>"><?php esc_html_e('Add a New Service', 'rrze-updater'); ?></a></p>
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
                            ['value' => 'tags', 'display' => __('Tags', 'rrze-updater')],
                            ['value' => 'releases', 'display' => __('Releases', 'rrze-updater')]
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
    <?php if (!empty($data['managedPluginUrl'])) : ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e('This folder already belongs to a managed plugin. Change its source or reinstall its files from the existing entry.', 'rrze-updater'); ?></p>
            <p><a class="button" href="<?php echo esc_url($data['managedPluginUrl']); ?>"><?php esc_html_e('Open managed plugin', 'rrze-updater'); ?></a></p>
        </div>
    <?php elseif (!empty($data['canReplace'])) : ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e('The plugin folder already exists. You can review a replacement before any files are changed.', 'rrze-updater'); ?></p>
            <?php wp_nonce_field('rrze-updater-plugin-replacement-prepare', 'rrze-updater-replacement-nonce'); ?>
            <p><button type="submit" class="button" name="rrze-updater[replacement_action]" value="prepare" formaction="<?php echo esc_url($replacementUrl); ?>"><?php esc_html_e('Replace folder and install', 'rrze-updater'); ?></button></p>
        </div>
    <?php endif; ?>
</form>

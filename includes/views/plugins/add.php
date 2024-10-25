<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<h2><?php _e('Install Plugin', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-plugin-add', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="add-plugin">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php _e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <select name="rrze-updater[connectorId]">
                        <?php
                        foreach ($data['connectors'] as $connector) {
                            $selected = '';
                            if (isset($_POST['rrze-updater']['connectorId']) && $_POST['rrze-updater']['connectorId'] === $connector->id) {
                                $selected = 'selected="selected"';
                            }
                            echo '<option value="' . $connector->id . '" ' . $selected . '>' . sprintf('%1$s [%2$s]', $connector->display, $connector->owner) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="description"><a href="admin.php?page=rrze-updater-connectors&action=add"><?php _e('Add a New Service', 'rrze-updater'); ?></a></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Repository', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[repository]" type="text" class="regular-text" value="<?php echo (isset($_POST['rrze-updater']['repository'])) ? $_POST['rrze-updater']['repository'] : ''; ?>">
                    <p class="description"><?php _e('The name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Branch', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[branch]" type="text" class="regular-text" placeholder="main" value="<?php echo (isset($_POST['rrze-updater']['branch'])) ? $_POST['rrze-updater']['branch'] : ''; ?>">
                    <p class="description"><?php _e('By default the main branch will be used.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Check for updates', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <select name="rrze-updater[updates]">
                        <?php
                        $updates = [
                            ['value' => 'commits', 'display' => __('Commits', 'rrze-updater')],
                            ['value' => 'tags', 'display' => __('Tags', 'rrze-updater')]
                        ];

                        foreach ($updates as $update) {
                            $selected = '';
                            if (isset($_POST['rrze-updater']['updates']) && $_POST['rrze-updater']['updates'] === $update['value']) {
                                $selected = 'selected="selected"';
                            }
                            echo '<option value="' . $update['value'] . '" ' . $selected . '>' . $update['display'] . '</option>';
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Plugin folder', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[installationFolder]" type="text" class="regular-text" placeholder="" value="<?php echo (isset($_POST['rrze-updater']['installationFolder'])) ? $_POST['rrze-updater']['installationFolder'] : ''; ?>">
                    <p class="description"><?php _e('By default, the name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Install Plugin', 'rrze-updater')); ?>
</form>
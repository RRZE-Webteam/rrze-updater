<?php

namespace RRZE\Updater;
defined('ABSPATH') || exit;
?>
<h2><?php _e('Add a New Service', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-connector-add', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="add-connector">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php _e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <select name="rrze-updater[type]">
                        <option value="github" <?php if (isset($_POST['rrze-updater']['type']) && $_POST['rrze-updater']['type'] === 'github') echo 'selected="selected" '; ?>><?php _e('GitHub.com', 'rrze-updater'); ?></option>
                        <option value="gitlab" <?php if (isset($_POST['rrze-updater']['type']) && $_POST['rrze-updater']['type'] === 'gitlab') echo 'selected="selected" '; ?>><?php _e('RRZE Gitlab', 'rrze-updater'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo (isset($_POST['rrze-updater']['owner'])) ? $_POST['rrze-updater']['owner'] : ''; ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo (isset($_POST['rrze-updater']['token'])) ? $_POST['rrze-updater']['token'] : ''; ?>">
                    <p class="description"><?php _e('Optional.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Add a New Service', 'rrze-updater')); ?>
</form>
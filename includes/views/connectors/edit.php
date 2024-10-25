<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
$connector = $data['connector'];
?>

<h2><?php _e('Edit Service', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-connector-edit', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="edit-connector">
    <input type="hidden" name="rrze-updater[id]" value="<?php echo $connector->id; ?>" />
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php _e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[type]" type="text" autocomplete="off" class="regular-text" value="<?php echo $connector->display; ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo $connector->owner; ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo (!empty($connector->token)) ? $connector->token : ''; ?>">
                    <p class="description"><?php _e('Optional.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Save Changes', 'rrze-updater')); ?>

</form>
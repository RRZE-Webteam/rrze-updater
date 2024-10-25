<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$extension = $data['theme'];
$lastChecked = $data['lastChecked'];
$searchTheme = add_query_arg(['s' => $extension->repository], self_admin_url('themes.php'));
?>
<?php if (($extension->localVersion != $extension->remoteVersion) && ($extension->lastError == "")) : ?>
    <div class="notice notice-info">
        <p><a href="<?php echo $searchTheme ?>"><?php _e('A new version is available.', 'rrze-updater'); ?></a></p>
    </div>
<?php endif; ?>
<?php if ($extension->lastWarning) : ?>
    <div class="notice notice-warning">
        <p><?php printf(__('Warning: %s', 'rrze-updater'), $extension->lastWarning); ?></p>
    </div>
<?php endif; ?>
<?php if ($extension->lastError) : ?>
    <div class="notice notice-error">
        <p><?php printf(__('Last error: %s', 'rrze-updater'), $extension->lastError); ?></p>
    </div>
<?php endif; ?>

<h2><?php _e('Theme', 'rrze-updater'); ?>
    <a href="?page=rrze-updater-themes&action=check-updates&id=<?php echo $extension->id ?>" class="add-new-h2"><?php _e('Check for updates', 'rrze-updater'); ?></a>
</h2>

<p><?php printf(__('Local Version: %s', 'rrze-updater'), $extension->localVersion); ?></p>
<p><?php printf(__('Remote Version: %s', 'rrze-updater'), $extension->remoteVersion); ?></p>
<p><?php printf(__('Last checked on: %s', 'rrze-updater'), $lastChecked); ?></p>

<form action="?page=rrze-updater-themes&action=edit&id=<?php echo $extension->id ?>" method="POST">
    <?php wp_nonce_field('rrze-updater-theme-edit', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="edit-theme" />
    <input type="hidden" name="rrze-updater[id]" value="<?php echo $extension->id; ?>" />
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
                            if ($extension->connectorId === $connector->id) {
                                $selected = 'selected="selected"';
                            }
                            echo '<option value="' . $connector->id . '" ' . $selected . '>' . sprintf('%1$s [%2$s]', $connector->display, $connector->owner) . '</option>';
                        }
                        ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Repository', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[repository]" type="text" class="regular-text" value="<?php echo $extension->repository; ?>">
                    <p class="description"><?php _e('The name of the repository.', 'rrze-updater'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Branch', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[branch]" type="text" class="regular-text" placeholder="main" value="<?php echo $extension->branch; ?>">
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
                            if ($extension->updates == $update['value']) {
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
                    <label><?php _e('Theme folder', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[installationFolder]" type="text" class="regular-text" placeholder="" value="<?php echo $extension->installationFolder; ?>">
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Save Changes', 'rrze-updater')); ?>
</form>
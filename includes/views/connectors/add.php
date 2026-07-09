<?php

namespace RRZE\Updater;
defined('ABSPATH') || exit;

$config = new Config();
$fields = $config->getFields();
$connectorTypes = $fields['connector_types'] ?? [];
$request = isset($_POST['rrze-updater']) && is_array($_POST['rrze-updater']) ? $_POST['rrze-updater'] : [];
$type = $request['type'] ?? 'github';
$owner = $request['owner'] ?? '';
$host = $request['host'] ?? '';
$apiUri = $request['apiUri'] ?? $config->getGitlabCustomDefaultApiUri();
$token = $request['token'] ?? '';
$hostPlaceholder = $config->getGitlabCustomHostPlaceholder();
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
                    <select id="rrze-updater-service-type" name="rrze-updater[type]">
                        <?php foreach ($connectorTypes as $value => $connectorType) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($type, $value); ?>><?php echo esc_html($connectorType['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr class="rrze-updater-gitlab-custom-setting">
                <th scope="row">
                    <label><?php _e('GitLab host', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[host]" type="text" autocomplete="off" class="regular-text" placeholder="<?php echo esc_attr($hostPlaceholder); ?>" value="<?php echo esc_attr($host); ?>">
                    <p class="description"><?php printf(esc_html__('Only used for GitLab. Example: %s', 'rrze-updater'), esc_html($hostPlaceholder)); ?></p>
                </td>
            </tr>
            <tr class="rrze-updater-gitlab-custom-setting">
                <th scope="row">
                    <label><?php _e('GitLab API URI', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[apiUri]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($apiUri); ?>">
                    <p class="description"><?php printf(esc_html__('Only used for GitLab. Default: %s', 'rrze-updater'), esc_html($config->getGitlabCustomDefaultApiUri())); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($owner); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($token); ?>">
                    <p class="description"><?php _e('Optional.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Add a New Service', 'rrze-updater')); ?>
</form>
<script>
function rrzeUpdaterToggleGitlabCustomSettings() {
    var serviceType = document.getElementById('rrze-updater-service-type');
    var customSettings = document.querySelectorAll('.rrze-updater-gitlab-custom-setting');
    var displayValue = serviceType && serviceType.value === 'gitlab-custom' ? '' : 'none';
    var i;

    for (i = 0; i < customSettings.length; i++) {
        customSettings[i].style.display = displayValue;
    }
}

function rrzeUpdaterInitConnectorForm() {
    var serviceType = document.getElementById('rrze-updater-service-type');

    if (!serviceType) {
        return;
    }

    serviceType.addEventListener('change', rrzeUpdaterToggleGitlabCustomSettings);
    rrzeUpdaterToggleGitlabCustomSettings();
}

document.addEventListener('DOMContentLoaded', rrzeUpdaterInitConnectorForm);
</script>

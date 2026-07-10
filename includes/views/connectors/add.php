<?php

namespace RRZE\Updater;
defined('ABSPATH') || exit;

$config = new Config();
$fields = $config->getFields();
$connectorTypes = $fields['connector_types'] ?? [];
$request = isset($_POST['rrze-updater']) && is_array($_POST['rrze-updater']) ? wp_unslash($_POST['rrze-updater']) : [];
$type = $request['type'] ?? 'github';
$owner = $request['owner'] ?? '';
$host = $request['host'] ?? '';
$apiUri = $request['apiUri'] ?? $config->getGitlabCustomDefaultApiUri();
$token = $request['token'] ?? '';
$hostPlaceholder = $config->getGitlabCustomHostPlaceholder();
?>
<h2><?php esc_html_e('Einstellungen', 'rrze-updater'); ?></h2>

<nav class="nav-tab-wrapper">
    <a class="nav-tab" href="<?php echo esc_url(self_admin_url('admin.php?page=rrze-updater-settings')); ?>"><?php esc_html_e('Allgemein', 'rrze-updater'); ?></a>
    <a class="nav-tab nav-tab-active" href="<?php echo esc_url(self_admin_url('admin.php?page=rrze-updater-settings&tab=services')); ?>"><?php esc_html_e('Dienste', 'rrze-updater'); ?></a>
</nav>

<h2><?php esc_html_e('Add a New Service', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-connector-add', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="add-connector">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Service', 'rrze-updater'); ?></label>
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
                    <label><?php esc_html_e('GitLab host', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[host]" type="text" autocomplete="off" class="regular-text" placeholder="<?php echo esc_attr($hostPlaceholder); ?>" value="<?php echo esc_attr($host); ?>">
                    <p class="description"><?php printf(
                        /* translators: %s: Example GitLab host name */
                        esc_html__('Only used for GitLab. Example: %s', 'rrze-updater'),
                        esc_html($hostPlaceholder)
                    ); ?></p>
                </td>
            </tr>
            <tr class="rrze-updater-gitlab-custom-setting">
                <th scope="row">
                    <label><?php esc_html_e('GitLab API URI', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[apiUri]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($apiUri); ?>">
                    <p class="description"><?php printf(
                        /* translators: %s: Default GitLab API URI */
                        esc_html__('Only used for GitLab. Default: %s', 'rrze-updater'),
                        esc_html($config->getGitlabCustomDefaultApiUri())
                    ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($owner); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($token); ?>">
                    <p class="description"><?php esc_html_e('Optional.', 'rrze-updater'); ?></p>
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

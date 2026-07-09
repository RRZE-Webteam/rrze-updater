<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
$config = new Config();
$defaultGitlabHost = $config->getGitlabDefaultHost();
$defaultGitlabApiUri = $config->getGitlabDefaultApiUri();
$hostPlaceholder = $config->getGitlabCustomHostPlaceholder();
$connector = $data['connector'];
$isGitlab = method_exists($connector, 'getType') && $connector->getType() === 'gitlab';
$host = $isGitlab ? $connector->host : '';
$apiUri = $isGitlab ? $connector->apiUri : '';
$isCustomGitlab = $isGitlab
    && (
        $host !== $defaultGitlabHost
        || $apiUri !== $defaultGitlabApiUri
    );
?>

<h2><?php _e('Edit Service', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-connector-edit', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="edit-connector">
    <input type="hidden" name="rrze-updater[id]" value="<?php echo esc_attr($connector->id); ?>" />
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php _e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[type]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($connector->display); ?>">
                </td>
            </tr>
            <?php if ($isCustomGitlab) : ?>
                <tr>
                    <th scope="row">
                        <label><?php _e('GitLab host', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input name="rrze-updater[host]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($host); ?>">
                        <p class="description"><?php printf(esc_html__('Example: %s', 'rrze-updater'), esc_html($hostPlaceholder)); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php _e('GitLab API URI', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input name="rrze-updater[apiUri]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($apiUri); ?>">
                        <p class="description"><?php printf(esc_html__('Default: %s', 'rrze-updater'), esc_html($config->getGitlabDefaultApiUri())); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">
                    <label><?php _e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($connector->owner); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php _e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr((!empty($connector->token)) ? $connector->token : ''); ?>">
                    <p class="description"><?php _e('Optional.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Save Changes', 'rrze-updater')); ?>

</form>

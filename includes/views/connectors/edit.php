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

<h2><?php esc_html_e('Einstellungen', 'rrze-updater'); ?></h2>

<nav class="nav-tab-wrapper">
    <a class="nav-tab" href="<?php echo esc_url(self_admin_url('admin.php?page=rrze-updater-settings')); ?>"><?php esc_html_e('Allgemein', 'rrze-updater'); ?></a>
    <a class="nav-tab nav-tab-active" href="<?php echo esc_url(self_admin_url('admin.php?page=rrze-updater-settings&tab=services')); ?>"><?php esc_html_e('Dienste', 'rrze-updater'); ?></a>
</nav>

<h2><?php esc_html_e('Edit Service', 'rrze-updater'); ?></h2>

<form action="" method="POST">
    <?php wp_nonce_field('rrze-updater-connector-edit', 'rrze-updater-nonce'); ?>
    <input type="hidden" name="rrze-updater[action]" value="edit-connector">
    <input type="hidden" name="rrze-updater[id]" value="<?php echo esc_attr($connector->id); ?>" />
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Service', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[type]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($connector->display); ?>">
                </td>
            </tr>
            <?php if ($isCustomGitlab) : ?>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('GitLab host', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input name="rrze-updater[host]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($host); ?>">
                        <p class="description"><?php printf(
                            /* translators: %s: Example GitLab host name */
                            esc_html__('Example: %s', 'rrze-updater'),
                            esc_html($hostPlaceholder)
                        ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('GitLab API URI', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input name="rrze-updater[apiUri]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($apiUri); ?>">
                        <p class="description"><?php printf(
                            /* translators: %s: Default GitLab API URI */
                            esc_html__('Default: %s', 'rrze-updater'),
                            esc_html($config->getGitlabDefaultApiUri())
                        ); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('User/Group', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input readonly="readonly" name="rrze-updater[owner]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr($connector->owner); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Token', 'rrze-updater'); ?></label>
                </th>
                <td>
                    <input name="rrze-updater[token]" type="text" autocomplete="off" class="regular-text" value="<?php echo esc_attr((!empty($connector->token)) ? $connector->token : ''); ?>">
                    <p class="description"><?php esc_html_e('Optional.', 'rrze-updater'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
    <?php submit_button(__('Save Changes', 'rrze-updater')); ?>

</form>

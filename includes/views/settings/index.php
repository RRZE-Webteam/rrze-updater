<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$tab = $data['tab'] ?? 'general';
$settings = $data['settings'] ?? [];
$cronSchedules = $data['cronSchedules'] ?? [];
$emailSchedules = $data['emailSchedules'] ?? [];
$settingsPage = 'rrze-updater-settings';
?>
<h2><?php _e('Einstellungen', 'rrze-updater'); ?></h2>

<nav class="nav-tab-wrapper">
    <a class="nav-tab <?php echo $tab == 'general' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self_admin_url('admin.php?page=' . $settingsPage)); ?>"><?php _e('Allgemein', 'rrze-updater'); ?></a>
    <a class="nav-tab <?php echo $tab == 'services' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self_admin_url('admin.php?page=' . $settingsPage . '&tab=services')); ?>"><?php _e('Dienste', 'rrze-updater'); ?></a>
</nav>

<?php if ($tab == 'services') : ?>
    <?php include plugin()->getPath('includes') . 'views/connectors/index.php'; ?>
<?php else : ?>
    <form action="<?php echo esc_url(self_admin_url('admin.php?page=' . $settingsPage)); ?>" method="POST">
        <?php wp_nonce_field('rrze-updater-settings', 'rrze-updater-nonce'); ?>
        <input type="hidden" name="rrze-updater[action]" value="save-settings">

        <h3><?php _e('Scheduler', 'rrze-updater'); ?></h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="rrze-updater-update-check-schedule"><?php _e('Abfragehäufigkeit', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <select id="rrze-updater-update-check-schedule" name="rrze-updater[update_check_schedule]">
                            <?php foreach ($cronSchedules as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['update_check_schedule'] ?? '', $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3><?php _e('Update-Hinweise per E-Mail', 'rrze-updater'); ?></h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php _e('E-Mail-Hinweise', 'rrze-updater'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rrze-updater[email_updates_enabled]" value="1" <?php checked(!empty($settings['email_updates_enabled'])); ?>>
                            <?php _e('E-Mail-Hinweise aktivieren', 'rrze-updater'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rrze-updater-email-address"><?php _e('E-Mail-Adresse', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input id="rrze-updater-email-address" name="rrze-updater[email_address]" type="email" class="regular-text" value="<?php echo esc_attr($settings['email_address'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rrze-updater-email-subject-prefix"><?php _e('E-Mail-Betreff-Prefix', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <input id="rrze-updater-email-subject-prefix" name="rrze-updater[email_subject_prefix]" type="text" class="regular-text" value="<?php echo esc_attr($settings['email_subject_prefix'] ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rrze-updater-email-schedule"><?php _e('Sendehäufigkeit', 'rrze-updater'); ?></label>
                    </th>
                    <td>
                        <select id="rrze-updater-email-schedule" name="rrze-updater[email_schedule]">
                            <?php foreach ($emailSchedules as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['email_schedule'] ?? '', $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(__('Save Changes', 'rrze-updater'), 'primary', 'submit', false); ?>
        <?php submit_button(__('Jetzt senden', 'rrze-updater'), 'secondary', 'rrze-updater-send-now', false); ?>
    </form>
<?php endif; ?>

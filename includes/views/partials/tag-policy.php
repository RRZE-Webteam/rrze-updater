<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

if (!$extension->connector instanceof Core\GithubConnector) {
    return;
}
?>
<tr>
    <th scope="row"><?php esc_html_e('GitHub tags', 'rrze-updater'); ?></th>
    <td>
        <label for="rrze-ignore-prereleases">
            <input type="checkbox" id="rrze-ignore-prereleases" name="rrze-updater[ignorePrereleases]" value="1" <?php checked($extension->ignorePrereleases); ?>>
            <?php esc_html_e('Ignore pre-releases', 'rrze-updater'); ?>
        </label>
        <p class="description"><?php esc_html_e('Applies to Tags: skip GitHub pre-releases and version tags with a prerelease suffix (e.g. beta or rc). Releases always excludes pre-releases.', 'rrze-updater'); ?></p>
        <p class="description"><?php esc_html_e('The highest version is selected, ignoring prefixes such as v or release-. If no version tags exist, the most recently published release is preferred; tags without a release retain GitHub ordering.', 'rrze-updater'); ?></p>
    </td>
</tr>

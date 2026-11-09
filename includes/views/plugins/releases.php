<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$extension = $data['plugin'];
$page = $data['page'];
$listUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'releases', 'id' => $extension->id], self_admin_url('admin.php'));
$editUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'edit', 'id' => $extension->id], self_admin_url('admin.php'));
$actionUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'replace'], self_admin_url('admin.php'));
?>
<h2><?php esc_html_e('Choose release', 'rrze-updater'); ?>: <?php echo esc_html($extension->repository); ?></h2>
<p><a href="<?php echo esc_url($editUrl); ?>">&larr; <?php esc_html_e('Back to plugin', 'rrze-updater'); ?></a></p>
<p><?php printf(
    /* translators: 1: Installed plugin version, 2: Installed Git reference. */
    esc_html__('Installed version: %1$s · Git reference: %2$s', 'rrze-updater'),
    esc_html($data['installedVersion']),
    esc_html($extension->localVersion ?: '—')
); ?></p>
<p><?php esc_html_e('Choose a published release to install, including an older version or a pre-release. The next step confirms the exact version before replacing files.', 'rrze-updater'); ?></p>
<p><?php esc_html_e('After installation, this release will be held until you resume release updates. Automatic updates will not undo your selection.', 'rrze-updater'); ?></p>
<div style="overflow-x: auto;">
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Release', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Version / Tag', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Author', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Published', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Install', 'rrze-updater'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['items'] as $release) : ?>
                <?php $published = strtotime($release['published_at']); ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url($release['url']); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html($release['title'] ?: $release['tag']); ?></strong></a>
                        <?php if ($extension->localVersion === $release['tag']) : ?>
                            <p class="description"><?php esc_html_e('Installed release', 'rrze-updater'); ?></p>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($release['tag']); ?></code></td>
                    <td><a href="<?php echo esc_url($release['author_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($release['author'] ?: '—'); ?></a></td>
                    <td><?php echo $published ? esc_html(wp_date(get_option('date_format') . ' H:i', $published)) : '&mdash;'; ?></td>
                    <td><strong><?php echo $release['prerelease'] ? esc_html__('Pre-release', 'rrze-updater') : esc_html__('Stable release', 'rrze-updater'); ?></strong></td>
                    <td>
                        <form action="<?php echo esc_url($actionUrl); ?>" method="post">
                            <?php wp_nonce_field('rrze-updater-plugin-replacement-prepare-release', 'rrze-updater-nonce'); ?>
                            <input type="hidden" name="rrze-updater[replacement_action]" value="prepare-release">
                            <input type="hidden" name="rrze-updater[plugin_id]" value="<?php echo esc_attr($extension->id); ?>">
                            <input type="hidden" name="rrze-updater[release_id]" value="<?php echo esc_attr($release['id']); ?>">
                            <button type="submit" class="button" aria-label="<?php printf(
                                /* translators: %s: Release tag selected for review. */
                                esc_attr__('Select release %s', 'rrze-updater'), esc_attr($release['tag'])
                            ); ?>"><?php esc_html_e('Select', 'rrze-updater'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['items']) : ?>
                <tr><td colspan="6"><?php esc_html_e('No published releases found on this page.', 'rrze-updater'); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<nav aria-label="<?php echo esc_attr__('Release pages', 'rrze-updater'); ?>" class="tablenav">
    <?php if ($page > 1) : ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(['release_page' => $page - 1], $listUrl)); ?>"><?php esc_html_e('Previous page', 'rrze-updater'); ?></a>
    <?php endif; ?>
    <span><?php printf(
        /* translators: %d: Current release page number. */
        esc_html__('Page %d', 'rrze-updater'), $page
    ); ?></span>
    <?php if ($data['has_more']) : ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(['release_page' => $page + 1], $listUrl)); ?>"><?php esc_html_e('Next page', 'rrze-updater'); ?></a>
    <?php endif; ?>
</nav>

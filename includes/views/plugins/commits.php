<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$extension = $data['plugin'];
$page = $data['page'];
$listUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'commits', 'id' => $extension->id], self_admin_url('admin.php'));
$editUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'edit', 'id' => $extension->id], self_admin_url('admin.php'));
$actionUrl = add_query_arg(['page' => 'rrze-updater-plugins', 'action' => 'replace'], self_admin_url('admin.php'));
?>
<h2><?php esc_html_e('Choose commit', 'rrze-updater'); ?>: <?php echo esc_html($extension->repository); ?></h2>
<p><a href="<?php echo esc_url($editUrl); ?>">&larr; <?php esc_html_e('Back to plugin', 'rrze-updater'); ?></a></p>
<p><?php esc_html_e('Branch', 'rrze-updater'); ?>: <code><?php echo esc_html($extension->branch); ?></code></p>
<p><?php printf(
    /* translators: 1: Installed plugin version, 2: Installed Git reference. */
    esc_html__('Installed version: %1$s · Git reference: %2$s', 'rrze-updater'),
    esc_html($data['installedVersion']), esc_html($extension->localVersion ?: '—')
); ?></p>
<p><?php esc_html_e('Choose a commit from the history of the configured branch, including an older version. The next step confirms the exact commit before replacing files.', 'rrze-updater'); ?></p>
<p><?php esc_html_e('After installation, this commit will be held until you resume commit updates. Automatic updates will not undo your selection.', 'rrze-updater'); ?></p>
<div style="overflow-x: auto;">
    <table class="widefat striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Commit message', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Commit', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Author', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Committed', 'rrze-updater'); ?></th>
                <th scope="col"><?php esc_html_e('Install', 'rrze-updater'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['items'] as $commit) : ?>
                <tr>
                    <td style="overflow-wrap: anywhere;">
                        <a href="<?php echo esc_url($commit['url']); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html($commit['title']); ?></strong></a>
                        <?php if (strtolower((string) $extension->localVersion) === $commit['sha']) : ?>
                            <p class="description"><?php esc_html_e('Installed commit', 'rrze-updater'); ?></p>
                        <?php endif; ?>
                    </td>
                    <td><code title="<?php echo esc_attr($commit['sha']); ?>"><?php echo esc_html(substr($commit['sha'], 0, 12)); ?></code></td>
                    <td>
                        <?php if ($commit['author_url']) : ?>
                            <a href="<?php echo esc_url($commit['author_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($commit['author'] ?: '—'); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($commit['author'] ?: '—'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', strtotime($commit['committed_at']))); ?></td>
                    <td>
                        <form action="<?php echo esc_url($actionUrl); ?>" method="post">
                            <?php wp_nonce_field('rrze-updater-plugin-replacement-prepare-commit', 'rrze-updater-nonce'); ?>
                            <input type="hidden" name="rrze-updater[replacement_action]" value="prepare-commit">
                            <input type="hidden" name="rrze-updater[plugin_id]" value="<?php echo esc_attr($extension->id); ?>">
                            <input type="hidden" name="rrze-updater[commit_sha]" value="<?php echo esc_attr($commit['sha']); ?>">
                            <button type="submit" class="button" aria-label="<?php printf(
                                /* translators: %s: Abbreviated commit ID selected for review. */
                                esc_attr__('Select commit %s', 'rrze-updater'), esc_attr(substr($commit['sha'], 0, 12))
                            ); ?>"><?php esc_html_e('Select', 'rrze-updater'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$data['items']) : ?>
                <tr><td colspan="5"><?php esc_html_e('No commits found on this page.', 'rrze-updater'); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<nav aria-label="<?php echo esc_attr__('Commit pages', 'rrze-updater'); ?>" class="tablenav">
    <?php if ($page > 1) : ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(['commit_page' => $page - 1], $listUrl)); ?>"><?php esc_html_e('Previous page', 'rrze-updater'); ?></a>
    <?php endif; ?>
    <span><?php printf(
        /* translators: %d: Current commit page number. */
        esc_html__('Page %d', 'rrze-updater'), $page
    ); ?></span>
    <?php if ($data['has_more']) : ?>
        <a class="button" href="<?php echo esc_url(add_query_arg(['commit_page' => $page + 1], $listUrl)); ?>"><?php esc_html_e('Next page', 'rrze-updater'); ?></a>
    <?php endif; ?>
</nav>

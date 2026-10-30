<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

if (!$extension->connector instanceof Core\GithubConnector
    || !in_array($extension->updates, ['tags', 'releases'], true)
    || !$extension->hasUpdate() || !empty($incomplete)) {
    return;
}
$release = $extension->remoteRelease;
// Never display details cached for a different archive ref.
if (($release['tag'] ?? '') !== $extension->remoteVersion) {
    $release = [];
}
$published = strtotime((string) ($release['published_at'] ?? ''));
$allowedReleaseHtml = [
    'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'del' => [],
    'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'ul' => [], 'ol' => ['start' => true], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
    'a' => ['href' => true, 'title' => true], 'hr' => [], 'table' => [], 'thead' => [],
    'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
];
?>
<section class="card rrze-updater-release-preview" aria-labelledby="rrze-update-release-heading" style="max-width: 800px; margin-bottom: 24px;">
    <h2 id="rrze-update-release-heading"><?php esc_html_e('Release for this update', 'rrze-updater'); ?></h2>
    <p><?php esc_html_e('The update will install the source archive at this tag:', 'rrze-updater'); ?> <code><?php echo esc_html($extension->remoteVersion); ?></code></p>
    <?php if (!$release) : ?>
        <p><?php esc_html_e('No release details are available for this tag. Run an update check to refresh the information; tags without a published GitHub release have no release notes.', 'rrze-updater'); ?></p>
    <?php else : ?>
        <h3>
            <a href="<?php echo esc_url($release['url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(($release['title'] ?? '') ?: $release['tag']); ?></a>
        </h3>
        <p><strong><?php echo !empty($release['prerelease']) ? esc_html__('Pre-release', 'rrze-updater') : esc_html__('Stable release', 'rrze-updater'); ?></strong></p>
        <p>
            <?php esc_html_e('Author:', 'rrze-updater'); ?>
            <?php if (!empty($release['author'])) : ?>
                <a href="<?php echo esc_url($release['author_url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($release['author']); ?></a>
            <?php else : ?>
                &mdash;
            <?php endif; ?>
            &middot; <?php esc_html_e('Published:', 'rrze-updater'); ?>
            <?php if ($published) : ?>
                <time datetime="<?php echo esc_attr(gmdate('c', $published)); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $published)); ?></time>
            <?php else : ?>
                &mdash;
            <?php endif; ?>
        </p>
        <details open>
            <summary><?php esc_html_e('Release notes', 'rrze-updater'); ?></summary>
            <div tabindex="0" role="region" aria-label="<?php echo esc_attr__('Release notes', 'rrze-updater'); ?>" style="max-height: 24rem; overflow: auto; overflow-wrap: anywhere;">
                <?php if (!empty($release['body_html'])) : ?>
                    <?php echo wp_kses($release['body_html'], $allowedReleaseHtml); ?>
                <?php elseif (!empty($release['body'])) : ?>
                    <p style="white-space: pre-wrap;"><?php echo esc_html($release['body']); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('No description provided.', 'rrze-updater'); ?></p>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</section>

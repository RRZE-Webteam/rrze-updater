<?php
namespace RRZE\Updater;
defined('ABSPATH') || exit;
?>
<div class="wrap" id="rrze-bundle">
    <h1><?php esc_html_e('Recommended installation', 'rrze-updater'); ?></h1>
    <h2><?php echo esc_html($bundle['name']); ?></h2>
    <p><?php printf(esc_html__('Bundle version %1$d · %2$d repositories', 'rrze-updater'), $bundle['version'], count($bundle['items'])); ?></p>
    <p><?php esc_html_e('Check repository access and review the planned actions before installing. Existing files are kept. Plugins are not activated and themes are not network-enabled.', 'rrze-updater'); ?></p>
    <p><?php esc_html_e('The bundle uses commits from each repository’s configured branch. Preflight records the exact commit to install. Registering an existing installation leaves its Git ref unknown; a future updater operation may replace those files with the selected repository version.', 'rrze-updater'); ?></p>
    <p><?php esc_html_e('Keep this page open while processing. Closing it pauses further requests; reopen it to resume. A paused or interrupted request may still be finishing on the server.', 'rrze-updater'); ?></p>
    <p><a href="<?php echo esc_url($servicesUrl); ?>"><?php esc_html_e('Configure services and access tokens', 'rrze-updater'); ?></a></p>
    <fieldset class="rrze-bundle-connectors">
        <legend><strong><?php esc_html_e('Connectors for this process', 'rrze-updater'); ?></strong></legend>
        <p><?php esc_html_e('Choose one connector for each provider. Only connectors matching the bundle’s host and owner are listed. Changing the selection requires new prerequisite checks.', 'rrze-updater'); ?></p>
        <?php foreach ($bundle['connectors'] as $provider => $requirement) : ?>
            <?php $choices = $connectorChoices[$provider] ?? []; ?>
            <p>
                <label for="rrze-bundle-connector-<?php echo esc_attr($provider); ?>">
                    <?php echo esc_html($requirement['host'] . ' / ' . $requirement['owner']); ?>
                </label><br>
                <select id="rrze-bundle-connector-<?php echo esc_attr($provider); ?>" required disabled>
                    <option value=""><?php esc_html_e('Select a connector', 'rrze-updater'); ?></option>
                    <?php foreach ($choices as $choice) : ?>
                        <option value="<?php echo esc_attr($choice['id']); ?>" <?php selected(count($choices), 1); ?>>
                            <?php echo esc_html($choice['name'] . ' (' . $choice['id'] . ')' . ($choice['has_token'] ? '' : ' — ' . __('Token missing', 'rrze-updater'))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$choices) : ?>
                    <span><?php esc_html_e('No compatible connector configured. Add one in Services, then reload this page.', 'rrze-updater'); ?></span>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
    </fieldset>
    <noscript><p><?php esc_html_e('JavaScript is required for incremental bundle installation.', 'rrze-updater'); ?></p></noscript>
    <p>
        <button type="button" class="button" id="rrze-bundle-check" disabled><?php esc_html_e('Check prerequisites', 'rrze-updater'); ?></button>
        <button type="button" class="button button-primary" id="rrze-bundle-install" hidden><?php esc_html_e('Install bundle', 'rrze-updater'); ?></button>
        <button type="button" class="button" id="rrze-bundle-install-anyways" hidden><?php esc_html_e('Install anyways', 'rrze-updater'); ?></button>
        <button type="button" class="button button-primary" id="rrze-bundle-resume" hidden><?php esc_html_e('Resume', 'rrze-updater'); ?></button>
        <button type="button" class="button" id="rrze-bundle-retry" hidden><?php esc_html_e('Retry failed entries / checks', 'rrze-updater'); ?></button>
        <button type="button" class="button" id="rrze-bundle-pause" hidden><?php esc_html_e('Pause after current entry', 'rrze-updater'); ?></button>
    </p>
    <p id="rrze-bundle-status" role="status" aria-live="polite"></p>
    <p id="rrze-bundle-error" role="alert" hidden></p>
    <progress id="rrze-bundle-progress" value="0" max="<?php echo esc_attr(count($bundle['items'])); ?>" aria-label="<?php esc_attr_e('Bundle progress', 'rrze-updater'); ?>"></progress>
    <span id="rrze-bundle-count"></span>
    <table class="widefat striped">
        <thead><tr>
            <th scope="col"><?php esc_html_e('Repository', 'rrze-updater'); ?></th>
            <th scope="col"><?php esc_html_e('Provider / Type', 'rrze-updater'); ?></th>
            <th scope="col"><?php esc_html_e('Branch / Updates', 'rrze-updater'); ?></th>
            <th scope="col"><?php esc_html_e('Planned action / Ref', 'rrze-updater'); ?></th>
            <th scope="col"><?php esc_html_e('Status', 'rrze-updater'); ?></th>
            <th scope="col"><?php esc_html_e('Details', 'rrze-updater'); ?></th>
        </tr></thead>
        <tbody id="rrze-bundle-items"></tbody>
    </table>
</div>

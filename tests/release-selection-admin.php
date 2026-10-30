<?php

/** Record the release controller contract without downloading or installing files. */
class ReleaseAdminServiceFixture extends PluginRepairServiceFixture
{
    /**
     * Return one paginated release with all required table fields.
     *
     * @param string $pluginId Existing plugin association ID.
     * @param int $page Requested page.
     * @return array Release page.
     */
    public function listReleases(string $pluginId, int $page = 1): array|WP_Error
    {
        $this->calls[] = ['list', $pluginId, $page];
        return ['items' => [[
            'id' => '42', 'tag' => 'v0.9', 'title' => '<script>Untrusted title</script>',
            'url' => 'https://github.com/owner/repo/releases/tag/v0.9', 'author' => 'maintainer',
            'author_url' => 'https://github.com/maintainer', 'published_at' => '2025-01-01T12:00:00Z',
            'prerelease' => true,
        ]], 'page' => $page, 'has_more' => true];
    }

    /**
     * Record the two trusted selection identifiers.
     *
     * @param string $pluginId Saved plugin ID.
     * @param string $releaseId Repository-scoped release ID.
     * @return array Fixture review including a visibly marked prerelease.
     */
    public function prepareRelease(string $pluginId, string $releaseId): array|WP_Error
    {
        $this->calls[] = ['release', $pluginId, $releaseId];
        return array_merge($this->review, ['mode' => 'release', 'installed_ref' => 'v1', 'ref' => 'v0.9',
            'release' => ['id' => '42', 'title' => 'Earlier release', 'url' => 'https://github.com/owner/repo/releases/tag/v0.9', 'prerelease' => true]]);
    }

    /**
     * Record resume dispatch and stop before the subsequent automatic check.
     *
     * @param string $pluginId Saved plugin association ID.
     * @return WP_Error Controlled persistence failure.
     */
    public function resumeReleaseUpdates(string $pluginId): true|WP_Error
    {
        $this->calls[] = ['resume', $pluginId];
        return new WP_Error('fixture_save_failed', 'Hold remains unchanged.');
    }
}

/**
 * Create an authorized release form request with isolated source settings.
 *
 * @param string $operation Replacement action name.
 * @return array Settings, controller and recording release service.
 */
function releaseAdminFixture(string $operation = 'prepare-release'): array
{
    [$settings] = pluginRepairAdminFixture($operation);
    $settings->plugins[0]->updates = 'releases';
    $settings->save();
    $service = new ReleaseAdminServiceFixture();
    return [$settings, new AdminPageFixture($settings, null, $service), $service];
}

$beforeReleaseAdmin = $checks;
$savedReleaseAdmin = [$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $multisite, $bundle_caps, $bundle_mods, $token_super_admin];
[$settings, $controller, $service] = releaseAdminFixture();
$_GET = ['action' => 'releases', 'id' => 'admin-plugin', 'release_page' => '2'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$controller->plugins->getPluginIndex();
check($service->calls === [['list', 'admin-plugin', 2]] && $controller->renderer->shown['view'] === 'plugins/releases',
    'The chooser loads the requested page from the saved association.');
$data = $controller->renderer->shown['data'];
$html = adminOutput(static function () use ($data): void { require dirname(__DIR__) . '/includes/views/plugins/releases.php'; });
check(str_contains($html, '&lt;script&gt;Untrusted title&lt;/script&gt;') && !str_contains($html, '<script>')
    && str_contains($html, 'maintainer') && str_contains($html, 'v0.9') && str_contains($html, 'Pre-release')
    && str_contains($html, 'https://github.com/owner/repo/releases/tag/v0.9'), 'The release table escapes provider metadata and displays title, tag, link, author and prerelease status.');
check(str_contains($html, 'release_page=1') && str_contains($html, 'release_page=3')
    && str_contains($html, 'rrze-updater-plugin-replacement-prepare-release') && str_contains($html, 'rrze-updater[release_id]'),
    'Pagination and per-release POST selection preserve their scoped identifiers and nonce.');

[$settings, $controller, $service] = releaseAdminFixture();
$_POST['rrze-updater'] += ['release_id' => '42', 'commit' => 'forged', 'repository' => 'forged', 'url' => 'https://other.example/package.zip'];
$controller->plugins->getPluginIndex();
check($service->calls === [['release', 'admin-plugin', '42']], 'Posted refs, URLs and repository overrides cannot change the chosen source.');
$data = $controller->renderer->shown['data'];
$html = adminOutput(static function () use ($data): void { require dirname(__DIR__) . '/includes/views/plugins/replace.php'; });
check(str_contains($html, 'You selected a pre-release') && str_contains($html, 'Database changes')
    && str_contains($html, 'Automatic updates will not undo') && str_contains($html, 'Earlier release')
    && str_contains($html, str_repeat('a', 40)), 'Review explains release hold and database limits alongside the exact selected release and commit.');

foreach (['prepare-release', 'resume-releases'] as $operation) {
    foreach (['nonce', 'method', 'permission', 'array-id'] as $denial) {
        [$settings, $controller, $service] = releaseAdminFixture($operation);
        $_POST['rrze-updater']['release_id'] = '42';
        if ($denial === 'nonce') $_POST['rrze-updater-nonce'] = 'wrong';
        if ($denial === 'method') $_SERVER['REQUEST_METHOD'] = 'GET';
        if ($denial === 'permission') $bundle_caps['install_plugins'] = false;
        if ($denial === 'array-id') $_POST['rrze-updater']['plugin_id'] = ['admin-plugin'];
        try { $controller->plugins->getPluginIndex(); } catch (RuntimeException) {}
        check($service->calls === [], 'Reject unauthorized or malformed ' . $operation . ' before reaching the service.');
    }
}
[$settings, $controller, $service] = releaseAdminFixture('resume-releases');
$controller->plugins->getPluginIndex();
check($service->calls === [['resume', 'admin-plugin']] && count(array_filter($controller->notices(), 'is_wp_error')) === 1,
    'A failed resume is displayed without claiming success.');
[$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $multisite, $bundle_caps, $bundle_mods, $token_super_admin] = $savedReleaseAdmin;
echo 'Passed ' . ($checks - $beforeReleaseAdmin) . " release chooser and authorization checks.\n";

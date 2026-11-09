<?php

/**
 * Render a fixture select attribute using WordPress-compatible value comparison.
 *
 * @param mixed $selected Current value.
 * @param mixed $current Option value.
 * @param bool $display Whether to echo the attribute.
 * @return string Selected attribute or an empty string.
 */
function selected($selected, $current = true, $display = true): string
{
    $attribute = (string) $selected === (string) $current ? ' selected="selected"' : '';
    if ($display) echo $attribute;
    return $attribute;
}

/**
 * Render the checkbox attribute needed by the real source editor template.
 *
 * @param mixed $checked Current value.
 * @param mixed $current Expected checked value.
 * @param bool $display Whether to echo the attribute.
 * @return string Checked attribute or an empty string.
 */
function checked($checked, $current = true, $display = true): string
{
    $attribute = (string) $checked === (string) $current ? ' checked="checked"' : '';
    if ($display) echo $attribute;
    return $attribute;
}

/** Record commit chooser controller operations without touching installed files. */
class CommitAdminServiceFixture extends PluginRepairServiceFixture
{
    /**
     * Return commit history containing untrusted provider text and an unlinked author.
     *
     * @param string $pluginId Existing association ID.
     * @param int $page Requested page number.
     * @return array Commit page for the actual chooser template.
     */
    public function listCommits(string $pluginId, int $page = 1): array|WP_Error
    {
        $this->calls[] = ['list-commits', $pluginId, $page];
        return ['items' => [[
            'sha' => str_repeat('a', 40), 'title' => '<script>Commit title</script>', 'message' => "Commit title\n\nDescription",
            'url' => 'https://github.com/owner/repo/commit/' . str_repeat('a', 40), 'author' => '<b>Test Author</b>',
            'author_url' => '', 'committed_at' => '2025-01-01T15:39:00Z',
        ]], 'page' => $page, 'has_more' => true];
    }

    /**
     * Record the only accepted selection identifiers and return reviewed metadata.
     *
     * @param string $pluginId Saved association ID.
     * @param string $sha Full selected commit ID.
     * @return array Review rendered by the shared confirmation template.
     */
    public function prepareCommit(string $pluginId, string $sha): array|WP_Error
    {
        $this->calls[] = ['commit', $pluginId, $sha];
        return array_merge($this->review, ['mode' => 'commit', 'installed_ref' => str_repeat('b', 40), 'ref' => $sha,
            'selected_commit' => ['title' => '<script>Earlier commit</script>', 'message' => "<script>Earlier commit</script>\n\nDetails",
                'url' => 'https://github.com/owner/repo/commit/' . $sha, 'author' => 'Test Author', 'committed_at' => '2025-01-01T15:39:00Z']]);
    }

    /**
     * Simulate persistence failure without triggering the subsequent branch check.
     *
     * @param string $pluginId Saved association ID.
     * @return WP_Error Controlled failure preserving the hold.
     */
    public function resumeCommitUpdates(string $pluginId): true|WP_Error
    {
        $this->calls[] = ['resume-commits', $pluginId];
        return new WP_Error('fixture_save_failed', 'Commit remains held.');
    }
}

/**
 * Set up an authorized controller request for a plugin tracking commits.
 *
 * @param string $operation Requested form operation.
 * @return array Settings, controller and recording service.
 */
function commitAdminFixture(string $operation = 'prepare-commit'): array
{
    [$settings] = pluginRepairAdminFixture($operation);
    $settings->plugins[0]->updates = 'commits';
    $settings->save();
    $service = new CommitAdminServiceFixture();
    return [$settings, new AdminPageFixture($settings, null, $service), $service];
}

$beforeCommitAdmin = $checks;
$savedCommitAdmin = [$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $multisite, $bundle_caps, $bundle_mods, $token_super_admin];
[$settings, $controller, $service] = commitAdminFixture();
$_GET = ['action' => 'commits', 'id' => 'admin-plugin', 'commit_page' => '2'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$controller->plugins->getPluginIndex();
check($service->calls === [['list-commits', 'admin-plugin', 2]] && $controller->renderer->shown['view'] === 'plugins/commits',
    'Commit history uses its dedicated route and requested page.');
$data = $controller->renderer->shown['data'];
$html = adminOutput(static function () use ($data): void { require dirname(__DIR__) . '/includes/views/plugins/commits.php'; });
check(str_contains($html, '&lt;script&gt;Commit title&lt;/script&gt;') && str_contains($html, '&lt;b&gt;Test Author&lt;/b&gt;')
    && !str_contains($html, '<script>') && str_contains($html, 'https://github.com/owner/repo/commit/') && str_contains($html, 'aaaaaaaaaaaa'),
    'The table escapes provider text and shows commit, title, author and link.');
check(str_contains($html, 'commit_page=1') && str_contains($html, 'commit_page=3')
    && str_contains($html, 'rrze-updater-plugin-replacement-prepare-commit') && str_contains($html, 'rrze-updater[commit_sha]'),
    'Pagination and POST forms preserve commit-specific identifiers and nonces.');
[$settings, $controller, $service] = commitAdminFixture();
$_POST['rrze-updater'] += ['commit_sha' => str_repeat('a', 40), 'branch' => 'forged', 'repository' => 'forged', 'url' => 'https://other.example/evil.zip'];
$controller->plugins->getPluginIndex();
check($service->calls === [['commit', 'admin-plugin', str_repeat('a', 40)]], 'Posted branch, repository and archive URLs cannot override the stored source.');
$data = $controller->renderer->shown['data'];
$html = adminOutput(static function () use ($data): void { require dirname(__DIR__) . '/includes/views/plugins/replace.php'; });
check(str_contains($html, 'Confirm commit installation') && str_contains($html, 'Database changes')
    && str_contains($html, 'Automatic updates will not undo') && !str_contains($html, '<script>')
    && str_contains($html, 'Details') && str_contains($html, 'Test Author') && str_contains($html, '15:39'),
    'Confirmation explains the hold, escapes the full commit message and uses a 24-hour time.');
$data = ['plugin' => $settings->plugins[0], 'lastChecked' => '', 'canReplace' => true, 'installedVersion' => '1.0', 'connectors' => $settings->connectors];
$data['plugin']->pinnedCommit = ['commit' => str_repeat('a', 40), 'version' => '1.0'];
$data['plugin']->connector = RRZE\Updater\Core\GithubConnector::createFromArray(['id' => 'admin-connector', 'owner' => 'owner']);
$html = adminOutput(static function () use ($data): void { require dirname(__DIR__) . '/includes/views/plugins/edit.php'; });
check(str_contains($html, 'Choose commit') && str_contains($html, 'Held at commit aaaaaaaaaaaa')
    && str_contains($html, 'rrze-updater-plugin-replacement-resume-commits') && !str_contains($html, 'Held at release'),
    'The editor exposes commit selection and a commit-specific hold with an authorized resume form.');
foreach (['prepare-commit', 'resume-commits'] as $operation) {
    foreach (['nonce', 'method', 'permission', 'array-sha', 'mods'] as $denial) {
        [$settings, $controller, $service] = commitAdminFixture($operation);
        $_POST['rrze-updater']['commit_sha'] = str_repeat('a', 40);
        if ($denial === 'nonce') $_POST['rrze-updater-nonce'] = 'wrong';
        if ($denial === 'method') $_SERVER['REQUEST_METHOD'] = 'GET';
        if ($denial === 'permission') $bundle_caps['install_plugins'] = false;
        if ($denial === 'array-sha') $_POST['rrze-updater']['commit_sha'] = ['a'];
        if ($denial === 'mods') $bundle_mods = false;
        try { $controller->plugins->getPluginIndex(); } catch (RuntimeException) {}
        check($service->calls === [], 'Reject invalid commit operation before reaching its service: ' . $operation . '/' . $denial);
    }
}
[$settings, $controller, $service] = commitAdminFixture('resume-commits');
$controller->plugins->getPluginIndex();
check($service->calls === [['resume-commits', 'admin-plugin']] && count(array_filter($controller->notices(), 'is_wp_error')) === 1,
    'Failed commit resume never claims success.');
[$_GET, $_POST, $_SERVER['REQUEST_METHOD'], $multisite, $bundle_caps, $bundle_mods, $token_super_admin] = $savedCommitAdmin;
echo 'Passed ' . ($checks - $beforeCommitAdmin) . " commit chooser and authorization checks.\n";

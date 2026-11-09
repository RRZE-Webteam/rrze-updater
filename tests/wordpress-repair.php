<?php
/**
 * Optional real-WordPress integration suite, invoked with wp eval-file and the repository root argument.
 *
 * Requires an explicitly marked disposable installation, real MySQL, ext-zip and PHP >= 8.3.
 * All provider HTTP is intercepted; plugin files, transients, settings and filesystem operations are real.
 */
namespace {
    if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI
        || !defined('RRZE_UPDATER_TEST_INSTALLATION') || RRZE_UPDATER_TEST_INSTALLATION !== true) {
        throw new \RuntimeException('Run only through WP-CLI on a disposable WordPress marked RRZE_UPDATER_TEST_INSTALLATION=true.');
    }
    $repairSource = realpath($args[0] ?? dirname(__DIR__));
    if (!$repairSource || !is_file($repairSource . '/includes/Core/PluginReplacement.php')) {
        throw new \RuntimeException('Pass the rrze-updater repository root as the first wp eval-file argument.');
    }
    /** Load production classes without starting the plugin lifecycle. */
    spl_autoload_register(static function (string $class) use ($repairSource): void {
        if (str_starts_with($class, 'RRZE\\Updater\\')) {
            require_once $repairSource . '/includes/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        }
    });
}

namespace RRZE\Updater {
    if (!function_exists(__NAMESPACE__ . '\\plugin')) {
        /** Supply only static user-agent metadata without registering lifecycle hooks. */
        function plugin(): object
        {
            return new class {
                /** Return a test-only version used in repository HTTP user agents. */
                public function getVersion(): string { return 'integration-fixture'; }
                /** Return the fixture's conventional plugin basename. */
                public function getBaseName(): string { return 'rrze-updater/rrze-updater.php'; }
            };
        }
    }
}

namespace {
    use RRZE\Updater\{Config, Settings};
    use RRZE\Updater\Core\{GithubConnector, GitlabConnector, Plugin, PluginReplacement, RepositoryManager};
    use RRZE\Updater\Upgrader\PluginPackageReplacement;

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $repairUsers = get_users(['role' => 'administrator', 'number' => 1]);
    if (!$repairUsers) throw new \RuntimeException('The disposable site needs an administrator.');
    wp_set_current_user($repairUsers[0]->ID);
    if (is_multisite() && !is_super_admin()) {
        throw new \RuntimeException('The disposable multisite administrator must already be a superadministrator.');
    }
    if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('The integration suite requires ext-zip.');

    /** Assert one real-core integration invariant and increment its counter. */
    function rrzeRepairAssert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
        $GLOBALS['rrzeRepairChecks']++;
    }

    /** Require success and include a safe operation error in failures. */
    function rrzeRepairSuccess(mixed $result, string $message): mixed
    {
        rrzeRepairAssert(!is_wp_error($result) && $result !== false,
            $message . (is_wp_error($result) ? ': ' . $result->get_error_code() . ' — ' . $result->get_error_message() : ''));
        return $result;
    }

    /** Remove only test-owned trees, never following any symlinks. */
    function rrzeRepairRemove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_link($path) || !is_dir($path)) { unlink($path); return; }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') rrzeRepairRemove($path . '/' . $name);
        }
        rmdir($path);
    }

    /** Return a valid fixture plugin header without side effects or activation hooks. */
    function rrzeRepairContents(string $provider): string
    {
        return "<?php\n/*\nPlugin Name: RRZE Repair Integration Fixture\nVersion: 1.0\nRequires at least: 6.8\nRequires PHP: 8.3\n*/\n// Package provider: " . $provider . "\n";
    }

    /** Create a realistic provider ZIP with one generated wrapper directory. */
    function rrzeRepairArchive(string $provider, string $directory): string
    {
        $path = $directory . '/' . $provider . '.zip';
        $zip = new \ZipArchive();
        rrzeRepairAssert($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true, 'Create provider ZIP.');
        $zip->addFromString('provider-wrapper/repair-demo.php', rrzeRepairContents($provider));
        $zip->addFromString('provider-wrapper/readme.txt', "=== Repair Fixture ===\nStable tag: 1.0\n");
        $zip->addFromString('provider-wrapper/new-file.txt', 'replacement package');
        $zip->close();
        return $path;
    }

    /** Build the standard WordPress HTTP response shape for fixture API responses. */
    function rrzeRepairHttpResponse(mixed $body, bool $json = true, int $code = 200): array
    {
        return ['headers' => [], 'body' => $json ? wp_json_encode($body) : $body,
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Not Found'],
            'cookies' => [], 'filename' => null];
    }

    /** Reset only the disposable fixture registry, plugin directory and activation selection. */
    function rrzeRepairFixture(string $provider, bool $managed, bool $recognized, string $activation): array
    {
        $folder = WP_PLUGIN_DIR . '/repair-demo';
        rrzeRepairRemove($folder);
        mkdir($folder, 0755);
        file_put_contents($folder . '/obsolete.txt', 'old custom file');
        if ($recognized) file_put_contents($folder . '/repair-demo.php', rrzeRepairContents('old-local'));
        wp_clean_plugins_cache(false);
        $settings = new Settings();
        $github = GithubConnector::createFromArray(['id' => 'repair-github', 'owner' => 'repair-fixture', 'token' => 'fixture-github-token']);
        $gitlab = GitlabConnector::createFromArray(['id' => 'repair-gitlab', 'owner' => 'repair-fixture', 'token' => 'fixture-gitlab-token',
            'host' => 'gitlab.fixture.invalid', 'apiUri' => '/api/v4/projects/']);
        $settings->connectors = [$github, $gitlab];
        $settings->plugins = $settings->themes = [];
        $connector = $provider === 'github' ? $github : $gitlab;
        $options = ['connector' => $connector->id, 'folder' => 'repair-demo', 'branch' => 'main', 'updates' => 'tags'];
        if ($managed) {
            $plugin = Plugin::createFromArray(['id' => 'repair-managed', 'connectorId' => $connector->id, 'repository' => 'repair-demo',
                'installationFolder' => 'repair-demo', 'branch' => 'main', 'updates' => 'tags', 'localVersion' => 'v1', 'remoteVersion' => 'v1']);
            $plugin->connector = $connector;
            $settings->plugins = [$plugin];
        }
        rrzeRepairAssert($settings->save(), 'Persist real fixture settings.');
        update_option('active_plugins', $activation === 'local' ? ['repair-demo/repair-demo.php'] : []);
        if (is_multisite()) update_site_option('active_sitewide_plugins', $activation === 'network' ? ['repair-demo/repair-demo.php' => 12345] : []);
        update_option('rrze_repair_demo_data', ['important' => 'preserve-me', 'counter' => 17]);
        update_site_option('auto_update_plugins', ['repair-demo/repair-demo.php']);
        return [$settings, $options, new PluginReplacement($settings)];
    }

    $GLOBALS['rrzeRepairChecks'] = 0;
    $repairOption = (new Config())->getOptionName();
    $repairOriginal = [
        'registry' => is_multisite() ? get_site_option($repairOption, null) : get_option($repairOption, null),
        'active' => get_option('active_plugins', []), 'network_active' => get_site_option('active_sitewide_plugins', []),
        'auto' => get_site_option('auto_update_plugins', []), 'data' => get_option('rrze_repair_demo_data', null),
    ];
    $repairTemporary = WP_CONTENT_DIR . '/repair-http-fixtures-' . bin2hex(random_bytes(8));
    mkdir($repairTemporary, 0700);
    $repairArchives = ['github' => rrzeRepairArchive('github', $repairTemporary), 'gitlab' => rrzeRepairArchive('gitlab', $repairTemporary)];
    $repairRequests = [];
    $repairDownloads = [];
    /** Intercept all fixture API metadata and archive streams without contacting external hosts. */
    $repairHttp = static function (mixed $preempt, array $httpArgs, string $url) use (&$repairRequests, &$repairDownloads, $repairArchives): mixed {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $provider = $host === 'api.github.com' ? 'github' : ($host === 'gitlab.fixture.invalid' ? 'gitlab' : '');
        if ($provider === '') return new \WP_Error('repair_http_blocked', 'No external HTTP is allowed in the integration suite.');
        $path = rawurldecode(wp_parse_url($url, PHP_URL_PATH));
        $repairRequests[] = [$provider, $path];
        $sha = str_repeat($provider === 'github' ? 'a' : 'b', 40);
        if (!empty($httpArgs['stream']) && !empty($httpArgs['filename'])) {
            rrzeRepairAssert(str_contains($url, $sha), 'Archive stream uses the immutable reviewed commit.');
            copy($repairArchives[$provider], $httpArgs['filename']);
            clearstatcache(true, $httpArgs['filename']);
            $repairDownloads[] = $provider;
            return rrzeRepairHttpResponse('downloaded', false);
        }
        if (str_ends_with($path, '/branches/main')) return rrzeRepairHttpResponse(['name' => 'main', 'commit' => ['sha' => $sha, 'id' => $sha]]);
        if (str_ends_with($path, '/branches')) return rrzeRepairHttpResponse([['name' => 'main']]);
        if (str_ends_with($path, '/tags')) return rrzeRepairHttpResponse([['name' => 'v1']]);
        if (str_ends_with($path, '/commits')) return rrzeRepairHttpResponse([['sha' => $sha, 'id' => $sha]]);
        if (str_contains($path, '/contents/') || str_contains($path, '/repository/files/')) {
            $filename = $provider === 'github' ? substr($path, strpos($path, '/contents/') + 10)
                : substr($path, strpos($path, '/repository/files/') + 18, -4);
            $content = match ($filename) {
                'repair-demo.php' => rrzeRepairContents($provider),
                'readme.txt', 'README.md' => "=== Repair Fixture ===\nStable tag: 1.0\n",
                'package.json' => '{"version":"1.0"}',
                default => null,
            };
            if ($content === null) return rrzeRepairHttpResponse(['message' => 'Not Found'], true, 404);
            return $provider === 'github' ? rrzeRepairHttpResponse(['encoding' => 'base64', 'content' => base64_encode($content)])
                : rrzeRepairHttpResponse($content, false);
        }
        return new \WP_Error('repair_unexpected_api', 'Unexpected fixture API path: ' . $path);
    };
    add_filter('pre_http_request', $repairHttp, 10, 3);
    $repairFailSave = null;
    try {
        $activations = is_multisite() ? ['inactive', 'local', 'network'] : ['inactive', 'local'];
        foreach (['github', 'gitlab'] as $provider) {
            foreach ($activations as $activation) {
                [$settings, $options, $service] = rrzeRepairFixture($provider, true, true, $activation);
                $active = get_option('active_plugins'); $networkActive = get_site_option('active_sitewide_plugins');
                $before = hash_file('sha256', WP_PLUGIN_DIR . '/repair-demo/repair-demo.php');
                $review = rrzeRepairSuccess($service->prepare('repair-demo', $options, 'repair-managed'), 'Prepare actual WordPress replacement.');
                rrzeRepairAssert(hash_file('sha256', WP_PLUGIN_DIR . '/repair-demo/repair-demo.php') === $before, 'Review preserves existing PHP files.');
                rrzeRepairAssert(!str_contains(wp_json_encode(get_site_transient('rrze_updater_replace_' . $review['id'])), 'fixture-' . $provider . '-token'), 'Actual review transient contains no token.');
                rrzeRepairAssert($service->getReview($review['id']) === $review, 'Actual transient review can be reopened.');
                $id = rrzeRepairSuccess($service->apply($review['id']), 'Apply actual WordPress replacement.');
                rrzeRepairAssert($id === 'repair-managed', 'Managed identity remains stable.');
                rrzeRepairAssert(!file_exists(WP_PLUGIN_DIR . '/repair-demo/obsolete.txt') && is_file(WP_PLUGIN_DIR . '/repair-demo/new-file.txt'), 'WordPress filesystem replaces the complete directory.');
                rrzeRepairAssert(file_get_contents(WP_PLUGIN_DIR . '/repair-demo/repair-demo.php') === rrzeRepairContents($provider), 'Real connector downloads the selected provider package.');
                rrzeRepairAssert(isset(get_plugins()['repair-demo/repair-demo.php']), 'WordPress discovers the replaced plugin after cache clearing.');
                rrzeRepairAssert(get_option('active_plugins') === $active && get_site_option('active_sitewide_plugins') === $networkActive, 'Local and network activation values remain byte-for-byte unchanged.');
                rrzeRepairAssert(is_plugin_active('repair-demo/repair-demo.php') === ($activation !== 'inactive'), 'WordPress reports the expected active/inactive plugin state.');
                rrzeRepairAssert(get_option('rrze_repair_demo_data') === ['important' => 'preserve-me', 'counter' => 17], 'Plugin data remains in the real database.');
                rrzeRepairAssert(get_site_option('auto_update_plugins') === ['repair-demo/repair-demo.php'], 'Automatic-update preferences remain intact.');
                rrzeRepairAssert((new Settings())->getPluginById($id)->localVersion === 'v1', 'Installed policy ref is persisted in MySQL.');
                rrzeRepairAssert(!file_exists(ABSPATH . '.maintenance'), 'Owned maintenance file is removed.');
                rrzeRepairAssert(is_wp_error($service->apply($review['id'])), 'Consumed real transient cannot be replayed.');
            }
            foreach ([false, true] as $recognized) {
                [$settings, $options, $service] = rrzeRepairFixture($provider, false, $recognized, 'inactive');
                $review = rrzeRepairSuccess($service->prepare('repair-demo', $options), 'Prepare unmanaged existing directory.');
                $id = rrzeRepairSuccess($service->apply($review['id']), 'Replace unmanaged existing directory.');
                rrzeRepairAssert((new Settings())->getPluginById($id) instanceof Plugin && isset(get_plugins()['repair-demo/repair-demo.php']), 'Incomplete/recognized unmanaged folder becomes a persisted recognized plugin.');
            }
        }
        foreach (['github' => 'gitlab', 'gitlab' => 'github'] as $from => $to) {
            [$settings, $options, $service] = rrzeRepairFixture($from, true, true, 'local');
            $before = hash_file('sha256', WP_PLUGIN_DIR . '/repair-demo/repair-demo.php');
            $target = array_merge($options, ['connector' => 'repair-' . $to]);
            rrzeRepairSuccess((new RepositoryManager($settings))->changePluginSource('repair-managed', 'repair-demo', $target), 'Switch provider with matching tag.');
            $current = (new Settings())->getPluginById('repair-managed');
            rrzeRepairAssert($current->connectorId === 'repair-' . $to && $current->localVersion === '' && $current->remoteVersion === 'v1', 'Matching tag from another provider requires an update.');
            rrzeRepairAssert(hash_file('sha256', WP_PLUGIN_DIR . '/repair-demo/repair-demo.php') === $before, 'Source edit alone leaves real plugin files untouched.');
            rrzeRepairAssert(get_site_option('auto_update_plugins') === ['repair-demo/repair-demo.php'], 'Source edit retains automatic updates.');
            $review = rrzeRepairSuccess($service->prepare('repair-demo', $target, 'repair-managed'), 'Review switched source.');
            rrzeRepairSuccess($service->apply($review['id']), 'Install switched source after explicit confirmation.');
            rrzeRepairAssert(file_get_contents(WP_PLUGIN_DIR . '/repair-demo/repair-demo.php') === rrzeRepairContents($to), 'Provider switch preserves basename and installs new provider files.');
        }
        [$settings, $options, $service] = rrzeRepairFixture('github', true, true, 'local');
        $before = (new PluginPackageReplacement())->snapshot('repair-demo');
        $review = rrzeRepairSuccess($service->prepare('repair-demo', $options, 'repair-managed'), 'Prepare real persistence failure.');
        /** Preserve the old stored value so the real Settings writer reports a failed update. */
        $repairFailSave = static function (mixed $value, mixed $oldValue): mixed { return $oldValue; };
        $saveHook = (is_multisite() ? 'pre_update_site_option_' : 'pre_update_option_') . $repairOption;
        add_filter($saveHook, $repairFailSave, 10, 2);
        $failed = $service->apply($review['id']);
        remove_filter($saveHook, $repairFailSave, 10);
        $repairFailSave = null;
        rrzeRepairAssert(is_wp_error($failed) && $failed->get_error_code() === 'rrze_updater_save_failed', 'Actual settings write failure is reported.');
        rrzeRepairAssert((new PluginPackageReplacement())->snapshot('repair-demo') === $before, 'Actual filesystem rollback restores all old files and directory identity.');
        rrzeRepairAssert(!file_exists(ABSPATH . '.maintenance') && is_plugin_active('repair-demo/repair-demo.php'), 'Rollback clears maintenance and preserves activation.');
        rrzeRepairAssert(count(array_unique($repairDownloads)) === 2, 'Both real provider archive pipelines were exercised.');
        \WP_CLI::success(sprintf('%d real WordPress repair/source/activation assertions passed (%s; %d fixture HTTP requests).',
            $GLOBALS['rrzeRepairChecks'], is_multisite() ? 'multisite' : 'single-site', count($repairRequests)));
    } finally {
        if ($repairFailSave !== null) remove_filter($saveHook, $repairFailSave, 10);
        remove_filter('pre_http_request', $repairHttp, 10);
        rrzeRepairRemove(WP_PLUGIN_DIR . '/repair-demo');
        rrzeRepairRemove($repairTemporary);
        if ($repairOriginal['registry'] === null) {
            is_multisite() ? delete_site_option($repairOption) : delete_option($repairOption);
        } elseif (is_multisite()) update_site_option($repairOption, $repairOriginal['registry']);
        else update_option($repairOption, $repairOriginal['registry']);
        update_option('active_plugins', $repairOriginal['active']);
        if (is_multisite()) update_site_option('active_sitewide_plugins', $repairOriginal['network_active']);
        update_site_option('auto_update_plugins', $repairOriginal['auto']);
        if ($repairOriginal['data'] === null) delete_option('rrze_repair_demo_data');
        else update_option('rrze_repair_demo_data', $repairOriginal['data']);
        wp_clean_plugins_cache(false);
    }
}

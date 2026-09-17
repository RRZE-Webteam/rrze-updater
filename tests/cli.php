<?php
// phpcs:ignoreFile -- Standalone regression suite with explicit environment fixtures.

namespace WP_CLI\Utils {
    function format_items($format, $items, $fields) {
        $GLOBALS['cli_output'] = compact('format', 'items', 'fields');
    }
}

namespace {
    if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
        exit(1);
    }
    define('ABSPATH', dirname(__DIR__, 4) . '/');
    define('HOUR_IN_SECONDS', 3600);
    require ABSPATH . 'wp-includes/plugin.php';
    require ABSPATH . 'wp-includes/class-wp-error.php';
    require ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    spl_autoload_register(function ($class) {
        $prefix = 'RRZE\\Updater\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    function __($text, $domain = '') { return $text; }
    function sanitize_text_field($text) { return trim((string) $text); }
    function wp_parse_args($args, $defaults = []) { return array_merge($defaults, $args); }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function is_multisite() { return $GLOBALS['multisite']; }
    function get_site_option($key) { return $GLOBALS['storage'][$key] ?? false; }
    function get_option($key) { return $GLOBALS['storage'][$key] ?? false; }
    function update_site_option($key, $value) {
        if ($GLOBALS['fail_save']) { return false; }
        $GLOBALS['storage'][$key] = $value;
        $GLOBALS['saves']++;
        return true;
    }
    function update_option($key, $value) { return update_site_option($key, $value); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function delete_site_transient($key) { $GLOBALS['invalidated'][] = $key; }
    function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
    function untrailingslashit($path) { return rtrim($path, '/\\'); }
    function wp_delete_file($path) { unlink($path); }

    class WP_CLI {
        public static array $commands = [];
        public static array $messages = [];
        public static array $warnings = [];
        public static function add_command($name, $callback, $options) { self::$commands[$name] = [$callback, $options]; }
        public static function warning($message) { self::$warnings[] = $message; }
        public static function success($message) { self::$messages[] = $message; }
        public static function error($message) { throw new RuntimeException($message); }
    }

    use RRZE\Updater\Core\{Connector, Extension, GithubConnector, GitlabConnector, RepositoryManager};
    use RRZE\Updater\Upgrader\RepositoryInstaller;
    use RRZE\Updater\{Settings, CLI};

    class ConnectorFixture extends Connector {
        public string|false $tag = 'v2.0.0';
        public string|false $release = 'v1.0.0';
        public bool $valid = true;
        public array $calls = [];
        public function getType(): string { return 'github'; }
        public function asArray(): array {
            return ['type' => 'github', 'id' => $this->id, 'owner' => $this->owner, 'token' => $this->token];
        }
        public function getUrl(string $repository): string { return 'https://github.com/RRZE-Webteam/' . $repository; }
        public function getRemoteCommit(string $repository, string $branch): string {
            $this->calls[] = ['commits', $branch]; return 'abc123';
        }
        public function remoteBranchExists(string $repository, string $branch): bool { return $branch === 'dev'; }
        public function getRemoteBranches(string $repository): array|false { return ['dev']; }
        public function getRemoteTag(string $repository): string|false { $this->calls[] = ['tags']; return $this->tag; }
        public function getRemoteRelease(string $repository): string|false { $this->calls[] = ['releases']; return $this->release; }
        public function getRemoteFile(string $repository, string $ref, string $file): string|bool {
            if (!$this->valid) { return false; }
            return match ($file) {
                "$repository.php" => "<?php\n/*\nPlugin Name: Fixture\nVersion: 1.0.0\n*/",
                'style.css' => "Theme Name: Fixture\nVersion: 1.0.0",
                'index.php' => '<?php',
                'readme.txt' => "=== Fixture ===\nStable tag: 1.0.0",
                default => false,
            };
        }
        public function downloadRepoZip(string $repository, string $branch): string { return 'fixture.zip'; }
    }
    class InstallerFixture extends RepositoryInstaller {
        public array $installed = [];
        public int $installs = 0;
        public bool $fail = false;
        public function isInstalled(string $type, string $folder): bool { return isset($this->installed["$type/$folder"]); }
        public function destinationExists(string $type, string $folder): bool { return $this->isInstalled($type, $folder); }
        public function install(string $type, Extension $extension): true|WP_Error {
            $this->installs++;
            if ($this->fail) { return new WP_Error('fixture_failure', 'Installation failed.'); }
            $this->installed["$type/{$extension->installationFolder}"] = true;
            return true;
        }
    }
    function check($condition, $message) {
        if (!$condition) { throw new RuntimeException($message); }
        $GLOBALS['checks']++;
    }
    function errorCode($result, $suffix) {
        check(is_wp_error($result) && $result->get_error_code() === 'rrze_updater_' . $suffix, $suffix);
    }
    $checks = $saves = 0;
    $multisite = true;
    $fail_save = false;
    $storage = $invalidated = [];
    $settings = new Settings();
    $connector = new ConnectorFixture();
    $connector->id = 'fixture-id';
    $connector->owner = 'RRZE-Webteam';
    $connector->token = 'never-print-this-token';
    $settings->connectors = [$connector];
    $installer = new InstallerFixture();
    $manager = new RepositoryManager($settings, $installer);
    $options = ['connector' => 'fixture-id'];

    errorCode($manager->register('plugin', 'missing', $options), 'not_installed');
    check($saves === 0, 'Missing plugins must not be persisted.');
    $installer->installed['plugin/existing'] = true;
    check(is_string($manager->register('plugin', 'existing', $options)), 'Register existing plugin.');
    check($settings->plugins[0]->updates === 'tags', 'CLI defaults to tags.');
    check($settings->plugins[0]->localVersion === '', 'Do not assume existing files match remote tag.');
    check($installer->installs === 0, 'Registration does not install.');
    $before = $saves;
    check(is_string($manager->register('plugin', 'existing', $options)), 'Repeated registration.');
    check($saves === $before && count($settings->plugins) === 1, 'Registration is idempotent.');
    errorCode($manager->register('plugin', 'existing', $options + ['updates' => 'commits']), 'conflicting_repository');
    errorCode($manager->install('plugin', '../bad', $options), 'invalid_name');
    errorCode($manager->install('theme', 'good', $options + ['folder' => '/absolute']), 'invalid_name');
    errorCode($manager->install('plugin', 'good', ['connector' => 'unknown']), 'unknown_connector');
    errorCode($manager->unregister('plugin', 'existing', 'unknown'), 'unknown_connector');
    errorCode($manager->install('plugin', 'good', $options + ['updates' => 'invalid']), 'invalid_updates');
    errorCode($manager->install('plugin', 'good', $options + ['branch' => '']), 'invalid_branch');
    errorCode($manager->install('plugin', 'good', $options + ['only-release' => true, 'updates' => 'commits']), 'conflicting_updates');
    errorCode($manager->install('plugin', 'good', $options + ['only-release' => true, 'updates' => 'tags']), 'conflicting_updates');

    foreach (['plugin', 'theme'] as $type) {
        check(is_string($manager->install($type, 'released', $options + ['only-release' => true])), 'Release installation.');
        $rows = $manager->listRepositories($type);
        $row = end($rows);
        check($row['updates'] === 'releases' && $row['local_ref'] === 'v1.0.0', 'Save release policy and installed ref.');
        $before = $installer->installs;
        check(is_string($manager->install($type, 'released', $options + ['only-release' => true])), 'Repeat install.');
        check($installer->installs === $before, 'Repeat install must not overwrite files.');
    }
    check($storage['rrze_updater']['themes'][0]['updates'] === 'releases', 'Release mode survives serialization.');
    $settings->themes[0]->checkForUpdates();
    check(end($connector->calls) === ['releases'], 'Future updates still select releases.');
    $connector->release = false;
    $settings->themes[0]->checkForUpdates();
    check($settings->themes[0]->remoteVersion === false, 'Missing release clears the previous update ref.');
    errorCode($manager->install('theme', 'no-release', $options + ['only-release' => true]), 'remote_ref_unavailable');
    check(end($connector->calls) === ['releases'], 'No fallback to tags.');
    $connector->release = 'v1.0.0';
    $connector->tag = false;
    errorCode($manager->install('plugin', 'no-tag', $options), 'remote_ref_unavailable');
    $connector->tag = 'v2.0.0';
    check(is_string($manager->install('plugin', 'development', $options + ['updates' => 'commits', 'branch' => 'dev'])), 'Commit mode.');
    check(end($connector->calls) === ['commits', 'dev'], 'Commit mode uses selected branch.');

    $installer->fail = true;
    $before = $settings->asArray();
    check(is_wp_error($manager->install('plugin', 'failed', $options)), 'Install failure returned.');
    check($settings->asArray() === $before, 'Failed install does not save an association.');
    $installer->fail = false;
    $connector->valid = false;
    $before = $installer->installs;
    check(is_wp_error($manager->install('theme', 'invalid-package', $options)), 'Reject malformed remote theme.');
    check($installer->installs === $before, 'Validate before downloading/installing.');
    $connector->valid = true;
    $installer->installed['plugin/unmanaged'] = true;
    errorCode($manager->install('plugin', 'unmanaged', $options), 'destination_exists');
    $fail_save = true;
    $before = $settings->asArray();
    errorCode($manager->register('plugin', 'unmanaged', $options), 'save_failed');
    check($settings->asArray() === $before, 'Failed save restores in-memory settings.');
    $fail_save = false;
    $multisite = false;
    check(is_string($manager->register('plugin', 'unmanaged', $options)), 'Single-site option storage.');
    $multisite = true;
    check(is_string($manager->unregister('plugin', 'existing')), 'Unregister.');
    check($installer->isInstalled('plugin', 'existing'), 'Unregister keeps files.');
    check(is_string($manager->unregister('plugin', 'existing')), 'Repeated unregister.');
    check(in_array('update_plugins', $invalidated, true), 'Invalidate update cache.');
    check(!str_contains(json_encode($manager->listConnectors()), 'never-print'), 'Connector list must not expose tokens.');

    CLI::registerCommands($settings);
    check(count(WP_CLI::$commands) === 9, 'All nine commands registered.');
    // Optional WP-CLI PHAR or php/ source directory. Help rendering alone does
    // not detect malformed option names; validate the rendered synopsis too.
    if (isset($argv[1])) {
        $wpCliPath = realpath($argv[1]);
        if ($wpCliPath === false) {
            throw new RuntimeException('WP-CLI path not found.');
        }
        $wpCliPhp = is_dir($wpCliPath) ? $wpCliPath : "phar://$wpCliPath/vendor/wp-cli/wp-cli/php";
        if (!file_exists($wpCliPhp . '/WP_CLI/SynopsisParser.php')) {
            throw new RuntimeException('Pass a WP-CLI php/ source directory or a PHAR file with a .phar extension.');
        }
        require $wpCliPhp . '/WP_CLI/SynopsisParser.php';
        require $wpCliPhp . '/WP_CLI/SynopsisValidator.php';
        foreach (WP_CLI::$commands as $name => [$callback, $metadata]) {
            $specification = $metadata['synopsis'];
            $synopsis = \WP_CLI\SynopsisParser::render($specification);
            $validator = new \WP_CLI\SynopsisValidator($synopsis);
            check($validator->get_unknown() === [], "$name: invalid synopsis: $synopsis");
            if (str_ends_with($name, ' register') || str_ends_with($name, ' install')) {
                check($validator->unknown_assoc(['connector' => 'fixture-id', 'only-release' => true]) === [],
                    "$name accepts --only-release.");
                check(in_array('onlyRelease', $validator->unknown_assoc(['onlyRelease' => true]), true),
                    "$name rejects the unsupported uppercase flag.");
            }
        }
    }
    foreach (['plugin', 'theme'] as $type) {
        foreach (['register', 'install', 'list', 'unregister'] as $action) {
            check(isset(WP_CLI::$commands["rrze-updater repo $type $action"]), "$type $action exists.");
        }
    }
    [ $list ] = WP_CLI::$commands['rrze-updater connector list'];
    $list([], ['format' => 'json']);
    check($cli_output['format'] === 'json' && count($cli_output['items']) === 1, 'CLI list output.');
    check(!str_contains(json_encode($cli_output), 'never-print'), 'CLI output hides credentials.');
    [ $unregister ] = WP_CLI::$commands['rrze-updater repo theme unregister'];
    $unregister(['released'], []);
    check(count($settings->themes) === 0, 'CLI invokes repository operation.');

    // Valid plugins need neither a README nor a conventional entry-file name.
    class AdvisoryPluginConnectorFixture extends ConnectorFixture {
        public string $scenario = 'readme';
        public function getRemoteFile(string $repository, string $ref, string $file): string|bool {
            if ($this->scenario === 'readme' && $file === 'readme.txt') {
                return false;
            }
            if ($this->scenario !== 'readme' && $file === "$repository.php") {
                return $this->scenario === 'main_file' ? false : '<?php // Helper without a plugin header.';
            }
            if ($file === 'bootstrap.php') {
                return "<?php\n/*\nPlugin Name: Valid Plugin\nVersion: 1.0.0\n*/";
            }
            return parent::getRemoteFile($repository, $ref, $file);
        }
    }
    $advisorySettings = new Settings();
    $advisorySettings->plugins = $advisorySettings->themes = [];
    $advisoryConnector = new AdvisoryPluginConnectorFixture();
    $advisoryConnector->id = 'advisory';
    $advisorySettings->connectors = [$advisoryConnector];
    $advisoryInstaller = new InstallerFixture();
    $advisoryManager = new RepositoryManager($advisorySettings, $advisoryInstaller);
    $advisoryOptions = ['connector' => 'advisory'];
    foreach (['readme', 'main_file', 'name_header'] as $scenario) {
        $advisoryConnector->scenario = $scenario;
        foreach (['register', 'install'] as $action) {
            $repository = "advisory-$scenario-$action";
            if ($action === 'register') {
                $advisoryInstaller->installed["plugin/$repository"] = true;
            }
            $result = $advisoryManager->$action('plugin', $repository, $advisoryOptions);
            check(is_string($result), "$action accepts advisory $scenario failure.");
            $warnings = $advisoryManager->getWarnings();
            check(count($warnings) === 1, "$action exposes one $scenario warning.");
            $storedPlugins = $storage['rrze_updater']['plugins'];
            check(RRZE\Updater\Core\Plugin::createFromArray(end($storedPlugins))->lastWarning === $warnings[0],
                'Persist advisory warning across serialization.');
        }
    }
    check($advisoryInstaller->installs === 3, 'Registration does not run the installer.');
    check(!RRZE\Updater\Core\Plugin::isRepositoryFileWarning(new WP_Error('download_failed', 'Failed.')),
        'Only known advisory errors are downgraded.');
    $advisoryInstaller->fail = true;
    $before = $advisorySettings->asArray();
    check(is_wp_error($advisoryManager->install('plugin', 'invalid-archive', $advisoryOptions)),
        'Installer rejection remains fatal despite an advisory warning.');
    check($before === $advisorySettings->asArray(), 'Rejected archive does not save an association.');
    $advisoryInstaller->fail = false;
    $advisoryConnector->tag = false;
    errorCode($advisoryManager->install('plugin', 'inaccessible', $advisoryOptions), 'remote_ref_unavailable');
    check($advisoryManager->getWarnings() === [], 'Failed ref lookup does not inherit old warnings.');
    $advisoryConnector->tag = 'v2.0.0';
    $advisoryConnector->scenario = 'readme';
    $advisoryCLI = new CLI($advisoryManager);
    $runCommand = new ReflectionMethod(CLI::class, 'runRepositoryCommand');
    WP_CLI::$warnings = WP_CLI::$messages = [];
    $runCommand->invoke($advisoryCLI, 'plugin', 'install', ['cli-advisory'], $advisoryOptions);
    check(count(WP_CLI::$warnings) === 1 && count(WP_CLI::$messages) === 1,
        'CLI prints the warning and still reports success.');
    $runCommand->invoke($advisoryCLI, 'plugin', 'unregister', ['cli-advisory'], []);
    check(count(WP_CLI::$warnings) === 1, 'Unregister does not print stale warnings.');

    class ThemeStructureConnectorFixture extends ConnectorFixture {
        public array $files = [];
        public function getRemoteFile(string $repository, string $ref, string $file): string|bool {
            return $this->files[$file] ?? false;
        }
    }
    $themeConnector = new ThemeStructureConnectorFixture();
    $themeConnector->id = 'theme-structure';
    $themeDefinition = RRZE\Updater\Core\Theme::createFromArray(['repository' => 'child']);
    $themeDefinition->connector = $themeConnector;
    foreach ([
        "/*\nTheme Name: Child\nTemplate: parent-theme\n*/",
        "/*\r\n * Theme Name: Child\r\n * Template: parent-theme\r\n */",
        "/* Theme Name: Child */\r/* tEmPlAtE: parent-theme */",
    ] as $stylesheet) {
        $themeConnector->files = ['style.css' => $stylesheet];
        check($themeDefinition->validateRemoteThemeRepository('v1') === true,
            'Child themes inherit index templates from their parent.');
    }
    foreach (['', "Template:\nVersion: 1.0", 'Template:   ', '/* Template: */',
        str_repeat(' ', 8192) . "\nTemplate: parent-theme"] as $template) {
        $themeConnector->files = ['style.css' => "Theme Name: Standalone\n$template"];
        errorCode($themeDefinition->validateRemoteThemeRepository('v1'), 'missing_theme_index_template');
    }
    foreach (['index.php', 'templates/index.html'] as $index) {
        $themeConnector->files = ['style.css' => 'Theme Name: Standalone', $index => 'index template'];
        check($themeDefinition->validateRemoteThemeRepository('v1') === true,
            'Standalone classic and block themes still validate.');
    }
    $themeConnector->files = [];
    errorCode($themeDefinition->validateRemoteThemeRepository('v1'), 'missing_theme_stylesheet');
    $themeConnector->files = ['style.css' => "Theme Name:   \nTemplate: parent-theme"];
    errorCode($themeDefinition->validateRemoteThemeRepository('v1'), 'missing_theme_name_header');

    $themeSettings = new Settings();
    $themeSettings->plugins = $themeSettings->themes = [];
    $themeSettings->connectors = [$themeConnector];
    $themeInstaller = new InstallerFixture();
    $themeManager = new RepositoryManager($themeSettings, $themeInstaller);
    $themeConnector->files = ['style.css' => "Theme Name: Child\nTemplate: parent-theme"];
    foreach (['register', 'install'] as $action) {
        $repository = "child-$action";
        if ($action === 'register') {
            $themeInstaller->installed["theme/$repository"] = true;
        }
        check(is_string($themeManager->$action('theme', $repository, ['connector' => 'theme-structure'])),
            "CLI manager can $action a child theme without a local index.");
        $storedThemes = $storage['rrze_updater']['themes'];
        check(end($storedThemes)['repository'] === $repository, 'Save child-theme association.');
    }
    check($themeInstaller->installs === 1, 'Only install invokes the WordPress installer adapter.');

    class GithubApiFixture extends GithubConnector {
        public mixed $response;
        public array $requests = [];
        protected function api(string $url, array $getArgs = [], array $args = []): mixed {
            $this->requests[] = [$url, $getArgs]; return $this->response;
        }
        public function isRateLimitReached(): bool { return false; }
    }
    $github = new GithubApiFixture();
    $github->owner = 'owner';
    $github->token = 'fixture';
    $github->response = (object) ['tag_name' => 'v1', 'draft' => false, 'prerelease' => false];
    check($github->getRemoteRelease('repo') === 'v1', 'GitHub published release.');
    check(str_ends_with($github->requests[0][0], '/releases/latest'), 'Use release API, not tags.');
    check(isset($github->requests[0][1]['headers']['Authorization']), 'Authenticate GitHub release lookup.');
    check(str_ends_with($github->downloadRepoZip('repo', 'release/v1'), '/release%2Fv1'), 'Encode archive refs.');
    $github->response = [(object) ['sha' => 'abc']];
    check($github->getRemoteCommit('repo', 'feature/a&b') === 'abc', 'Read branch commit.');
    check(str_ends_with(end($github->requests)[0], '?sha=feature%2Fa%26b'), 'Encode commit query refs.');
    foreach ([(object) ['tag_name' => 'v2', 'draft' => true], (object) ['tag_name' => 'v2', 'prerelease' => true], false] as $response) {
        $github->response = $response;
        check($github->getRemoteRelease('repo') === false, 'Exclude unpublished/prerelease/missing GitHub releases.');
    }
    class GitlabApiFixture extends GitlabConnector {
        public array $responses = [];
        public array $requests = [];
        protected function api(string $url, array $getArgs = [], array $args = []): mixed {
            $this->requests[] = [$url, $getArgs]; return array_shift($this->responses);
        }
    }
    $gitlab = GitlabApiFixture::createFromArray(['id' => 'lab', 'owner' => 'group/subgroup', 'token' => 'fixture']);
    // The factory returns the production class; configure our API fixture separately.
    $lab = new GitlabApiFixture();
    $lab->owner = $gitlab->owner;
    $lab->host = $gitlab->host;
    $lab->apiUri = $gitlab->apiUri;
    $lab->token = $gitlab->token;
    $future = (object) ['tag_name' => 'future', 'released_at' => '2999-01-01T00:00:00Z'];
    $published = (object) ['tag_name' => 'v1', 'released_at' => '2020-01-01T00:00:00Z'];
    $lab->responses = [array_fill(0, 100, $future), [$published]];
    check($lab->getRemoteRelease('repo') === 'v1', 'Skip future GitLab releases across pages.');
    check(count($lab->requests) === 2 && str_contains($lab->requests[1][0], 'page=2'), 'GitLab pagination.');
    check(str_contains($lab->requests[0][0], 'group%2Fsubgroup%2Frepo'), 'Encode GitLab subgroup.');
    check($lab->requests[0][1]['headers']['PRIVATE-TOKEN'] === 'fixture', 'Authenticate GitLab release lookup.');
    $lab->responses = [[]];
    check($lab->getRemoteRelease('repo') === false, 'No GitLab release, no fallback.');
    // Exercise the installer adapter with actual WP hooks/skin classes and a
    // fake WordPress upgrader. No repository ZIP is fetched or extracted.
    class PackageFixture extends GithubApiFixture {
        public string $package = '';
        public bool $downloadFails = false;
        public function downloadRepoZipToTempFile(string $repository, string $branch = 'main'): string|bool {
            if ($this->downloadFails) { return false; }
            $this->package = tempnam(sys_get_temp_dir(), 'rrze-cli-test-');
            file_put_contents($this->package, 'fixture archive');
            return $this->package;
        }
    }
    class WordPressUpgraderFixture extends WP_Upgrader {
        public function __construct(public InstallerAdapterFixture $adapter) {}
        public function install($package, $options = []) {
            check($options['overwrite_package'] === false, 'Never overwrite installed files.');
            $other = new stdClass();
            check(apply_filters('upgrader_source_selection', '/tmp/other/', '/tmp/', $other) === '/tmp/other/',
                'Source hook only handles its own upgrader.');
            $source = apply_filters('upgrader_source_selection', '/tmp/generated/', '/tmp/', $this);
            if (is_wp_error($source)) { return $source; }
            check($source === '/tmp/package/', 'Use configured installation folder.');
            if ($this->adapter->throws) { throw new RuntimeException('Fixture exception.'); }
            if ($this->adapter->fails) { return new WP_Error('fixture_failure', 'Failed.'); }
            $this->adapter->installed = true;
            return true;
        }
    }
    class InstallerAdapterFixture extends RepositoryInstaller {
        public bool $installed = false;
        public bool $fails = false;
        public bool $throws = false;
        public function isInstalled(string $type, string $folder): bool { return $this->installed; }
        public function destinationExists(string $type, string $folder): bool { return $this->installed; }
        protected function createUpgrader(string $type): WP_Upgrader { return new WordPressUpgraderFixture($this); }
    }
    $wp_filesystem = new class {
        public bool $fails = false;
        public function move($source, $destination, $overwrite) {
            check($overwrite === false, 'Do not replace an existing archive directory.');
            return !$this->fails;
        }
    };
    $package = new PackageFixture();
    $definition = RRZE\Updater\Core\Plugin::createFromArray([
        'repository' => 'package', 'installationFolder' => 'package', 'remoteVersion' => 'v1',
    ]);
    $definition->connector = $package;
    $adapter = new InstallerAdapterFixture();
    check($adapter->install('plugin', $definition) === true, 'Installer success.');
    check(!is_file($package->package), 'Remove temporary authenticated download after success.');
    check(has_filter('upgrader_source_selection') === false, 'Remove scoped filter after success.');
    errorCode($adapter->install('plugin', $definition), 'destination_exists');
    $adapter->installed = false;
    $adapter->fails = true;
    check(is_wp_error($adapter->install('theme', $definition)), 'Installer propagates WordPress failure.');
    check(!is_file($package->package), 'Remove temporary download after failure.');
    check(has_filter('upgrader_source_selection') === false, 'Remove scoped filter after failure.');
    $adapter->fails = false;
    $adapter->throws = true;
    try { $adapter->install('plugin', $definition); } catch (RuntimeException $e) {}
    check(!is_file($package->package) && !has_filter('upgrader_source_selection'), 'Exception cleanup.');
    $adapter->throws = false;
    $wp_filesystem->fails = true;
    errorCode($adapter->install('plugin', $definition), 'source_move_failed');
    $package->downloadFails = true;
    errorCode($adapter->install('plugin', $definition), 'download_failed');
    echo "Passed $checks repository/CLI/release/installer checks.\n";
}

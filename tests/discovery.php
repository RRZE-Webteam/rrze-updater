<?php
// Standalone discovery, inspection, and shared-job integration tests. No live API calls.
require __DIR__ . '/bundles.php';

use RRZE\Updater\Core\{RepositoryDiscovery, RepositoryInspector, GitlabConnector};

function get_transient($key) { return $GLOBALS['discovery_cache'][$key] ?? false; }
function set_transient($key, $value, $expiry) { $GLOBALS['discovery_cache'][$key] = $value; }
function wp_remote_get($url, $args) {
    $GLOBALS['discovery_requests'][] = [$url, $args];
    $data = ($GLOBALS['discovery_http'])($url, $args);
    if (is_wp_error($data)) return $data;
    if (isset($data['http_error'])) return ['code' => $data['http_error'], 'body' => '{"message":"private service detail"}'];
    return ['code' => 200, 'body' => json_encode($data)];
}
function wp_remote_retrieve_response_code($response) { return is_wp_error($response) ? '' : $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function discoveryReset(callable $handler): void {
    $GLOBALS['discovery_cache'] = $GLOBALS['discovery_requests'] = [];
    $GLOBALS['discovery_http'] = $handler;
}
function githubRepo($name, $owner = 'RRZE-Webteam'): array {
    return ['id' => 123, 'name' => $name, 'owner' => ['login' => $owner], 'default_branch' => 'develop', 'private' => true];
}
$beforeDiscovery = $checks;
$wp_version = '6.8';
$f = bundleFixture([]);
$api = new RepositoryDiscovery($f['connector']);
discoveryReset(function ($url, $args) {
    check(!str_contains($url, 'bundle-secret-token') && $args['redirection'] === 0, 'Credentials are headers only and cannot follow redirects.');
    check(($args['headers']['Authorization'] ?? '') === 'Bearer bundle-secret-token', 'GitHub uses the selected token.');
    $path = parse_url($url, PHP_URL_PATH);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if ($path === '/users/RRZE-Webteam') return ['type' => 'Organization'];
    check($path === '/orgs/RRZE-Webteam/repos', 'Organization discovery stays within configured owner.');
    return ($query['page'] ?? 1) == 1 ? array_fill(0, 100, githubRepo('repo')) : [githubRepo('last'), githubRepo('foreign', 'SomeoneElse')];
});
$first = $api->repositories(); $second = $api->repositories(2);
check(count($first['items']) === 100 && $first['has_more'], 'Repository listings paginate beyond the first 100.');
check(count($second['items']) === 1 && $second['items'][0]['repository'] === 'last' && !$second['has_more'], 'Filter out foreign owners and preserve pagination.');
$count = count($discovery_requests); $api->repositories(2);
check(count($discovery_requests) === $count, 'Repeat listings use the short-lived cache.');
$f['connector']->token = 'replacement-token';
$GLOBALS['discovery_http'] = fn() => ['http_error' => 401];
check(is_wp_error($api->repositories(2)), 'Replacing the token does not expose the previous token’s cached private repositories.');

$gitlab = GitlabConnector::createFromArray(['id' => 'gitlab', 'owner' => 'team/subgroup', 'host' => 'gitlab.example.org', 'apiUri' => '/api/v4/projects/', 'token' => 'gitlab-secret']);
$lab = new RepositoryDiscovery($gitlab);
discoveryReset(function ($url, $args) {
    check(($args['headers']['PRIVATE-TOKEN'] ?? '') === 'gitlab-secret' && !str_contains($url, 'gitlab-secret'), 'GitLab token uses a header.');
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === '/api/v4/groups/team%2Fsubgroup') return ['id' => 99];
    check($path === '/api/v4/groups/99/projects', 'Encoded subgroup owner resolves to the group endpoint.');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    check($query['include_subgroups'] === 'false' && $query['with_shared'] === 'false', 'Do not discover child namespaces or shared projects.');
    return [['id' => 1, 'path' => 'ours', 'namespace' => ['full_path' => 'team/subgroup'], 'default_branch' => 'master'], ['id' => 2, 'path' => 'nested', 'namespace' => ['full_path' => 'team/subgroup/child']]];
});
$result = $lab->repositories();
check(count($result['items']) === 1 && $result['items'][0]['branch'] === 'master', 'GitLab preserves owner boundary and actual default branch.');

$gitlab->owner = 'person';
discoveryReset(function ($url) {
    return match (parse_url($url, PHP_URL_PATH)) {
        '/api/v4/groups/person' => ['http_error' => 404],
        '/api/v4/users' => [['id' => 8, 'username' => 'person']],
        '/api/v4/users/8/projects' => [['id' => 3, 'path' => 'personal', 'namespace' => ['full_path' => 'person']]],
        default => throw new RuntimeException('Unexpected personal namespace endpoint'),
    };
});
check($lab->repositories()['items'][0]['repository'] === 'personal', 'GitLab personal namespaces are supported.');

$f = bundleFixture([]); $api = new RepositoryDiscovery($f['connector']);
discoveryReset(function ($url) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    return ($query['page'] ?? 1) == 1 ? array_fill(0, 100, ['name' => 'main']) : [['name' => 'feature/after-100']];
});
check($api->branches('repo')['has_more'] && $api->branches('repo', 2)['items'] === ['feature/after-100'], 'Branch pagination includes names beyond page one and preserves slashes.');

// Dynamic provider fixture used through the real discovery client and inspector.
function inspectionFixture(array $files, string $owner = 'RRZE-Webteam'): void {
    $GLOBALS['inspection_files'] = $files;
    discoveryReset(function ($url) use ($owner) {
        $path = parse_url($url, PHP_URL_PATH);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (!preg_match('~/repos/RRZE-Webteam/([^/]+)(.*)$~', $path, $match)) throw new RuntimeException('Unexpected inspection URL');
        $repo = rawurldecode($match[1]); $tail = $match[2];
        if ($tail === '') return githubRepo($repo, $owner);
        if (str_starts_with($tail, '/branches/')) {
            check(rawurldecode(substr($tail, strlen('/branches/'))) === 'feature/test', 'Resolve the selected branch, including slash names.');
            return ['commit' => ['sha' => str_repeat('a', 40)]];
        }
        check(($query['ref'] ?? '') === str_repeat('a', 40), 'Every content check is pinned to the resolved commit.');
        $files = $GLOBALS['inspection_files'][$repo] ?? $GLOBALS['inspection_files']['*'] ?? [];
        if ($tail === '/contents') return array_map(fn($name) => ['type' => 'file', 'name' => $name], array_values(array_filter(array_keys($files), fn($name) => !str_contains($name, '/'))));
        $file = rawurldecode(substr($tail, strlen('/contents/')));
        return isset($files[$file]) ? ['encoding' => 'base64', 'content' => base64_encode($files[$file])] : ['http_error' => 404];
    });
}
$inspector = new RepositoryInspector();
inspectionFixture(['*' => ['bootstrap.php' => "<?php\n/*\n * Plugin Name: Different filename\n * Version: 2.0\n * Requires at least: 6.8\n * Requires PHP: 8.3\n */"]]);
$result = $inspector->inspect($api, 'repo', 'feature/test');
check(!is_wp_error($result) && $result['type'] === 'plugin' && $result['main_file'] === 'bootstrap.php' && $result['warning'] === '', 'Detect nonstandard plugin filename without requiring readme or tags.');
inspectionFixture(['*' => ['bootstrap.php' => "Plugin Name: Test\nRequires Plugins: dependency"]]);
check(str_contains($inspector->inspect($api, 'repo', 'feature/test')['warning'], 'dependency'), 'Declared plugin dependencies appear in the review.');
inspectionFixture(['*' => ['one.php' => 'Plugin Name: First', 'two.php' => 'Plugin Name: Second']]);
check($inspector->inspect($api, 'repo', 'feature/test')->get_error_code() === 'unsupported_structure', 'Reject ambiguous multiple-plugin packages.');
inspectionFixture(['*' => ['one.php' => str_repeat(' ', 8192) . "\nPlugin Name: Hidden"]]);
check(is_wp_error($inspector->inspect($api, 'repo', 'feature/test')), 'Only inspect WordPress’s first 8 KiB of headers.');
inspectionFixture(['*' => ['style.css' => '/* Theme Name: Block theme */', 'templates/index.html' => '<!-- wp:paragraph /-->']]);
check($inspector->inspect($api, 'repo', 'feature/test')['type'] === 'theme', 'Recognize a block theme with index template.');
inspectionFixture(['*' => ['style.css' => 'Theme Name: Broken']]);
check(is_wp_error($inspector->inspect($api, 'repo', 'feature/test')), 'Reject parent themes without an index template.');
inspectionFixture(['*' => ['main.php' => "Plugin Name: Future\nRequires at least: 999.0"]]);
check($inspector->inspect($api, 'repo', 'feature/test')->get_error_code() === 'incompatible_extension', 'Declared incompatible WordPress requirements block installation.');
inspectionFixture(['*' => ['main.php' => 'Plugin Name: Foreign']], 'OtherOwner');
check($inspector->inspect($api, 'repo', 'feature/test')->get_error_code() === 'discovery_scope', 'Forged selection cannot escape the connector owner.');

function customAction(array $f, string $action, array $selection = []): array|WP_Error {
    $job = $f['store']->load();
    return $f['manager']->handle($action, $job['id'] ?? '', $job['revision'] ?? -1, ['browse' => 'bundle-github'], $selection);
}
function customSelection($repository, $folder = null): array { return ['repository' => $repository, 'folder' => $folder ?? $repository, 'branch' => 'feature/test']; }
$f = bundleFixture([]);
inspectionFixture([
    'child' => ['style.css' => "Theme Name: Child\nTemplate: parent"],
    'parent' => ['style.css' => 'Theme Name: Parent', 'index.php' => '<?php'],
    'plugin' => ['bootstrap.php' => 'Plugin Name: Custom'],
]);
$result = customAction($f, 'check_custom', [customSelection('child'), customSelection('plugin'), customSelection('parent')]);
check(!is_wp_error($result), 'Create custom selection on the shared job runner.');
$job = bundleDrain($f);
check($job['phase'] === 'ready' && array_column(array_values($job['items']), 'repository') === ['plugin', 'parent', 'child'], 'Custom selection reuses parent ordering.');
check(!str_contains(json_encode($job), $f['connector']->token), 'Jobs never persist or return tokens.');
customAction($f, 'install'); $job = bundleDrain($f);
check($job['phase'] === 'complete' && $f['installer']->installs === 3, 'Custom batch installs through shared runner.');
check(count(array_unique($f['installer']->refs)) === 1 && reset($f['installer']->refs) === str_repeat('a', 40), 'Install the reviewed immutable refs.');
foreach (array_merge($f['settings']->plugins, $f['settings']->themes) as $extension) {
    check($extension->updates === 'commits' && $extension->branch === 'feature/test', 'Associations track selected branches using commits.');
}
$f = bundleFixture([]);
foreach ([[], [customSelection('../escape')], [customSelection('repo', '../escape')], [customSelection('repo'), customSelection('repo')], [['repository' => 'repo', 'branch' => ['nested']]], array_fill(0, 101, customSelection('repo'))] as $selection) {
    check(is_wp_error(customAction($f, 'check_custom', $selection)) && $f['store']->job === null, 'Reject malformed selections before persistence.');
}
inspectionFixture(['*' => ['bootstrap.php' => 'Plugin Name: Duplicate']]);
customAction($f, 'check_custom', [customSelection('first', 'same'), customSelection('second', 'same')]);
$job = bundleDrain($f);
check($job['phase'] === 'blocked' && count(array_filter($job['items'], fn($item) => $item['status'] === 'error')) === 2, 'Conflicting installation folders block both entries before any installation.');
$f = bundleFixture([]);
inspectionFixture(['*' => []]);
customAction($f, 'check_custom', [customSelection('repo')]); bundleDrain($f);
inspectionFixture(['*' => ['bootstrap.php' => 'Plugin Name: Repaired']]);
customAction($f, 'retry'); $job = bundleDrain($f);
check($job['phase'] === 'ready', 'Retry custom checks rebuilds the selected job rather than the recommended catalog.');
$f['connector']->owner = 'ChangedOwner';
customAction($f, 'install'); $job = bundleDrain($f);
check(array_values($job['items'])[0]['status'] === 'failed' && $f['installer']->installs === 0, 'Connector owner is revalidated before installation.');

$f = bundleFixture([]);
inspectionFixture(['*' => ['bootstrap.php' => 'Plugin Name: Custom cancellation']]);
customAction($f, 'check_custom', [customSelection('one'), customSelection('two')]);
bundleDrain($f); customAction($f, 'install'); customAction($f, 'step');
$cancelled = customAction($f, 'cancel')['job'];
check($cancelled['phase'] === 'cancelled' && $f['installer']->installs === 1, 'Discovered selection uses the same cancellation behavior.');
check(count(array_filter($cancelled['items'], fn($item) => $item['status'] === 'cancelled')) === 1, 'Only the remaining custom entry is cancelled.');
check(is_wp_error(customAction($f, 'retry')), 'A cancelled custom job cannot be revived by retry.');
customAction($f, 'check_custom', [customSelection('two')]);
check(bundleDrain($f)['phase'] === 'ready', 'A new custom selection can be reviewed after cancellation.');

// New AJAX operations retain network/capability/nonce checks and reject bad input.
$bundle_caps = ['manage_network_options' => true, 'install_plugins' => true, 'install_themes' => true];
$bundle_mods = true;
$bundle_network = 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$admin = new \RRZE\Updater\BundleAdmin();
foreach (['operation', 'job', 'revision', 'connector', 'repository', 'page', 'selection'] as $field) {
    $_POST = ['operation' => 'check_custom', 'network' => 1, 'nonce' => 'rrze_updater_bundle_1', $field => ['nested']];
    $admin->request();
    check($bundle_response['status'] === 400, "Reject non-scalar AJAX field $field.");
}
foreach (['null', '{}', '{broken', '"string"'] as $selection) {
    $_POST = ['operation' => 'check_custom', 'network' => 1, 'nonce' => 'rrze_updater_bundle_1', 'selection' => $selection];
    $admin->request();
    // An empty JSON object decodes to [] and is rejected by the job selection validator.
    check(!$bundle_response['success'], 'Invalid or empty JSON selections cannot start a job.');
}
$_POST = ['operation' => 'browse_repositories', 'network' => [1], 'nonce' => 'rrze_updater_bundle_1'];
$admin->request();
check($bundle_response['status'] === 403, 'Reject malformed network IDs before discovery.');
discoveryReset(fn() => ['unexpected' => 'object']);
check(is_wp_error($api->branches('repo')), 'Malformed branch listing is a retryable error, not an empty success.');

echo 'Passed ' . ($checks - $beforeDiscovery) . " discovery/inspection/custom-job checks.\n";

require __DIR__ . '/settings.php';

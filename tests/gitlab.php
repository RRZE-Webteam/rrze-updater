<?php
require_once __DIR__ . '/cli.php';

use RRZE\Updater\Core\{GitlabConnector, Theme};
use RRZE\Updater\{Main, Settings, Config};

function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), 'rrze-gitlab-'); }
function remove_query_arg($key, $url) {
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query);
    unset($query[$key]);
    return $parts[0] . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}
class GitlabArchiveFixture extends GitlabConnector {
    public array $requests = [];
    public bool $fail = false;
    public bool $throws = false;
    public bool $empty = false;
    public string $temporary = '';
    protected function api(string $url, array $getArgs = [], array $args = []): mixed {
        $this->requests[] = [$url, $getArgs];
        if (isset($getArgs['filename'])) {
            $this->temporary = $getArgs['filename'];
            if (!$this->empty) file_put_contents($this->temporary, 'archive-fixture');
            if ($this->throws) throw new RuntimeException('Transport failure');
            return $this->fail ? false : ['body' => ''];
        }
        return false;
    }
}
$beforeGitlab = $checks;
$lab = new GitlabArchiveFixture();
$lab->host = 'gitlab.example.org'; $lab->apiUri = '/api/v4/projects/';
$lab->owner = 'group/subgroup'; $lab->token = 'secret & token';
$url = $lab->downloadRepoZip('package', 'feature/a&b');
check(!str_contains($url, 'private_token') && !$lab->requests, 'Package identifiers contain no credentials and perform no downloads.');
check(str_ends_with($url, '?sha=feature%2Fa%26b'), 'Archive ref is encoded.');
$path = $lab->downloadRepoZipToTempFile('package', 'feature/a&b');
check(is_file($path) && file_get_contents($path) === 'archive-fixture', 'Archive streams into a local temporary file.');
[$requestUrl, $args] = end($lab->requests);
check($requestUrl === $url && $args['headers']['PRIVATE-TOKEN'] === $lab->token && $args['redirection'] === 0,
    'Authenticate using a header without forwarding credentials to redirects.');
wp_delete_file($path);
foreach (['fail', 'throws', 'empty'] as $failure) {
    $lab->$failure = true;
    $result = false;
    try { $result = $lab->downloadRepoZipToTempFile('package'); } catch (RuntimeException $e) {}
    check($result === false && !is_file($lab->temporary), 'Delete partial archive on ' . $failure . '.');
    $lab->$failure = false;
}
// Metadata requests must also keep credentials out of URLs.
$lab->getRemoteCommit('package'); $lab->remoteBranchExists('package', 'main');
$lab->getRemoteBranches('package'); $lab->getRemoteTag('package'); $lab->getRemoteFile('package', 'main', 'style.css');
foreach ($lab->requests as [$requestUrl, $args]) {
    check(!str_contains($requestUrl, 'private_token') && ($args['headers']['PRIVATE-TOKEN'] ?? '') === $lab->token,
        'GitLab requests use header authentication.');
}
$definition = Theme::createFromArray(['repository' => 'package', 'installationFolder' => 'package', 'remoteVersion' => 'v1']);
$definition->connector = $lab;
foreach (['success', 'fails', 'throws'] as $outcome) {
    $adapter = new InstallerAdapterFixture();
    if ($outcome !== 'success') $adapter->$outcome = true;
    try { $adapter->install('theme', $definition); } catch (RuntimeException $e) {}
    check(!is_file($lab->temporary), 'Installer cleans GitLab archive after ' . $outcome . '.');
}
// Exercise the actual WordPress pre-download hook, including old cached URLs.
$main = (new ReflectionClass(Main::class))->newInstanceWithoutConstructor();
$main->settings = new Settings();
$main->settings->themes = [$definition];
(new ReflectionProperty(Main::class, 'config'))->setValue($main, new Config());
$upgrader = new WP_Upgrader();
foreach (['', '&private_token=old-cached-token'] as $legacyQuery) {
    $path = $main->upgraderPreDownloadFilter(false, $lab->downloadRepoZip('package', 'v1') . $legacyQuery, $upgrader,
        ['type' => 'theme', 'theme' => 'package']);
    check(is_string($path) && is_file($path), 'Managed updates intercept clean and legacy package URLs.');
    check(!str_contains(end($lab->requests)[0], 'private_token'), 'Cached tokens are never sent in URLs.');
    wp_delete_file($path);
}
check($main->upgraderPreDownloadFilter(false, 'https://unrelated.example/archive.zip', $upgrader,
    ['type' => 'theme', 'theme' => 'package']) === false, 'Unrelated package is not downloaded with connector credentials.');
$lab->fail = true;
check(is_wp_error($main->upgraderPreDownloadFilter(false, $lab->downloadRepoZip('package', 'v1'), $upgrader,
    ['type' => 'theme', 'theme' => 'package'])), 'Failed authenticated update does not fall back to an unauthenticated download.');
echo 'Passed ' . ($checks - $beforeGitlab) . " GitLab credential/download checks.\n";

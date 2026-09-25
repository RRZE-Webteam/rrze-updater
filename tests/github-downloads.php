<?php
// Real Requests redirect handling and WordPress hook bridge, with an offline transport.
require_once __DIR__ . '/gitlab.php';
require_once ABSPATH . 'wp-includes/Requests/src/Autoload.php';
\WpOrg\Requests\Autoload::register();
require_once ABSPATH . 'wp-includes/class-wp-http-requests-hooks.php';

use RRZE\Updater\Core\{GithubConnector, Theme};
use RRZE\Updater\{Config, ManagedUpgrader, Settings};
use WpOrg\Requests\Requests;

class GithubDownloadTransportFixture {
    public array $requests = [];
    public array $responses = [];
    public string $temporary = '';

    public function request($url, $headers, $data, $options): string {
        $this->requests[] = [$url, $headers, $options];
        check(str_starts_with($url, 'https://') && $options['verify'] === true,
            'Every archive hop uses HTTPS with certificate verification.');
        $this->temporary = $options['filename'];
        check(is_file($this->temporary), 'Allocate the temporary file before making any HTTP request.');
        $response = array_shift($this->responses);
        check(is_array($response), 'No unexpected HTTP request.');
        $chunk = $response['chunk'] ?? 'archive-fixture';
        $chunks = $response['chunks'] ?? 1;
        $file = fopen($this->temporary, 'wb');
        for ($i = 0; $i < $chunks; $i++) fwrite($file, $chunk);
        fclose($file);
        if (isset($response['exception'])) throw $response['exception'];
        if (!empty($response['remove'])) unlink($this->temporary);
        $code = $response['code'] ?? 200;
        $raw = "HTTP/1.1 $code Fixture\r\nContent-Length: " . strlen($chunk) * $chunks;
        if (isset($response['location'])) $raw .= "\r\nLocation: " . $response['location'];
        return $raw;
    }
}

class GithubDownloadFixture extends GithubConnector {
    public GithubDownloadTransportFixture $transport;
    public bool $limited = false;
    public int $quotaChecks = 0;
    public array $requestArgs = [];
    public array $logs = [];

    public function __construct() {
        parent::__construct();
        $this->transport = new GithubDownloadTransportFixture();
        $this->owner = 'owner';
        $this->token = 'private-api-token';
    }

    protected function api(string $url, array $getArgs = [], array $args = []): mixed {
        $this->requestArgs[] = $getArgs;
        check($getArgs['stream'] === true && $args['jsonDecodeBody'] === false,
            'The archive is streamed without reading or decoding its response body.');
        check($getArgs['timeout'] === 300 && $getArgs['reject_unsafe_urls'] === true,
            'Archive requests retain URL validation and use a download timeout.');
        // Map the same options and hook bridge used by WordPress WP_Http.
        try {
            $response = Requests::request($url, $getArgs['headers'], [], Requests::GET, [
                'filename' => $getArgs['filename'], 'verify' => $getArgs['sslverify'],
                'timeout' => $getArgs['timeout'], 'redirects' => $getArgs['redirection'],
                'hooks' => new WP_HTTP_Requests_Hooks($url, $getArgs),
                'transport' => $this->transport,
            ]);
        } catch (\WpOrg\Requests\Exception $e) {
            // WP_Http converts these exceptions to WP_Error, then Connector::api returns false.
            $this->error = $e->getMessage();
            return false;
        }
        if ($response->status_code !== 200) {
            $this->error = 'HTTP error ' . $response->status_code;
            return false;
        }
        check($response->body === '', 'A streamed response contains no archive in memory.');
        return ['body' => $response->body];
    }

    public function isRateLimitReached(): bool { $this->quotaChecks++; return $this->limited; }
    protected function logError(string $message, array $context = []) { $this->logs[] = [$message, $context]; }
}

$beforeGithubDownloads = $checks;
$archiveHook = 'requests-requests.before_redirect';
$originalDownloadHooks = $wp_filter;
$wp_filter = [];
$signedArchive = 'https://codeload.github.com/owner/package/legacy.zip/feature%2Fa?token=temporary-download-grant';
foreach (['', 'private-api-token'] as $token) {
    $githubDownload = new GithubDownloadFixture();
    $githubDownload->token = $token;
    $url = $githubDownload->downloadRepoZip('package', 'feature/a&b');
    check(str_ends_with($url, '/feature%2Fa%26b') && !$githubDownload->transport->requests
        && !str_contains($url, 'token'), 'Package URLs remain encoded, credential-free identifiers.');
    $githubDownload->transport->responses = [
        ['code' => 301, 'location' => '/repositories/123/zipball/feature%2Fa%26b'],
        ['code' => 302, 'location' => $signedArchive],
        ['chunk' => 'downloaded archive'],
    ];
    $path = $githubDownload->downloadRepoZipToTempFile('package', 'feature/a&b');
    check(is_string($path) && file_get_contents($path) === 'downloaded archive',
        'Public and private archives stream successfully through GitHub redirects.');
    foreach (array_slice($githubDownload->transport->requests, 0, 2) as [$requestUrl, $headers]) {
        check(($headers['Authorization'] ?? '') === ($token ? 'token ' . $token : ''),
            'Same-host API redirects retain authentication for private repositories.');
    }
    [$requestUrl, $headers] = $githubDownload->transport->requests[2];
    check($requestUrl === $signedArchive && !isset($headers['Authorization']),
        'Codeload receives its temporary grant without the API token.');
    check(!has_action($archiveHook), 'Success removes the temporary redirect hook.');
    wp_delete_file($path);
}

foreach ([
    'http://codeload.github.com/owner/package/zip/main',
    'https://untrusted.example/archive.zip',
    'https://codeload.github.com.untrusted.example/archive.zip',
    'https://user:secret@codeload.github.com/archive.zip',
    'https://codeload.github.com:8443/archive.zip',
] as $location) {
    $githubDownload = new GithubDownloadFixture();
    $githubDownload->transport->responses = [['code' => 302, 'location' => $location]];
    check($githubDownload->downloadRepoZipToTempFile('package') === false, 'Reject unsafe archive redirect.');
    check(count($githubDownload->transport->requests) === 1, 'Reject the redirect before sending a request.');
    check(!is_file($githubDownload->transport->temporary) && !has_action($archiveHook),
        'Refused redirects remove partial files and the temporary hook.');
    check(!str_contains($githubDownload->error, $location), 'Errors do not expose redirected URLs or their credentials.');
}

foreach ([
    ['code' => 403], ['code' => 404], ['code' => 429], ['code' => 500],
    ['chunk' => ''], ['remove' => true],
    ['exception' => new \WpOrg\Requests\Exception('Connection interrupted', 'fixture_transport')],
    ['exception' => new \WpOrg\Requests\Exception('Certificate validation failed', 'fixture_tls')],
    ['exception' => new \WpOrg\Requests\Exception('Failed writing to file', 'fixture_disk')],
    ['exception' => new RuntimeException('Unexpected failure')],
] as $failure) {
    $githubDownload = new GithubDownloadFixture();
    $githubDownload->transport->responses = [$failure];
    $result = false;
    try { $result = $githubDownload->downloadRepoZipToTempFile('package'); } catch (RuntimeException $e) {}
    check($result === false && !is_file($githubDownload->transport->temporary),
        'HTTP, TLS, transport, disk, empty-file and unexpected failures never return partial archives.');
    check(!has_action($archiveHook), 'Failures remove the temporary redirect hook.');
}

$githubDownload = new GithubDownloadFixture();
$githubDownload->transport->responses = array_fill(0, 6, ['code' => 302, 'location' => $signedArchive]);
check($githubDownload->downloadRepoZipToTempFile('package') === false
    && count($githubDownload->transport->requests) === 6, 'Bound archive redirect loops.');
check(!is_file($githubDownload->transport->temporary) && !has_action($archiveHook), 'Clean up after redirect exhaustion.');

$githubDownload = new GithubDownloadFixture();
$githubDownload->limited = true;
$githubDownload->transport->responses = [[]];
$path = $githubDownload->downloadRepoZipToTempFile('package');
check(is_string($path) && file_get_contents($path) === 'archive-fixture',
    'Keep a successful download even when the API quota has been exhausted.');
check($githubDownload->quotaChecks === 0, 'Successful downloads never make an additional quota request.');
wp_delete_file($path);

$githubDownload = new GithubDownloadFixture();
$GLOBALS['fail_tempnam'] = true;
check($githubDownload->downloadRepoZipToTempFile('package') === false && !$githubDownload->transport->requests,
    'Temporary-file allocation failure performs no download.');
check($githubDownload->error !== '' && count($githubDownload->logs) === 1, 'Report temporary-file allocation failure.');
unset($GLOBALS['fail_tempnam']);

// Generate a large body in fixed-size chunks; no complete archive exists in memory.
$githubDownload = new GithubDownloadFixture();
$chunk = str_repeat('z', 65536);
$githubDownload->transport->responses = [['chunk' => $chunk, 'chunks' => 512]];
$memory = memory_get_usage(true);
memory_reset_peak_usage();
$path = $githubDownload->downloadRepoZipToTempFile('package');
check(is_string($path) && filesize($path) === 32 * 1024 * 1024, 'A 32 MiB download produces the complete file.');
check(memory_get_peak_usage(true) - $memory < 4 * 1024 * 1024, 'Peak memory stays bounded while streaming the archive.');
wp_delete_file($path);

// Real installer and managed-upgrader entry points use the streamed file.
$definition = Theme::createFromArray(['repository' => 'package', 'installationFolder' => 'package', 'remoteVersion' => 'v1']);
$definition->connector = $githubDownload;
$originalDownloadFilesystem = $wp_filesystem;
$wp_filesystem = new class {
    public function move($source, $destination, $overwrite): bool { return true; }
};
foreach (['success', 'fails', 'throws'] as $outcome) {
    $githubDownload->transport->responses = [[]];
    $adapter = new InstallerAdapterFixture();
    if ($outcome !== 'success') $adapter->$outcome = true;
    try { $result = $adapter->install('theme', $definition); } catch (RuntimeException $e) { $result = $e; }
    check(match ($outcome) {
        'success' => $result === true,
        'fails' => is_wp_error($result),
        'throws' => $result instanceof RuntimeException,
    }, 'Streamed downloads preserve the installer outcome: ' . $outcome . '.');
    check(!is_file($githubDownload->transport->temporary), 'The installer cleans the streamed archive after ' . $outcome . '.');
}
$settings = new Settings();
$settings->themes = [$definition];
$managed = new ManagedUpgrader($settings, new Config());
$githubDownload->transport->responses = [[]];
$path = $managed->upgraderPreDownloadFilter(false, $githubDownload->downloadRepoZip('package', 'v1'), new WP_Upgrader(),
    ['type' => 'theme', 'theme' => 'package']);
check(is_string($path) && is_file($path), 'Managed updates receive the streamed GitHub archive.');
wp_delete_file($path);
$githubDownload->transport->responses = [['code' => 500]];
check(is_wp_error($managed->upgraderPreDownloadFilter(false, $githubDownload->downloadRepoZip('package', 'v1'), new WP_Upgrader(),
    ['type' => 'theme', 'theme' => 'package'])), 'A failed streamed update cannot fall back to an unauthenticated request.');

$wp_filesystem = $originalDownloadFilesystem;
$wp_filter = $originalDownloadHooks;
echo 'Passed ' . ($checks - $beforeGithubDownloads) . " GitHub streaming/HTTPS download checks.\n";

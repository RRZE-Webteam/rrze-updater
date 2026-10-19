<?php
// Exercise the production connector against offline WordPress HTTP responses.
use RRZE\Updater\Config;
use RRZE\Updater\Core\GithubConnector;

function esc_html_x($text, $context, $domain) { return $text; }
function human_time_diff($from, $to) { return abs($to - $from) . ' seconds'; }

$beforeGithubRateLimits = $checks;
$savedRateLimitTransients = $admin_transients;
$savedRateLimitHttp = $discovery_http;
$noticeKey = (new Config())->getInvalidTokenTransient();
$newGithub = static fn() => GithubConnector::createFromArray([
    'id' => 'github-rate-limit', 'owner' => 'owner', 'token' => 'fixture-token', 'display' => 'GitHub',
]);

foreach ([0, 1] as $remaining) {
    foreach ([
        ['getRemoteCommit', [(object) ['sha' => 'abc123']], 'abc123'],
        ['getRemoteTag', [(object) ['name' => 'v1.2.3']], 'v1.2.3'],
        ['getRemoteRelease', (object) ['tag_name' => 'v1.2.3'], 'v1.2.3'],
        ['downloadRepoZipToTempFile', null, 'archive-fixture'],
    ] as [$method, $body, $expected]) {
        $github = $newGithub();
        $temporary = '';
        discoveryReset(function ($url, $args) use ($remaining, $body, &$temporary) {
            check(!str_ends_with($url, '/rate_limit'), 'Success never triggers a separate quota probe.');
            if (!empty($args['stream'])) {
                $temporary = $args['filename'];
                file_put_contents($temporary, 'archive-fixture');
            }
            return ['raw_response' => [
                'code' => 200, 'headers' => ['x-ratelimit-remaining' => (string) $remaining],
                'body' => $temporary ? '' : json_encode($body),
            ]];
        });
        $result = $github->$method('package');
        if ($temporary) {
            check($result === $temporary && is_file($temporary), 'Return the successful streamed archive.');
            $result = file_get_contents($temporary);
            wp_delete_file($temporary);
        }
        check($result === $expected && $github->error === '', "Accept $method with $remaining requests remaining.");
        check(count($discovery_requests) === 1, 'A successful repository operation needs only its own API request.');
    }
}

foreach ([
    [403, ['x-ratelimit-remaining' => '0'], 'API rate limit exceeded', true],
    [403, ['retry-after' => '60', 'x-ratelimit-remaining' => '20'], '', true],
    [403, [], 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.', true],
    [403, [], 'API rate limit exceeded for user ID.', true],
    [429, [], '', true],
    [401, [], 'Bad credentials', false],
    [403, [], 'Resource not accessible by personal access token', false],
    [403, ['x-ratelimit-remaining' => '10'], 'Forbidden', false],
] as [$code, $headers, $message, $limited]) {
    foreach (['getRemoteTag', 'downloadRepoZipToTempFile'] as $method) {
        // WordPress streams error bodies too; cover both headers and the small JSON error file.
        unset($admin_transients[$noticeKey]);
        $github = $newGithub();
        $temporary = '';
        discoveryReset(function ($url, $args) use ($code, $headers, $message, &$temporary) {
            $body = json_encode(['message' => $message]);
            if (!empty($args['stream'])) {
                $temporary = $args['filename'];
                file_put_contents($temporary, $body);
                $body = '';
            }
            return ['raw_response' => ['code' => $code, 'headers' => $headers, 'body' => $body]];
        });
        check($github->$method('package') === false, 'Failed requests never return repository metadata or an archive.');
        check(($github->error === 'Too many requests') === $limited, 'Report throttling separately from authentication failures.');
        check(!empty($admin_transients[$noticeKey]) === !$limited, 'Only rejected credentials create a token notice.');
        check(count($discovery_requests) === 1, 'Failed requests do not trigger immediate retries or quota probes.');
        check(!$temporary || !is_file($temporary), 'Delete the streamed error response.');
    }
}

foreach ([0, 1, 2, null] as $remaining) {
    $github = $newGithub();
    discoveryReset(fn() => ['resources' => ['core' => ['remaining' => $remaining, 'limit' => 5000, 'reset' => time() + 60]]]);
    check($github->isRateLimitReached() === ($remaining === 0), 'Explicit quota queries report exhaustion only at zero remaining.');
}
$admin_transients = $savedRateLimitTransients;
$discovery_http = $savedRateLimitHttp;
echo 'Passed ' . ($checks - $beforeGithubRateLimits) . " GitHub rate-limit checks.\n";

<?php
// Offline token metadata and toolbar regressions; loaded by discovery.php.

use RRZE\Updater\{AdminIntegration, Config, Controller, Settings, TokenNotice, TokenStatus};
use RRZE\Updater\Core\{GithubConnector, GitlabConnector};
use RRZE\Updater\ListTable\ConnListTable;

/**
 * Supplies the super-admin identity independently of delegated capabilities.
 *
 * @return bool Fixture super-admin flag.
 */
function is_super_admin(): bool { return $GLOBALS['token_super_admin'] ?? false; }

/**
 * Formats dates deterministically in UTC for these offline checks.
 *
 * @param string $format Date format.
 * @param int $timestamp Unix timestamp.
 * @return string Formatted date.
 */
function wp_date($format, $timestamp): string { return gmdate($format, $timestamp); }

/**
 * Adds query parameters to an existing fixture URL.
 *
 * @param array $args New query parameters.
 * @param string $url Original URL.
 * @return string Updated URL.
 */
function add_query_arg(array $args, string $url): string
{
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query);
    return $parts[0] . '?' . http_build_query(array_merge($query, $args));
}

/** Collects toolbar nodes without requiring a rendered admin page. */
class TokenToolbarFixture
{
    /** @var array Nodes keyed by ID. */
    public array $nodes = [];

    /**
     * Stores a node in the same shape accepted by WP_Admin_Bar.
     *
     * @param array $node Toolbar node.
     * @return void
     */
    public function add_node(array $node): void { $this->nodes[$node['id']] = $node; }
}

/**
 * Creates an isolated token configuration with no repository requirement.
 *
 * @param string $provider Provider name.
 * @return Settings Network settings containing one connector.
 */
function tokenFixture(string $provider = 'github'): Settings
{
    $GLOBALS['storage'][TokenStatus::OPTION] = [];
    $GLOBALS['admin_transients'][(new Config())->getInvalidTokenTransient()] = [];
    $data = ['id' => 'token-service', 'owner' => 'team', 'token' => 'do-not-expose-this-token'];
    $connector = $provider === 'github' ? GithubConnector::createFromArray($data)
        : GitlabConnector::createFromArray($data + ['host' => 'gitlab.example.test', 'apiUri' => '/gitlab/api/v4/projects/']);
    $settings = new Settings();
    $settings->connectors = [$connector];
    $settings->plugins = $settings->themes = [];
    return $settings;
}

/**
 * Makes a stored result due for another metadata request.
 *
 * @return void
 */
function tokenCheckDue(): void
{
    $GLOBALS['storage'][TokenStatus::OPTION]['token-service']['checked_at'] = time() - 13 * HOUR_IN_SECONDS;
}

$beforeTokens = $checks;
$tokenGlobalNames = ['storage', 'admin_transients', 'wp_filter', 'multisite', 'bundle_caps', 'bundle_network',
    'fixture_blog_id', 'fixture_main_sites', 'cron_events', 'discovery_http', 'discovery_requests'];
$savedTokenGlobals = [];
foreach ($tokenGlobalNames as $name) {
    $savedTokenGlobals[$name] = $GLOBALS[$name] ?? null;
}
$multisite = true;
$fail_save = false;

foreach (['github', 'gitlab'] as $provider) {
    foreach ([-2, 5, 60] as $days) {
        $settings = tokenFixture($provider);
        $expiry = gmdate($provider === 'github' ? 'Y-m-d H:i:s \U\T\C' : 'Y-m-d', time() + $days * DAY_IN_SECONDS);
        discoveryReset(
            /**
             * Returns provider metadata while checking credential transport.
             *
             * @param string $url Request URL.
             * @param array $args Request options.
             * @return array Fake HTTP response.
             */
            function ($url, $args) use ($provider, $expiry) {
                check(!str_contains($url, 'do-not-expose-this-token'), 'Tokens never enter metadata URLs.');
                check($args['redirection'] === 0 && $args['timeout'] === 5, 'Metadata checks have a bounded timeout and never redirect credentials.');
                check(!isset($args['sslverify']) || $args['sslverify'], 'Metadata checks retain TLS verification.');
                if ($provider === 'github') {
                    check($url === 'https://api.github.com/user', 'GitHub checks authenticate even when no repositories are configured.');
                    check($args['headers']['Authorization'] === 'Bearer do-not-expose-this-token', 'GitHub authenticates through a header.');
                    return ['raw_response' => ['code' => 200, 'headers' => ['github-authentication-token-expiration' => $expiry], 'body' => '{"id":1}']];
                }
                check($url === 'https://gitlab.example.test/gitlab/api/v4/personal_access_tokens/self', 'GitLab preserves custom API subpaths.');
                check($args['headers']['PRIVATE-TOKEN'] === 'do-not-expose-this-token', 'GitLab authenticates through a header.');
                return ['id' => 1, 'active' => true, 'revoked' => false, 'expires_at' => $expiry];
            }
        );
        TokenStatus::refresh($settings);
        $status = TokenStatus::getAll($settings)['token-service'];
        $expected = $days < 0 ? 'expired' : ($days <= 30 ? 'expiring' : 'valid');
        check($status['state'] === $expected, "$provider recognizes $expected.");
        check(TokenStatus::needsAttention($status) === ($days <= 30), 'Only impending/past expiration needs attention.');
        check($status['expires_at'] === strtotime($expiry . ($provider === 'gitlab' ? ' 00:00:00 UTC' : '')), 'Provider timestamps use the correct UTC boundary.');
        check(!str_contains(json_encode($storage[TokenStatus::OPTION]), 'do-not-expose-this-token'), 'Stored metadata contains no token.');
        check(!str_contains(TokenStatus::render($status), 'do-not-expose-this-token'), 'Rendered status contains no token.');
        TokenStatus::refresh($settings);
        TokenStatus::getAll($settings);
        check(count($discovery_requests) === 1, 'Fresh checks and toolbar reads reuse network metadata.');
    }
}

// Cached dates cross warning and expiration boundaries without another HTTP call.
$settings = tokenFixture();
discoveryReset(fn() => ['raw_response' => ['code' => 200, 'headers' => [
    'github-authentication-token-expiration' => gmdate('Y-m-d H:i:s \U\T\C', time() + 60 * DAY_IN_SECONDS),
], 'body' => '{"id":1}']]);
TokenStatus::refresh($settings);
foreach ([30 * DAY_IN_SECONDS => 'expiring', 30 * DAY_IN_SECONDS + 60 => 'valid', -1 => 'expired'] as $offset => $expected) {
    $storage[TokenStatus::OPTION]['token-service']['expires_at'] = time() + $offset;
    check(TokenStatus::getAll($settings)['token-service']['state'] === $expected, 'Cached expiry changes status at the warning/expiration boundary.');
}
check(count($discovery_requests) === 1, 'Date boundaries do not require API calls.');

// Outages, throttling, unavailable self-introspection and malformed metadata.
foreach (['github', 'gitlab'] as $provider) {
    foreach ([
        ['http_error' => 401], ['http_error' => 403], ['http_error' => 404],
        ['http_error' => 429], ['http_error' => 500], new WP_Error('offline', 'Sensitive transport details'),
        ['raw_response' => ['code' => 403, 'headers' => ['x-ratelimit-remaining' => '0'], 'body' => '{}']],
        ['raw_response' => ['code' => 200, 'body' => 'not-json']],
    ] as $response) {
        $settings = tokenFixture($provider);
        discoveryReset(fn() => $response);
        TokenStatus::refresh($settings);
        $status = TokenStatus::getAll($settings)['token-service'];
        check(TokenStatus::needsAttention($status) === (is_array($response) && ($response['http_error'] ?? null) === 401),
            'Only rejected authentication triggers a warning without an expiry date.');
        check($status['state'] !== 'expired', 'Transport and permission failures never claim that a token expired.');
        check(!str_contains(json_encode($storage[TokenStatus::OPTION]), 'Sensitive transport details'), 'Transport details are not persisted.');
    }
}

$settings = tokenFixture('gitlab');
discoveryReset(fn() => ['id' => 1, 'expires_at' => null, 'active' => true]);
TokenStatus::refresh($settings);
check(TokenStatus::getAll($settings)['token-service']['state'] === 'no-expiry', 'Explicit GitLab null indicates no expiry.');
foreach ([[], ['github-authentication-token-expiration' => 'garbage'], ['github-authentication-token-expiration' => '2026-02-30']] as $headers) {
    $settings = tokenFixture();
    discoveryReset(fn() => ['raw_response' => ['code' => 200, 'body' => '{"id":1}', 'headers' => $headers]]);
    TokenStatus::refresh($settings);
    check(TokenStatus::getAll($settings)['token-service']['state'] === 'unknown', 'Missing or malformed GitHub expiry never implies an unlimited lifetime.');
}

$settings = tokenFixture('gitlab');
$knownExpiry = gmdate('Y-m-d', time() + 3 * DAY_IN_SECONDS);
discoveryReset(fn() => ['id' => 1, 'active' => true, 'expires_at' => $knownExpiry]);
TokenStatus::refresh($settings);
foreach ([new WP_Error('offline'), ['http_error' => 403], ['http_error' => 404], ['id' => 1]] as $response) {
    tokenCheckDue();
    discoveryReset(fn() => $response);
    TokenStatus::refresh($settings);
    check(TokenStatus::getAll($settings)['token-service']['state'] === 'expiring', 'Temporary or missing metadata never erases a known expiration warning.');
}
$settings->connectors[0]->host = 'other.example.test';
check(TokenStatus::getAll($settings)['token-service']['state'] === 'pending', 'Changing the API host invalidates cached metadata.');
$settings->connectors[0]->host = 'gitlab.example.test';
$settings->connectors[0]->token = 'replacement-token';
check(TokenStatus::getAll($settings)['token-service']['state'] === 'pending', 'Replacing the token immediately removes the previous warning.');
$settings->connectors[0]->token = '';
TokenStatus::refresh($settings);
check(count($discovery_requests) === 1 && TokenStatus::getAll($settings)['token-service']['state'] === 'missing', 'Empty tokens never cause API requests or warnings.');
$settings->connectors = [];
TokenStatus::refresh($settings);
check(TokenStatus::getAll($settings) === [] && $storage[TokenStatus::OPTION] === [], 'Deleting a connector prunes its metadata.');

// Existing authentication failures participate in toolbar and service warnings.
$settings = tokenFixture();
$connector = $settings->connectors[0];
TokenNotice::record($connector, 401);
check(TokenStatus::getAll($settings)['token-service']['state'] === 'invalid', 'Repository authentication errors are visible before the next metadata check.');
$connector->token = 'replacement-token';
check(TokenStatus::getAll($settings)['token-service']['state'] === 'pending', 'Token replacement also ignores the old rejected-token notice.');
$settings = tokenFixture('gitlab');
TokenNotice::record($settings->connectors[0], 403);
$settings->connectors[0]->host = 'other.example.test';
check(TokenStatus::getAll($settings)['token-service']['state'] === 'pending', 'Changing the GitLab host removes old repository access warnings.');

// Successful self-authentication resolves 401 failures but cannot prove repository permissions.
foreach ([401, 403] as $code) {
    $settings = tokenFixture('gitlab');
    TokenNotice::record($settings->connectors[0], $code);
    // Retain a second notice so the fixture exercises transient writes, not its deletion stub.
    $other = GithubConnector::createFromArray(['id' => 'other', 'owner' => 'other', 'token' => 'other-token']);
    TokenNotice::record($other, 401);
    $settings->connectors[] = $other;
    discoveryReset(fn() => ['id' => 1, 'expires_at' => gmdate('Y-m-d', time() + 60 * DAY_IN_SECONDS)]);
    TokenStatus::refresh($settings);
    check(TokenStatus::getAll($settings)['token-service']['state'] === ($code === 401 ? 'valid' : 'invalid'),
        'Self-authentication clears old 401 failures without hiding repository-specific 403 failures.');
}

foreach (['revoked' => true, 'active' => false] as $field => $value) {
    $settings = tokenFixture('gitlab');
    discoveryReset(fn() => ['id' => 1, $field => $value, 'expires_at' => gmdate('Y-m-d', time() + 60 * DAY_IN_SECONDS)]);
    TokenStatus::refresh($settings);
    check(TokenStatus::getAll($settings)['token-service']['state'] === 'invalid', 'Revoked/inactive tokens warn even before their expiry date.');
}

// Independently verify super-admin identity, delegated capability and multisite scope.
$settings = tokenFixture('gitlab');
$settings->connectors[0]->owner = '<script>alert(1)</script>';
discoveryReset(fn() => ['id' => 1, 'expires_at' => gmdate('Y-m-d', time() + DAY_IN_SECONDS)]);
TokenStatus::refresh($settings);
$admin = new AdminIntegration($settings, new Controller($settings), new Config());
foreach ([false, true] as $multisite) {
    foreach ([false, true] as $token_super_admin) {
        foreach ([false, true] as $authorized) {
            $bundle_caps = ['manage_network_options' => $authorized];
            $toolbar = new TokenToolbarFixture();
            $admin->tokenAdminBarMenu($toolbar);
            $visible = $multisite && $token_super_admin && $authorized;
            check((bool) $toolbar->nodes === $visible, 'Only authorized multisite super admins see token warnings.');
            if ($visible) {
                $root = $toolbar->nodes['rrze-updater-token-warnings'];
                check(!isset($root['parent']), 'The warning is visible in the top-level toolbar.');
                check(str_contains($root['href'], '/wp-admin/network/') && str_contains($root['href'], 'tab=services'), 'Toolbar opens network service settings.');
                $child = $toolbar->nodes['rrze-updater-token-token-service'];
                check(str_contains($child['href'], 'action=edit') && str_contains($child['href'], 'id=token-service'), 'Each warning links to the affected service editor.');
                check(!str_contains($child['title'], '<script>') && str_contains($child['title'], '&lt;script&gt;'), 'Provider labels are escaped.');
            }
        }
    }
}
check(count($discovery_requests) === 1, 'Toolbar rendering never requests provider data.');
$table = (new ReflectionClass(ConnListTable::class))->newInstanceWithoutConstructor();
$status = TokenStatus::getAll($settings)['token-service'];
check(isset($table->get_columns()['token_status']), 'Service overview has a dedicated status column.');
check(str_contains($table->column_default(['token_status' => $status], 'token_status'), 'rrze-updater-token-warning'), 'Service overview renders a visible expiry warning.');
$controller = new Controller($settings);
$dataMethod = new ReflectionMethod($controller, 'getConnectorListData');
$rows = $dataMethod->invoke($controller);
check($rows[0]['token'] === 'Configured' && isset($rows[0]['token_status']), 'Both service routes receive masked tokens and status data.');
check(!str_contains(json_encode($rows), $settings->connectors[0]->token), 'Service list data never contains the credential.');

// Cron events belong to the current network's main site, not a fixed blog ID.
$monitor = new TokenStatus();
$multisite = true;
$bundle_network = 2;
$fixture_main_sites = [1 => 1, 2 => 42];
$cron_events = [];
$fixture_blog_id = 43;
$monitor->schedule();
check(!wp_next_scheduled(TokenStatus::CRON_HOOK), 'Subsites do not create token schedules.');
$fixture_blog_id = 42;
$monitor->schedule();
check(wp_get_schedule(TokenStatus::CRON_HOOK) === 'twicedaily', 'Token checks run twice daily on the current network main site.');
$event = $cron_events[42];
$monitor->schedule();
check($cron_events[42] === $event, 'Repeated bootstrap does not duplicate scheduled checks.');
$fixture_blog_id = 43;
wp_schedule_event(time(), 'twicedaily', TokenStatus::CRON_HOOK);
$monitor->schedule();
check(!wp_next_scheduled(TokenStatus::CRON_HOOK) && $cron_events[42] === $event, 'Stale subsite schedules are removed without touching the main site.');

foreach ($savedTokenGlobals as $name => $value) {
    $GLOBALS[$name] = $value;
}
echo 'Passed ' . ($checks - $beforeTokens) . " token status and toolbar checks.\n";

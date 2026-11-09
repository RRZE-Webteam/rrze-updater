<?php

use RRZE\Updater\Core\{Extension, GithubConnector, TagVersion};

/**
 * Supply complete GitHub responses without touching a repository or installation.
 *
 * @param array $tags Tags in intentionally unreliable provider order.
 * @param array $releases Associated release records.
 * @return GithubConnector Production connector using the offline HTTP fixture.
 */
function githubTagFixture(array $tags, array $releases = []): GithubConnector
{
    discoveryReset(static function ($url) use ($tags, $releases) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $items = str_ends_with(parse_url($url, PHP_URL_PATH), '/tags')
            ? array_map(static fn($name) => ['name' => $name], $tags) : $releases;
        return array_slice($items, (((int) ($query['page'] ?? 1)) - 1) * 100, 100);
    });
    return GithubConnector::createFromArray(['id' => 'tag-fixture', 'owner' => 'owner', 'token' => 'fixture']);
}

/**
 * Render the real release preview for a checked extension.
 *
 * @param Extension $extension Update target with optional release details.
 * @return string Escaped preview markup or an empty string when no update exists.
 */
function githubReleasePreview(Extension $extension): string
{
    ob_start();
    require dirname(__DIR__) . '/includes/views/partials/release-preview.php';
    return ob_get_clean();
}

$beforeGithubTags = $checks;
$savedTagHttp = $discovery_http;
foreach (['v2.3.0', 'V2.3.0', 'release-2.3.0', 'release/2.3.0', '2.3.0+build.8'] as $tag) {
    check(TagVersion::normalize($tag) === '2.3.0', 'Normalize prefixes and build metadata without changing the archive ref.');
}
foreach (['v2.0.0-beta.1', 'release-2.0.0-rc1', '2.0.0-preview.2', '2.0.0-dev'] as $tag) {
    check(TagVersion::isPrerelease($tag), 'Recognize prerelease suffix: ' . $tag);
}
check(!TagVersion::isPrerelease('release-2.0.0') && TagVersion::normalize('spring') === '', 'Prefixes are not prerelease suffixes.');

$release = ['tag_name' => 'release-2.0.0', 'name' => 'Second major release',
    'html_url' => 'https://github.com/owner/repo/releases/tag/release-2.0.0',
    'published_at' => '2026-01-01T12:00:00Z', 'author' => ['login' => 'maintainer', 'html_url' => 'https://github.com/maintainer'],
    'body' => 'Release description', 'body_html' => '<p><strong>Release description</strong></p>'];
$releases = [$release, ['tag_name' => '1.9.9', 'published_at' => '2026-10-01T12:00:00Z'],
    ['tag_name' => '3.0.0', 'prerelease' => true], ['tag_name' => '99.0.0', 'draft' => true]];
$tags = ['v1.0.0', '99.0.0', '1.9.9', 'release-2.0.0', '3.0.0', '4.0.0-rc.1'];
$github = githubTagFixture($tags, $releases);
$github->ignorePrereleases = true;
check($github->getRemoteTag('repo') === 'release-2.0.0', 'Use highest stable version despite prefixes, draft tags, plain-number prereleases and later maintenance releases.');
check($github->remoteRelease['title'] === $release['name'] && $github->remoteRelease['author'] === 'maintainer'
    && $github->remoteRelease['url'] === $release['html_url'] && $github->remoteRelease['body_html'] === $release['body_html'],
    'Selected archive and release preview describe the same exact tag.');
$github->ignorePrereleases = false;
check($github->getRemoteTag('repo') === '4.0.0-rc.1' && $github->remoteRelease === [], 'Opting into prereleases includes tag-only candidates and clears old release details.');

$github = githubTagFixture(['v1', '2.0.0-rc.1'], [['tag_name' => 'v1', 'prerelease' => true]]);
$github->ignorePrereleases = true;
check($github->getRemoteTag('repo') === false, 'No stable candidates means no update, never an unfiltered fallback.');
$github = githubTagFixture(array_merge(array_fill(0, 100, 'v1'), ['release-5.0']), array_merge(array_fill(0, 100, ['tag_name' => 'v1']), [['tag_name' => 'release-5.0', 'name' => 'Page two']]));
check($github->getRemoteTag('repo') === 'release-5.0' && $github->remoteRelease['title'] === 'Page two'
    && count($discovery_requests) === 4, 'Read tags and releases beyond page one.');
$github = githubTagFixture(['spring', 'summer'], [['tag_name' => 'summer', 'published_at' => '2026-06-01T00:00:00Z'], ['tag_name' => 'spring', 'published_at' => '2026-03-01T00:00:00Z']]);
check($github->getRemoteTag('repo') === 'summer', 'Use publication dates when no comparable version tags exist.');
$github = githubTagFixture(['custom-a', 'custom-b']);
check($github->getRemoteTag('repo') === 'custom-a', 'Arbitrary tags without releases retain provider order.');
$github = githubTagFixture(array_fill(0, 1000, 'v1'));
check($github->getRemoteTag('repo') === false && $github->error !== '', 'Refuse truncated selection after the bounded pagination limit.');
foreach ([false, true] as $ignorePrereleases) {
    $github = githubTagFixture(['v1']);
    $github->ignorePrereleases = $ignorePrereleases;
    discoveryReset(static fn($url) => str_contains($url, '/tags?') ? [['name' => 'v1']] : ['http_error' => 429]);
    check($github->getRemoteTag('repo') === false && $github->remoteRelease === [] && $github->error !== '',
        'Failed release lookup does not select from partial metadata.');
}

$github = githubTagFixture(['v1.9', 'release-2.0.0'], [$release]);
$extension = new Extension();
$extension->updateFromArray(['repository' => 'repo', 'updates' => 'tags', 'localVersion' => 'v1.9', 'ignorePrereleases' => true]);
$extension->connector = $github;
// The update check also reads version files. Those optional probes are unrelated
// to target selection and can simply return no file in this fixture.
$tagHandler = $discovery_http;
discoveryReset(static fn($url, $args) => str_contains($url, '/contents/') ? [] : $tagHandler($url, $args));
$extension->checkForUpdates();
$copy = new Extension();
$copy->updateFromArray($extension->asArray());
check($copy->ignorePrereleases && $copy->remoteRelease === $extension->remoteRelease && $copy->remoteVersion === 'release-2.0.0', 'Persist filter and exact release details alongside the checked ref.');
$preview = githubReleasePreview($extension);
check(str_contains($preview, 'Second major release') && str_contains($preview, 'maintainer')
    && str_contains($preview, $release['html_url']) && str_contains($preview, 'Release description')
    && str_contains($preview, '2026-01-01T12:00:00+00:00'), 'An available update shows title, release link, author, publication date and description.');
foreach (['v2.0.0', 'v3.0.0'] as $local) {
    $extension->localVersion = $local;
    check(!$extension->hasUpdate() && githubReleasePreview($extension) === '', 'Equivalent versions and downgrades have neither update offer nor preview.');
}
$extension->localVersion = '';
check($extension->hasUpdate(), 'Unknown installed refs remain eligible for an update.');
$extension->remoteRelease['tag'] = 'unrelated';
check(!str_contains(githubReleasePreview($extension), 'Second major release'), 'Never show stale details for another target.');
$extension->remoteRelease = $copy->remoteRelease;
$extension->remoteRelease['title'] = '<script>bad()</script>';
$extension->remoteRelease['body_html'] = '';
$extension->remoteRelease['body'] = '<img src=x onerror=bad()>';
$preview = githubReleasePreview($extension);
check(!str_contains($preview, '<script>') && !str_contains($preview, '<img') && str_contains($preview, '&lt;img'), 'Escape titles and plain-text descriptions.');
$extension->lastError = 'API failure';
check(!$extension->hasUpdate() && githubReleasePreview($extension) === '', 'API failures suppress update offers and previews.');
$extension->lastError = '';
$extension->updates = 'commits';
check($extension->hasUpdate() && githubReleasePreview($extension) === '', 'Commit mode keeps ref-based behavior without release details.');
$discovery_http = $savedTagHttp;
echo 'Passed ' . ($checks - $beforeGithubTags) . " GitHub tag and release preview checks.\n";

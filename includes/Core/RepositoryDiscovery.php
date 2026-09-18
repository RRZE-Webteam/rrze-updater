<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;
use WP_Error;

/** Read-only, owner-scoped provider API. Credentials and upstream URLs never leave PHP. */
class RepositoryDiscovery
{
    public function __construct(private Connector $connector) {}

    public function repositories(int $page = 1, bool $refresh = false): array|WP_Error
    {
        $owner = rawurlencode($this->connector->owner);
        if ($this->connector->getType() === 'github') {
            $account = $this->request('/users/' . $owner, [], $refresh);
            if (is_wp_error($account)) {
                return $account;
            }
            // The public users endpoint omits private repositories. Authenticated
            // user listings include collaborations, so filter every result by owner.
            $path = ($account['type'] ?? '') === 'Organization' ? '/orgs/' . $owner . '/repos' : '/user/repos';
            $query = ['per_page' => 100, 'page' => $page, 'sort' => 'updated', 'direction' => 'desc'];
        } else {
            $group = $this->request('/groups/' . $owner, [], $refresh);
            if (is_wp_error($group) && $group->get_error_code() !== 'discovery_not_found') {
                return $group;
            }
            if (is_wp_error($group)) {
                $users = $this->request('/users', ['username' => $this->connector->owner], $refresh);
                if (is_wp_error($users)) {
                    return $users;
                }
                $user = array_values(array_filter($users, fn($user) => ($user['username'] ?? '') === $this->connector->owner))[0] ?? null;
                if (!$user) {
                    return new WP_Error('discovery_owner', __('The configured owner could not be found or accessed.', 'rrze-updater'));
                }
                $path = '/users/' . (int) $user['id'] . '/projects';
            } else {
                $path = '/groups/' . (int) $group['id'] . '/projects';
            }
            $query = ['per_page' => 100, 'page' => $page, 'order_by' => 'updated_at', 'sort' => 'desc', 'include_subgroups' => 'false', 'with_shared' => 'false'];
        }
        $data = $this->request($path, $query, $refresh);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!array_is_list($data)) {
            return $this->invalidResponse();
        }
        $items = [];
        foreach ($data as $repo) {
            if (!is_array($repo) || !is_string($repo[$this->isGithub() ? 'name' : 'path'] ?? null)) {
                return $this->invalidResponse();
            }
            if (!$this->matchesOwner($repo)) {
                continue;
            }
            $items[] = $this->normalize($repo);
        }
        return ['items' => $items, 'has_more' => count($data) === 100, 'page' => $page];
    }

    public function repository(string $repository): array|WP_Error
    {
        $data = $this->request($this->repositoryPath($repository));
        if (is_wp_error($data)) {
            return $data;
        }
        if (!$this->matchesOwner($data) || ($data[$this->isGithub() ? 'name' : 'path'] ?? '') !== $repository) {
            return new WP_Error('discovery_scope', __('The repository does not belong to the configured owner.', 'rrze-updater'));
        }
        return $this->normalize($data);
    }

    public function branches(string $repository, int $page = 1): array|WP_Error
    {
        $data = $this->request($this->repositoryPath($repository) . ($this->isGithub() ? '/branches' : '/repository/branches'), ['per_page' => 100, 'page' => $page]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!array_is_list($data) || count(array_filter($data, fn($branch) => is_array($branch) && is_string($branch['name'] ?? null))) !== count($data)) {
            return $this->invalidResponse();
        }
        return ['items' => array_values(array_filter(array_column($data, 'name'), 'is_string')), 'has_more' => count($data) === 100, 'page' => $page];
    }

    public function commit(string $repository, string $branch): string|WP_Error
    {
        // Resolve afresh for each review. All file checks and installation then
        // use this immutable commit, even if the branch moves during the review.
        $data = $this->request($this->repositoryPath($repository) . ($this->isGithub() ? '/branches/' : '/repository/branches/') . rawurlencode($branch), [], true);
        if (is_wp_error($data)) {
            return $data;
        }
        $sha = $data['commit'][$this->isGithub() ? 'sha' : 'id'] ?? '';
        return is_string($sha) && preg_match('/\A[0-9a-f]{40,64}\z/i', $sha)
            ? $sha : new WP_Error('discovery_commit', __('Could not resolve the selected branch to a commit.', 'rrze-updater'));
    }

    public function rootFiles(string $repository, string $ref): array|WP_Error
    {
        $files = [];
        for ($page = 1; $page <= 10; $page++) {
            $data = $this->request($this->repositoryPath($repository) . ($this->isGithub() ? '/contents' : '/repository/tree'), ['ref' => $ref, 'per_page' => 100, 'page' => $page]);
            if (is_wp_error($data)) {
                return $data;
            }
            if (!array_is_list($data)) {
                return $this->invalidResponse();
            }
            foreach ($data as $file) {
                if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['type'] ?? null)) {
                    return $this->invalidResponse();
                }
                if (is_array($file) && in_array($file['type'] ?? '', ['file', 'blob'], true)) {
                    $files[] = $file['name'];
                }
            }
            if ($this->isGithub() ? count($data) < 1000 : count($data) < 100) {
                return $files;
            }
            if ($this->isGithub()) {
                break; // Contents API truncates at 1,000; never call a truncated scan complete.
            }
        }
        return new WP_Error('discovery_tree_limit', __('This repository has too many root entries to inspect automatically.', 'rrze-updater'));
    }

    public function file(string $repository, string $ref, string $path): string|WP_Error
    {
        $endpoint = $this->repositoryPath($repository) . ($this->isGithub()
            ? '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            : '/repository/files/' . rawurlencode($path));
        $data = $this->request($endpoint, ['ref' => $ref]);
        if (is_wp_error($data)) {
            return $data;
        }
        $content = ($data['encoding'] ?? '') === 'base64' && is_string($data['content'] ?? null)
            ? base64_decode(str_replace(["\n", "\r"], '', $data['content']), true) : false;
        return is_string($content) ? $content : new WP_Error('discovery_file', __('Could not read a repository file. It may exceed the API file-size limit.', 'rrze-updater'));
    }

    private function isGithub(): bool { return $this->connector->getType() === 'github'; }

    private function invalidResponse(): WP_Error
    {
        return new WP_Error('discovery_response', __('The repository service returned an invalid response. Try again.', 'rrze-updater'));
    }

    private function repositoryPath(string $repository): string
    {
        return $this->isGithub()
            ? '/repos/' . rawurlencode($this->connector->owner) . '/' . rawurlencode($repository)
            : '/projects/' . rawurlencode($this->connector->owner . '/' . $repository);
    }

    private function matchesOwner(array $repo): bool
    {
        return $this->isGithub()
            ? strcasecmp($repo['owner']['login'] ?? '', $this->connector->owner) === 0
            : ($repo['namespace']['full_path'] ?? '') === $this->connector->owner;
    }

    private function normalize(array $repo): array
    {
        return [
            'id' => $repo[$this->isGithub() ? 'name' : 'path'], 'repository' => $repo[$this->isGithub() ? 'name' : 'path'],
            'description' => (string) ($repo['description'] ?? ''),
            'updated_at' => (string) ($repo['updated_at'] ?? $repo['last_activity_at'] ?? ''),
            'branch' => (string) ($repo['default_branch'] ?? ''),
            'visibility' => $this->isGithub() ? (!empty($repo['private']) ? 'private' : 'public') : ($repo['visibility'] ?? 'private'),
            'archived' => !empty($repo['archived']),
        ];
    }

    protected function request(string $path, array $query = [], bool $refresh = false): array|WP_Error
    {
        if (!is_string($this->connector->token) || trim($this->connector->token) === '') {
            return new WP_Error('discovery_token', __('Configure an access token for this connector first.', 'rrze-updater'));
        }
        $base = $this->isGithub() ? 'https://' . (new Config())->getGithubApiHost()
            : 'https://' . $this->connector->host . preg_replace('~/projects/?$~', '', rtrim($this->connector->apiUri, '/'));
        $url = rtrim($base, '/') . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        // Include network and credentials so switching/revoking tokens cannot reuse
        // another connector's private repository cache. Never persist credentials.
        $key = 'rrze_discovery_' . hash('sha256', get_current_network_id() . '|' . $this->connector->token . '|' . $url);
        $cached = $refresh ? false : get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $headers = $this->isGithub()
            ? ['Authorization' => 'Bearer ' . $this->connector->token, 'Accept' => 'application/vnd.github+json']
            : ['PRIVATE-TOKEN' => $this->connector->token];
        $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 3 * 1024 * 1024]);
        if (is_wp_error($response)) {
            return new WP_Error('discovery_network', __('Could not contact the repository service. Try again.', 'rrze-updater'));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error($status === 404 ? 'discovery_not_found' : 'discovery_access', match ($status) {
                404 => __('The repository resource could not be found or accessed.', 'rrze-updater'),
                401, 403 => __('The service denied access. Check the token permissions or API rate limit.', 'rrze-updater'),
                429 => __('The service rate limit was reached. Wait before trying again.', 'rrze-updater'),
                default => __('Could not retrieve repository data. Check the connector and try again.', 'rrze-updater'),
            });
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('discovery_response', __('The repository service returned an invalid or oversized response.', 'rrze-updater'));
        }
        set_transient($key, $data, 60);
        return $data;
    }
}

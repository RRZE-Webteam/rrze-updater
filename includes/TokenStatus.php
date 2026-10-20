<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use DateTimeImmutable;
use DateTimeZone;
use RRZE\Updater\Core\Connector;

/** Checks token metadata independently of repository updates and caches it per network. */
class TokenStatus
{
    public const OPTION = 'rrze_updater_token_status';
    public const CRON_HOOK = 'rrze_updater_check_tokens';
    public const WARNING_DAYS = 30;

    /**
     * Registers background checks without making HTTP requests during bootstrap.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [$this, 'schedule']);
        add_action(self::CRON_HOOK, [$this, 'run']);
    }

    /**
     * Schedules checks only on the current network's main site.
     *
     * @return void
     */
    public function schedule(): void
    {
        if (get_current_blog_id() !== (new Config())->getCronMainBlogId()) {
            if (wp_next_scheduled(self::CRON_HOOK)) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'twicedaily', self::CRON_HOOK);
        }
    }

    /**
     * Refreshes tokens in the network that owns the executing cron job.
     *
     * @return void
     */
    public function run(): void
    {
        if (get_current_blog_id() === (new Config())->getCronMainBlogId()) {
            self::refresh(new Settings());
        }
    }

    /**
     * Refreshes due checks, retaining known expiration dates during service failures.
     *
     * Credentials and provider response bodies are never stored here. A separate
     * option keeps background results out of concurrent edits to plugin settings.
     *
     * @param Settings $settings Current network settings.
     * @return void
     */
    public static function refresh(Settings $settings): void
    {
        $stored = self::getStored();
        $currentIds = array_column($settings->connectors, 'id');
        $pruned = array_intersect_key($stored, array_flip($currentIds));
        if ($stored !== $pruned) {
            update_site_option(self::OPTION, $pruned);
        }

        foreach ($settings->connectors as $connector) {
            if (empty($connector->token) || empty($connector->id)) {
                continue;
            }

            $previous = self::getCached($connector);
            $interval = ($previous['state'] ?? '') === 'unavailable' ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS;
            if (!empty($previous['checked_at']) && $previous['checked_at'] + $interval > time()) {
                continue;
            }

            $result = self::probe($connector);
            if ($result['expires_at'] === null && !$result['no_expiry']) {
                $result['expires_at'] = $previous['expires_at'] ?? null;
            }
            // Missing metadata must not erase an already known expiry or rejection.
            if (in_array($result['state'], ['unavailable', 'unknown'], true)) {
                $result['expires_at'] = $previous['expires_at'] ?? null;
                $result['no_expiry'] = $previous['no_expiry'] ?? false;
                if (($previous['state'] ?? '') === 'invalid') {
                    $result['state'] = 'invalid';
                }
            }
            $result['fingerprint'] = self::fingerprint($connector);
            $result['checked_at'] = time();

            // Re-read before each write so other connectors' results are preserved.
            $stored = self::getStored();
            $stored[$connector->id] = $result;
            update_site_option(self::OPTION, $stored);
            if ($result['state'] === 'valid') {
                TokenNotice::clearAuthenticationFailure($connector);
            }
        }
    }

    /**
     * Returns presentation states using cached data only.
     *
     * Expiration is evaluated at read time, so a cached valid token becomes expired
     * even when the next API request fails or WP-Cron has not run yet.
     *
     * @param Settings $settings Current network settings.
     * @return array<string, array> Status indexed by connector ID.
     */
    public static function getAll(Settings $settings): array
    {
        $notices = TokenNotice::getActive($settings);
        $statuses = [];
        foreach ($settings->connectors as $connector) {
            $status = self::getCached($connector);
            $state = $status['state'] ?? 'pending';
            $expires = $status['expires_at'] ?? null;
            if (empty($connector->token)) {
                $state = 'missing';
            } elseif ($expires && $expires <= time()) {
                $state = 'expired';
            } elseif ($state === 'invalid' || isset($notices[$connector->id])) {
                $state = 'invalid';
            } elseif ($expires && $expires <= time() + self::WARNING_DAYS * DAY_IN_SECONDS) {
                $state = 'expiring';
            } elseif ($state === 'valid' && !$expires) {
                $state = !empty($status['no_expiry']) ? 'no-expiry' : 'unknown';
            }
            $status['state'] = $state;
            $status['http_code'] = $notices[$connector->id]['http-code'] ?? ($status['http_code'] ?? 0);
            $statuses[$connector->id] = $status;
        }
        return $statuses;
    }

    /**
     * Returns whether a status requires an administrator's attention.
     *
     * @param array $status Cached presentation state.
     * @return bool Whether to show a toolbar warning.
     */
    public static function needsAttention(array $status): bool
    {
        return in_array($status['state'], ['expiring', 'expired', 'invalid'], true);
    }

    /**
     * Formats a status as plain, translatable text without exposing credentials.
     *
     * @param array $status Cached presentation state.
     * @return string Human-readable status.
     */
    public static function label(array $status): string
    {
        $date = !empty($status['expires_at']) ? wp_date('d.m.Y H:i T', $status['expires_at']) : '';
        return match ($status['state']) {
            'expired' => sprintf(
                /* translators: %s: Localized expiration date and time. */
                __('Token expired on %s. Replace the token.', 'rrze-updater'), $date
            ),
            'expiring' => sprintf(
                /* translators: %s: Localized expiration date and time. */
                __('Token expires on %s. Renew the token soon.', 'rrze-updater'), $date
            ),
            'invalid' => __('Token rejected or access denied. Check the token and its permissions.', 'rrze-updater'),
            'valid' => sprintf(
                /* translators: %s: Localized expiration date and time. */
                __('Token expires on %s.', 'rrze-updater'), $date
            ),
            'no-expiry' => __('No expiration date.', 'rrze-updater'),
            'unknown' => __('Expiration date unknown. Check it in the service.', 'rrze-updater'),
            'unavailable' => __('Token check temporarily unavailable. Please try again later.', 'rrze-updater'),
            'missing' => __('No token configured.', 'rrze-updater'),
            default => __('Token has not been checked yet.', 'rrze-updater'),
        };
    }

    /**
     * Renders an accessible status with text as well as a colored indicator.
     *
     * @param array $status Cached presentation state.
     * @return string Escaped status markup.
     */
    public static function render(array $status): string
    {
        $class = self::needsAttention($status)
            ? ($status['state'] === 'expiring' ? 'warning' : 'error')
            : 'info';
        return sprintf(
            '<span class="rrze-updater-token-status rrze-updater-token-%1$s">%2$s</span>',
            esc_attr($class),
            esc_html(self::label($status))
        );
    }

    /**
     * Retrieves metadata through a read-only, bounded, non-redirecting API request.
     *
     * Metadata endpoints can be unavailable for a token type or scope. Only HTTP
     * 401 proves rejected authentication; 403/404 must not imply expiration.
     *
     * @param Connector $connector Service and credentials to inspect.
     * @return array Normalized status without token or response body.
     */
    private static function probe(Connector $connector): array
    {
        $result = ['state' => 'unknown', 'expires_at' => null, 'no_expiry' => false, 'http_code' => 0];
        $headers = ['User-Agent' => (new Config())->getUserAgent()];
        if ($connector->getType() === 'github') {
            $url = 'https://' . (new Config())->getGithubApiHost() . '/user';
            $headers['Authorization'] = 'Bearer ' . $connector->token;
            $headers['Accept'] = 'application/vnd.github+json';
        } elseif ($connector->getType() === 'gitlab' && !empty($connector->host)) {
            $api = rtrim((string) $connector->apiUri, '/');
            if (!str_ends_with($api, '/projects')) {
                return $result;
            }
            $url = 'https://' . $connector->host . substr($api, 0, -strlen('/projects')) . '/personal_access_tokens/self';
            $headers['PRIVATE-TOKEN'] = $connector->token;
        } else {
            return $result;
        }

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 65536,
        ]);
        if (is_wp_error($response)) {
            $result['state'] = 'unavailable';
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $result['http_code'] = $code;
        if ($code !== 200) {
            $rateLimited = $code === 429 || ($code === 403 && (
                wp_remote_retrieve_header($response, 'x-ratelimit-remaining') === '0'
                || wp_remote_retrieve_header($response, 'retry-after') !== ''
                || str_contains(strtolower(wp_remote_retrieve_body($response)), 'rate limit')
            ));
            $result['state'] = $rateLimited ? 'unavailable'
                : ($code === 401 ? 'invalid' : ($code === 403 || $code === 404 ? 'unknown' : 'unavailable'));
            return $result;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['id'])) {
            return $result;
        }
        $result['state'] = 'valid';
        if ($connector->getType() === 'github') {
            $expires = wp_remote_retrieve_header($response, 'github-authentication-token-expiration');
            $result['expires_at'] = self::parseExpiry($expires);
        } else {
            if (!empty($body['revoked']) || (isset($body['active']) && $body['active'] === false)) {
                $result['state'] = 'invalid';
            }
            $result['no_expiry'] = array_key_exists('expires_at', $body) && $body['expires_at'] === null;
            $result['expires_at'] = self::parseExpiry($body['expires_at'] ?? '');
        }
        return $result;
    }

    /**
     * Parses provider timestamps strictly; date-only GitLab expirations are midnight UTC.
     *
     * @param mixed $value Provider expiration value.
     * @return int|null Unix timestamp, or null for missing/malformed metadata.
     */
    private static function parseExpiry(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        foreach (['!Y-m-d', '!Y-m-d H:i:s T', '!Y-m-d\TH:i:sP'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                return $date->getTimestamp();
            }
        }
        return null;
    }

    /**
     * Reads persisted metadata for the current network.
     *
     * @return array Connector status records.
     */
    private static function getStored(): array
    {
        $stored = get_site_option(self::OPTION);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Reads a result only when it still matches the service and token.
     *
     * @param Connector $connector Currently configured service.
     * @return array Matching metadata, or an empty array.
     */
    private static function getCached(Connector $connector): array
    {
        $status = self::getStored()[$connector->id] ?? [];
        return !empty($status['fingerprint']) && hash_equals($status['fingerprint'], self::fingerprint($connector))
            ? $status : [];
    }

    /**
     * Binds cached metadata to the credential and its configured API destination.
     *
     * @param Connector $connector Currently configured service.
     * @return string Non-reversible, salted fingerprint.
     */
    private static function fingerprint(Connector $connector): string
    {
        return wp_hash(wp_json_encode([
            $connector->getType(), $connector->host ?? (new Config())->getGithubApiHost(),
            $connector->apiUri ?? '', $connector->owner, $connector->token,
        ]));
    }
}

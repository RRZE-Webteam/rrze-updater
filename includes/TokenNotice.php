<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\Connector;

class TokenNotice
{
    /**
     * Stores a rejected token notice without persisting the token itself.
     *
     * @param Connector $connector Connector whose token was rejected.
     * @param int       $httpCode  HTTP response status code.
     * @return void
     */
    public static function record(Connector $connector, int $httpCode): void
    {
        if (empty($connector->id) || empty($connector->token)) {
            return;
        }

        $notices = self::getStoredNotices();
        $notices[$connector->id] = [
            'token-fingerprint' => self::getTokenFingerprint((string) $connector->token),
            'connector-fingerprint' => self::getConnectorFingerprint($connector),
            'service' => (string) ($connector->display ?? ''),
            'owner' => (string) ($connector->owner ?? ''),
            'http-code' => $httpCode
        ];

        set_site_transient((new Config())->getInvalidTokenTransient(), $notices);
    }

    /**
     * Returns notices that still belong to the currently configured tokens.
     *
     * @param Settings $settings Current plugin settings.
     * @return array Active token notices indexed by connector ID.
     */
    public static function getActive(Settings $settings): array
    {
        $storedNotices = self::getStoredNotices();
        $activeNotices = [];

        foreach ($storedNotices as $connectorId => $notice) {
            $connector = $settings->getConnectorById($connectorId);
            if (!$connector || empty($connector->token)) {
                continue;
            }

            $fingerprint = (string) ($notice['token-fingerprint'] ?? '');
            if ($fingerprint === '' || !hash_equals($fingerprint, self::getTokenFingerprint((string) $connector->token))) {
                continue;
            }
            if (isset($notice['connector-fingerprint'])
                && !hash_equals($notice['connector-fingerprint'], self::getConnectorFingerprint($connector))) {
                continue;
            }
            if (($notice['service'] ?? '') !== (string) $connector->display
                || ($notice['owner'] ?? '') !== (string) $connector->owner) {
                continue;
            }

            $activeNotices[$connectorId] = $notice;
        }

        if ($activeNotices !== $storedNotices) {
            self::storeNotices($activeNotices);
        }

        return $activeNotices;
    }

    /**
     * Clears a matching authentication failure after a successful token check.
     *
     * Repository-specific 403 permission failures remain visible: authenticating
     * successfully does not prove access to every managed repository.
     *
     * @param Connector $connector Successfully authenticated connector.
     * @return void
     */
    public static function clearAuthenticationFailure(Connector $connector): void
    {
        $notices = self::getStoredNotices();
        $notice = $notices[$connector->id] ?? [];
        if (($notice['http-code'] ?? 0) === 401
            && hash_equals((string) ($notice['token-fingerprint'] ?? ''), self::getTokenFingerprint((string) $connector->token))) {
            unset($notices[$connector->id]);
            self::storeNotices($notices);
        }
    }

    /**
     * Reads the network's rejected-token notices.
     *
     * @return array Stored notices.
     */
    private static function getStoredNotices(): array
    {
        $notices = get_site_transient((new Config())->getInvalidTokenTransient());

        return is_array($notices) ? $notices : [];
    }

    /**
     * Persists notices or removes their transient when none remain.
     *
     * @param array $notices Current notices.
     * @return void
     */
    private static function storeNotices(array $notices): void
    {
        $transient = (new Config())->getInvalidTokenTransient();

        if (empty($notices)) {
            delete_site_transient($transient);
            return;
        }

        set_site_transient($transient, $notices);
    }

    /**
     * Hashes a token without persisting the credential in a notice.
     *
     * @param string $token Configured token.
     * @return string Salted token fingerprint.
     */
    private static function getTokenFingerprint(string $token): string
    {
        return wp_hash($token);
    }

    /**
     * Binds a rejection to the service configuration that produced it.
     *
     * @param Connector $connector Configured service.
     * @return string Salted service fingerprint.
     */
    private static function getConnectorFingerprint(Connector $connector): string
    {
        return wp_hash(wp_json_encode([
            $connector->getType(), $connector->host ?? '', $connector->apiUri ?? '', $connector->owner,
        ]));
    }
}

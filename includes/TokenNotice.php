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

            $activeNotices[$connectorId] = $notice;
        }

        self::storeNotices($activeNotices);

        return $activeNotices;
    }

    private static function getStoredNotices(): array
    {
        $notices = get_site_transient((new Config())->getInvalidTokenTransient());

        return is_array($notices) ? $notices : [];
    }

    private static function storeNotices(array $notices): void
    {
        $transient = (new Config())->getInvalidTokenTransient();

        if (empty($notices)) {
            delete_site_transient($transient);
            return;
        }

        set_site_transient($transient, $notices);
    }

    private static function getTokenFingerprint(string $token): string
    {
        return wp_hash($token);
    }
}

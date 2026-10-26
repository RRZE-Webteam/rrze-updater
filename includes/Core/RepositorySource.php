<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use RRZE\Updater\Config;

/** Identifies repository contents without retaining credentials or display labels. */
final class RepositorySource
{
    /**
     * Calculate the effective source identity for a managed extension.
     *
     * @param Extension $extension Extension with its effective connector attached.
     * @return string SHA-256 identity of the provider, repository and update policy.
     */
    public static function fingerprint(Extension $extension): string
    {
        $connector = $extension->connector;
        $type = $connector instanceof Connector ? $connector->getType() : '';
        $config = new Config();
        $host = $type === 'github' ? $config->getGithubWebHost() : (string) ($connector->host ?? '');
        $api = $type === 'github' ? $config->getGithubApiHost() : (string) ($connector->apiUri ?? '');
        $owner = (string) ($connector->owner ?? '');
        $repository = (string) $extension->repository;

        return hash('sha256', json_encode([
            (string) $extension->connectorId, $type, strtolower($host), trim($api, '/'),
            $type === 'github' ? strtolower($owner) : $owner,
            $type === 'github' ? strtolower($repository) : $repository,
            (string) $extension->branch, (string) $extension->updates,
            (string) $extension->installationFolder,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve a saved source against the connector snapshot from the same read.
     *
     * @param array<string, mixed> $record Serialized plugin or theme.
     * @param array<string, array<string, mixed>> $connectors Connectors indexed by ID.
     * @return string Effective source fingerprint, including a missing connector.
     */
    public static function fingerprintRecord(array $record, array $connectors): string
    {
        $extension = new Extension();
        $extension->updateFromArray($record);
        $connector = $connectors[$record['connectorId'] ?? ''] ?? null;
        $extension->connector = $connector ? Connector::createFromArray($connector) : false;
        return self::fingerprint($extension);
    }
}

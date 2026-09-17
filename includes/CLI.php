<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\RepositoryManager;
use WP_CLI;
use WP_Error;

/** WP-CLI adapter; repository operations are independent of terminal output. */
class CLI
{
    public function __construct(private RepositoryManager $repositories) {}

    public static function registerCommands(Settings $settings): void
    {
        $cli = new self(new RepositoryManager($settings));
        WP_CLI::add_command('rrze-updater connector list', [$cli, 'listConnectors'], [
            'shortdesc' => 'List configured connector IDs without exposing credentials.',
            'synopsis' => self::listSynopsis(),
        ]);
        foreach (['plugin', 'theme'] as $type) {
            foreach (['register', 'install', 'list', 'unregister'] as $action) {
                WP_CLI::add_command("rrze-updater repo $type $action", function ($args, $options) use ($cli, $type, $action) {
                    $cli->runRepositoryCommand($type, $action, $args, $options);
                }, [
                    'shortdesc' => self::description($type, $action),
                    'synopsis' => self::repositorySynopsis($action),
                ]);
            }
        }
    }

    public function listConnectors(array $args, array $options): void
    {
        \WP_CLI\Utils\format_items($options['format'] ?? 'table',
            $this->repositories->listConnectors(), ['id', 'type', 'host', 'owner', 'authenticated']);
    }

    private function runRepositoryCommand(string $type, string $action, array $args, array $options): void
    {
        if ($action === 'list') {
            \WP_CLI\Utils\format_items($options['format'] ?? 'table',
                $this->repositories->listRepositories($type),
                ['repository', 'connector', 'folder', 'branch', 'updates', 'local_ref', 'remote_ref']);
            return;
        }
        $repository = $args[0];
        $result = match ($action) {
            'register' => $this->repositories->register($type, $repository, $options),
            'install' => $this->repositories->install($type, $repository, $options),
            'unregister' => $this->repositories->unregister($type, $repository, $options['connector'] ?? ''),
        };
        if ($result instanceof WP_Error) {
            WP_CLI::error($result->get_error_message());
            return;
        }
        WP_CLI::success($result);
    }

    private static function description(string $type, string $action): string
    {
        return match ($action) {
            'register' => "Register an installed $type for repository updates; defaults to tags.",
            'install' => "Install and register a $type without activating or overwriting it; defaults to tags.",
            'list' => "List managed {$type} repositories.",
            'unregister' => "Remove a $type repository association, keeping all installed files.",
        };
    }

    private static function listSynopsis(): array
    {
        return [[
            'type' => 'assoc', 'name' => 'format', 'optional' => true,
            'description' => 'Output format.', 'options' => ['table', 'json', 'csv', 'yaml', 'count'],
        ]];
    }

    private static function repositorySynopsis(string $action): array
    {
        if ($action === 'list') {
            return self::listSynopsis();
        }
        $synopsis = [
            ['type' => 'positional', 'name' => 'repository', 'description' => 'Repository name within the connector owner/group.'],
            ['type' => 'assoc', 'name' => 'connector', 'optional' => $action === 'unregister',
                'description' => 'Connector ID from wp rrze-updater connector list.'],
        ];
        if ($action === 'unregister') {
            return $synopsis;
        }
        return array_merge($synopsis, [
            ['type' => 'assoc', 'name' => 'branch', 'optional' => true,
                'description' => 'Branch for commit updates (default: main). Tags/releases are repository-wide.'],
            ['type' => 'assoc', 'name' => 'folder', 'optional' => true,
                'description' => 'Installation folder (default: repository name).'],
            ['type' => 'assoc', 'name' => 'updates', 'optional' => true,
                'description' => 'Update policy (default: tags).', 'options' => ['tags', 'commits', 'releases']],
            ['type' => 'flag', 'name' => 'only-release', 'optional' => true,
                'description' => 'Use published releases for installation and future updates.'],
            ['type' => 'flag', 'name' => 'onlyRelease', 'optional' => true,
                'description' => 'Alias for --only-release.'],
        ]);
    }
}

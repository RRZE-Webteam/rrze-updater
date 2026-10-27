<?php

namespace RRZE\Updater\Admin;

defined('ABSPATH') || exit;

use RRZE\Updater\Core\RepositoryManager;
use WP_Error;

/** Validates the common legacy install form and delegates installation. */
class RepositoryInstallAction
{
    /**
     * Inject the shared non-overwriting repository installer.
     *
     * @param RepositoryManager $manager Repository installation service.
     */
    public function __construct(private RepositoryManager $manager)
    {
    }

    /**
     * Install an explicitly submitted plugin or theme through the existing path.
     *
     * @param string $type Extension type: plugin or theme.
     * @return array{result: string|WP_Error, messages: array, folder: string}|null Outcome or no submitted form.
     */
    public function install(string $type): ?array
    {
        $plural = $type === 'plugin' ? 'plugins' : 'themes';
        if (!current_user_can('update_' . $plural) || !current_user_can('install_' . $plural)) {
            wp_die(esc_html__('You need a higher level of permission.', 'rrze-updater'));
        }
        $request = $_POST['rrze-updater'] ?? null;
        if (!is_array($request)) {
            return null;
        }
        $error = $this->validate($request);
        if ($error) {
            return ['result' => $error, 'messages' => [$error], 'folder' => ''];
        }
        $request = wp_unslash($request);
        $repository = trim($request['repository'] ?? '');
        $folder = ($request['installationFolder'] ?? '') ?: $repository;
        try {
            $result = $this->manager->install($type, $repository, [
                'connector' => $request['connectorId'] ?? '',
                'folder' => $folder,
                'branch' => ($request['branch'] ?? '') ?: 'main',
                'updates' => ($request['updates'] ?? '') ?: 'tags',
            ]);
        } catch (\Throwable $exception) {
            // Transport exception text may expose credentials.
            $result = new WP_Error('installation_interrupted', __('Installation was interrupted. Inspect the destination before retrying; existing files will not be overwritten.', 'rrze-updater'));
        }
        $messages = [];
        foreach ($this->manager->getWarnings() as $warning) {
            $messages[] = new WP_Error('rrze_updater_repository_warning', $warning);
        }
        $messages[] = $result;
        return compact('result', 'messages', 'folder');
    }

    /**
     * Validate scalar installation fields and the file modification policy.
     *
     * @param array $request Raw submitted form.
     * @return WP_Error|null Validation failure, or null for a valid request.
     */
    private function validate(array $request): ?WP_Error
    {
        if (!wp_is_file_mod_allowed('rrze_updater_install')) {
            return new WP_Error('file_modifications_disabled', __('File modifications are disabled on this installation.', 'rrze-updater'));
        }
        foreach (['repository', 'connectorId', 'installationFolder', 'branch', 'updates'] as $field) {
            if (isset($request[$field]) && !is_string($request[$field])) {
                return new WP_Error('invalid_request', __('Invalid repository installation request.', 'rrze-updater'));
            }
        }
        return null;
    }
}

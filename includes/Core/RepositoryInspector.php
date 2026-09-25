<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

use WP_Error;

/** Identify one root-level WordPress extension without executing repository code. */
class RepositoryInspector
{
    public function inspect(RepositoryDiscovery $api, string $repository, string $branch): array|WP_Error
    {
        $metadata = $api->repository($repository);
        if (is_wp_error($metadata)) {
            return $metadata;
        }
        $ref = $api->commit($repository, $branch);
        if (is_wp_error($ref)) {
            return $ref;
        }
        $files = $api->rootFiles($repository, $ref);
        if (is_wp_error($files)) {
            return $files;
        }
        $plugins = [];
        $phpFiles = array_values(array_filter($files, fn($file) => str_ends_with(strtolower($file), '.php')));
        if (count($phpFiles) > 30) {
            return new WP_Error('inspection_limit', __('Could not check this repository: more than 30 root PHP files require inspection.', 'rrze-updater'));
        }
        foreach ($phpFiles as $file) {
            $content = $api->file($repository, $ref, $file);
            if (is_wp_error($content)) {
                return $content;
            }
            if (self::header($content, 'Plugin Name') !== '') {
                $plugins[$file] = $content;
            }
        }
        $stylesheet = '';
        if (in_array('style.css', $files, true)) {
            $stylesheet = $api->file($repository, $ref, 'style.css');
            if (is_wp_error($stylesheet)) {
                return $stylesheet;
            }
        }
        $isTheme = self::header($stylesheet, 'Theme Name') !== '';
        if (count($plugins) + (int) $isTheme !== 1) {
            return new WP_Error('unsupported_structure', __('Unsupported structure: expected one plugin or theme at the repository root.', 'rrze-updater'));
        }
        $content = $isTheme ? $stylesheet : reset($plugins);
        $parent = $isTheme ? self::header($content, 'Template') : '';
        if ($isTheme && $parent === '' && !in_array('index.php', $files, true)) {
            $template = $api->file($repository, $ref, 'templates/index.html');
            if (is_wp_error($template)) {
                return $template->get_error_code() === 'discovery_not_found'
                    ? new WP_Error('unsupported_structure', __('Unsupported theme: missing index.php or templates/index.html.', 'rrze-updater')) : $template;
            }
        }
        global $wp_version;
        $requiresWp = self::header($content, 'Requires at least');
        $requiresPhp = self::header($content, 'Requires PHP');
        if (($requiresWp !== '' && version_compare($wp_version, $requiresWp, '<'))
            || ($requiresPhp !== '' && version_compare(PHP_VERSION, $requiresPhp, '<'))) {
            return new WP_Error('incompatible_extension', sprintf(__('Requires WordPress %1$s and PHP %2$s. This installation does not meet the declared requirements.', 'rrze-updater'), $requiresWp ?: '—', $requiresPhp ?: '—'));
        }
        $dependencies = $isTheme ? '' : self::header($content, 'Requires Plugins');
        return [
            'type' => $isTheme ? 'theme' : 'plugin', 'ref' => $ref,
            'name' => self::header($content, $isTheme ? 'Theme Name' : 'Plugin Name'),
            'readable' => self::header($content, 'Version'), 'parent' => $parent,
            'main_file' => $isTheme ? 'style.css' : array_key_first($plugins),
            'warning' => $dependencies !== '' ? sprintf(__('Declared plugin dependencies: %s. Review these before activation.', 'rrze-updater'), $dependencies) : '',
        ];
    }

    /** Mirror WordPress get_file_data() header parsing and its first-8-KiB limit. */
    public static function header(string $content, string $name): string
    {
        $content = str_replace("\r", "\n", substr($content, 0, 8192));
        if (!preg_match('/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $content, $match)) {
            return '';
        }
        return trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
    }
}

<?php

namespace RRZE\Updater\Bundles;

defined('ABSPATH') || exit;

/** The bundle is shipped with the plugin; clients never supply repositories. */
class Catalog
{
    public function get(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/standard.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->get(), JSON_THROW_ON_ERROR));
    }
}

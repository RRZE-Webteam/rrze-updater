<?php

namespace RRZE\Updater\Upgrader;

defined('ABSPATH') || exit;

use Plugin_Upgrader_Skin;

/**
 * Class PluginUpgraderSkin
 *
 * Custom skin for upgrading plugins.
 */
class PluginUpgraderSkin extends Plugin_Upgrader_Skin
{
    /**
     * The extension (plugin) associated with this skin.
     *
     * @var Extension
     */
    public $extension;

    /**
     * Constructor for the PluginUpgraderSkin class.
     *
     * @param Extension $extension The extension (plugin) associated with this skin.
     */
    public function __construct($extension)
    {
        parent::__construct();
        $this->extension = $extension;
    }
}

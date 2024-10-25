<?php

namespace RRZE\Updater\Upgrader;

defined('ABSPATH') || exit;

use Theme_Upgrader_Skin;

/**
 * Class ThemeUpgraderSkin
 *
 * Custom skin for upgrading themes.
 */
class ThemeUpgraderSkin extends Theme_Upgrader_Skin
{
    /**
     * The extension (theme) associated with this skin.
     *
     * @var Extension
     */
    public $extension;

    /**
     * Constructor for the ThemeUpgraderSkin class.
     *
     * @param Extension $extension The extension (theme) associated with this skin.
     */
    public function __construct($extension)
    {
        parent::__construct();
        $this->extension = $extension;
    }
}

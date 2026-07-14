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
    public function __construct($extension, array $args = []) {
        parent::__construct($args);
        $this->extension = $extension;
    }

    public function after() {
        add_filter('gettext', [$this, 'translateNetworkActivationLabel'], 10, 3);
        parent::after();
        remove_filter('gettext', [$this, 'translateNetworkActivationLabel'], 10);
    }

    public function translateNetworkActivationLabel($translation, $text, $domain) {
        if (!is_network_admin()) {
            return $translation;
        }

        $activationLabels = [
            'Activate Plugin',
            'Network Activate',
            'Activate'
        ];

        if (in_array($text, $activationLabels, true)) {
            return __('Plugin netzwerkweit aktivieren', 'rrze-updater');
        }

        return $translation;
    }
}

<?php
require_once __DIR__ . '/legacy-parent.php';

use RRZE\Updater\{Controller, Settings};
use RRZE\Updater\Core\RepositoryManager;
use RRZE\Updater\Upgrader\RepositoryInstaller;

class LegacyInstallConnectorFixture extends BundleConnectorFixture {
    public bool $downloadFails = false;
    public function downloadRepoZip(string $repository, string $branch): string {
        return $this->downloadFails ? '' : 'fixture.zip';
    }
}
class LegacyInstallAdapterFixture extends InstallerAdapterFixture {
    public int $attempts = 0;
    public function __construct(public Settings $settings, public string $outcome) {}
    protected function createUpgrader(string $type): WP_Upgrader {
        return new LegacyInstallUpgraderFixture($this, $type);
    }
}
class LegacyInstallUpgraderFixture extends WP_Upgrader {
    public function __construct(public LegacyInstallAdapterFixture $adapter, public string $type) {}
    public function install($package, $options = []) {
        $this->adapter->attempts++;
        $property = $this->type === 'plugin' ? 'plugins' : 'themes';
        check($options['overwrite_package'] === false, 'Legacy installation never overwrites an existing destination.');
        check($this->adapter->settings->$property === [] && (new Settings())->$property === [],
            'No registry entry or installed version exists before the upgrader succeeds.');
        $source = apply_filters('upgrader_source_selection', '/tmp/generated/', '/tmp/', $this);
        check($source === '/tmp/package/', 'Legacy installation uses the shared installer folder selection.');
        switch ($this->adapter->outcome) {
            case 'error': return new WP_Error('fixture_error', 'Installation failed.');
            case 'false': return false;
            case 'exception': throw new RuntimeException('Transport failure: secret-token');
            case 'missing-files': return true;
            case 'partial':
                $this->adapter->installed = true;
                return new WP_Error('fixture_partial', 'Installation failed after writing files.');
            case 'save-failure': $GLOBALS['fail_save'] = true; break;
        }
        $this->adapter->installed = true;
        return true;
    }
}
class LegacyInstallControllerFixture extends Controller {
    public string $view = '';
    public array $notices = [];
    public function __construct(Settings $settings, private RepositoryInstaller $installer) {
        parent::__construct($settings);
    }
    protected function createRepositoryManager(): RepositoryManager {
        return new RepositoryManager($this->settings, $this->installer);
    }
    protected function display($view, $data = []) {
        $this->view = $view;
        $this->notices = $this->messages;
    }
    public function submit(string $type): void {
        if ($type === 'plugin') { $this->postPluginAdd(); }
        else { $this->postThemeAdd(); }
    }
}

$beforeLegacyInstalls = $checks;
$bundle_caps = ['update_plugins' => true, 'update_themes' => true, 'install_plugins' => true, 'install_themes' => true];
foreach (['plugin', 'theme'] as $type) {
    foreach (['success', 'download', 'error', 'false', 'exception', 'missing-files', 'partial', 'save-failure',
        'existing', 'invalid-request', 'unknown-connector', 'file-mods-disabled'] as $outcome) {
        $storage = [];
        $fail_save = false;
        $bundle_mods = $outcome !== 'file-mods-disabled';
        $settings = new Settings();
        $settings->plugins = $settings->themes = [];
        $connector = new LegacyInstallConnectorFixture();
        $connector->id = 'legacy-fixture';
        $connector->owner = 'RRZE-Webteam';
        $connector->token = 'fixture-token';
        $connector->downloadFails = $outcome === 'download';
        $settings->connectors = [$connector];
        $settings->save();
        $adapter = new LegacyInstallAdapterFixture($settings, $outcome);
        $adapter->installed = $outcome === 'existing';
        $controller = new LegacyInstallControllerFixture($settings, $adapter);
        $_POST['rrze-updater'] = ['repository' => 'package', 'installationFolder' => 'package',
            'connectorId' => $connector->id, 'branch' => 'main', 'updates' => 'commits'];
        if ($outcome === 'invalid-request') { $_POST['rrze-updater']['repository'] = ['invalid']; }
        if ($outcome === 'unknown-connector') { $_POST['rrze-updater']['connectorId'] = 'unknown'; }
        $controller->submit($type);
        $property = $type === 'plugin' ? 'plugins' : 'themes';
        $notice = end($controller->notices);
        $stored = (new Settings())->$property;
        if ($outcome === 'success') {
            check($controller->view === "$property/add-progress" && is_string($notice), 'Success is displayed only after installation and registration.');
            check(count($settings->$property) === 1 && count($stored) === 1, 'Successful legacy installation persists one association.');
            $installed = reset($stored);
            check($installed->localVersion === $connector->commit && $installed->remoteVersion === $connector->commit,
                'Successful installation persists the installed commit.');
        } else {
            check($controller->view === "$property/add" && is_wp_error($notice), "$type/$outcome displays an error and the add form.");
            check($settings->$property === [] && $stored === [], "$type/$outcome does not register or claim an installed ref.");
        }
        if (in_array($outcome, ['download', 'existing', 'invalid-request', 'unknown-connector', 'file-mods-disabled'], true)) {
            check($adapter->attempts === 0, "$type/$outcome never invokes the WordPress installer.");
        }
        if (in_array($outcome, ['partial', 'save-failure', 'existing'], true)) {
            check($adapter->installed, 'Failure handling preserves files for inspection or explicit registration.');
        }
        check(!str_contains(serialize($controller->notices), 'secret-token'), 'Raw exception credentials are not displayed.');
        check(has_filter('upgrader_source_selection') === false, 'Source filter is removed after success, failure or exception.');
        $fail_save = false;
    }
}
$bundle_mods = true;
unset($_POST['rrze-updater']);
echo 'Passed ' . ($checks - $beforeLegacyInstalls) . " legacy installation checks.\n";

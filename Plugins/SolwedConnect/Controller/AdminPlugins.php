<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHubPlugins;

/**
 * Sobreescribe AdminPlugins de NeoRazorX:
 * - Elimina Forja y Telemetry
 * - Usa SolwedGitHubPlugins como tienda de plugins
 * - Usa SolwedGitHub para detectar updates del core
 * - Protege SolwedConnect de desinstalación en instancias managed
 */
class AdminPlugins extends \FacturaScripts\Core\Controller\AdminPlugins
{
    /** @var array */
    public $solwedPluginList = [];

    /** @var array */
    public $licenseStatus = [];

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        // No Telemetry, no Forja
        $this->registered = true;
        $this->updated = SolwedGitHub::canUpdateCore() === false;

        $this->licenseStatus = LicenseClient::getStatus();

        $action = $this->request->inputOrQuery('action', '');

        if ($action === 'install-from-store') {
            $this->installFromStoreAction();
            return;
        }

        if ($action === 'refresh-store') {
            SolwedGitHubPlugins::clearCache();
        }

        $this->loadSolwedPluginList();
    }

    protected function removePluginAction(): void
    {
        $pluginName = $this->request->queryOrInput('plugin', '');
        if ($pluginName === 'SolwedConnect' && LicenseClient::isManaged()) {
            Tools::log()->warning('solwedconnect-protected');
            return;
        }
        parent::removePluginAction();
    }

    protected function disablePluginAction(): void
    {
        $pluginName = $this->request->queryOrInput('plugin', '');
        if ($pluginName === 'SolwedConnect' && LicenseClient::isManaged()) {
            Tools::log()->warning('solwedconnect-protected');
            return;
        }
        parent::disablePluginAction();
    }

    private function installFromStoreAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            $this->loadSolwedPluginList();
            return;
        }

        if (false === $this->validateFormToken()) {
            $this->loadSolwedPluginList();
            return;
        }

        $pluginName = $this->request->inputOrQuery('plugin', '');
        $downloadUrl = SolwedGitHubPlugins::getDownloadUrl($pluginName);
        if (empty($downloadUrl)) {
            Tools::log()->error('plugin-not-found', ['%plugin%' => $pluginName]);
            $this->loadSolwedPluginList();
            return;
        }

        $tmpFile = Tools::folder(Plugins::folder(), $pluginName . '.zip');

        $http = Http::get($downloadUrl)->setTimeout(30);
        if (false === $http->saveAs($tmpFile)) {
            Tools::log()->error('download-error', [
                '%error%' => $http->errorMessage(),
                '%status%' => $http->status(),
            ]);
            $this->loadSolwedPluginList();
            return;
        }

        if (Plugins::add($tmpFile, $pluginName . '.zip')) {
            @unlink($tmpFile);
            Tools::log()->notice('reloading');
            $this->redirect($this->url(), 3);
            return;
        }

        @unlink($tmpFile);
        Tools::log()->error('plugin-install-error', ['%plugin%' => $pluginName]);
        $this->loadSolwedPluginList();
    }

    private function loadSolwedPluginList(): void
    {
        if (Tools::config('disable_add_plugins', false)) {
            return;
        }

        $installedNames = array_map(fn($p) => $p->name, Plugins::list());

        $this->solwedPluginList = [];
        $this->remotePluginList = [];

        foreach (SolwedGitHubPlugins::getList() as $item) {
            $name = $item['name'] ?? '';
            if (empty($name) || in_array($name, $installedNames)) {
                continue;
            }
            $this->solwedPluginList[] = $item;
            // remotePluginList en el formato que espera la vista core
            $this->remotePluginList[] = [
                'idplugin' => 'sc-' . strtolower(preg_replace('/[^a-z0-9]/i', '-', $name)),
                'name' => $name,
                'version' => $item['version'] ?? '1.0',
                'description' => $item['description'] ?? '',
                'url' => $this->url() . '?action=install-from-store&plugin=' . urlencode($name)
                    . '&multireqtoken=' . $this->multiRequestProtection->newToken(),
                'health' => 3,
            ];
        }
    }
}

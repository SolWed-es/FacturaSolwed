<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Internal\Plugin;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHubPlugins;

/**
 * Sobreescribe Updater de NeoRazorX:
 * - Elimina Forja, Telemetry y UPDATE_CORE_URL de facturascripts.com
 * - Core updates via Mind proxy (SolwedGitHub)
 * - Plugin updates via catálogo propio plugins.erpsolwed.es (SolwedGitHubPlugins)
 */
class Updater extends \FacturaScripts\Core\Controller\Updater
{
    public function privateCore(&$response, $user, $permissions)
    {
        // sustituimos telemetryManager por un objeto vacío para que la vista no falle
        $this->telemetryManager = new class {
            public function ready(): bool { return true; }
            public function signUrl(string $url): string { return $url; }
            public function claimUrl(): string { return ''; }
            public function install(): bool { return true; }
            public function unlink(): bool { return true; }
        };

        parent::privateCore($response, $user, $permissions);
    }

    public static function getUpdateItems(): array
    {
        $items = [];

        // core update via SolwedGitHub
        if (SolwedGitHub::canUpdateCore()) {
            $build = SolwedGitHub::getCoreBuild();
            if (!empty($build)) {
                $fileName = 'update-core-solwed.zip';
                $items[] = [
                    'description' => Tools::trans('core-update', ['%version%' => $build['version']]),
                    'downloaded' => file_exists(Tools::folder($fileName)),
                    'filename' => $fileName,
                    'id' => 'core-solwed',
                    'name' => 'CORE',
                    'stable' => $build['stable'],
                    'url' => $build['url'],
                    'version' => $build['version'],
                    'mincore' => 0,
                    'maxcore' => 0,
                ];
            }
        }

        // plugin updates via catálogo Solwed (plugins.erpsolwed.es)
        foreach (Plugins::list() as $plugin) {
            if (!$plugin->enabled) {
                continue;
            }
            $item = self::getSolwedPluginUpdate($plugin);
            if (!empty($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    protected function execAction(string $action): void
    {
        // eliminamos acciones de Telemetry que no aplican
        switch ($action) {
            case 'claim-install':
            case 'register':
            case 'unlink':
                // no-op en FacturaSolwed
                $this->updaterItems = self::getUpdateItems();
                return;

            case 'download':
                $this->downloadSolwedAction();
                return;
        }

        parent::execAction($action);
    }

    private function downloadSolwedAction(): void
    {
        $idItem = $this->request->get('item', '');
        $this->updaterItems = self::getUpdateItems();

        foreach ($this->updaterItems as $key => $item) {
            if ($item['id'] != $idItem) {
                continue;
            }

            if (file_exists(Tools::folder($item['filename']))) {
                unlink(Tools::folder($item['filename']));
            }

            // descarga directa sin signUrl de Telemetry
            $http = Http::get($item['url'])
                ->setHeader('User-Agent', 'FacturaSolwed/' . Kernel::version());

            if ($http->saveAs(Tools::folder($item['filename']))) {
                Tools::log()->notice('download-completed');
                $this->updaterItems[$key]['downloaded'] = true;
                return;
            }

            Tools::log()->error('download-error', [
                '%body%' => $http->body(),
                '%error%' => $http->errorMessage(),
                '%status%' => $http->status(),
            ]);
            return;
        }
    }

    private static function getSolwedPluginUpdate(Plugin $plugin): array
    {
        $catalog = SolwedGitHubPlugins::getPluginMap();
        $entry   = $catalog[$plugin->name] ?? [];

        if (empty($entry) || empty($entry['download_url'])) {
            return [];
        }

        $catalogVersion = (string)($entry['version'] ?? '0');
        if (version_compare($catalogVersion, (string)$plugin->version, '<=')) {
            return [];
        }

        $fileName = 'update-' . $plugin->name . '.zip';
        return [
            'description' => Tools::trans('plugin-update', [
                '%pluginName%' => $plugin->name,
                '%version%'    => $catalogVersion,
            ]),
            'downloaded' => file_exists(Tools::folder($fileName)),
            'filename'   => $fileName,
            'id'         => $plugin->name,
            'name'       => $plugin->name,
            'stable'     => true,
            'url'        => $entry['download_url'],
            'version'    => $catalogVersion,
            'mincore'    => 0,
            'maxcore'    => 0,
        ];
    }
}

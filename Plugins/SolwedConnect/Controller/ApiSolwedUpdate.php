<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHubPlugins;

/**
 * API de gestión remota para w-api / Mind.
 *
 * Rutas (autenticadas con la API Key de FS — header Token: <key>):
 *
 *   POST /api/3/solwedupdate
 *   Body: { "action": "install-plugin", "plugin": "TPVneo" }
 *         { "action": "update-plugin",  "plugin": "TPVneo" }
 *         { "action": "enable-plugin",  "plugin": "TPVneo" }
 *         { "action": "disable-plugin", "plugin": "TPVneo" }
 *         { "action": "list-plugins" }
 *         { "action": "check-updates" }
 *         { "action": "update-core" }   ← solo si hay release disponible
 *
 * Usado por w-api para gestión de instancias managed de forma desatendida.
 */
class ApiSolwedUpdate extends ApiController
{
    protected function runResource(): void
    {
        if (!$this->request->isMethod(Request::METHOD_POST)) {
            $this->response
                ->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED)
                ->json(['error' => 'Use POST']);
            return;
        }

        // Solo instancias managed aceptan comandos remotos
        if (!LicenseClient::isManaged()) {
            $this->response
                ->setHttpCode(Response::HTTP_FORBIDDEN)
                ->json(['error' => 'Solo instancias managed aceptan comandos remotos']);
            return;
        }

        // Licencia activa requerida — sin suscripción la API no está disponible
        $license = LicenseClient::getStatus();
        if (!($license['active'] ?? false)) {
            $this->response
                ->setHttpCode(Response::HTTP_FORBIDDEN)
                ->json(['error' => 'Licencia no activa', 'plan' => $license['plan'] ?? 'none']);
            return;
        }

        $body   = $this->request->request->all()
            ?: (array)(json_decode($this->request->getContent() ?? '{}', true) ?? []);
        $action = $body['action'] ?? '';

        switch ($action) {
            case 'list-plugins':
                $this->listPlugins();
                break;

            case 'check-updates':
                $this->checkUpdates();
                break;

            case 'install-plugin':
                $plugin = $body['plugin'] ?? '';
                if (!$this->validatePluginName($plugin)) {
                    return;
                }
                $this->audit($action, $plugin);
                $this->installPlugin($plugin);
                break;

            case 'update-plugin':
                $plugin = $body['plugin'] ?? '';
                if (!$this->validatePluginName($plugin)) {
                    return;
                }
                $this->audit($action, $plugin);
                $this->updatePlugin($plugin);
                break;

            case 'enable-plugin':
                $plugin = $body['plugin'] ?? '';
                if (!$this->validatePluginName($plugin)) {
                    return;
                }
                $this->audit($action, $plugin);
                $this->togglePlugin($plugin, true);
                break;

            case 'disable-plugin':
                $plugin = $body['plugin'] ?? '';
                if (!$this->validatePluginName($plugin)) {
                    return;
                }
                $this->audit($action, $plugin);
                $this->togglePlugin($plugin, false);
                break;

            case 'update-core':
                $this->audit($action);
                $this->updateCore();
                break;

            default:
                $this->response
                    ->setHttpCode(Response::HTTP_BAD_REQUEST)
                    ->json(['error' => 'Acción desconocida', 'actions' => [
                        'list-plugins', 'check-updates',
                        'install-plugin', 'update-plugin',
                        'enable-plugin', 'disable-plugin',
                        'update-core',
                    ]]);
        }
    }

    // ── list-plugins ─────────────────────────────────────────────────────────

    private function listPlugins(): void
    {
        $list = [];
        foreach (Plugins::list() as $plugin) {
            $list[] = [
                'name'        => $plugin->name,
                'version'     => $plugin->version,
                'enabled'     => $plugin->enabled,
                'compatible'  => $plugin->compatible,
                'description' => $plugin->description,
            ];
        }
        $this->response->setHttpCode(Response::HTTP_OK)->json([
            'ok'      => true,
            'plugins' => $list,
            'count'   => count($list),
        ]);
    }

    // ── check-updates ─────────────────────────────────────────────────────────

    private function checkUpdates(): void
    {
        $updates = [];

        // Core update
        if (SolwedGitHub::canUpdateCore()) {
            $build = SolwedGitHub::getCoreBuild();
            $updates[] = [
                'type'    => 'core',
                'name'    => 'CORE',
                'current' => (string)\FacturaScripts\Core\Kernel::version(),
                'latest'  => (string)($build['version'] ?? ''),
                'url'     => $build['url'] ?? '',
            ];
        }

        // Plugin updates via catálogo plugins.erpsolwed.es
        $catalog = SolwedGitHubPlugins::getPluginMap();
        foreach (Plugins::list() as $plugin) {
            if (!$plugin->enabled) {
                continue;
            }
            $entry = $catalog[$plugin->name] ?? [];
            if (empty($entry) || empty($entry['download_url'])) {
                continue;
            }
            $catalogVersion = (string)($entry['version'] ?? '0');
            if (version_compare($catalogVersion, (string)$plugin->version, '>')) {
                $updates[] = [
                    'type'    => 'plugin',
                    'name'    => $plugin->name,
                    'current' => (string)$plugin->version,
                    'latest'  => $catalogVersion,
                    'url'     => $entry['download_url'],
                ];
            }
        }

        $this->response->setHttpCode(Response::HTTP_OK)->json([
            'ok'      => true,
            'updates' => $updates,
            'count'   => count($updates),
        ]);
    }

    // ── install-plugin ────────────────────────────────────────────────────────

    private function installPlugin(string $pluginName): void
    {
        // Comprobar que no está ya instalado
        foreach (Plugins::list() as $plugin) {
            if ($plugin->name === $pluginName) {
                $this->response->setHttpCode(Response::HTTP_OK)
                    ->json(['ok' => true, 'message' => 'Plugin ya instalado', 'installed' => true]);
                return;
            }
        }

        $downloadUrl = SolwedGitHubPlugins::getDownloadUrl($pluginName);
        if (empty($downloadUrl)) {
            $this->response->setHttpCode(Response::HTTP_NOT_FOUND)
                ->json(['error' => "Plugin '$pluginName' no encontrado en el catálogo"]);
            return;
        }

        $result = $this->downloadAndInstall($pluginName, $downloadUrl);
        $this->response->setHttpCode($result['ok'] ? Response::HTTP_OK : 500)
            ->json($result);
    }

    // ── update-plugin ─────────────────────────────────────────────────────────

    private function updatePlugin(string $pluginName): void
    {
        $installed = false;
        foreach (Plugins::list() as $p) {
            if ($p->name === $pluginName) {
                $installed = true;
                break;
            }
        }

        if (!$installed) {
            $this->response->setHttpCode(Response::HTTP_NOT_FOUND)
                ->json(['error' => "Plugin '$pluginName' no instalado"]);
            return;
        }

        $downloadUrl = SolwedGitHubPlugins::getDownloadUrl($pluginName);
        if (empty($downloadUrl)) {
            $this->response->setHttpCode(Response::HTTP_NOT_FOUND)
                ->json(['error' => "No se encontró '$pluginName' en el catálogo"]);
            return;
        }

        $result = $this->downloadAndInstall($pluginName, $downloadUrl);
        $this->response->setHttpCode($result['ok'] ? Response::HTTP_OK : 500)
            ->json($result);
    }

    // ── enable/disable plugin ─────────────────────────────────────────────────

    private function togglePlugin(string $pluginName, bool $enable): void
    {
        // Proteger SolwedConnect
        if ($pluginName === 'SolwedConnect') {
            $this->response->setHttpCode(Response::HTTP_FORBIDDEN)
                ->json(['error' => 'SolwedConnect no se puede desactivar']);
            return;
        }

        $ok = $enable ? Plugins::enable($pluginName) : Plugins::disable($pluginName);
        $this->response->setHttpCode(Response::HTTP_OK)->json([
            'ok'     => $ok,
            'plugin' => $pluginName,
            'action' => $enable ? 'enabled' : 'disabled',
        ]);
    }

    // ── update-core ───────────────────────────────────────────────────────────

    private function updateCore(): void
    {
        if (!SolwedGitHub::canUpdateCore()) {
            $this->response->setHttpCode(Response::HTTP_OK)->json([
                'ok'      => true,
                'message' => 'El core ya está en la última versión',
                'updated' => false,
            ]);
            return;
        }

        $build = SolwedGitHub::getCoreBuild();
        $url   = $build['url'] ?? '';
        if (empty($url)) {
            $this->response->setHttpCode(500)->json(['error' => 'No se pudo obtener URL de descarga del core']);
            return;
        }

        $tmpFile = Tools::folder('update-core-solwed.zip');

        $http = Http::get($url)
            ->setTimeout(60)
            ->setHeader('User-Agent', 'FacturaSolwed/' . \FacturaScripts\Core\Kernel::version());

        if (!$http->saveAs($tmpFile)) {
            $this->response->setHttpCode(500)->json([
                'error'  => 'Error descargando el core',
                'detail' => $http->errorMessage(),
            ]);
            return;
        }

        $this->response->setHttpCode(Response::HTTP_OK)->json([
            'ok'      => true,
            'message' => 'Core descargado. Aplicar manualmente desde /Updater o reiniciar el container.',
            'version' => (string)($build['version'] ?? ''),
            'file'    => 'update-core-solwed.zip',
            'updated' => false,  // la aplicación real requiere reinicio
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validatePluginName(string $name): bool
    {
        if (empty($name)) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST)
                ->json(['error' => 'plugin requerido']);
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST)
                ->json(['error' => 'Nombre de plugin inválido']);
            return false;
        }

        return true;
    }

    private function audit(string $action, string $plugin = ''): void
    {
        $context = ['action' => $action];
        if ($plugin !== '') {
            $context['plugin'] = $plugin;
        }
        Tools::log('solwedconnect')->notice('remote-action', $context);
    }

    private function downloadAndInstall(string $pluginName, string $url): array
    {
        Tools::folderCheckOrCreate(Tools::folder('MyFiles', 'Tmp'));
        $tmpFile = Tools::folder('MyFiles', 'Tmp') . DIRECTORY_SEPARATOR . $pluginName . '.zip';

        $http = Http::get($url)
            ->setTimeout(60)
            ->setHeader('User-Agent', 'FacturaSolwed/' . \FacturaScripts\Core\Kernel::version());

        if (!$http->saveAs($tmpFile)) {
            return [
                'ok'     => false,
                'error'  => 'Error de descarga',
                'detail' => $http->errorMessage(),
                'status' => $http->status(),
            ];
        }

        if (Plugins::add($tmpFile, $pluginName . '.zip')) {
            @unlink($tmpFile);
            Plugins::enable($pluginName);
            return ['ok' => true, 'plugin' => $pluginName, 'installed' => true];
        }

        @unlink($tmpFile);
        return ['ok' => false, 'error' => "No se pudo instalar '$pluginName'"];
    }
}

<?php
namespace FacturaScripts\Plugins\SolwedConnect;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Extension\Model\Cliente;
use FacturaScripts\Plugins\SolwedConnect\Extension\Model\FacturaCliente;
use FacturaScripts\Plugins\SolwedConnect\Extension\Model\ReciboCliente;

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Cliente());
        $this->loadExtension(new FacturaCliente());
        $this->loadExtension(new ReciboCliente());

        // ruta del webhook de suscripción (recibe notificaciones de app.solwed.es)
        Kernel::addRoute('/SolwedWebhook', 'SolwedWebhook', 5);
    }

    public function update(): void
    {
        // Si viene del entorno (instancia managed recién provisionada), tiene prioridad
        $envToken = defined('FS_MIND_TOKEN') ? FS_MIND_TOKEN : '';
        $envType  = getenv('FS_INSTANCE_TYPE') ?: 'self-hosted';

        $defaults = [
            'mind_url'      => 'https://mind.solwed.es',
            'mind_token'    => $envToken,
            'license_key'   => '',
            'instance_type' => $envType,
        ];

        foreach ($defaults as $key => $default) {
            $current = Tools::settings('solwedconnect', $key, '__missing__');

            // Escribir si: nunca se ha guardado, o si el env tiene un valor nuevo
            if ($current === '__missing__') {
                Tools::settingsSet('solwedconnect', $key, $default);
            } elseif ($key === 'mind_token' && !empty($envToken) && $current !== $envToken) {
                // El token del entorno siempre gana (permite re-provisioning)
                Tools::settingsSet('solwedconnect', $key, $envToken);
            } elseif ($key === 'instance_type' && $current !== $envType) {
                Tools::settingsSet('solwedconnect', $key, $envType);
            }
        }

        Tools::settingsSave();
    }

    public function uninstall(): void
    {
        // en managed no se puede desinstalar — controlado en AdminPlugins override
    }
}

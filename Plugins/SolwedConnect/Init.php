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
        // settings por defecto
        $defaults = [
            'mind_url' => 'https://mind.solwed.es',
            'mind_token' => '',
            'license_key' => '',
            'instance_type' => getenv('FS_INSTANCE_TYPE') ?: 'self-hosted',
        ];
        foreach ($defaults as $key => $default) {
            if (Tools::settings('solwedconnect', $key, '__missing__') === '__missing__') {
                Tools::settingsSet('solwedconnect', $key, $default);
            }
        }
        Tools::settingsSave();
    }

    public function uninstall(): void
    {
        // en managed no se puede desinstalar — controlado en AdminPlugins override
    }
}

<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;

/**
 * Sobreescribe Dashboard de NeoRazorX:
 * - Elimina Telemetry y Forja
 * - Elimina llamadas a facturascripts.com para noticias
 * - Muestra aviso si la suscripción no está activa
 */
class Dashboard extends \FacturaScripts\Core\Controller\Dashboard
{
    /** @var array */
    public $licenseStatus = [];

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->registered = true;
        $this->news = [];

        $this->licenseStatus = LicenseClient::getStatus();
        $licenseActive = $this->licenseStatus['active'] ?? false;

        // Sin licencia: ocultar botón de update + aviso prominente
        $this->updated = $licenseActive
            ? SolwedGitHub::canUpdateCore() === false
            : true;

        if (!$licenseActive && LicenseClient::getType() !== 'managed') {
            Tools::log()->warning('Tu suscripción no está activa. No se están realizando ' .
                'copias de seguridad ni actualizaciones de seguridad, estabilidad y legalidad. ' .
                'Activa tu plan en app.solwed.es para restablecer el servicio.');
        }
    }
}

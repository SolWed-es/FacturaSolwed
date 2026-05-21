<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;

/**
 * Sobreescribe Dashboard de NeoRazorX:
 * - Elimina Telemetry y Forja
 * - Elimina llamadas a facturascripts.com para noticias
 * - Añade estado de licencia Solwed
 */
class Dashboard extends \FacturaScripts\Core\Controller\Dashboard
{
    /** @var array */
    public $licenseStatus = [];

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // sustituimos sin Telemetry ni Forja
        $this->registered = true;
        $this->updated = SolwedGitHub::canUpdateCore() === false;
        $this->news = []; // sin noticias de facturascripts.com

        // estado de licencia Solwed
        $this->licenseStatus = LicenseClient::getStatus();
    }
}

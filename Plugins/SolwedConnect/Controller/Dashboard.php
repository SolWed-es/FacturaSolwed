<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\SolwedGitHub;

/**
 * Sobreescribe Dashboard de NeoRazorX:
 * - Elimina Telemetry y Forja
 * - Elimina llamadas a facturascripts.com para noticias
 * - Muestra aviso si la suscripción no está activa
 * - Muestra notificaciones enviadas por Mind (vía heartbeat)
 */
class Dashboard extends \FacturaScripts\Core\Controller\Dashboard
{
    /** @var array */
    public $licenseStatus = [];

    /** @var array  Notificaciones pendientes de Mind [{id, tipo, titulo, mensaje}] */
    public $mindNotifications = [];

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->registered = true;
        $this->news = [];

        // Acción: usuario descarta notificaciones
        if ($this->request->inputOrQuery('action') === 'dismiss-notifications') {
            MindClient::markNotificationsRead();
            Cache::delete('solwedconnect_notifications');
            $this->redirect($this->url());
            return;
        }

        // Acción: activar licencia con código
        if ($this->request->inputOrQuery('action') === 'activate-license'
            && !LicenseClient::isManaged()
            && $this->validateFormToken()) {
            $code = trim($this->request->request->get('license_code', ''));
            if (empty($code)) {
                Tools::log()->warning('Introduce un código de activación.');
            } else {
                $result = LicenseClient::activate($code, Tools::settings('default', 'site_url', ''), '');
                if ($result['ok'] ?? false) {
                    Tools::log()->notice('Licencia activada correctamente.');
                } else {
                    Tools::log()->error($result['error'] ?? 'Error al activar la licencia.');
                }
            }
            $this->redirect($this->url());
            return;
        }

        // Acción: quitar licencia (solo self-hosted)
        if ($this->request->inputOrQuery('action') === 'revoke-license'
            && !LicenseClient::isManaged()
            && $this->validateFormToken()) {
            LicenseClient::revoke();
            Tools::log()->notice('Licencia eliminada correctamente.');
            $this->redirect($this->url());
            return;
        }

        $this->licenseStatus = LicenseClient::getStatus();
        $this->licenseStatus['managed'] = LicenseClient::isManaged();
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

        // Notificaciones de Mind (almacenadas por el cron del heartbeat)
        $cached = Cache::get('solwedconnect_notifications');
        $this->mindNotifications = is_array($cached) ? $cached : [];
    }
}

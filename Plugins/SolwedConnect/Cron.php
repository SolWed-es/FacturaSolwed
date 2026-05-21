<?php
namespace FacturaScripts\Plugins\SolwedConnect;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;

class Cron extends CronClass
{
    public function run(): void
    {
        // ping de telemetría cada 6 horas
        if ($this->isTimeForJob('solwedconnect-ping', 6)) {
            $this->sendTelemetry();
            $this->markJobDone('solwedconnect-ping');
        }

        // reverificación de licencia cada 23 horas
        if ($this->isTimeForJob('solwedconnect-license', 23)) {
            LicenseClient::clearCache();
            $this->markJobDone('solwedconnect-license');
        }
    }

    private function sendTelemetry(): void
    {
        $plugins = array_values(array_map(
            fn($p) => $p->name,
            array_filter(Plugins::list(), fn($p) => $p->enabled)
        ));

        $userCount = 0;
        try {
            $userModel = new \FacturaScripts\Dinamic\Model\User();
            $userCount = count($userModel->all([], [], 0, 0));
        } catch (\Exception $e) {
            // modelo no disponible
        }

        MindClient::sendTelemetry(
            $plugins,
            $userCount,
            (string) Kernel::version(),
            LicenseClient::getPlan()
        );
    }
}

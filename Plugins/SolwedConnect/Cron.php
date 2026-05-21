<?php
namespace FacturaScripts\Plugins\SolwedConnect;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;
use FacturaScripts\Plugins\SolwedConnect\Lib\MindClient;

class Cron extends CronClass
{
    public function run(): void
    {
        // telemetría cada 6 horas
        $this->job('solwedconnect-ping')
            ->every('6 hours')
            ->run(function () {
                $this->sendTelemetry();
            });

        // reverificación de licencia cada 23 horas
        $this->job('solwedconnect-license')
            ->every('23 hours')
            ->run(function () {
                LicenseClient::clearCache();
            });
    }

    private function sendTelemetry(): void
    {
        $plugins = array_values(Plugins::enabled());

        $userCount = 0;
        try {
            $user = new User();
            $userCount = $user->count([Where::eq('enabled', true)]);
        } catch (\Throwable $e) {
            // modelo puede no estar disponible
        }

        MindClient::sendTelemetry(
            $plugins,
            $userCount,
            (string) Kernel::version(),
            LicenseClient::getPlan()
        );
    }
}

<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Envía eventos e ingestión de telemetría a w-api (api.solwed.es).
 *
 * Endpoints:
 *   POST /fs-eventos/evento                        → evento genérico
 *   PUT  /fs-eventos/instalacion/:id/telemetria    → datos de plugins/usuarios
 *   GET  /fs-eventos/instalacion-por-token?token=  → resolver instalación
 *
 * Fire-and-forget. Todos los errores se silencian para no romper el flujo.
 */
class MindClient
{
    const BASE_URL = 'https://api.solwed.es';

    public static function emit(string $evento, array $payload = []): void
    {
        $token = Tools::settings('solwedconnect', 'mind_token', '');

        if (empty($token)) {
            return;
        }

        try {
            Http::postJson(self::BASE_URL . '/fs-eventos/evento', [
                'evento' => $evento,
                'payload' => $payload,
            ])
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(3)
                ->ok();
        } catch (\Exception $e) {
            // silenciar
        }
    }

    public static function sendTelemetry(array $plugins, int $userCount, string $fsVersion, string $plan): void
    {
        $instalacionId = Tools::settings('solwedconnect', 'instalacion_id', '');
        $token = Tools::settings('solwedconnect', 'mind_token', '');

        if (empty($token) || empty($instalacionId)) {
            return;
        }

        try {
            Http::put(self::BASE_URL . '/fs-eventos/instalacion/' . $instalacionId . '/telemetria', [
                'plugins' => $plugins,
                'user_count' => $userCount,
                'fs_version' => $fsVersion,
                'plan' => $plan,
            ])
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(5)
                ->ok();
        } catch (\Exception $e) {
            // silenciar
        }
    }

    public static function sendHeartbeat(string $fsVersion): void
    {
        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return;
        }

        try {
            Http::postJson(self::BASE_URL . '/fs/heartbeat', [
                'fs_version' => $fsVersion,
                'php_version' => PHP_VERSION,
            ])
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(5)
                ->ok();
        } catch (\Exception $e) {
            // silenciar — el heartbeat no debe romper el cron
        }
    }

    /**
     * Resuelve el instalacion_id desde el token (útil al activar por primera vez).
     */
    public static function resolveInstallation(string $token): ?int
    {
        try {
            $response = Http::get(self::BASE_URL . '/fs-eventos/instalacion-por-token?token=' . urlencode($token))
                ->setTimeout(5);

            if ($response->status() === 200) {
                $data = $response->json() ?? [];
                return $data['id'] ?? null;
            }
        } catch (\Exception $e) {
            // silenciar
        }
        return null;
    }
}

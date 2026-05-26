<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Envía eventos e ingestión de telemetría a w-api (api.solwed.es).
 *
 * Endpoints:
 *   POST /fs/heartbeat                            → ping + recibe notificaciones pendientes
 *   POST /fs/notificaciones/leidas                → marca notificaciones como leídas
 *   POST /fs/eventos/evento                       → evento genérico
 *   PUT  /fs/eventos/instalacion/:id/telemetria   → datos de plugins/usuarios
 *   GET  /fs/eventos/instalacion-por-token?token= → resolver instalación
 *
 * Fire-and-forget. Todos los errores se silencian para no romper el flujo.
 */
class MindClient
{
    const BASE_URL = 'https://api.solwed.es';

    public static function baseUrl(): string
    {
        return rtrim(Tools::settings('solwedconnect', 'mind_url', self::BASE_URL), '/');
    }

    public static function emit(string $evento, array $payload = []): void
    {
        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return;
        }

        try {
            Http::postJson(self::baseUrl() . '/fs/eventos/evento', [
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
        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return;
        }

        // Resolver instalacion_id la primera vez y persistirlo en settings
        $instalacionId = Tools::settings('solwedconnect', 'instalacion_id', '');
        if (empty($instalacionId)) {
            $instalacionId = (string)(self::resolveInstallation($token) ?? '');
            if (empty($instalacionId)) {
                return;
            }
            Tools::settingsSet('solwedconnect', 'instalacion_id', $instalacionId);
            Tools::settingsSave();
        }

        try {
            Http::put(self::baseUrl() . '/fs/eventos/instalacion/' . $instalacionId . '/telemetria', json_encode([
                'plugins'    => $plugins,
                'user_count' => $userCount,
                'fs_version' => $fsVersion,
                'plan'       => $plan,
            ]))
                ->setHeader('Content-Type', 'application/json')
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(5)
                ->ok();
        } catch (\Exception $e) {
            // silenciar
        }
    }

    /**
     * Envía heartbeat a Mind y devuelve las notificaciones pendientes.
     * La respuesta viaja de vuelta en el mismo request (piggyback).
     *
     * @return array  Lista de notificaciones: [{id, tipo, titulo, mensaje}]
     */
    public static function sendHeartbeat(string $fsVersion): array
    {
        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return [];
        }

        try {
            $response = Http::postJson(self::baseUrl() . '/fs/heartbeat', [
                'fs_version' => $fsVersion,
                'php_version' => PHP_VERSION,
            ])
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(5);

            $data = $response->json() ?? [];
            return is_array($data['notifications'] ?? null) ? $data['notifications'] : [];

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Marca todas las notificaciones pendientes como leídas en Mind.
     * Se llama cuando el usuario descarta los avisos desde el Dashboard.
     */
    public static function markNotificationsRead(): void
    {
        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return;
        }

        try {
            Http::postJson(self::baseUrl() . '/fs/notificaciones/leidas', [])
                ->setHeader('X-Mind-Token', $token)
                ->setTimeout(5)
                ->ok();
        } catch (\Exception $e) {
            // silenciar
        }
    }

    /**
     * Resuelve el instalacion_id desde el token (útil al activar por primera vez).
     */
    public static function resolveInstallation(string $token): ?int
    {
        try {
            $response = Http::get(self::baseUrl() . '/fs/eventos/instalacion-por-token?token=' . urlencode($token))
                ->setTimeout(5);

            if ($response->status() === 200) {
                $data = $response->json() ?? [];
                return isset($data['id']) ? (int)$data['id'] : null;
            }
        } catch (\Exception $e) {
            // silenciar
        }
        return null;
    }
}

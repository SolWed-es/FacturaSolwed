<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Verifica licencia/suscripción con w-api (api.solwed.es).
 *
 * Cache: Cache::set() no soporta TTL — usamos FileCache de FS que expira automáticamente.
 * El TTL se gestiona manualmente guardando el timestamp en el propio array.
 */
class LicenseClient
{
    const CACHE_KEY  = 'solwedconnect_license';
    const CACHE_TTL  = 86400;    // 24h en segundos
    const GRACE_TTL  = 259200;   // 72h en segundos
    /** @deprecated Usar MindClient::baseUrl() — respeta el setting mind_url */

    const FEATURES = [
        'principiante' => [
            'facturacion_electronica', 'verifactu', 'presupuestos', 'albaranes',
            'inventario_basico', 'contabilidad_basica', 'informes_basicos',
            'clientes', 'proveedores',
        ],
        'estandar' => [
            'facturacion_electronica', 'verifactu', 'presupuestos', 'albaranes',
            'inventario_basico', 'contabilidad_basica', 'informes_basicos',
            'clientes', 'proveedores',
            'tpv', 'tickets', 'almacen_completo', 'transferencias_stock',
            'rrhh', 'nominas', 'contabilidad_avanzada', 'informes_avanzados', 'proyectos',
        ],
        'profesional' => [
            'facturacion_electronica', 'verifactu', 'presupuestos', 'albaranes',
            'inventario_basico', 'contabilidad_basica', 'informes_basicos',
            'clientes', 'proveedores',
            'tpv', 'tickets', 'almacen_completo', 'transferencias_stock',
            'rrhh', 'nominas', 'contabilidad_avanzada', 'informes_avanzados', 'proyectos',
            'portal_cliente', 'portal_chat', 'multi_empresa',
            'firmas_digitales', 'conciliacion_bancaria', 'remesas_sepa',
        ],
    ];

    // ── Consulta pública ──────────────────────────────────────────────────────

    public static function getStatus(): array
    {
        // Comprobar cache (con TTL manual)
        $cached = Cache::get(self::CACHE_KEY);
        if (!empty($cached) && self::isFresh($cached, self::CACHE_TTL)) {
            return $cached;
        }

        $token = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($token)) {
            return self::unlicensed('sin_token');
        }

        return self::verify($token);
    }

    public static function isActive(): bool
    {
        return (bool)((self::getStatus())['active'] ?? false);
    }

    public static function getPlan(): string
    {
        return (string)((self::getStatus())['plan'] ?? 'none');
    }

    public static function getType(): string
    {
        return Tools::settings('solwedconnect', 'instance_type', 'self-hosted');
    }

    public static function isManaged(): bool
    {
        return self::getType() === 'managed';
    }

    public static function hasFeature(string $feature): bool
    {
        $plan = self::getPlan();
        return in_array($feature, self::FEATURES[$plan] ?? [], true);
    }

    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
        Cache::delete(self::CACHE_KEY . '_grace');
    }

    public static function revoke(): void
    {
        Tools::settingsSet('solwedconnect', 'mind_token', '');
        Tools::settingsSet('solwedconnect', 'instalacion_id', '');
        Tools::settingsSave();
        self::clearCache();
    }

    // ── Activación self-hosted ────────────────────────────────────────────────

    public static function activate(string $code, string $url, string $nombre): array
    {
        try {
            $response = Http::postJson(MindClient::baseUrl() . '/fs/activate', [
                'code' => $code,
                'url' => $url,
                'nombre' => $nombre,
            ])->setTimeout(10);

            if ($response->status() === 200) {
                $data = $response->json() ?? [];
                if (!empty($data['mind_token'])) {
                    Tools::settingsSet('solwedconnect', 'mind_token', $data['mind_token']);
                    Tools::settingsSet('solwedconnect', 'instalacion_id', (string)($data['instalacion_id'] ?? ''));
                    Tools::settingsSave();
                    self::clearCache();
                    return ['ok' => true, 'mind_token' => $data['mind_token']];
                }
            }

            $body = $response->json() ?? [];
            return ['ok' => false, 'error' => $body['message'] ?? 'Error al activar'];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => 'Sin conexión con app.solwed.es'];
        }
    }

    // ── Webhook entrante ──────────────────────────────────────────────────────

    public static function processWebhook(array $payload): void
    {
        $status = $payload['status'] ?? '';
        $plan   = str_replace('erp-', '', $payload['producto'] ?? '');

        $newStatus = [
            'active'     => in_array($status, ['active', 'trialing'], true),
            'plan'       => $plan,
            'type'       => self::getType(),
            'features'   => self::FEATURES[$plan] ?? [],
            'expires_at' => $payload['expires_at'] ?? null,
            'checked_at' => time(),
            'reason'     => ($status !== 'active') ? $status : null,
        ];

        // BUG FIX: Cache::set() no acepta TTL — guardamos timestamp para TTL manual
        Cache::set(self::CACHE_KEY, $newStatus);
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    private static function verify(string $token): array
    {
        try {
            $response = Http::get(MindClient::baseUrl() . '/fs/license?token=' . urlencode($token))
                ->setTimeout(5);

            if ($response->status() === 200) {
                $raw  = $response->json() ?? [];
                // Soporta respuesta directa {active,plan,...} y wrapped {success,data:{...}}
                $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
                $plan = (string)($data['plan'] ?? 'none');
                $status = [
                    'active'              => (bool)($data['active'] ?? false),
                    'plan'                => $plan,
                    'type'                => self::getType(),
                    'features'            => $data['features'] ?? self::FEATURES[$plan] ?? [],
                    'expires_at'          => $data['expires_at'] ?? null,
                    'dias_restantes'      => $data['dias_restantes'] ?? null,
                    'instalacion_id'      => $data['instalacion_id'] ?? null,
                    'instalacion_nombre'  => $data['instalacion_nombre'] ?? null,
                    'checked_at'          => time(),
                ];
                // BUG FIX: sin TTL — gestionado con checked_at
                Cache::set(self::CACHE_KEY, $status);
                Cache::set(self::CACHE_KEY . '_grace', $status);
                return $status;
            }

            if (in_array($response->status(), [401, 403], true)) {
                $body   = $response->json() ?? [];
                $status = self::unlicensed($body['reason'] ?? 'sin_suscripcion');
                Cache::set(self::CACHE_KEY, $status);
                return $status;
            }
        } catch (\Exception $e) {
            // sin conexión — caer al bloque de gracia
        }

        // BUG FIX: gracia SOLO si hubo una verificación exitosa previa
        $grace = Cache::get(self::CACHE_KEY . '_grace');
        if (!empty($grace) && self::isFresh($grace, self::GRACE_TTL)) {
            $grace['grace'] = true;
            Cache::set(self::CACHE_KEY, $grace);
            return $grace;
        }

        // Sin verificación previa + sin conexión → no conceder acceso
        return self::unlicensed('sin_conexion');
    }

    /** Comprueba si un status cacheado sigue siendo válido según su TTL */
    private static function isFresh(array $status, int $ttl): bool
    {
        return isset($status['checked_at']) && (time() - $status['checked_at']) < $ttl;
    }

    private static function unlicensed(string $reason = 'sin_suscripcion'): array
    {
        return [
            'active'             => false,
            'plan'               => 'none',
            'type'               => self::getType(),
            'features'           => [],
            'expires_at'         => null,
            'dias_restantes'     => null,
            'checked_at'         => time(),
            'reason'             => $reason,
        ];
    }
}

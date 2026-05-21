<?php
namespace FacturaScripts\Plugins\SolwedConnect\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Verifica licencia/suscripción con w-api (app.solwed.es).
 *
 * Endpoints:
 *   GET  /fs/license?token=<mind_token>   → estado de suscripción
 *   POST /fs/activate                     → activar instalación self-hosted
 *
 * Cache 24h. 72h de gracia sin conexión.
 */
class LicenseClient
{
    const CACHE_KEY  = 'solwedconnect_license';
    const CACHE_TTL  = 86400;    // 24h
    const GRACE_TTL  = 259200;   // 72h
    const BASE_URL   = 'https://api.solwed.es';

    // Features por plan (mirror del catálogo de w-api)
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
        $cached = Cache::get(self::CACHE_KEY);
        if (!empty($cached)) {
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
        return self::getStatus()['active'] ?? false;
    }

    public static function getPlan(): string
    {
        return self::getStatus()['plan'] ?? 'none';
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
        return in_array($feature, self::FEATURES[$plan] ?? []);
    }

    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    // ── Activación self-hosted ─────────────────────────────────────────────────

    /**
     * Activa una instalación self-hosted con código de un solo uso.
     * Devuelve ['ok' => true, 'mind_token' => '...'] o ['ok' => false, 'error' => '...']
     */
    public static function activate(string $code, string $url, string $nombre): array
    {
        try {
            $response = Http::postJson(self::BASE_URL . '/fs/activate', [
                'code' => $code,
                'url' => $url,
                'nombre' => $nombre,
            ])->setTimeout(10);

            if ($response->status() === 200) {
                $data = $response->json() ?? [];
                if (!empty($data['mind_token'])) {
                    // guardar token en settings
                    Tools::settingsSet('solwedconnect', 'mind_token', $data['mind_token']);
                    Tools::settingsSet('solwedconnect', 'instalacion_id', $data['instalacion_id'] ?? '');
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

    // ── Webhook entrante ───────────────────────────────────────────────────────

    /**
     * Procesa notificación en tiempo real de cambio de suscripción.
     * Llamado desde Controller/SolwedWebhook.php
     */
    public static function processWebhook(array $payload): void
    {
        $status = $payload['status'] ?? '';
        $plan = $payload['producto'] ?? '';

        // limpiar "erp-" del nombre del producto si lo trae
        $plan = str_replace('erp-', '', $plan);

        $newStatus = [
            'active' => in_array($status, ['active', 'trialing']),
            'plan' => $plan,
            'type' => self::getType(),
            'features' => self::FEATURES[$plan] ?? [],
            'expires_at' => $payload['expires_at'] ?? null,
            'checked_at' => time(),
            'reason' => $status !== 'active' ? $status : null,
        ];

        Cache::set(self::CACHE_KEY, $newStatus, self::CACHE_TTL);
    }

    // ── Privado ───────────────────────────────────────────────────────────────

    private static function verify(string $token): array
    {
        try {
            $response = Http::get(self::BASE_URL . '/fs/license')
                ->setHeader('Content-Type', 'application/json')
                ->setTimeout(5);

            // pasamos el token como query param según spec de la API
            $response = Http::get(self::BASE_URL . '/fs/license?token=' . urlencode($token))
                ->setTimeout(5);

            if ($response->status() === 200) {
                $data = $response->json() ?? [];
                $plan = $data['plan'] ?? 'none';
                $status = [
                    'active' => (bool)($data['active'] ?? false),
                    'plan' => $plan,
                    'type' => self::getType(),
                    'features' => $data['features'] ?? self::FEATURES[$plan] ?? [],
                    'expires_at' => $data['expires_at'] ?? null,
                    'dias_restantes' => $data['dias_restantes'] ?? null,
                    'instalacion_id' => $data['instalacion_id'] ?? null,
                    'instalacion_nombre' => $data['instalacion_nombre'] ?? null,
                    'checked_at' => time(),
                ];
                Cache::set(self::CACHE_KEY, $status, self::CACHE_TTL);
                // guardar también como gracia para periodos sin conexión
                Cache::set(self::CACHE_KEY . '_grace', $status, self::GRACE_TTL);
                return $status;
            }

            if (in_array($response->status(), [401, 403])) {
                $body = $response->json() ?? [];
                $status = self::unlicensed($body['reason'] ?? 'sin_suscripcion');
                Cache::set(self::CACHE_KEY, $status, self::CACHE_TTL);
                return $status;
            }
        } catch (\Exception $e) {
            // sin conexión
        }

        // periodo de gracia
        $grace = Cache::get(self::CACHE_KEY . '_grace');
        if (!empty($grace)) {
            $grace['grace'] = true;
            Cache::set(self::CACHE_KEY, $grace, self::GRACE_TTL);
            return $grace;
        }

        // primera vez sin conexión → gracia optimista
        $status = [
            'active' => true,
            'plan' => 'unknown',
            'type' => self::getType(),
            'features' => [],
            'expires_at' => null,
            'dias_restantes' => null,
            'checked_at' => time(),
            'grace' => true,
        ];
        Cache::set(self::CACHE_KEY, $status, self::GRACE_TTL);
        return $status;
    }

    private static function unlicensed(string $reason = 'sin_suscripcion'): array
    {
        return [
            'active' => false,
            'plan' => 'none',
            'type' => self::getType(),
            'features' => [],
            'expires_at' => null,
            'dias_restantes' => null,
            'checked_at' => time(),
            'reason' => $reason,
        ];
    }
}

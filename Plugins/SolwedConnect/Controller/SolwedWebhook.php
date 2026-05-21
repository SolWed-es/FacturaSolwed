<?php
namespace FacturaScripts\Plugins\SolwedConnect\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedConnect\Lib\LicenseClient;

/**
 * Recibe webhooks de app.solwed.es cuando cambia la suscripción.
 *
 * POST /SolwedWebhook
 * Header: X-Mind-Token: <mind_token>
 * Body: { event, mind_token, producto, status, expires_at }
 *
 * Si el plugin no expone este endpoint, app.solwed.es lo ignora (fire-and-forget).
 */
class SolwedWebhook implements ControllerInterface
{
    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // validar token
        $headerToken = $_SERVER['HTTP_X_MIND_TOKEN'] ?? '';
        $storedToken = Tools::settings('solwedconnect', 'mind_token', '');
        if (empty($storedToken) || !hash_equals($storedToken, $headerToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        // procesar cambio de suscripción
        LicenseClient::processWebhook($payload);

        echo json_encode(['ok' => true]);
    }
}

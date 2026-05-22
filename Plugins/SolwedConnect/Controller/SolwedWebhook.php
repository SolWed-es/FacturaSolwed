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
 */
class SolwedWebhook implements ControllerInterface
{
    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [
            'menu'        => '',
            'title'       => 'SolwedWebhook',
            'showonmenu'  => false,
        ];
    }

    public function run(): void
    {
        header('Content-Type: application/json');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // Validar token — buscar en header HTTP_X_MIND_TOKEN
        // Apache/Nginx normalizan a HTTP_ + uppercase con _ en vez de -
        $headerToken = $_SERVER['HTTP_X_MIND_TOKEN']
            ?? $_SERVER['HTTP_X_SOLWED_TOKEN']
            ?? '';
        $storedToken = Tools::settings('solwedconnect', 'mind_token', '');

        if (empty($storedToken) || empty($headerToken) || !hash_equals($storedToken, $headerToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty body']);
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        LicenseClient::processWebhook($payload);

        echo json_encode(['ok' => true]);
    }
}

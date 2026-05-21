<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Tools;

/**
 * Public webhook endpoint called by mind.solwed.es to register itself.
 *
 * POST /SolwedMindConnect
 * Body (JSON): { secret, mind_url, mind_token }
 *
 * - Validates secret against env FS_MIND_CONNECT_SECRET (fallback: AppSettings mind.secret)
 * - Saves mind_url → AppSettings mind.url
 * - Saves mind_token → AppSettings mind.token
 * - Returns { success, installation, version }
 */
class SolwedMindConnect implements ControllerInterface
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

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body']);
            return;
        }

        // validar secret — env var tiene prioridad sobre AppSettings
        $secret = $body['secret'] ?? '';
        $expected = getenv('FS_MIND_CONNECT_SECRET') ?: Tools::settings('mind', 'secret', '');
        if (empty($expected) || !hash_equals($expected, $secret)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        // guardar mind_url y mind_token
        if (!empty($body['mind_url'])) {
            Tools::settingsSet('mind', 'url', $body['mind_url']);
        }
        if (!empty($body['mind_token'])) {
            Tools::settingsSet('mind', 'token', $body['mind_token']);
        }
        Tools::settingsSave();

        echo json_encode([
            'success' => true,
            'installation' => Tools::config('db_name', ''),
            'version' => Kernel::version(),
        ]);
    }
}

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

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Fire-and-forget HTTP client for sending domain events to mind.solwed.es.
 *
 * Events are only sent when mind.url and mind.token are configured in AppSettings.
 * All errors are silenced to avoid impacting the main request flow.
 */
class MindClient
{
    /**
     * Emit a domain event to the configured mind endpoint.
     *
     * @param string $event  Event name, e.g. 'factura.created', 'pago.recibido'
     * @param array  $data   Event payload (model fields)
     */
    public static function emit(string $event, array $data): void
    {
        $url = Tools::settings('mind', 'url', '');
        $token = Tools::settings('mind', 'token', '');
        if (empty($url) || empty($token)) {
            return;
        }

        try {
            Http::postJson($url . '/api/fs/events', [
                'event' => $event,
                'installation' => Tools::config('db_name', ''),
                'data' => $data,
                'timestamp' => date('c'),
            ])
                ->setHeader('Authorization', 'Bearer ' . $token)
                ->setTimeout(3)
                ->ok(); // triggers execution; result is intentionally ignored
        } catch (\Exception $e) {
            // silenciar — no queremos romper el flujo principal
        }
    }
}

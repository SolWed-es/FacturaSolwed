# SolwedConnect — Integración con api.solwed.es

Documentación completa de todos los endpoints que consume y expone el plugin SolwedConnect,
y los requisitos del servidor para que la integración funcione.

---

## Requisitos del servidor (api.solwed.es)

Todos los endpoints están en `https://api.solwed.es`. No requieren autenticación de portal
(no usan cookies ni JWT de usuario), sino el `mind_token` de la instalación como credencial.

### Base de datos

La tabla `fs_instalaciones` debe tener estas columnas (migración `20260521_erp_self_hosted.sql`):

```sql
-- Columnas requeridas
id                    SERIAL PRIMARY KEY
mind_token            VARCHAR   -- token único de la instalación (credencial)
estado                VARCHAR   -- 'active' | 'pending' | 'inactive' | 'suspended'
hosting_type          VARCHAR   -- 'solwed' | 'self_hosted'
activation_code       VARCHAR   -- código de un solo uso para activación self-hosted (NULL tras usar)
activation_expires_at TIMESTAMPTZ
nombre                VARCHAR
url                   VARCHAR
idcontacto            INTEGER   -- FK a contactos (para buscar suscripción)
tenant_id             INTEGER

-- Columnas de telemetría (opcionales, se crean si no existen)
plugins_json          JSONB
user_count            INTEGER
fs_version            VARCHAR
telemetry_at          TIMESTAMPTZ
```

La tabla `fs_eventos` debe existir:

```sql
CREATE TABLE IF NOT EXISTS fs_eventos (
    id            SERIAL PRIMARY KEY,
    instalacion_id INTEGER REFERENCES fs_instalaciones(id),
    evento        VARCHAR NOT NULL,
    payload       JSONB DEFAULT '{}',
    created_at    TIMESTAMPTZ DEFAULT NOW(),
    procesado     BOOLEAN DEFAULT FALSE,
    procesado_at  TIMESTAMPTZ,
    error         TEXT
);
```

---

## Endpoints que consume el plugin

### 1. `GET /fs/license?token=<mind_token>`

**Propósito:** Verificar si la instalación tiene suscripción ERP activa.  
**Auth:** `mind_token` en query string (el token ES la credencial).  
**Frecuencia:** Cada 23h via cron + al arrancar si la cache está vacía.  
**Cache local:** 24h en cache de FacturaScripts; 72h de gracia si el servidor no responde.

**Respuesta con licencia activa:**
```json
{
  "active": true,
  "plan": "estandar",
  "plan_nombre": "erp-estandar",
  "features": ["facturacion_electronica", "verifactu", "tpv", "..."],
  "precio": 49,
  "intervalo": "month",
  "expires_at": "2026-06-21T00:00:00.000Z",
  "dias_restantes": 30,
  "instalacion_id": 42,
  "instalacion_nombre": "Mi empresa SL"
}
```

**Respuesta sin licencia:**
```json
{
  "active": false,
  "reason": "sin_suscripcion"
}
```

**Posibles valores de `reason`:** `sin_token`, `sin_contacto`, `sin_suscripcion`, `pago_pendiente`, `sin_conexion`, `inactive`, `suspended`, `pending`

**Comportamiento del plugin según licencia:**
- `active: true` → funcionalidad completa
- `active: false` → ERP sigue funcionando, se oculta botón de actualización, se muestra aviso en dashboard

**Nota de seguridad:** El endpoint no devuelve `Cache-Control` cacheables — usa `no-store`.

---

### 2. `POST /fs/activate`

**Propósito:** Activar una instalación self-hosted con el código de un solo uso.  
**Auth:** Pública (el código de activación es la credencial).

**Request body:**
```json
{
  "code": "SOLWED-ABCD-1234-EFGH",
  "url": "https://erp.miempresa.es",
  "nombre": "Mi empresa SL"
}
```

**Respuesta exitosa (200):**
```json
{
  "mind_token": "uuid-v4-generado-al-crear-instalacion",
  "instalacion_id": 42
}
```

El plugin guarda `mind_token` e `instalacion_id` en settings de FacturaScripts.

**Errores:**
- `400` — faltan campos
- `410` — código inválido, ya usado o expirado

**Flujo completo de activación self-hosted:**
1. Admin crea instalación en `app.solwed.es` → genera código `SOLWED-XXXX-XXXX-XXXX`
2. Admin introduce el código en el plugin (dashboard o settings)
3. Plugin llama `POST /fs/activate` con el código + URL de la instalación
4. Servidor marca `estado='active'`, borra el código (un solo uso), devuelve `mind_token`
5. Plugin guarda `mind_token` en settings y empieza a usar los demás endpoints

---

### 3. `POST /fs-eventos/evento`

**Propósito:** Log de eventos de la instalación (fire-and-forget).  
**Auth:** `X-Mind-Token: <mind_token>` en header.  
**Servidor resuelve `instalacion_id` del token** — el cliente NO envía `instalacion_id`.

**Request body:**
```json
{
  "evento": "plugin_instalado",
  "payload": { "plugin": "TPVneo", "version": "1.2.3" }
}
```

**Eventos que emite el plugin:**

| Evento | Payload | Descripción |
|--------|---------|-------------|
| `plugin_instalado` | `{plugin, version}` | Plugin instalado |
| `plugin_actualizado` | `{plugin, version_anterior, version_nueva}` | Plugin actualizado |
| `plugin_desinstalado` | `{plugin}` | Plugin eliminado |
| `core_actualizado` | `{version_anterior, version_nueva}` | Core FS actualizado |
| `activacion_completada` | `{}` | Primera activación exitosa |

**Respuesta:** `201 Created` con `{id}` del evento.

---

### 4. `PUT /fs-eventos/instalacion/:id/telemetria`

**Propósito:** Enviar datos de uso periódicos.  
**Auth:** `X-Mind-Token: <mind_token>` en header.  
**Servidor valida que el token pertenece al `:id`** — previene que un token actualice otra instalación.  
**Frecuencia:** Cada 6h via cron.

**Request body:**
```json
{
  "plugins": ["TPVneo", "Anticipos", "SolwedTheme"],
  "user_count": 5,
  "fs_version": "2026.1",
  "plan": "estandar"
}
```

**Respuesta:** `200 OK` con `{updated: 1}`.

---

### 5. `GET /fs-eventos/instalacion-por-token?token=<mind_token>`

**Propósito:** Resolver `instalacion_id` a partir del token.  
**Auth:** `token` en query string.  
**Uso:** Al activar por primera vez, para obtener el ID que se guarda en settings.

**Respuesta (200):**
```json
{
  "id": 42,
  "nombre": "Mi empresa SL",
  "url": "https://erp.miempresa.es",
  "idcontacto": 150,
  "estado": "active"
}
```

**Errores:** `404` si el token no existe o la instalación no está activa.

---

### 6. `POST /fs/heartbeat`

**Propósito:** Ping periódico para detectar instancias caídas y recibir notificaciones push.  
**Auth:** `X-Mind-Token: <mind_token>` en header.  
**Frecuencia:** Cada 15 minutos via cron.

**Request body:**
```json
{
  "fs_version": "2026.1",
  "php_version": "8.3.4"
}
```

**Respuesta (200):**
```json
{
  "ok": true,
  "updated": 1,
  "notifications": [
    {
      "id": "notif-uuid",
      "tipo": "info",
      "titulo": "Actualización disponible",
      "mensaje": "FacturaScripts 2026.2 ya está disponible."
    }
  ]
}
```

El plugin guarda las notificaciones en cache y las muestra en el dashboard.  
Cuando el usuario las descarta, el plugin llama `POST /fs/notificaciones/leidas` (ver abajo).

---

## Endpoints que expone el plugin (recibe el servidor)

### 7. `POST /SolwedWebhook`

**Propósito:** Recibir notificaciones de cambios de suscripción desde app.solwed.es.  
**Auth:** `X-Mind-Token: <mind_token>` en header (el servidor lo envía, el plugin lo valida).

**Body que envía el servidor:**
```json
{
  "event": "subscription.updated",
  "producto": "erp-estandar",
  "status": "active",
  "expires_at": "2026-06-21T00:00:00.000Z"
}
```

**Comportamiento:** El plugin actualiza la cache de licencia inmediatamente, sin esperar al cron.

**Nota para el servidor:** El webhook debe enviarse cuando cambia `subscriptions.status`. La URL es `https://<url_erp>/SolwedWebhook`.

---

### 8. `POST /api/3/solwedupdate`

**Propósito:** Gestión remota de plugins y core (solo instancias `managed`).  
**Auth:** `Token: <api_key_facturascripts>` en header (la API Key nativa de FS).  
**Disponible solo si:** `instance_type = 'managed'` en settings del plugin.

**Acciones disponibles:**

```json
{ "action": "list-plugins" }
{ "action": "check-updates" }
{ "action": "install-plugin", "plugin": "TPVneo" }
{ "action": "update-plugin",  "plugin": "TPVneo" }
{ "action": "enable-plugin",  "plugin": "TPVneo" }
{ "action": "disable-plugin", "plugin": "TPVneo" }
{ "action": "update-core" }
```

---

## Configuración del plugin

Los settings se guardan en `fs_settings` de FacturaScripts bajo el nick `solwedconnect`:

| Clave | Valor por defecto | Descripción |
|-------|-------------------|-------------|
| `mind_url` | `https://mind.solwed.es` | URL base de Mind |
| `mind_token` | `''` | Token de autenticación de la instalación |
| `instalacion_id` | `''` | ID de la instalación en api.solwed.es |
| `instance_type` | `'self-hosted'` | `'managed'` o `'self-hosted'` |

**Variables de entorno** (tienen prioridad sobre los settings guardados):

```env
FS_MIND_TOKEN=uuid-del-token      # Token inyectado en instancias managed
FS_INSTANCE_TYPE=managed          # Tipo de instancia
```

---

## Flujo de instancia managed (provisionada por Solwed)

1. Servidor crea la instalación en `fs_instalaciones` con `hosting_type='solwed'`, `estado='active'`, `mind_token=<uuid>`
2. Servidor provisiona el contenedor con `FS_MIND_TOKEN=<uuid>` y `FS_INSTANCE_TYPE=managed`
3. Al arrancar FacturaScripts, `Init::update()` detecta `FS_MIND_TOKEN` y lo guarda en settings
4. El plugin empieza heartbeats y telemetría automáticamente
5. El servidor puede gestionar el ERP remotamente via `POST /api/3/solwedupdate`

## Flujo de instancia self-hosted (cliente instala en su servidor)

1. Admin genera código en `app.solwed.es` → `POST /api/admin/erp/activation-code`
2. Admin le da el código al cliente (`SOLWED-XXXX-XXXX-XXXX`, válido X días)
3. Cliente instala SolwedConnect en su FacturaScripts
4. Cliente introduce el código en el dashboard del plugin
5. Plugin llama `POST /fs/activate` → recibe `mind_token`
6. A partir de aquí funciona igual que managed (heartbeat, telemetría, licencia)

# FacturaSolwed — Roadmap de Features

## Feature 1: Backup automático para suscriptores

### Objetivo
Los clientes con suscripción activa reciben cada noche un backup de su base de datos PostgreSQL en un enlace de descarga temporal. Sin configuración manual por su parte.

### Arquitectura

```
Servidor del cliente (cron 03:00)
│
├─ pg_dump → backup-YYYY-MM-DD.sql.gz
│
└─ POST https://api.solwed.es/fs/backup/upload
   Headers: X-Mind-Token: <mind_token>
   Body:    multipart/form-data → archivo .sql.gz
              │
              ▼
         api.solwed.es (w-api)
         ├─ verifica mind_token → fs_instalaciones
         ├─ sube a pCloud → carpeta erp-backups/<instalacion_id>/YYYY-MM-DD.sql.gz
         │    via uploadfile (pCloud API) con PCloudProvider
         ├─ genera enlace de descarga (getfilelink, expire = now + 72h)
         ├─ guarda registro en fs_backups (id, instalacion_id, fecha, size, pcloud_file_id, download_url, url_expires_at)
         └─ envía email al cliente (Resend)
              │
              ▼
         Email cliente
         "Tu backup del 26/05/2026 está listo — [Descargar] (caduca en 72h)"
```

### Componentes a implementar

#### 1. Script `backup-agent.sh` (nuevo, instalado por `install.sh`)
```bash
# /usr/local/bin/solwed-backup
# Ejecutado por cron: 0 3 * * * root /usr/local/bin/solwed-backup
```
- Lee credenciales de `/etc/solwed-erp.conf` (generado por `install.sh`)
- Hace `pg_dump` con compresión gzip
- Sube a `api.solwed.es/fs/backup/upload` con `curl` usando el `mind_token`
- Limpia backups locales de más de 3 días (solo guarda lo local de seguridad)
- Loguea resultado en `/var/log/solwed-backup.log`

#### 2. Actualizar `install.sh`
- Generar `/etc/solwed-erp.conf` con `MIND_TOKEN`, `DB_*` credentials
- Instalar `backup-agent.sh` en `/usr/local/bin/solwed-backup`
- Añadir crontab: `0 3 * * * root /usr/local/bin/solwed-backup`

#### 3. Endpoint `POST /fs/backup/upload` (w-api — nuevo router `fs-backup.ts`)
- Auth: `X-Mind-Token` header → verificar en `fs_instalaciones`
- Verificar licencia activa
- Límite tamaño: 2GB
- Subir a pCloud via `PCloudProvider`:
  - Crear carpeta `/erp-backups/<instalacion_id>` si no existe (`createfolder`)
  - Subir archivo (`uploadfile` con multipart stream)
  - Guardar `fileId` devuelto por pCloud
- Generar enlace de descarga: `getfilelink` con `expire = now + 72h` (Unix timestamp)
  - URL final: `https://{hosts[0]}{path}`
- Insertar en `fs_backups` (nueva tabla)
- Enviar email via Resend con enlace
- Borrar backups del mismo cliente de más de 30 días (listar carpeta → `deletefile` por fecha)

#### 4. Endpoint `GET /fs/backup/list` (w-api)
- Devuelve historial de backups de la instalación (últimos 30)
- Cada item incluye `download_url` regenerada en el momento si está expirada (`getfilelink` fresco)
- Usado por app.solwed.es y opcionalmente por el ERP (Dashboard)

#### 5. DB — nueva tabla `fs_backups`
```sql
CREATE TABLE fs_backups (
  id              SERIAL PRIMARY KEY,
  instalacion_id  INTEGER NOT NULL REFERENCES fs_instalaciones(id),
  filename        TEXT NOT NULL,
  size_bytes      BIGINT,
  pcloud_file_id  BIGINT NOT NULL,
  download_url    TEXT,
  url_expires_at  TIMESTAMPTZ,
  created_at      TIMESTAMPTZ DEFAULT NOW()
);
```

#### 6. pCloud (PCloudProvider — ya en w-api)
- Proveedor: `lib/providers/pcloud.ts` — expandir con 3 nuevos métodos:
  - `uploadFile(folderId, filename, stream)` → `uploadfile`
  - `createFolder(name, parentFolderId)` → `createfolder`
  - `deleteFile(fileId)` → `deletefile`
- Carpeta raíz: `/erp-backups/` (crear una vez manualmente o en primer upload)
- Subcarpeta por instalación: `/erp-backups/<instalacion_id>/`
- Retención manual: listar carpeta → borrar archivos con `created` > 30 días
- Sin coste adicional (pCloud Business ya contratado)

#### 7. Email (Resend — ya disponible en w-api)
- Template: "Backup listo"
- Asunto: `[FacturaSolwed] Backup del DD/MM/YYYY disponible`
- CTA: botón "Descargar backup" → enlace pCloud con TTL 72h
- Aviso: "Este enlace caduca en 72 horas"

### Dependencias
- `PCLOUD_TOKEN` en secrets de w-api (ya presente)
- `PCLOUD_BASE` en secrets de w-api (ya presente)
- `FS_MIND_TOKEN` en `/etc/solwed-erp.conf` del servidor del cliente
- `pg_dump` disponible en el servidor del cliente (instalado por `install.sh`)
- `curl` disponible (siempre presente en Debian/Ubuntu)
- Carpeta `/erp-backups/` creada en pCloud (operación única de setup)

### Estimación
- `backup-agent.sh` + cambios `install.sh`: ~3h
- Endpoint w-api + tabla DB: ~4h
- Email template: ~1h
- Tests: ~2h
- **Total: ~10h**

---

## Feature 2: Acceso remoto (VPN / túnel)

### Objetivo
Los clientes con ERP en red local de oficina (o en casa) pueden acceder al ERP desde cualquier lugar de forma segura, sin abrir puertos en el router.

### Decisión de arquitectura

**Fase 1: Tailscale** (zero infrastructure, lanzamiento rápido)
- Tailscale gestiona la coordinación VPN (WireGuard bajo el capó)
- Solwed usa la Tailscale Admin API para crear y gestionar tailnets por cliente
- Gratis hasta 100 dispositivos / 3 usuarios por tailnet
- El cliente instala Tailscale en su PC de casa (Windows/Mac/iOS/Android)

**Fase 2: Headscale** (cuando escale a 50+ clientes o se necesite control total)
- Headscale = coordinador Tailscale self-hosted (un servidor Solwed)
- Misma app de Tailscale en el cliente → migración transparente
- ~5€/mes VPS adicional

### Arquitectura Fase 1 (Tailscale)

```
install.sh (servidor del cliente)
│
├─ apt install tailscale
├─ POST api.solwed.es/fs/vpn/provision {mind_token}
│         │
│         ▼
│    api.solwed.es
│    ├─ verifica mind_token
│    ├─ Tailscale Admin API → crea auth key para el tailnet del cliente
│    │   (o crea el tailnet si es la primera vez)
│    └─ devuelve {auth_key, tailnet_name}
│
└─ tailscale up --authkey=<auth_key> --hostname=erp-solwed
   → servidor queda en la VPN con IP 100.x.x.x

app.solwed.es (portal cliente)
├─ muestra QR code de invitación al tailnet
├─ el cliente escanea desde su móvil/PC de casa
└─ ya puede acceder al ERP por http://100.x.x.x/
```

### Componentes a implementar

#### 1. Endpoint `POST /fs/vpn/provision` (w-api — nuevo)
- Auth: `X-Mind-Token`
- Verifica licencia activa
- Llama Tailscale Admin API:
  - Si primera vez: crea tailnet para el cliente (`<instalacion_id>.solwed.net`)
  - Genera auth key (reusable=true, ephemeral=false, TTL=1h)
- Guarda `tailnet_id`, `tailnet_name` en `fs_instalaciones.metadata`
- Devuelve `{auth_key, tailnet_name, join_url}`

#### 2. Endpoint `GET /fs/vpn/status` (w-api — nuevo)
- Devuelve estado de la VPN: `{enabled, tailnet_name, devices: [{name, ip, last_seen}]}`
- Usado por app.solwed.es para mostrar el panel de VPN

#### 3. Endpoint `POST /fs/vpn/invite` (w-api — nuevo)
- Genera un enlace de invitación para que el cliente una su PC de casa al tailnet
- Devuelve `{invite_url, qr_code_url}` (QR generado por Tailscale API)

#### 4. Actualizar `install.sh`
```bash
# Sección VPN (opcional — preguntar al usuario)
read -rp "¿Activar acceso remoto VPN? [S/n]: " _vpn
if [[ "${_vpn,,}" != "n" ]]; then
    install_vpn
fi
```
Función `install_vpn()`:
- `curl -fsSL https://tailscale.com/install.sh | sh`
- Llama a `api.solwed.es/fs/vpn/provision`
- `tailscale up --authkey=<key> --hostname=erp-$(hostname)`
- Muestra la IP de la VPN y el enlace para unir otros dispositivos

#### 5. Panel VPN en app.solwed.es (nuevo)
- Sección "Acceso remoto" en el portal del cliente
- Muestra: estado (activo/inactivo), IP de la VPN, dispositivos conectados
- Botón "Conectar nuevo dispositivo" → genera invitación + QR
- Botón "Desconectar dispositivo" → revocar acceso

#### 6. Tailscale Admin API — credenciales necesarias
- `TAILSCALE_API_KEY` en secrets de w-api
- `TAILSCALE_TAILNET` (la organización de Solwed en Tailscale)
- Llamadas: `POST /v2/tailnet/{tailnet}/keys`, `GET /v2/tailnet/{tailnet}/devices`

### Flujo completo del cliente

```
1. Cliente instala ERP con install.sh → elige activar VPN
   → servidor queda en VPN con IP 100.x.x.x

2. Cliente va a app.solwed.es → panel "Acceso remoto"
   → ve el QR / enlace de invitación

3. Cliente instala Tailscale en su PC de casa
   → escanea QR o abre enlace de invitación
   → ya puede acceder al ERP desde casa en http://100.x.x.x/

4. (Futuro) Cliente añade móvil, portátil, etc. desde el mismo panel
```

### Consideraciones de seguridad
- La IP de la VPN (100.x.x.x) solo es accesible desde dispositivos del mismo tailnet
- No se abre ningún puerto en el router del cliente
- Tailscale cifra con WireGuard (ChaCha20-Poly1305)
- Si el cliente cancela la suscripción: revocar auth keys vía Tailscale API

### Dependencias
- Cuenta Tailscale (plan gratuito para empezar, ~$6/usuario/mes para Teams)
- `TAILSCALE_API_KEY` en w-api secrets
- `tailscale` instalable en Debian/Ubuntu/WSL2 (soporte oficial)
- WSL2: requiere `tailscaled` corriendo como servicio (funciona con `wsl-service`)

### Estimación
- Endpoints w-api (provision + status + invite): ~4h
- Cambios `install.sh`: ~2h
- Panel en app.solwed.es: ~6h
- Tests + documentación cliente: ~3h
- **Total: ~15h**

---

## Prioridad y dependencias

```
Feature 1 (Backup)    ──────────────────────────► Lanzar primero
                                                   Sin dependencias externas nuevas
                                                   Solo necesita R2 + Resend (ya disponibles)

Feature 2 (VPN)       ─────────────────────────► Segunda fase
                                                   Requiere cuenta Tailscale
                                                   Más complejo de documentar para el cliente
```

## Bloqueantes antes de implementar

| Feature | Bloqueante | Responsable |
|---------|-----------|-------------|
| Backup | Crear carpeta `/erp-backups/` en pCloud y anotar su `folderId` | Ops |
| Backup | Confirmar que `PCLOUD_TOKEN` tiene permisos de escritura | Ops |
| Backup | Definir email de notificación (from, template) | Marketing/Diseño |
| Backup | Decidir retención: 7 días vs 30 días | Producto |
| VPN | Crear cuenta Tailscale Business o usar personal | Ops |
| VPN | Confirmar soporte WSL2 con clientes Windows | QA |
| VPN | Decidir qué plan de Tailscale (gratis vs Teams) | Finanzas |

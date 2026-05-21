# CLAUDE.md — FacturaSolwed

Fork de FacturaScripts de Solwed para clientes. Producto SaaS: Solwed despliega y gestiona una instancia Docker por cliente en `{cliente}.erpsolwed.es`.

## Repos del ecosistema

| Repo | Propósito |
|------|-----------|
| `SolWed-es/facturasolwed` | **Este repo** — fork del core + plugins Solwed + infraestructura Docker |
| `SolWed-es/SolwedPlugins-container` | Catálogo de plugins (`plugin-list.json` + ZIPs) |
| `SolWed-es/facturascripts` | ERP interno de Solwed (distinto producto) |

**Upstream**: `https://github.com/NeoRazorX/facturascripts.git`

## Ramas

| Rama | Propósito |
|------|-----------|
| `main` | Sync con upstream NeoRazorX — **no tocar** |
| `solwed/stable` | Producción — core + plugins Solwed + Docker files |
| `solwed/dev` | Desarrollo — pruebas de nuevas features antes de stable |

## Plugins preinstalados (en Plugins/)

| Plugin | Propósito |
|--------|-----------|
| `SolwedTheme` | Branding y tema Bootstrap 5 de Solwed |
| `SolwedPlugins` | Tienda de plugins — el cliente instala más desde AdminPlugins |
| `MindConnect` | Telemetría y soporte remoto — conecta con mind.solwed.es |

Plugins adicionales: el cliente los instala desde la tienda integrada en AdminPlugins.

## Arquitectura de despliegue

```
erpsolwed.es (servidor dedicado)
├── Caddy (wildcard *.erpsolwed.es, TLS via Cloudflare)
├── postgres (compartido, una DB por cliente: erp_{cliente})
└── containers Docker
    ├── erp-acme   → acme.erpsolwed.es
    ├── erp-beta   → beta.erpsolwed.es
    └── erp-gama   → gama.erpsolwed.es
```

Imagen: `ghcr.io/solwed-es/facturasolwed:latest`

## Gestión de clientes

```bash
# Nuevo cliente
./scripts/new-client.sh acme

# Listar todos
./scripts/list-clients.sh

# Actualizar uno
./scripts/update-client.sh acme

# Actualizar todos
./scripts/update-client.sh --all

# Backup
./scripts/backup-client.sh acme
```

Cada cliente tiene su directorio en `clients/{cliente}/` con su `.env`.

## Variables de entorno por instancia

| Variable | Descripción |
|----------|-------------|
| `CLIENT_ID` | Identificador único del cliente (slug) |
| `POSTGRES_HOST` | Host de PostgreSQL |
| `POSTGRES_PASS` | Contraseña de la DB del cliente |
| `FS_LANG` | Idioma (default: es_ES) |
| `FS_TIMEZONE` | Zona horaria (default: Europe/Madrid) |
| `MIND_API_KEY` | API key para conectar con mind.solwed.es |
| `GROQ_API_KEY` | Para generación SEO en plugin Blog (opcional) |

## Docker

### Desarrollo local

```bash
# Levantar instancia de prueba
CLIENT_ID=test POSTGRES_PASS=test123 MIND_API_KEY=dev \
  docker compose up -d

# Ver logs
docker logs erp-test -f
```

### Build y publicación de imagen

```bash
# Build
docker build -t ghcr.io/solwed-es/facturasolwed:latest .

# Push (requiere login en GHCR)
docker push ghcr.io/solwed-es/facturasolwed:latest
```

El GitHub Actions workflow (`.github/workflows/build.yml`) hace el build y push automáticamente en cada push a `solwed/stable`.

## Sincronizar con upstream

```bash
git fetch upstream
git checkout main
git merge upstream/master

git checkout solwed/stable
git merge main
# Resolver conflictos — prioridad a cambios Solwed
```

## Troubleshooting

| Síntoma | Solución |
|---------|----------|
| Instancia no arranca | `docker logs erp-{cliente}` |
| Error de DB | Verificar credenciales en `clients/{cliente}/.env` |
| 500 en la app | `docker exec erp-{cliente} cat MyFiles/crash_*.json` |
| Plugin no aparece | `docker exec erp-{cliente} cat MyFiles/plugins.json` |
| Dinamic/ corrupto | `docker restart erp-{cliente}` (entrypoint lo regenera) |

## Archivos NO versionados

- `clients/*/.env` — credenciales por cliente
- `config.php`, `MyFiles/`, `Dinamic/`, `vendor/`

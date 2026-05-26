# SolwedConnect

Plugin oficial de Solwed para FacturaScripts. Conecta tu instalación con el ecosistema Solwed: gestión de suscripción, tienda de plugins y actualizaciones remotas.

## Descarga

| Versión | Descarga |
|---------|---------|
| v0.1.1 (última) | [SolwedConnect.zip](https://github.com/SolWed-es/FacturaSolwed/releases/download/SolwedConnect-v0.1.1/SolwedConnect.zip) |

Todas las versiones: [github.com/SolWed-es/FacturaSolwed/releases](https://github.com/SolWed-es/FacturaSolwed/releases)

## Requisitos

- FacturaScripts 2026 o superior
- PHP 8.1 o superior
- Acceso a internet (opcional — ver [modo offline](#modo-offline))

## Instalación

### Paso 1 — Descargar e instalar el plugin

1. Descarga el ZIP de la [última versión](https://github.com/SolWed-es/FacturaSolwed/releases/latest)
2. En tu FacturaScripts, ve a **Plugins → Subir plugin**
3. Selecciona el ZIP descargado y súbelo
4. Pulsa **Activar** en la lista de plugins

### Paso 2 — Activar la licencia

1. Accede a [app.solwed.es](https://app.solwed.es) → **Servicios → ERP**
2. Pulsa **Obtener código** en tu instalación (válido 24 h)
3. En tu FacturaScripts, abre el **Dashboard**
4. En el bloque **Suscripción Solwed**, introduce el código y pulsa **Activar licencia**

Al activar correctamente verás el plan asociado a tu suscripción (`principiante`, `estandar` o `profesional`).

### Instalación alternativa — Tienda de plugins Solwed

Si ya tienes SolwedConnect activo en otra instalación, puedes instalar plugins directamente desde **Plugins → Tienda de plugins Solwed** sin descargar ZIPs manualmente.

### Instalaciones managed (Solwed Cloud)

Las instalaciones gestionadas por Solwed se configuran automáticamente mediante variables de entorno — no requieren código de activación manual:

```env
FS_MIND_TOKEN=<token_asignado_por_solwed>
FS_INSTANCE_TYPE=managed
```

## Configuración avanzada

### URL de la API (entornos privados)

Por defecto el plugin apunta a `https://api.solwed.es`. Puedes sobreescribirlo en:

```
FacturaScripts → Configuración → SolwedConnect → mind_url
```

Útil para entornos de staging o redes internas sin acceso a internet.

## Funcionamiento

| Tarea | Frecuencia |
|-------|-----------|
| Heartbeat (ping + notificaciones) | Cada 15 min |
| Telemetría (plugins, usuarios, versión) | Cada 6 h |
| Renovación de caché de licencia | Cada 23 h |

El cron de FacturaScripts debe estar activo:

```bash
*/15 * * * * cd /ruta/facturascripts && php index.php -cron >> /dev/null 2>&1
```

## Modo offline

Si el servidor no tiene acceso a internet o la API no responde:

- Si hay una verificación previa en caché (menos de 72 h) → se usa esa, marcada como `grace`
- Si no hay caché previa → la instalación sigue funcionando con `plan: none`

El plugin **nunca bloquea el ERP** por falta de conexión.

## Actualizar el plugin

Desde el Dashboard → bloque **Suscripción Solwed** → botón **Actualizaciones** (si hay nueva versión).

O desde **Plugins → Tienda de plugins Solwed**.

## Desinstalar / revocar licencia

1. En el Dashboard, bloque **Suscripción Solwed**, pulsa **Quitar licencia**
2. Desinstala el plugin desde **Plugins → Gestionar**

La revocación es local — no cancela la suscripción en Solwed.

## Soporte

- Portal clientes: [app.solwed.es](https://app.solwed.es)
- Email: soporte@solwed.es

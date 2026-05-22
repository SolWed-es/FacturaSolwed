# SolwedConnect

Plugin oficial de Solwed para FacturaScripts. Conecta tu instalación con el ecosistema Solwed: gestión de suscripción, tienda de plugins y actualizaciones remotas.

## Requisitos

- FacturaScripts 2026 o superior
- PHP 8.1 o superior
- Acceso a internet (opcional — ver [modo offline](#modo-offline))

## Instalación

### Opción A — Desde el portal Solwed (recomendado)

1. Accede a [app.solwed.es](https://app.solwed.es) → **Servicios → ERP**
2. En la instalación pendiente, pulsa **Obtener código**
3. En tu FacturaScripts, ve a **Plugins → Gestionar** e instala `SolwedConnect`
4. Ve al **Dashboard** y pega el código de activación en el campo correspondiente
5. Pulsa **Activar licencia**

### Opción B — Instalación manual

1. Descarga el ZIP desde la tienda de plugins:
   ```
   https://plugins.erpsolwed.es/zip/SolwedConnect.zip
   ```
2. En FacturaScripts, ve a **Plugins → Subir plugin** y sube el ZIP
3. Activa el plugin desde la lista
4. Sigue los pasos de activación del Opción A desde el punto 4

### Opción C — Variables de entorno (instalaciones managed)

Las instalaciones gestionadas por Solwed se configuran automáticamente mediante variables de entorno en el contenedor:

```env
FS_MIND_TOKEN=<token_asignado_por_solwed>
FS_INSTANCE_TYPE=managed
```

No requieren código de activación manual.

## Activación

Una vez instalado, el plugin muestra un bloque **Suscripción Solwed** en el Dashboard.

Si la instalación está **pendiente de activación**:

1. Ve a [app.solwed.es](https://app.solwed.es) → **Servicios → ERP**
2. Pulsa **Obtener código** en tu instalación
3. Copia el código (válido 24 h) y pégalo en el Dashboard del ERP
4. Pulsa **Activar licencia**

Al activar correctamente verás el plan asociado a tu suscripción (`principiante`, `estandar` o `profesional`).

## Configuración avanzada

### URL de la API (entornos privados)

Por defecto el plugin apunta a `https://api.solwed.es`. Puedes sobreescribirlo en la configuración del plugin:

```
FacturaScripts → Configuración → SolwedConnect → mind_url
```

Útil para entornos de staging o redes privadas.

## Funcionamiento

| Función | Frecuencia |
|---------|-----------|
| Heartbeat (ping + notificaciones) | Cada 15 min (cron) |
| Telemetría (plugins, usuarios, versión) | Cada 6 h (cron) |
| Renovación de caché de licencia | Cada 23 h (cron) |

El cron de FacturaScripts debe estar activo:
```bash
*/15 * * * * cd /ruta/facturascripts && php index.php -cron >> /dev/null 2>&1
```

## Modo offline

Si el servidor no tiene acceso a internet o la API no responde:

- Si existe una verificación previa en caché (< 72 h) → se usa esa, marcada como `grace`
- Si no hay caché → la instalación sigue funcionando con `plan: none` (sin restricciones de uso)

El plugin **nunca bloquea el ERP** por falta de conexión.

## Actualizar el plugin

Desde el Dashboard → bloque **Suscripción Solwed** → botón **Actualizaciones** (si hay nueva versión disponible).

O desde **Plugins → Tienda de plugins Solwed**.

## Desinstalar / revocar licencia

1. En el Dashboard, bloque **Suscripción Solwed**, pulsa **Quitar licencia**
2. Desinstala el plugin desde **Plugins → Gestionar**

La revocación es local — no cancela la suscripción en Solwed.

## Soporte

- Portal clientes: [app.solwed.es](https://app.solwed.es)
- Email: soporte@solwed.es

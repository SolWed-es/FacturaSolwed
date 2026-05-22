#!/bin/bash
set -e

WEBROOT=/var/www/html

# ── Composer ──────────────────────────────────────────────────────────────────
if [ ! -f "$WEBROOT/vendor/autoload.php" ]; then
    echo "[entrypoint] Instalando dependencias Composer..."
    composer install --no-interaction --prefer-dist --optimize-autoloader \
        --working-dir="$WEBROOT"
fi

# ── config.php ────────────────────────────────────────────────────────────────
# Si hay env vars de DB → generar config dinámico (modo cliente SaaS)
# Si no → copiar plantilla de .docker/config.php (modo dev local)
if [ -n "${FS_DB_HOST:-}" ] && [ -n "${FS_DB_PASS:-}" ]; then
    echo "[entrypoint] Generando config.php desde variables de entorno..."
    cat > "$WEBROOT/config.php" << EOF
<?php
define('FS_DB_TYPE',     '${FS_DB_TYPE:-postgresql}');
define('FS_DB_HOST',     '${FS_DB_HOST}');
define('FS_DB_PORT',     '${FS_DB_PORT:-5432}');
define('FS_DB_NAME',     '${FS_DB_NAME}');
define('FS_DB_USER',     '${FS_DB_USER:-fs_user}');
define('FS_DB_PASS',     '${FS_DB_PASS}');
define('FS_DB_FOREIGN_KEYS', true);
define('FS_LANG',        '${FS_LANG:-es_ES}');
define('FS_TIMEZONE',    '${FS_TIMEZONE:-Europe/Madrid}');
define('FS_ROUTE',       '');
define('FS_COOKIES_EXPIRE', 2592000);
define('FS_DEBUG',            ${FS_DEBUG:-false});
define('FS_DEV_MODE',         ${FS_DEV_MODE:-false});
define('FS_DEV_USER',         '${FS_DEV_USER:-admin}');
define('FS_MIND_CONNECT_SECRET', '${FS_MIND_CONNECT_SECRET:-}');
define('GROQ_API_KEY',        '${GROQ_API_KEY:-}');
define('FS_DISABLE_RM_PLUGINS', $([ "${FS_INSTANCE_TYPE:-self-hosted}" = "managed" ] && echo "true" || echo "false"));
define('FS_HIDDEN_PLUGINS',   '${FS_HIDDEN_PLUGINS:-SolwedConnect}');
define('FS_MIND_TOKEN',       '${FS_MIND_TOKEN:-}');
EOF
elif [ ! -f "$WEBROOT/config.php" ]; then
    echo "[entrypoint] Copiando config.php de plantilla dev..."
    cp "$WEBROOT/.docker/config.php" "$WEBROOT/config.php"
fi

# ── .htaccess ────────────────────────────────────────────────────────────────
if [ ! -f "$WEBROOT/.htaccess" ]; then
    cp "$WEBROOT/htaccess-sample" "$WEBROOT/.htaccess"
fi

# ── Directorios ───────────────────────────────────────────────────────────────
mkdir -p "$WEBROOT/MyFiles/Tmp" "$WEBROOT/MyFiles/uploads" "$WEBROOT/Dinamic"

# ── SolwedConnect — siempre activo en instancias managed ──────────────────────
PLUGINS_FILE="$WEBROOT/MyFiles/plugins.json"
if [ "${FS_INSTANCE_TYPE:-self-hosted}" = "managed" ]; then
    if [ -f "$PLUGINS_FILE" ]; then
        # asegurar que SolwedConnect está en la lista y habilitado
        php -r "
            \$file = '$PLUGINS_FILE';
            \$plugins = json_decode(file_get_contents(\$file), true) ?: [];
            \$found = false;
            foreach (\$plugins as &\$p) {
                if (\$p['name'] === 'SolwedConnect') {
                    \$p['enabled'] = true;
                    \$found = true;
                }
            }
            if (!\$found) {
                \$plugins[] = ['name' => 'SolwedConnect', 'enabled' => true];
            }
            file_put_contents(\$file, json_encode(\$plugins, JSON_PRETTY_PRINT));
        "
    else
        # primera vez: crear plugins.json con SolwedConnect activo
        echo '[{"name":"SolwedConnect","enabled":true}]' > "$PLUGINS_FILE"
    fi
    echo "[entrypoint] SolwedConnect forzado activo (managed)"
fi

# ── Dinamic/ + routes + DB schema ─────────────────────────────────────────────
echo "[entrypoint] Regenerando Dinamic/..."
php -r "
    const FS_FOLDER = '/var/www/html';
    require_once '/var/www/html/vendor/autoload.php';
    require_once '/var/www/html/config.php';
    \$pluginsFile = FS_FOLDER . '/MyFiles/plugins.json';
    if (file_exists(\$pluginsFile)) {
        \$all = json_decode(file_get_contents(\$pluginsFile), true) ?: [];
        \$enabled = array_map(fn(\$p) => \$p['name'], array_filter(\$all, fn(\$p) => \$p['enabled'] ?? false));
    } else {
        \$enabled = [];
    }
    FacturaScripts\Core\Internal\PluginsDeploy::run(\$enabled, true);
    FacturaScripts\Core\Kernel::init();
    FacturaScripts\Core\Kernel::rebuildRoutes();
    FacturaScripts\Core\Kernel::saveRoutes();
    echo 'Dinamic ok (' . count(\$enabled) . ' plugins)' . PHP_EOL;
" || echo "[entrypoint] WARNING: Dinamic falló, continuando..."

# ── Permisos ──────────────────────────────────────────────────────────────────
chown -R www-data:www-data "$WEBROOT/MyFiles" "$WEBROOT/Dinamic"
[ -f "$WEBROOT/config.php" ] && chown www-data:www-data "$WEBROOT/config.php" || true

exec apache2-foreground

#!/bin/bash
set -e

WEBROOT=/var/www/html

# Generar config.php desde variables de entorno
cat > "$WEBROOT/config.php" <<EOF
<?php
define('FS_DB_TYPE',     '${FS_DB_TYPE:-postgresql}');
define('FS_DB_HOST',     '${FS_DB_HOST}');
define('FS_DB_PORT',     '${FS_DB_PORT:-5432}');
define('FS_DB_NAME',     '${FS_DB_NAME}');
define('FS_DB_USER',     '${FS_DB_USER}');
define('FS_DB_PASS',     '${FS_DB_PASS}');
define('FS_LANG',        '${FS_LANG:-es_ES}');
define('FS_TIMEZONE',    '${FS_TIMEZONE:-Europe/Madrid}');
define('FS_ROUTE',       '${FS_ROUTE:-}');
define('FS_COOKIES_EXPIRE', 2592000);
define('FS_DEBUG',       ${FS_DEBUG:-false});
define('MIND_API_URL',   '${MIND_API_URL:-https://mind.solwed.es}');
define('MIND_API_KEY',   '${MIND_API_KEY:-}');
define('GROQ_API_KEY',   '${GROQ_API_KEY:-}');
EOF

# Permisos de MyFiles
mkdir -p "$WEBROOT/MyFiles"
chown -R www-data:www-data "$WEBROOT/MyFiles" "$WEBROOT/Dinamic" 2>/dev/null || true

# Regenerar Dinamic/ si no existe o está vacío
if [ ! -f "$WEBROOT/Dinamic/init.php" ]; then
    echo "[facturasolwed] Generando Dinamic/..."
    php -r "require '$WEBROOT/vendor/autoload.php'; FacturaScripts\Core\Plugins::deploy();" || true
fi

exec "$@"

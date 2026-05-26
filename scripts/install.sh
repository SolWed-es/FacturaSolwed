#!/bin/bash
# install.sh — Instalador de FacturaSolwed ERP para Debian/Ubuntu (y WSL)
#
# Uso:
#   sudo bash install.sh                  # interactivo
#   sudo bash install.sh --non-interactive --domain erp.miempresa.com --db-pass MiPassword123
#
# Requisitos:
#   - Debian 11/12 o Ubuntu 20.04/22.04/24.04 (o WSL con Ubuntu)
#   - Ejecutar con sudo o como root
#   - Conexión a internet (para apt, composer y activación de licencia)

set -euo pipefail

# ── Colores ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓${NC} $*"; }
info() { echo -e "${BLUE}→${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC} $*"; }
die()  { echo -e "${RED}✗${NC} $*" >&2; exit 1; }

# ── Detección de entorno ──────────────────────────────────────────────────────
IS_WSL=false
if grep -qiE "microsoft|wsl" /proc/version 2>/dev/null; then
    IS_WSL=true
fi

# En WSL sin systemd usar 'service', en Linux nativo usar 'systemctl'
svc_start()  { $IS_WSL && service "$1" start   || systemctl start "$1";   }
svc_enable() { $IS_WSL && true                 || systemctl enable "$1";  }
svc_reload() { $IS_WSL && service "$1" reload  || systemctl reload "$1";  }

# ── Valores por defecto ───────────────────────────────────────────────────────
NON_INTERACTIVE=false
APP_DIR="/var/www/facturascripts"
APACHE_CONF_DIR="/etc/apache2/sites-available"
DB_HOST="localhost"
DB_PORT="5432"
DB_NAME="facturascripts"
DB_USER="fs_user"
DB_PASS=""
APP_DOMAIN=""
FS_LANG="es_ES"
RELEASE_URL=""   # Si vacío, usa el directorio actual (modo repo)

# ── Argumentos ────────────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --non-interactive) NON_INTERACTIVE=true ;;
        --dir)       APP_DIR="$2";    shift ;;
        --domain)    APP_DOMAIN="$2"; shift ;;
        --db-name)   DB_NAME="$2";    shift ;;
        --db-user)   DB_USER="$2";    shift ;;
        --db-pass)   DB_PASS="$2";    shift ;;
        --lang)      FS_LANG="$2";    shift ;;
        --release)   RELEASE_URL="$2";shift ;;
        *) die "Opción desconocida: $1" ;;
    esac
    shift
done

# ── Cabecera ──────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║       FacturaSolwed ERP — Instalador        ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════╝${NC}"
$IS_WSL && echo -e "${YELLOW}  Entorno WSL detectado${NC}"
echo ""

[[ $(id -u) -ne 0 ]] && die "Ejecuta el instalador con sudo o como root."

# ── Preguntas interactivas ─────────────────────────────────────────────────────
if ! $NON_INTERACTIVE; then
    read -rp "Dominio o IP del servidor [localhost]: " _domain
    APP_DOMAIN="${_domain:-localhost}"

    read -rp "Directorio de instalación [$APP_DIR]: " _dir
    APP_DIR="${_dir:-$APP_DIR}"

    read -rp "Nombre de la base de datos [$DB_NAME]: " _dbname
    DB_NAME="${_dbname:-$DB_NAME}"

    read -rp "Usuario de la base de datos [$DB_USER]: " _dbuser
    DB_USER="${_dbuser:-$DB_USER}"

    while [[ -z "$DB_PASS" ]]; do
        read -rsp "Contraseña de la base de datos (mín. 8 caracteres): " DB_PASS; echo
        [[ ${#DB_PASS} -ge 8 ]] || { warn "La contraseña debe tener al menos 8 caracteres."; DB_PASS=""; }
    done

    read -rp "Idioma [es_ES]: " _lang
    FS_LANG="${_lang:-es_ES}"
fi

[[ -z "$APP_DOMAIN" ]] && APP_DOMAIN="localhost"
[[ -z "$DB_PASS" ]]   && die "Se requiere una contraseña para la base de datos (--db-pass)."

echo ""
info "Configuración de instalación:"
echo "  Directorio : $APP_DIR"
echo "  Dominio    : $APP_DOMAIN"
echo "  Base datos : $DB_NAME (usuario: $DB_USER)"
echo "  Idioma     : $FS_LANG"
$IS_WSL && echo "  WSL        : sí (se usará 'service' en vez de systemctl)"
echo ""

if ! $NON_INTERACTIVE; then
    read -rp "¿Continuar? [S/n]: " _confirm
    [[ "${_confirm,,}" =~ ^(n|no)$ ]] && { echo "Cancelado."; exit 0; }
fi

# ── 1. Dependencias del sistema ────────────────────────────────────────────────
info "Instalando dependencias del sistema..."

apt-get update -q

# PHP 8.2 — añadir repositorio si no está disponible
if ! apt-cache show php8.2 &>/dev/null; then
    apt-get install -y -q software-properties-common
    if grep -qi "ubuntu" /etc/os-release 2>/dev/null; then
        add-apt-repository -y ppa:ondrej/php
    else
        # Debian — sury.org
        apt-get install -y -q lsb-release ca-certificates curl
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg \
            https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/php.list
    fi
    apt-get update -q
fi

apt-get install -y -q \
    apache2 \
    php8.2 \
    php8.2-pgsql \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-gd \
    php8.2-bcmath \
    php8.2-curl \
    php8.2-intl \
    libapache2-mod-php8.2 \
    postgresql \
    postgresql-client \
    composer \
    curl \
    unzip \
    git

a2enmod rewrite php8.2
ok "Dependencias instaladas"

# ── 2. PostgreSQL ──────────────────────────────────────────────────────────────
info "Configurando PostgreSQL..."
svc_start postgresql

# Crear usuario y base de datos (idempotente)
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" \
    | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER $DB_USER WITH PASSWORD '$DB_PASS';"

sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" \
    | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE $DB_NAME OWNER $DB_USER;"

sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;"
# PostgreSQL 15+ requiere GRANT en el schema public
sudo -u postgres psql -d "$DB_NAME" -c "GRANT ALL ON SCHEMA public TO $DB_USER;" 2>/dev/null || true

svc_enable postgresql
ok "PostgreSQL configurado (DB: $DB_NAME, usuario: $DB_USER)"

# ── 3. Código de FacturaSolwed ─────────────────────────────────────────────────
info "Instalando FacturaSolwed en $APP_DIR..."

if [[ -n "$RELEASE_URL" ]]; then
    # Descargar desde URL de release
    TMP_ZIP=$(mktemp /tmp/facturascripts-XXXXXX.zip)
    info "Descargando $RELEASE_URL..."
    curl -sL "$RELEASE_URL" -o "$TMP_ZIP"
    mkdir -p "$APP_DIR"
    unzip -q "$TMP_ZIP" -d "$APP_DIR"
    rm -f "$TMP_ZIP"
else
    # Modo repo: copiar desde el directorio del script
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    if [[ ! -f "$SCRIPT_DIR/index.php" ]]; then
        die "No se encontró index.php en $SCRIPT_DIR. Usa --release <url> para descargar desde internet."
    fi
    info "Copiando código desde $SCRIPT_DIR..."
    mkdir -p "$APP_DIR"
    rsync -a --exclude='.git' --exclude='MyFiles' --exclude='Dinamic' \
        --exclude='vendor' --exclude='*.zip' --exclude='scripts' \
        --exclude='docker-compose.yml' --exclude='Dockerfile' --exclude='.docker' \
        "$SCRIPT_DIR/" "$APP_DIR/"
fi

ok "Código copiado"

# ── 4. Composer ────────────────────────────────────────────────────────────────
info "Instalando dependencias PHP (composer)..."
composer install --no-dev --optimize-autoloader --no-interaction \
    --working-dir="$APP_DIR" --quiet
ok "Dependencias PHP instaladas"

# ── 5. .htaccess ──────────────────────────────────────────────────────────────
if [[ ! -f "$APP_DIR/.htaccess" ]]; then
    cp "$APP_DIR/htaccess-sample" "$APP_DIR/.htaccess"
fi

# ── 6. config.php ─────────────────────────────────────────────────────────────
info "Generando config.php..."
cat > "$APP_DIR/config.php" << PHPEOF
<?php
define('FS_DB_TYPE',          'postgresql');
define('FS_DB_HOST',          '$DB_HOST');
define('FS_DB_PORT',          '$DB_PORT');
define('FS_DB_NAME',          '$DB_NAME');
define('FS_DB_USER',          '$DB_USER');
define('FS_DB_PASS',          '$DB_PASS');
define('FS_DB_FOREIGN_KEYS',  true);
define('FS_LANG',             '$FS_LANG');
define('FS_TIMEZONE',         'Europe/Madrid');
define('FS_ROUTE',            '');
define('FS_COOKIES_EXPIRE',   2592000);
define('FS_DEBUG',            false);
define('FS_HIDDEN_PLUGINS',   'SolwedConnect');
PHPEOF
ok "config.php generado"

# ── 7. Directorios y permisos ──────────────────────────────────────────────────
mkdir -p "$APP_DIR/MyFiles/Tmp" "$APP_DIR/MyFiles/uploads" "$APP_DIR/Dinamic"
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 640 "$APP_DIR/config.php"
ok "Permisos configurados"

# ── 8. Dinamic/ y rutas ────────────────────────────────────────────────────────
info "Generando Dinamic/ y rutas..."
# Activar SolwedConnect
echo '[{"name":"SolwedConnect","enabled":true}]' > "$APP_DIR/MyFiles/plugins.json"
chown www-data:www-data "$APP_DIR/MyFiles/plugins.json"

php -r "
    define('FS_FOLDER', '$APP_DIR');
    require_once '$APP_DIR/vendor/autoload.php';
    require_once '$APP_DIR/config.php';
    \FacturaScripts\Core\Internal\PluginsDeploy::run(['SolwedConnect'], true);
    \FacturaScripts\Core\Kernel::rebuildRoutes();
    \FacturaScripts\Core\Kernel::saveRoutes();
    echo 'Dinamic ok' . PHP_EOL;
" && ok "Dinamic/ generado" || warn "Dinamic/ falló — se generará en el primer acceso web"

chown -R www-data:www-data "$APP_DIR/Dinamic" "$APP_DIR/MyFiles"

# ── 9. Apache VirtualHost ─────────────────────────────────────────────────────
info "Configurando Apache..."

VHOST_FILE="$APACHE_CONF_DIR/facturascripts.conf"
cat > "$VHOST_FILE" << APACHEEOF
<VirtualHost *:80>
    ServerName $APP_DOMAIN
    DocumentRoot $APP_DIR

    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/facturascripts-error.log
    CustomLog \${APACHE_LOG_DIR}/facturascripts-access.log combined
</VirtualHost>
APACHEEOF

a2ensite facturascripts
a2dissite 000-default 2>/dev/null || true

svc_start apache2
svc_enable apache2
svc_reload apache2
ok "Apache configurado"

# ── 10. PHP settings ──────────────────────────────────────────────────────────
PHP_INI_DIR=$(php --ini | grep "Scan for additional" | awk '{print $NF}')
if [[ -d "$PHP_INI_DIR" ]]; then
    cat > "$PHP_INI_DIR/facturascripts.ini" << PHPINIEOF
upload_max_filesize = 99M
post_max_size = 99M
max_input_vars = 10000
memory_limit = 256M
PHPINIEOF
    svc_reload apache2
    ok "PHP configurado (upload 99M, memory 256M)"
fi

# ── Resumen final ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║       Instalación completada con éxito       ║${NC}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  URL        : ${BOLD}http://$APP_DOMAIN/${NC}"
echo -e "  Directorio : $APP_DIR"
echo -e "  DB         : $DB_NAME@$DB_HOST:$DB_PORT"
echo ""

if $IS_WSL; then
    echo -e "${YELLOW}  WSL: para acceder desde Windows usa la IP de WSL:${NC}"
    echo -e "  $(hostname -I | awk '{print $1}')"
    echo ""
    echo -e "${YELLOW}  Para iniciar los servicios tras reiniciar WSL:${NC}"
    echo -e "  sudo service postgresql start && sudo service apache2 start"
    echo ""
fi

echo -e "  ${BOLD}Próximos pasos:${NC}"
echo -e "  1. Abre http://$APP_DOMAIN/ en el navegador"
echo -e "  2. Completa el asistente de configuración inicial"
echo -e "  3. Activa la licencia en el Dashboard de SolwedConnect"
echo ""
echo -e "  ${YELLOW}Guarda estas credenciales en un lugar seguro:${NC}"
echo -e "  DB usuario : $DB_USER"
echo -e "  DB pass    : $DB_PASS"
echo ""

#!/bin/bash
# new-client.sh <cliente> [lang] [timezone]
# Crea una nueva instancia FacturaSolwed para un cliente.
# Registra la instalación en w-api y obtiene el mind_token.
set -euo pipefail

CLIENT_ID="${1:?Uso: $0 <cliente> [es_ES] [Europe/Madrid]}"
LANG="${2:-es_ES}"
TIMEZONE="${3:-Europe/Madrid}"

CLIENTS_DIR="/opt/solwed/clients"
CLIENT_DIR="$CLIENTS_DIR/$CLIENT_ID"
WAPI_URL="${WAPI_URL:-http://localhost:3009}"
WAPI_TOKEN="${WAPI_TOKEN:-}"

# Validar nombre
if [[ ! "$CLIENT_ID" =~ ^[a-z0-9-]+$ ]]; then
    echo "ERROR: Solo letras minúsculas, números y guiones."
    exit 1
fi

if [ -d "$CLIENT_DIR" ]; then
    echo "ERROR: El cliente '$CLIENT_ID' ya existe."
    exit 1
fi

echo "→ Creando cliente: $CLIENT_ID"
mkdir -p "$CLIENT_DIR"

# Generar credenciales locales
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)

# Registrar en w-api y obtener mind_token
MIND_TOKEN=""
if [ -n "$WAPI_TOKEN" ]; then
    echo "→ Registrando en w-api..."
    PROVISION_RESP=$(curl -sf --max-time 10 \
        -X POST "$WAPI_URL/admin/erp/fs-instalaciones" \
        -H "Authorization: Bearer $WAPI_TOKEN" \
        -H "Content-Type: application/json" \
        -d "{
            \"nombre\": \"$CLIENT_ID\",
            \"url\": \"https://${CLIENT_ID}.erpsolwed.es\",
            \"estado\": \"managed\",
            \"metadata\": {\"tipo\": \"managed\", \"plan\": \"estandar\"}
        }" 2>/dev/null) || true

    if [ -n "$PROVISION_RESP" ]; then
        MIND_TOKEN=$(echo "$PROVISION_RESP" | python3 -c \
            "import json,sys; d=json.load(sys.stdin); print(d.get('data',{}).get('token','') or d.get('token',''))" \
            2>/dev/null || echo "")
        INSTALL_ID=$(echo "$PROVISION_RESP" | python3 -c \
            "import json,sys; d=json.load(sys.stdin); print(d.get('data',{}).get('id','') or d.get('id',''))" \
            2>/dev/null || echo "")
        [ -n "$MIND_TOKEN" ] && echo "  mind_token obtenido (instalacion_id=$INSTALL_ID)"
    fi
fi

# Si no hay w-api disponible, generar token local (se puede vincular después)
if [ -z "$MIND_TOKEN" ]; then
    MIND_TOKEN=$(openssl rand -hex 32)
    echo "  ⚠ w-api no disponible — token generado localmente (vincular manualmente)"
fi

# .env del cliente
cat > "$CLIENT_DIR/.env" << EOF
CLIENT_ID=$CLIENT_ID
FS_DB_HOST=postgres
FS_DB_PORT=5432
FS_DB_NAME=erp_${CLIENT_ID}
FS_DB_USER=fs_user
FS_DB_PASS=$DB_PASS
FS_LANG=$LANG
FS_TIMEZONE=$TIMEZONE
FS_INSTANCE_TYPE=managed
FS_MIND_TOKEN=$MIND_TOKEN
SOLWED_PLUGIN_STORE_URL=http://fs-plugins
GROQ_API_KEY=
EOF

# docker-compose.yml del cliente
cat > "$CLIENT_DIR/docker-compose.yml" << EOF
services:
  erp:
    image: facturasolwed:latest
    container_name: erp-${CLIENT_ID}
    restart: unless-stopped
    env_file: .env
    volumes:
      - myfiles:/var/www/html/MyFiles
    networks:
      - caddy-proxy
      - fs-net

volumes:
  myfiles:
    name: erp-${CLIENT_ID}-myfiles

networks:
  caddy-proxy:
    external: true
  fs-net:
    external: true
EOF

# Crear base de datos
echo "→ Creando base de datos erp_${CLIENT_ID}..."
docker exec postgres psql -U postgres \
  -c "CREATE USER fs_user WITH PASSWORD '$DB_PASS';" 2>/dev/null || true
docker exec postgres psql -U postgres \
  -c "CREATE DATABASE erp_${CLIENT_ID} OWNER fs_user;" 2>/dev/null || \
  echo "  (DB ya existe — continuando)"

# Añadir subdominio en Caddy
CADDYFILE="/etc/caddy/Caddyfile"
if [ -f "$CADDYFILE" ] && ! grep -q "${CLIENT_ID}.erpsolwed.es" "$CADDYFILE"; then
    echo "→ Añadiendo ${CLIENT_ID}.erpsolwed.es a Caddy..."
    cat >> "$CADDYFILE" << CADDY

${CLIENT_ID}.erpsolwed.es {
    reverse_proxy erp-${CLIENT_ID}:80
    tls {
        dns cloudflare {env.CF_API_TOKEN}
    }
}
CADDY
    systemctl reload caddy 2>/dev/null || caddy reload 2>/dev/null || true
fi

# Levantar
echo "→ Levantando instancia..."
cd "$CLIENT_DIR" && docker compose up -d

echo ""
echo "✓ Cliente '$CLIENT_ID' listo"
echo "  URL:        https://${CLIENT_ID}.erpsolwed.es"
echo "  DB:         erp_${CLIENT_ID}"
echo "  Mind token: ${MIND_TOKEN:0:16}..."
echo "  Config:     $CLIENT_DIR/.env"

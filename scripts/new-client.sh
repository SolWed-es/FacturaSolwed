#!/bin/bash
# new-client.sh <cliente> [--lang es_ES] [--timezone Europe/Madrid]
# Crea una nueva instancia FacturaSolwed para un cliente
set -euo pipefail

CLIENT_ID="${1:?Uso: $0 <cliente>}"
LANG="${3:-es_ES}"
TIMEZONE="${5:-Europe/Madrid}"
CLIENTS_DIR="$(dirname "$0")/../clients"
COMPOSE_TEMPLATE="$(dirname "$0")/../docker-compose.yml"

# Validar nombre (solo letras, números, guiones)
if [[ ! "$CLIENT_ID" =~ ^[a-z0-9-]+$ ]]; then
    echo "ERROR: El nombre de cliente solo puede contener letras minúsculas, números y guiones."
    exit 1
fi

CLIENT_DIR="$CLIENTS_DIR/$CLIENT_ID"

if [ -d "$CLIENT_DIR" ]; then
    echo "ERROR: El cliente '$CLIENT_ID' ya existe en $CLIENT_DIR"
    exit 1
fi

echo "→ Creando cliente: $CLIENT_ID"

# Generar credenciales
DB_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)
MIND_API_KEY=$(openssl rand -hex 32)

# Crear directorio del cliente
mkdir -p "$CLIENT_DIR"

# Crear .env del cliente
cat > "$CLIENT_DIR/.env" <<EOF
CLIENT_ID=$CLIENT_ID
POSTGRES_HOST=postgres
POSTGRES_PORT=5432
POSTGRES_USER=fs_user
POSTGRES_PASS=$DB_PASS
FS_LANG=$LANG
FS_TIMEZONE=$TIMEZONE
MIND_API_KEY=$MIND_API_KEY
GROQ_API_KEY=
EOF

# Copiar docker-compose
cp "$COMPOSE_TEMPLATE" "$CLIENT_DIR/docker-compose.yml"

echo "→ Creando base de datos: erp_${CLIENT_ID}"
# Crear DB en PostgreSQL (ajusta el host/usuario según tu config)
PGPASSWORD="${POSTGRES_ADMIN_PASS:-postgres}" psql \
    -h "${POSTGRES_HOST:-localhost}" \
    -U "${POSTGRES_ADMIN_USER:-postgres}" \
    -c "CREATE DATABASE erp_${CLIENT_ID} OWNER fs_user;" 2>/dev/null || \
    echo "  (DB ya existe o error — revisar manualmente)"

echo "→ Levantando instancia..."
cd "$CLIENT_DIR"
docker compose --env-file .env up -d

echo ""
echo "✓ Cliente '$CLIENT_ID' creado correctamente"
echo "  URL:     https://${CLIENT_ID}.erpsolwed.es"
echo "  DB:      erp_${CLIENT_ID}"
echo "  Config:  $CLIENT_DIR/.env"
echo ""
echo "  Primera visita: https://${CLIENT_ID}.erpsolwed.es/install"

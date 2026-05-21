#!/bin/bash
# update-client.sh <cliente|--all> — actualiza imagen Docker de uno o todos los clientes
set -euo pipefail

TARGET="${1:?Uso: $0 <cliente|--all>}"
CLIENTS_DIR="$(dirname "$0")/../clients"

update_client() {
    local CLIENT_ID="$1"
    local CLIENT_DIR="$CLIENTS_DIR/$CLIENT_ID"

    if [ ! -d "$CLIENT_DIR" ]; then
        echo "ERROR: Cliente '$CLIENT_ID' no encontrado"
        return 1
    fi

    echo "→ Actualizando $CLIENT_ID..."
    cd "$CLIENT_DIR"
    docker compose --env-file .env pull
    docker compose --env-file .env up -d --no-deps erp
    echo "✓ $CLIENT_ID actualizado — https://${CLIENT_ID}.erpsolwed.es"
}

if [ "$TARGET" = "--all" ]; then
    echo "Actualizando todos los clientes..."
    docker pull ghcr.io/solwed-es/facturasolwed:latest
    for dir in "$CLIENTS_DIR"/*/; do
        update_client "$(basename "$dir")" || true
    done
    echo "✓ Todos los clientes actualizados"
else
    update_client "$TARGET"
fi

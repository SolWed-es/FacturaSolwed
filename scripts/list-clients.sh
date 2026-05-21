#!/bin/bash
# list-clients.sh — muestra todas las instancias y su estado
CLIENTS_DIR="$(dirname "$0")/../clients"

printf "%-20s %-10s %-40s\n" "CLIENTE" "ESTADO" "URL"
printf "%-20s %-10s %-40s\n" "-------" "------" "---"

for dir in "$CLIENTS_DIR"/*/; do
    CLIENT_ID=$(basename "$dir")
    URL="https://${CLIENT_ID}.erpsolwed.es"
    STATUS=$(docker inspect --format='{{.State.Status}}' "erp-${CLIENT_ID}" 2>/dev/null || echo "stopped")
    printf "%-20s %-10s %-40s\n" "$CLIENT_ID" "$STATUS" "$URL"
done

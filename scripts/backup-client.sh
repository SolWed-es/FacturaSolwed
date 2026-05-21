#!/bin/bash
# backup-client.sh <cliente> — backup de DB y MyFiles
set -euo pipefail

CLIENT_ID="${1:?Uso: $0 <cliente>}"
CLIENTS_DIR="$(dirname "$0")/../clients"
BACKUP_DIR="/opt/solwed/backups/erp/${CLIENT_ID}"
DATE=$(date +%Y-%m-%d_%H-%M)

source "$CLIENTS_DIR/$CLIENT_ID/.env"

mkdir -p "$BACKUP_DIR"

echo "→ Backup DB erp_${CLIENT_ID}..."
PGPASSWORD="$POSTGRES_PASS" pg_dump \
    -h "${POSTGRES_HOST:-localhost}" \
    -U "${POSTGRES_USER:-fs_user}" \
    "erp_${CLIENT_ID}" \
    | gzip > "$BACKUP_DIR/db_${DATE}.sql.gz"

echo "→ Backup MyFiles..."
docker run --rm \
    --volumes-from "erp-${CLIENT_ID}" \
    -v "$BACKUP_DIR":/backup \
    alpine tar czf "/backup/myfiles_${DATE}.tar.gz" /var/www/html/MyFiles

echo "✓ Backup completado en $BACKUP_DIR"
ls -lh "$BACKUP_DIR"

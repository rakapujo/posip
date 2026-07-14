#!/bin/bash
# SIPOS Database Backup Script
#
# USAGE:
#   1. Edit variabel di bawah sesuai environment production
#   2. chmod +x scripts/backup-sipos.sh
#   3. Test manual: ./scripts/backup-sipos.sh
#   4. Setup cron:
#      sudo crontab -u www-data -e
#      # Daily backup jam 2 pagi
#      0 2 * * * /var/www/sipos/scripts/backup-sipos.sh >> /var/log/sipos-backup.log 2>&1

set -e  # Exit on error

# ============ CONFIG ============
DB_NAME="${DB_DATABASE:-sipos}"
DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
BACKUP_DIR="/backup/sipos"
RETENTION_DAYS=30
# =================================

# Ensure backup dir exists
mkdir -p "$BACKUP_DIR"

# Filename dengan timestamp
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/sipos_${TIMESTAMP}.sql.gz"

echo "[$(date)] Starting backup to $BACKUP_FILE..."

# Dump + gzip. Password via env var MYSQL_PWD (tidak muncul di `ps aux`).
MYSQL_PWD="$DB_PASS" mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --single-transaction \
    --routines \
    --triggers \
    --add-drop-table \
    "$DB_NAME" \
    | gzip > "$BACKUP_FILE"

# Verify backup not empty
if [ ! -s "$BACKUP_FILE" ]; then
    echo "[$(date)] ERROR: Backup file kosong atau gagal dibuat." >&2
    exit 1
fi

SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
echo "[$(date)] Backup success: $BACKUP_FILE ($SIZE)"

# Cleanup old backups (>retention days)
DELETED=$(find "$BACKUP_DIR" -name "sipos_*.sql.gz" -mtime +$RETENTION_DAYS -delete -print | wc -l)
if [ "$DELETED" -gt 0 ]; then
    echo "[$(date)] Deleted $DELETED old backup(s) (>$RETENTION_DAYS days)"
fi

echo "[$(date)] Done."

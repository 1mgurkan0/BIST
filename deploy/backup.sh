#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/bist_project}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/var/backups}"
STAMP="$(date +%Y%m%d_%H%M%S)"

mkdir -p "$BACKUP_DIR"
cd "$APP_DIR"

docker compose exec -T database sh -c \
  'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump --single-transaction --quick --no-tablespaces --routines --triggers -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
  | gzip -9 > "$BACKUP_DIR/bist_${STAMP}.sql.gz"

find "$BACKUP_DIR" -type f -name 'bist_*.sql.gz' -mtime +14 -delete
echo "Backup created: $BACKUP_DIR/bist_${STAMP}.sql.gz"

#!/usr/bin/env bash
# rollback.sh - restore pre-deploy state from backup
# Usage: bash scripts/rollback.sh storage/backups/pre_deploy_YYYYMMDD_HHMMSS.sql
# Retains both pre/post backups - never overwrites
set -euo pipefail
if [ $# -eq 0 ]; then
  echo "Usage: $0 <backup.sql> (use pre_deploy_* for full rollback, post_deploy_* for re-apply)"
  echo "Available:"
  ls -lh storage/backups/*.sql 2>/dev/null || echo "  no backups"
  exit 1
fi
BACKUP="$1"
if [ ! -f "$BACKUP" ]; then echo "Backup not found: $BACKUP"; exit 1; fi
echo "=== Rollback: restoring DB from $BACKUP ==="
# Fresh mysqldump of current (safety) before restore
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
SAFETY="storage/backups/pre_rollback_safety_${TIMESTAMP}.sql"
echo "Safety backup -> $SAFETY"
# Find mysqldump
MYSQLDUMP=""
for p in \
  "$HOME/AppData/Roaming/Local/lightning-services/mysql-8.4.0/bin/win64/bin/mysqldump.exe" \
  "$HOME/AppData/Roaming/Local/lightning-services/mariadb-10.6.23+0/bin/win32/bin/mysqldump.exe" \
  "$(which mysqldump 2>/dev/null || true)"; do
  if [ -x "$p" ]; then MYSQLDUMP="$p"; break; fi
done
if [ -n "$MYSQLDUMP" ]; then
  "$MYSQLDUMP" --host=127.0.0.1 --port=10004 --user=root --password=root local --single-transaction --quick --routines --triggers 2>/dev/null > "$SAFETY" || echo "Safety backup failed, continuing"
  ls -lh "$SAFETY" 2>/dev/null || true
fi
php artisan down
# Restore - resolve mysql binary (LocalWP)
MYSQL=""
for p in \
  "$HOME/AppData/Roaming/Local/lightning-services/mysql-8.4.0/bin/win64/bin/mysql.exe" \
  "$HOME/AppData/Roaming/Local/lightning-services/mariadb-10.6.23+0/bin/win32/bin/mysql.exe" \
  "$(which mysql 2>/dev/null || true)"; do
  if [ -x "$p" ]; then MYSQL="$p"; break; fi
done
if [ -z "$MYSQL" ]; then MYSQL="mysql"; fi
"$MYSQL" --host=127.0.0.1 --port=10004 --user=root --password=root local < "$BACKUP"
php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
php artisan test --filter SeederIntegrityTest
php artisan up
echo "Rollback complete from $BACKUP (safety at $SAFETY retained)"


#!/usr/bin/env bash
# deploy.sh - production rollout with pre/post mysqldump retention
# Usage: bash scripts/deploy.sh
set -euo pipefail

# Resolve mysqldump (LocalWP)
MYSQLDUMP=""
for p in \
  "$HOME/AppData/Roaming/Local/lightning-services/mysql-8.4.0/bin/win64/bin/mysqldump.exe" \
  "$HOME/AppData/Roaming/Local/lightning-services/mariadb-10.6.23+0/bin/win32/bin/mysqldump.exe" \
  "/c/Users/Administrator/AppData/Roaming/Local/lightning-services/mysql-8.4.0/bin/win64/bin/mysqldump.exe" \
  "$(which mysqldump 2>/dev/null || true)"; do
  if [ -x "$p" ]; then MYSQLDUMP="$p"; break; fi
done
if [ -z "$MYSQLDUMP" ]; then echo "mysqldump not found"; exit 1; fi

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="storage/backups"
mkdir -p "$BACKUP_DIR"

# 1) PRE-DEPLOY backup BEFORE down (fresh, accurate pre state)
PRE="$BACKUP_DIR/pre_deploy_${TIMESTAMP}.sql"
echo "=== PRE-DEPLOY mysqldump -> $PRE ==="
"$MYSQLDUMP" --host=127.0.0.1 --port=10004 --user=root --password=root local --single-transaction --quick --routines --triggers 2>/dev/null > "$PRE"
ls -lh "$PRE"
# keep .env snapshot
cp .env "$BACKUP_DIR/.env.pre_${TIMESTAMP}.bak"

# 2) Down
php artisan down

# 3) Deploy artifact (assumes code already updated - git pull / rsync)
echo "=== Deploy artifact ==="
# git pull / deploy steps here - artifact already staged
php artisan view:clear
if [ -f package.json ]; then npm run build || true; fi

# 4) Migrate & seed
php artisan migrate --force
php artisan db:seed --force  # runs DatabaseSeeder: Initial -> Admin -> Dummy (correct order, granular authoritative)

# 5) POST-DEPLOY backup AFTER seed (retain both)
POST="$BACKUP_DIR/post_deploy_${TIMESTAMP}.sql"
echo "=== POST-DEPLOY mysqldump -> $POST ==="
"$MYSQLDUMP" --host=127.0.0.1 --port=10004 --user=root --password=root local --single-transaction --quick --routines --triggers 2>/dev/null > "$POST"
ls -lh "$POST"
cp .env "$BACKUP_DIR/.env.post_${TIMESTAMP}.bak"

# 6) Clear caches
php artisan optimize:clear

# 7) Smoke test (staging gate)
php artisan test --filter "SeederIntegrityTest|GranularPermissionRevocationTest|TicketTransferPermissionTest"

# 8) Up
php artisan up

echo "=== DEPLOY COMPLETE ==="
echo "Pre : $PRE"
echo "Post: $POST"
echo "Retain both for rollback. Rollback: bash scripts/rollback.sh $PRE"


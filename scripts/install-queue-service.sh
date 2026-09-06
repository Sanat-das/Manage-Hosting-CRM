#!/usr/bin/env bash
# Install and enable the ManageHosting emails queue worker systemd service.
# Run as root on the production server: sudo bash scripts/install-queue-service.sh
# Optionally pass app path: sudo bash scripts/install-queue-service.sh /var/www/managehosting

set -euo pipefail

APP_PATH="${1:-/var/www/managehosting}"
SERVICE_SRC="deploy/systemd/managehosting-queue-emails.service"
SERVICE_DST="/etc/systemd/system/managehosting-queue-emails.service"
PHP_BIN="$(command -v php || echo /usr/bin/php)"

if [[ ! -f "$SERVICE_SRC" ]]; then
  # fallback when run from APP_PATH
  SERVICE_SRC="$APP_PATH/$SERVICE_SRC"
fi

if [[ ! -f "$SERVICE_SRC" ]]; then
  echo "Service template not found: $SERVICE_SRC" >&2
  exit 1
fi

echo "Installing queue worker service..."
echo "  App path: $APP_PATH"
echo "  PHP bin:  $PHP_BIN"

# Patch WorkingDirectory and ExecStart with the real app path / php bin
sed -e "s|WorkingDirectory=.*|WorkingDirectory=$APP_PATH|" \
    -e "s|ExecStart=.*|ExecStart=$PHP_BIN $APP_PATH/artisan queue:work --queue=emails --sleep=3 --tries=3 --max-time=3600 --rest=0|" \
    -e "s|EnvironmentFile=-.*|EnvironmentFile=-$APP_PATH/.env|" \
    "$SERVICE_SRC" > "$SERVICE_DST"

chmod 644 "$SERVICE_DST"

systemctl daemon-reload
systemctl enable --now managehosting-queue-emails.service
systemctl status managehosting-queue-emails.service --no-pager -l || true

echo ""
echo "Done. Useful commands:"
echo "  journalctl -u managehosting-queue-emails -f"
echo "  systemctl status managehosting-queue-emails"
echo "  systemctl restart managehosting-queue-emails"

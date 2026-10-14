#!/usr/bin/env bash
#
# Uninstall wp-visitor-insights (app + service). Does NOT touch your WordPress
# files or databases — remove the mu-plugin files and drop the tables yourself
# (see README "Uninstall").
#
set -euo pipefail
PREFIX="${VI_APP_DIR:-/opt/wp-visitor-insights}"
log(){ printf '\033[1;33m==>\033[0m %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "run as root"; exit 1; }

if systemctl list-unit-files 2>/dev/null | grep -q '^wp-visitor-insights.service'; then
  log "Stopping + disabling service"
  systemctl disable --now wp-visitor-insights.service || true
  rm -f /etc/systemd/system/wp-visitor-insights.service
  systemctl daemon-reload
fi

if command -v tailscale >/dev/null 2>&1; then
  log "Removing any tailnet serve entries for this app (manual check advised: tailscale serve status)"
fi

log "Removing $PREFIX"
rm -rf "$PREFIX"

log "Done. Remember to remove mu-plugin files and drop vi_hits tables on each site."

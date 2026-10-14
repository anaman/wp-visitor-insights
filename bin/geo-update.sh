#!/usr/bin/env bash
# Refresh the offline GeoLite2 country database (run monthly from cron).
set -euo pipefail
APP="${VI_APP_DIR:-/opt/wp-visitor-insights}"
DEST="$APP/geoip/GeoLite2-Country.mmdb"
URL="https://raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-Country.mmdb"

# Optional: set MAXMIND_LICENSE_KEY to download straight from MaxMind instead.
if [ -n "${MAXMIND_LICENSE_KEY:-}" ]; then
  URL="https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT
  curl -fsSL --max-time 300 -o "$tmp/db.tgz" "$URL"
  tar xzf "$tmp/db.tgz" -C "$tmp"
  found="$(find "$tmp" -name 'GeoLite2-Country.mmdb' | head -1)"
  [ -n "$found" ] || { echo "geo-update: mmdb not found in archive" >&2; exit 1; }
  install -m 644 "$found" "$DEST"
  echo "geo-update: replaced $DEST from MaxMind at $(date -Is)"
  exit 0
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
curl -fsSL --max-time 300 -o "$tmp" "$URL"
size=$(stat -c%s "$tmp")
if [ "$size" -lt 1000000 ]; then
  echo "geo-update: download too small ($size bytes); keeping existing DB" >&2
  exit 1
fi
install -m 644 "$tmp" "$DEST"
echo "geo-update: replaced $DEST ($size bytes) at $(date -Is)"

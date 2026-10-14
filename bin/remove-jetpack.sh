#!/usr/bin/env bash
#
# Remove the Jetpack plugin from the WordPress sites listed in sites.list.
#
# ⚠️  DESTRUCTIVE. Read this script before running. It will:
#   - back up the plugin dir, the jetpack-waf drop-in, and Jetpack options,
#   - deactivate + delete the Jetpack plugin via WP-CLI,
#   - remove wp-content/jetpack-waf,
#   - drop wp_jetpack_* tables and delete %jetpack% options,
#   - purge page caches.
#
# Usage:
#   sudo ./bin/remove-jetpack.sh [--sites FILE] [--backup DIR]
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITES_FILE="$SRC/sites.list"
DB_SOCKET="/run/mysqld/mysqld.sock"
BACKUP="/root/jetpack-removal-$(date +%Y%m%d-%H%M%S)"

while [ $# -gt 0 ]; do
  case "$1" in
    --sites) SITES_FILE="$2"; shift 2;;
    --backup) BACKUP="$2"; shift 2;;
    -h|--help) sed -n '2,20p' "$0"; exit 0;;
    *) echo "unknown option: $1" >&2; exit 1;;
  esac
done

log(){ printf '\033[1;33m==>\033[0m %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "run as root"; exit 1; }
[ -f "$SITES_FILE" ] || { echo "site list not found: $SITES_FILE" >&2; exit 1; }
command -v wp >/dev/null || { echo "wp-cli not found in PATH" >&2; exit 1; }
mkdir -p "$BACKUP"

while IFS='|' read -r key label path db prefix; do
  key="$(echo "${key:-}" | xargs)"; [ -z "$key" ] && continue
  case "$key" in \#*) continue;; esac
  path="$(echo "${path:-}" | xargs)"; db="$(echo "${db:-}" | xargs)"; prefix="$(echo "${prefix:-wp_}" | xargs)"
  [ -d "$path" ] || { log "skip $key: no path $path"; continue; }
  webuser="$(stat -c '%U' "$path/wp-config.php" 2>/dev/null || echo www-data)"

  log "[$label] backup -> $BACKUP/$key"
  mkdir -p "$BACKUP/$key"
  [ -d "$path/wp-content/plugins/jetpack" ] && tar czf "$BACKUP/$key/jetpack-plugin.tar.gz" -C "$path/wp-content/plugins" jetpack || true
  [ -d "$path/wp-content/jetpack-waf" ]     && tar czf "$BACKUP/$key/jetpack-waf.tar.gz"     -C "$path/wp-content" jetpack-waf || true
  sudo -u "$webuser" wp --path="$path" option get jetpack_options --format=json > "$BACKUP/$key/jetpack_options.json" 2>/dev/null || true

  log "[$label] deactivate + delete Jetpack"
  sudo -u "$webuser" wp --path="$path" plugin deactivate jetpack --quiet 2>/dev/null || true
  sudo -u "$webuser" wp --path="$path" plugin delete jetpack 2>&1 | sed 's/^/   /' || true

  log "[$label] remove jetpack-waf + drop tables/options"
  rm -rf "$path/wp-content/jetpack-waf"
  mysql --socket="$DB_SOCKET" "$db" <<SQL
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS ${prefix}jetpack_waf_blocklog, ${prefix}jetpack_sync_queue;
DELETE FROM ${prefix}options WHERE option_name LIKE 'jetpack%' OR option_name LIKE '%jetpack%' OR option_name LIKE 'jb\_%';
SET FOREIGN_KEY_CHECKS=1;
SQL

  log "[$label] purge caches"
  sudo -u "$webuser" wp --path="$path" cache flush --quiet 2>/dev/null || true
  sudo -u "$webuser" wp --path="$path" litespeed-purge all --quiet 2>/dev/null || true

  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/" || true)
done < "$SITES_FILE"

log "Backups saved to $BACKUP"

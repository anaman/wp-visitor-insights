#!/usr/bin/env bash
# Serve the Visitor Insights dashboard with PHP's built-in server.
# Binds loopback only; expose it via your own reverse proxy or `tailscale serve`.
set -euo pipefail
APP="${VI_APP_DIR:-/opt/wp-visitor-insights}"
PORT="${VI_PORT:-8095}"
exec /usr/bin/php -d display_errors=0 -d log_errors=1 -S "127.0.0.1:${PORT}" "$APP/www" "$APP/www/router.php"

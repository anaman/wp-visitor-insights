# Changelog

## 1.0.0

- Initial public release.
- Cookie-less front-end pageview collection via a JS beacon →
  `admin-ajax.php?action=vi_track` (works with full-page caches).
- Real-client-IP recovery behind proxies/CDNs (`X-Forwarded-For`, `X-Real-IP`,
  `CF-Connecting-IP`).
- Offline country geolocation from a local GeoLite2/DB-IP `.mmdb` (pure-PHP
  reader bundled).
- Bot detection and coarse browser/OS/device classification.
- Per-site dashboard at `/visitor-insights/` (administrators only) and a
  standalone loopback app, optional Tailscale exposure.
- Multi-site aggregation with site/range selectors, timeline, top countries,
  referrers, pages, devices and a live recent-visits feed.
- `install.sh`, `uninstall.sh`, `bin/geo-update.sh`, and an optional
  `bin/remove-jetpack.sh`.
- OpenClaw skill wrapper in `skill/`.
- Licensed GPL-3.0-or-later.

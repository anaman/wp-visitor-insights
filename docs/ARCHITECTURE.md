# Architecture

```
┌─────────────────────────── Visitor's browser ───────────────────────────┐
│  every front-end page embeds  /wp-content/mu-plugins/vi-beacon.js        │
│  which POSTs one pageview to  /wp-admin/admin-ajax.php?action=vi_track   │
└───────────────────────────────────┬─────────────────────────────────────┘
                                     ▼
┌──────────────────────────── WordPress (per site) ────────────────────────┐
│ mu-plugin: visitor-insights.php                                          │
│   • resolves the real client IP (X-Forwarded-For / X-Real-IP / CF-…)     │
│   • flags bots, sniffs browser/OS/device                                 │
│   • resolves country from the local GeoLite2 .mmdb (pure PHP)            │
│   • INSERTs a row into  {prefix}vi_hits                                  │
└───────────────────────────────────┬─────────────────────────────────────┘
                                     ▼
                            MariaDB:  {prefix}vi_hits
                                     ▲
                                     │ SELECT (user: vi_read)
┌──────────────────────────── Dashboard app (PHP) ─────────────────────────┐
│ lib/Aggregate.php  →  UNION ALL across sites  →  JSON                    │
│ www/api.php        →  standalone JSON endpoint                           │
│ www/index.html     →  the UI (vanilla JS, no external assets)            │
│ www/router.php     →  guard for PHP's built-in server                    │
└───────────────▲───────────────────────────────────▲──────────────────────┘
                │                                    │
   WordPress route (admins only):        standalone service on 127.0.0.1:8095
   mu-plugin visitor-insights-dashboard.php       (systemd: wp-visitor-insights.service)
   → /visitor-insights/  and  /visitor-insights/api        → optionally `tailscale serve`
```

## Design decisions

**Why a JS beacon instead of logging nginx requests?**
Public traffic usually arrives through a CDN/reverse-proxy edge, so the web
server sees the proxy's address, not the visitor's. The beacon runs in the real
browser; the server still recovers the real IP from the proxy headers. The beacon
also survives full-page caching, which would otherwise bypass PHP entirely.

**Why `admin-ajax.php`?**
It is part of WordPress core, loads the mu-plugin, and is excluded from page
caches. No rewrite rules to add, nothing to configure.

**Why store in the site's own DB?**
Zero extra infrastructure; the dashboard just needs read access. A multi-site
dashboard UNIONs the per-site `vi_hits` tables.

**Why a standalone PHP service rather than an nginx vhost?**
It keeps installation independent of your existing web-server config (which you
may not want an installer to touch) and works the same on nginx or Apache. Point
your own reverse proxy at `127.0.0.1:8095` if you want a nicer URL.

**Why cookie-less?**
A random id in `localStorage` gives daily/across-day unique-visitor counting
without setting cookies, avoiding most consent-banner requirements. Sessions are
derived from a 30-minute time bucket, not a cookie.

## Data model (`{prefix}vi_hits`)

| column | notes |
|---|---|
| `id` | PK |
| `ts`, `ts_epoch` | local wall-clock and unix time |
| `visitor_id` | sha256(salt + browser id), daily-scoped |
| `session_id` | sha256(salt + browser id + 30-min bucket) |
| `ip`, `country_code`, `country_name` | real client IP + geo |
| `referrer`, `ref_host` | full referrer + host (own host = internal) |
| `is_bot` | 1 for known crawlers |
| `ua`, `browser`, `os`, `device` | user-agent + coarse classification |
| `url_path`, `page_title` | what was viewed |

Indexed on time, country, referrer host, visitor, session and path for fast
dashboards.

## Security

- The per-site route requires the `manage_options` capability (administrators).
- The read-only `vi_read` DB account has `SELECT` only.
- `config.php` (DB credentials) is mode `640`, and the standalone router refuses
  to serve it, dotfiles, or the `lib/`, `geoip/`, `bin/` trees.
- The dashboard routes send `no-cache` / `X-Robots-Tag: noindex` headers and set
  `DONOTCACHEPAGE` so page caches never store them.

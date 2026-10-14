# wp-visitor-insights

**Self-hosted, cookie-less WordPress visitor analytics.** See where your visitors
come from (country), what sent them (referrers), which pages they read, and on what
device — with a dashboard you can open **directly from your own website**, and
nothing ever leaving your server.

Built as a drop-in replacement for **Jetpack Stats** (and similar hosted analytics),
with no external services, no third-party scripts, and no subscription.

![license: GPL-3.0](https://img.shields.io/badge/license-GPL--3.0-blue)

## Features

- **First-party & private** — data lives in your own database; no calls to Google,
  WordPress.com, or anyone else.
- **Cookie-less** — unique visitors are counted with a random browser-side id in
  `localStorage`; no cookies, no consent banner required.
- **Works with full-page caches** — a tiny JS beacon posts to `admin-ajax.php`, which
  is never cached (LiteSpeed, WP Rocket, Cloudflare APO, etc. all fine).
- **Accurate geography behind proxies/CDNs** — real client IP is read from
  `X-Forwarded-For` / `X-Real-IP` / `CF-Connecting-IP`.
- **Offline GeoIP** — country lookup from a local GeoLite2/DB-IP `.mmdb` (pure PHP,
  no extensions, no API keys).
- **Access the dashboard three ways**:
  1. **On your own site** at `https://yoursite.com/visitor-insights/` (admins only),
  2. a standalone loopback app (behind your reverse proxy / VPN), or
  3. over Tailscale via `tailscale serve`.
- **Multi-site** — one dashboard can aggregate several WordPress sites.
- **Bot filtering** — crawlers are detected and separated from humans.

## What it looks like

KPI cards (pageviews, visitors, sessions, countries, bots), a traffic timeline,
**top countries**, **top referrers** (traffic source), top pages, device/browser
split, and a live "recent visits" feed — switchable between sites and time ranges
(24h / 7d / 30d / 90d).

## How it works

```
Visitor's browser
   │  JS beacon →  POST /wp-admin/admin-ajax.php?action=vi_track   (never page-cached)
   ▼
mu-plugin (visitor-insights.php) records the hit into  {prefix}vi_hits
   │
Dashboard app (PHP) ──read-only DB user──▶ aggregates one or many sites ──▶ JSON ──▶ UI
   ▲
   └── served by WordPress at /visitor-insights/  (mu-plugin: visitor-insights-dashboard.php)
       and/or by a standalone PHP service on 127.0.0.1:8095
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for details.

## Requirements

- Linux server with **nginx/Apache + PHP-FPM** (PHP 7.4+; tested on 8.3/8.5)
- **MariaDB/MySQL**
- One or more **WordPress** sites
- PHP **PDO MySQL** extension
- Optional: `tailscale` (for tailnet access), `systemd` (for the standalone app)

No Composer, no `ext-geoip`, no build step.

## Install

1. **Copy the example site list and edit it** (one line per WordPress site):

   ```bash
   cp sites.list.example sites.list
   ${EDITOR:-vi} sites.list
   ```

   Format: `key|label|/path/to/wordpress|database_name|table_prefix`

2. **Run the installer as root:**

   ```bash
   sudo ./install.sh
   ```

   Useful options:

   ```bash
   sudo ./install.sh --sites /etc/wp-visitor-insights.list \
        --prefix /opt/wp-visitor-insights --port 8095 \
        --tailscale-port 8450          # optional tailnet exposure
   ```

   The installer will:
   - copy the app to `/opt/wp-visitor-insights`,
   - download a GeoLite2 country database,
   - create a read-only DB user (`vi_read`) and generate `www/config.php`,
   - install the collector + dashboard mu-plugins into every site,
   - install and start a small systemd service (PHP built-in server on loopback),
   - optionally expose it over Tailscale.

3. **Open the dashboard:**

   - **Per site (recommended):** `https://your-site.com/visitor-insights/`
     (you must be logged in as an administrator; a "Visitor Insights" link also
     appears in the WP admin bar).
   - **Standalone:** `http://127.0.0.1:8095/` (put it behind your own reverse
     proxy / VPN, or use `--tailscale-port`).

That's it — data starts collecting immediately. (No history is imported.)

### Manual install

Prefer to wire it up yourself? Copy `lib/`, `www/`, `bin/` to a directory, copy
`config.example.php` to `www/config.php` and edit it, then copy the three files in
`src/mu-plugin/` into each site's `wp-content/mu-plugins/`. Run the app with:

```bash
VI_APP_DIR=/opt/wp-visitor-insights VI_PORT=8095 /opt/wp-visitor-insights/bin/serve.sh
```

or point any PHP-FPM vhost at `www/`.

## Updating the GeoIP database

A monthly cron refreshes the database (add it yourself if you like):

```
17 5 3 * * /opt/wp-visitor-insights/bin/geo-update.sh >> /var/log/wp-visitor-insights-geo.log 2>&1
```

## Removing Jetpack (optional)

If you're migrating off Jetpack Stats, `bin/remove-jetpack.sh` will back up and
remove the Jetpack plugin, its WAF drop-in, tables and options from each site in
your `sites.list`. **Read the script first** — it makes destructive changes.
(Note: Jetpack *Protect* brute-force protection goes away; make sure you have
something else, e.g. `wp-fail2ban`.)

## Uninstall

```bash
sudo ./uninstall.sh          # stops/removes the service and app dir
# then delete the mu-plugin files from each site's wp-content/mu-plugins/ and:
#   DROP TABLE wp_vi_hits;   (in each site DB)
#   DROP USER 'vi_read'@'localhost', 'vi_read'@'127.0.0.1';
```

## Privacy notes

- Collected: timestamp, a hashed/random visitor id, session id, IP address, country,
  referrer, user agent, browser/OS/device, URL path, page title.
- **IP addresses are stored.** If that matters for your jurisdiction, truncate them
  in `src/mu-plugin/visitor-insights.php` (e.g. zero the last octet) before deploying.
- No data is transmitted anywhere off your server.

## As an OpenClaw skill

This repo also ships an [OpenClaw](https://github.com/openclaw/openclaw) skill wrapper
in [`skill/`](skill/) so an AI agent can install and operate it for you.

## License

**GPL-3.0-or-later.** See [LICENSE](LICENSE).

Bundled third-party code: the pure-PHP MaxMind DB reader under `lib/MaxMind/` is
© MaxMind, Inc., licensed **Apache-2.0** (compatible with GPL-3.0). See
[`NOTICE`](NOTICE).

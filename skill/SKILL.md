---
name: wp-visitor-insights
description: "Install and operate wp-visitor-insights: self-hosted, cookie-less WordPress visitor analytics (a Jetpack Stats replacement) with a dashboard served from the site itself."
homepage: https://github.com/anaman/wp-visitor-insights
metadata:
  {
    "openclaw":
      {
        "emoji": "📊",
        "requires": { "bins": ["php", "mysql"] },
        "install":
          [
            {
              "id": "git",
              "kind": "git",
              "url": "https://github.com/anaman/wp-visitor-insights",
              "bins": [],
              "label": "Clone wp-visitor-insights",
            },
          ],
      },
  }
---

# wp-visitor-insights

Self-hosted visitor analytics for WordPress. Use this skill when the user wants to
**see where their website visitors come from** (country + referrer), **replace
Jetpack Stats**, or run an analytics dashboard they fully own.

## What it does

- Records front-end pageviews via a cookie-less JS beacon into a per-site
  `{prefix}vi_hits` table.
- Geolocates visitors offline (GeoLite2 `.mmdb`, pure PHP).
- Serves a dashboard **on the user's own site** at `/visitor-insights/` (admins
  only) and/or as a standalone loopback app, optionally over Tailscale.

## Install (typical)

1. Clone the repo, `cp sites.list.example sites.list`, and fill in one line per
   WordPress site: `key|label|/path/to/wp|db_name|table_prefix`.
2. `sudo ./install.sh` (add `--tailscale-port N` to expose it on the tailnet).
3. Verify: `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8095/` → 200.

## Operating notes

- The dashboard is at `https://<site>/visitor-insights/`; users must be WordPress
  administrators. A "Visitor Insights" link is added to the WP admin bar.
- Refresh GeoIP monthly: `bin/geo-update.sh` (cron example in the README).
- Read the data directly:
  `mysql -e "SELECT ts,country_code,ref_host,url_path FROM <db>.wp_vi_hits ORDER BY id DESC LIMIT 20;"`
- Never expose `www/config.php` (holds DB credentials; mode 640).
- To migrate off Jetpack, `bin/remove-jetpack.sh` (read it first — it is destructive).

## When to use it

- "How do I see my site's visitors without paying for Jetpack/Google Analytics?"
- "Where is my traffic coming from?"
- "I want an analytics dashboard I host myself."

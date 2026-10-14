<?php
/**
 * Visitor Insights — aggregation library.
 * Shared by the standalone dashboard (www/api.php) and the per-site
 * WordPress endpoint (mu-plugin visitor-insights-dashboard.php).
 */
declare(strict_types=1);

if (!function_exists('vi_aggregate')) {

    /** Validate a range token -> seconds. */
    function vi_range_seconds(string $range): int
    {
        $ranges = array(
            '24h' => 86400,
            '7d'  => 7 * 86400,
            '30d' => 30 * 86400,
            '90d' => 90 * 86400,
            'all' => 3650 * 86400,
        );
        return $ranges[$range] ?? $ranges['7d'];
    }

    function vi_range_ok(string $range): string
    {
        return in_array($range, array('24h', '7d', '30d', '90d', 'all'), true) ? $range : '7d';
    }

    /** Tables that exist for the selected sites. */
    function vi_tables(PDO $pdo, array $selected, string $ident): array
    {
        $out = array();
        foreach ($selected as $key => $s) {
            $db  = $s['db'];
            $tbl = $s['prefix'] . 'vi_hits';
            if (!preg_match($ident, $db) || !preg_match($ident, $tbl)) { continue; }
            try {
                $st = $pdo->query("SHOW TABLES FROM `{$db}` LIKE '{$tbl}'");
                if ($st && $st->fetchColumn() !== false) {
                    $out[$key] = "`{$db}`.`{$tbl}`";
                }
            } catch (Throwable $e) { /* skip */ }
        }
        return $out;
    }

    function vi_union(array $tables): string
    {
        $parts = array();
        foreach ($tables as $key => $t) {
            $k = "'" . addslashes($key) . "'";
            $parts[] = "SELECT {$k} AS site, ts, ts_epoch, visitor_id, session_id, ip, "
                . "country_code, country_name, referrer, ref_host, is_bot, ua, browser, os, "
                . "device, url_path, page_title FROM {$t} WHERE ts_epoch >= :since";
        }
        return '(' . implode(' UNION ALL ', $parts) . ') u';
    }

    function vi_run(PDO $pdo, string $sql, int $since): array
    {
        $st = $pdo->prepare($sql);
        $st->bindValue(':since', $since, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Aggregate both sites into a single JSON-ready structure.
     * Never throws: failures are reported inside the result.
     */
    function vi_aggregate(array $cfg, string $site, string $range): array
    {
        $range  = vi_range_ok($range);
        $since  = time() - vi_range_seconds($range);
        $sites  = $cfg['sites'];
        if ($site !== 'all' && !isset($sites[$site])) { $site = 'all'; }
        $selected = ($site === 'all') ? $sites : array($site => $sites[$site]);
        $ident = '/^[A-Za-z0-9_]+$/';

        $result = array(
            'generated_at' => date('c'),
            'site'         => $site,
            'range'        => $range,
            'since'        => $since,
            'sites'        => array_map(function ($s) { return $s['label']; }, $selected),
            'available'    => array_map(function ($s) { return $s['label']; }, $sites),
            'totals'       => array('pageviews' => 0, 'visitors' => 0, 'sessions' => 0, 'bots' => 0, 'countries' => 0),
            'timeline'     => array(),
            'countries'    => array(),
            'referrers'    => array(),
            'pages'        => array(),
            'devices'      => array(),
            'browsers'     => array(),
            'os'           => array(),
            'recent'       => array(),
            'by_site'      => array(),
        );

        try {
            $pdo = new PDO(
                'mysql:host=' . $cfg['db']['host'] . ';port=' . (int) $cfg['db']['port'] . ';charset=utf8mb4',
                $cfg['db']['user'],
                $cfg['db']['pass'],
                array(
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                )
            );
        } catch (Throwable $e) {
            $result['error'] = 'db_connect';
            $result['detail'] = $e->getMessage();
            return $result;
        }

        $tables = vi_tables($pdo, $selected, $ident);
        if (empty($tables)) { return $result; }

        $u = vi_union($tables);

        try {
            $rows = vi_run($pdo, "SELECT COUNT(*) pv, COUNT(DISTINCT visitor_id) uv,
                COUNT(DISTINCT session_id) sess, COALESCE(SUM(is_bot),0) bots,
                COUNT(DISTINCT NULLIF(country_code,'')) countries FROM {$u}", $since);
            if ($rows) {
                $r = $rows[0];
                $result['totals'] = array(
                    'pageviews' => (int) $r['pv'],
                    'visitors'  => (int) $r['uv'],
                    'sessions'  => (int) $r['sess'],
                    'bots'      => (int) $r['bots'],
                    'countries' => (int) $r['countries'],
                );
            }

            $bySite = vi_run($pdo, "SELECT site, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv FROM {$u} GROUP BY site", $since);
            foreach ($bySite as $r) {
                $result['by_site'][$r['site']] = array('pageviews' => (int) $r['pv'], 'visitors' => (int) $r['uv']);
            }

            $fmt = ($range === '24h') ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
            $tl = vi_run($pdo, "SELECT DATE_FORMAT(ts, '{$fmt}') bucket, COUNT(*) pv,
                COUNT(DISTINCT visitor_id) uv FROM {$u} GROUP BY bucket ORDER BY bucket", $since);
            foreach ($tl as $r) { $result['timeline'][] = array('t' => $r['bucket'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']); }

            $rows = vi_run($pdo, "SELECT country_code code, MAX(country_name) name, COUNT(*) pv,
                COUNT(DISTINCT visitor_id) uv, MAX(is_bot) anybot FROM {$u}
                GROUP BY country_code ORDER BY pv DESC LIMIT 30", $since);
            foreach ($rows as $r) {
                $result['countries'][] = array('code' => $r['code'], 'name' => $r['name'],
                    'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'bot' => (int) $r['anybot']);
            }

            $rows = vi_run($pdo, "SELECT ref_host host, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv FROM {$u}
                WHERE ref_host <> '' GROUP BY ref_host ORDER BY pv DESC LIMIT 30", $since);
            foreach ($rows as $r) { $result['referrers'][] = array('host' => $r['host'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']); }

            $rows = vi_run($pdo, "SELECT url_path path, MAX(page_title) title, COUNT(*) pv,
                COUNT(DISTINCT visitor_id) uv FROM {$u} WHERE is_bot = 0
                GROUP BY url_path ORDER BY pv DESC LIMIT 30", $since);
            foreach ($rows as $r) { $result['pages'][] = array('path' => $r['path'], 'title' => $r['title'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']); }

            foreach (array('device' => 'devices', 'browser' => 'browsers', 'os' => 'os') as $col => $key) {
                $rows = vi_run($pdo, "SELECT {$col} label, COUNT(*) pv FROM {$u} WHERE is_bot = 0
                    GROUP BY {$col} ORDER BY pv DESC LIMIT 12", $since);
                foreach ($rows as $r) { $result[$key][] = array('label' => $r['label'] ?: 'Unknown', 'pv' => (int) $r['pv']); }
            }

            $rows = vi_run($pdo, "SELECT ts, site, ip, country_code, country_name, ref_host, url_path,
                page_title, device, browser, os, is_bot FROM {$u} ORDER BY ts_epoch DESC LIMIT 60", $since);
            foreach ($rows as $r) {
                $result['recent'][] = array(
                    'ts' => $r['ts'], 'site' => $r['site'], 'ip' => $r['ip'],
                    'cc' => $r['country_code'], 'country' => $r['country_name'],
                    'ref' => $r['ref_host'], 'path' => $r['url_path'], 'title' => $r['page_title'],
                    'device' => $r['device'], 'browser' => $r['browser'], 'os' => $r['os'],
                    'bot' => (int) $r['is_bot'],
                );
            }
        } catch (Throwable $e) {
            $result['error'] = 'query_failed';
            $result['detail'] = $e->getMessage();
        }

        return $result;
    }
}

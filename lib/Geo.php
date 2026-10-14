<?php
/**
 * Offline country lookup using a GeoLite2/DB-IP MaxMind-format database.
 * Pure PHP (no ext-geoip required). Failures are always silent.
 */

class VI_Geo
{
    /** @var \MaxMind\Db\Reader|null */
    private static $reader = null;
    private static $failed = false;
    private static $cache = array();

    public static function country($ip)
    {
        if ($ip === '' || $ip === null) { return array('', ''); }
        if (isset(self::$cache[$ip])) { return self::$cache[$ip]; }

        $out = array('', '');
        if (!self::$failed) {
            try {
                if (self::$reader === null) {
                    self::loadReader();
                    if (!class_exists('MaxMind\\Db\\Reader')) {
                        self::$failed = true;
                        return $out;
                    }
                    self::$reader = new \MaxMind\Db\Reader(self::dbPath());
                }
                $rec = self::$reader->get($ip);
                if (is_array($rec)) {
                    if (isset($rec['country']['iso_code'])) {
                        $out[0] = (string) $rec['country']['iso_code'];
                    } elseif (isset($rec['registered_country']['iso_code'])) {
                        $out[0] = (string) $rec['registered_country']['iso_code'];
                    }
                    if (isset($rec['country']['names']['en'])) {
                        $out[1] = (string) $rec['country']['names']['en'];
                    } elseif (isset($rec['continent']['names']['en'])) {
                        $out[1] = (string) $rec['continent']['names']['en'];
                    }
                }
            } catch (\Throwable $e) {
                self::$failed = true;
            }
        }
        self::$cache[$ip] = $out;
        return $out;
    }

    /** Require the vendored pure-PHP reader classes (no composer available). */
    private static function loadReader()
    {
        $base = __DIR__ . '/MaxMind/Db/';
        foreach (array(
            'Reader/InvalidDatabaseException.php',
            'Reader/Util.php',
            'Reader/Decoder.php',
            'Reader/Metadata.php',
            'Reader.php',
        ) as $f) {
            $p = $base . $f;
            if (is_file($p)) { require_once $p; }
        }
    }

    private static function dbPath()
    {
        $env = getenv('VI_GEOIP_MMDB');
        if ($env && is_file($env)) { return $env; }
        $candidates = array(
            __DIR__ . '/../geoip/GeoLite2-Country.mmdb',
            __DIR__ . '/../geoip/dbip-country-lite.mmdb',
            '/opt/wp-visitor-insights/geoip/GeoLite2-Country.mmdb',
            '/opt/wp-visitor-insights/geoip/dbip-country-lite.mmdb',
        );
        foreach ($candidates as $c) {
            if (is_file($c)) { return $c; }
        }
        return $candidates[0];
    }
}

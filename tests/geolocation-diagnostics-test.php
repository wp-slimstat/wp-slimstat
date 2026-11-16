<?php
/** Database health must not depend on the visitor having a public, covered IP. */
namespace SlimStat\Dependencies\GeoIp2\Database {
    class Reader {
        public function __construct($path) { if ('corrupt' === file_get_contents($path)) { throw new \RuntimeException('bad database'); } }
        public function metadata() { return (object) ['databaseType' => 'DBIP-City-Lite']; }
        public function city($ip) { throw new \RuntimeException('address not found'); }
        public function country($ip) { throw new \RuntimeException('address not found'); }
        public function close() {}
    }
}
namespace SlimStat\Services\Geolocation {
    class GeolocationService {
        public function __construct($provider, $options) {}
        public function getProvider() { return $this; }
        public function getDbPath() { return $GLOBALS['geo_path']; }
    }
}
namespace SlimStat\Tracker { class Utils { public static function logGeoIpError($message) {} } }
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    function __($message, $domain = '') { return $message; }
    function sanitize_text_field($value) { return $value; }
    function wp_unslash($value) { return $value; }
    class wp_slimstat {
        public static $settings = ['maxmind_license_key' => ''];
        public static function resolve_geolocation_provider() { return 'dbip'; }
        public static function get_geolocation_precision() { return 'city'; }
    }
    require __DIR__ . '/../src/Services/GeoService.php';
    $GLOBALS['geo_path'] = tempnam(sys_get_temp_dir(), 'geo-health-');
    $service = new \SlimStat\Services\GeoService();
    try {
        file_put_contents($GLOBALS['geo_path'], 'usable');
        foreach (['127.0.0.1', '::1', '192.168.1.1', '8.8.8.8'] as $ip) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            if (true !== $service->checkDatabase()['status']) { throw new \RuntimeException('Usable database rejected for ' . $ip); }
        }
        file_put_contents($GLOBALS['geo_path'], 'corrupt');
        if (false !== $service->checkDatabase()['status']) { throw new \RuntimeException('Corrupt database accepted'); }
        unlink($GLOBALS['geo_path']);
        $missing = $service->checkDatabase();
        if ($missing['status'] || false === strpos($missing['notice'], 'not found')) { throw new \RuntimeException('Missing database must have its own recovery message'); }
        echo "PASS: database health is independent of IP coverage; missing and corrupt files are distinguished\n";
    } finally { if (is_file($GLOBALS['geo_path'])) { unlink($GLOBALS['geo_path']); } }
}

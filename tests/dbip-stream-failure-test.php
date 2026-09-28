<?php
/** A failed or partial local GeoIP write must never replace the last valid database. */
namespace SlimStat\Dependencies\GeoIp2\Database {
    class Reader { public function __construct($path) {} public function city($ip) { return null; } }
}
namespace SlimStat\Services\Geolocation {
    abstract class AbstractGeoIPProvider {
        protected $dbType, $dbName, $dbPath, $dbUrl;
        public function __construct() { $this->init(); }
        protected function getDbDir() { return $GLOBALS['dbip_test_dir']; }
        protected function ensureDirExists($dir) {}
        protected function ensureWpFileApiLoaded() {}
    }
}
namespace SlimStat\Services\Geolocation\Provider {
    function fwrite($stream, $chunk) {
        if ('write' === $GLOBALS['dbip_test_failure']) { return false; }
        if ('short' === $GLOBALS['dbip_test_failure']) { return \fwrite($stream, substr($chunk, 0, 1)); }
        return \fwrite($stream, $chunk);
    }
    function gzread($stream, $size) {
        return 'read' === $GLOBALS['dbip_test_failure'] ? false : \gzread($stream, $size);
    }
    function rename($from, $to) { return 'rename' === $GLOBALS['dbip_test_failure'] ? false : \rename($from, $to); }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    function wp_tempnam($path) { return tempnam($GLOBALS['dbip_test_dir'], 'download'); }
    function wp_remote_get($url, $args) { copy($GLOBALS['dbip_test_gzip'], $args['filename']); return []; }
    function is_wp_error($response) { return false; }
    function wp_remote_retrieve_response_code($response) { return 200; }
    function wp_delete_file($path) { unlink($path); }
    require __DIR__ . '/../src/Services/Geolocation/Provider/DbIpProvider.php';
    $GLOBALS['dbip_test_dir'] = sys_get_temp_dir() . '/slimstat-dbip-' . uniqid();
    mkdir($GLOBALS['dbip_test_dir']);
    $GLOBALS['dbip_test_gzip'] = $GLOBALS['dbip_test_dir'] . '/fixture.gz';
    $bytes = random_bytes(6 * 1024 * 1024);
    file_put_contents($GLOBALS['dbip_test_gzip'], gzencode($bytes, 1));
    $database = $GLOBALS['dbip_test_dir'] . '/dbip-city-lite.mmdb';
    $provider = new \SlimStat\Services\Geolocation\Provider\DbIpProvider();
    try {
        foreach (['write', 'short', 'read', 'rename', 'none'] as $failure) {
            $GLOBALS['dbip_test_failure'] = $failure;
            file_put_contents($database, 'last valid database');
            $updated = $provider->updateDatabase();
            if ($updated !== ('none' === $failure) || file_get_contents($database) !== ('none' === $failure ? $bytes : 'last valid database')) {
                throw new \RuntimeException('GeoIP replacement or result incorrect after ' . $failure);
            }
        }
        echo "PASS: failed, partial and successful GeoIP streams preserve atomic replacement\n";
    } finally {
        foreach (glob($GLOBALS['dbip_test_dir'] . '/*') as $file) { unlink($file); }
        rmdir($GLOBALS['dbip_test_dir']);
    }
}

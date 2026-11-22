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
    function chmod($path, $mode) {
        return 'permissions' === $GLOBALS['dbip_test_failure'] ? false : \chmod($path, $mode);
    }
    function tempnam($dir, $prefix) {
        if ('stage' === $GLOBALS['dbip_test_failure']) { return false; }
        if ('fallback' === $GLOBALS['dbip_test_failure']) {
            $GLOBALS['dbip_fallback'] = \tempnam(sys_get_temp_dir(), $prefix);
            return $GLOBALS['dbip_fallback'];
        }
        return \tempnam($dir, $prefix);
    }
    function fwrite($stream, $chunk) {
        if ('write' === $GLOBALS['dbip_test_failure']) { return false; }
        if ('short' === $GLOBALS['dbip_test_failure']) { return \fwrite($stream, substr($chunk, 0, 1)); }
        return \fwrite($stream, $chunk);
    }
    function gzread($stream, $size) {
        if ('empty' === $GLOBALS['dbip_test_failure']) { return ''; }
        return 'read' === $GLOBALS['dbip_test_failure'] ? false : \gzread($stream, $size);
    }
    function rename($from, $to) {
        clearstatcache(true, $from);
        if (0644 !== (fileperms($from) & 0777)) { throw new \RuntimeException('Published database must be readable by a different web worker user'); }
        if ('overlap' === $GLOBALS['dbip_test_failure']) {
            // A second updater fails after the first validated its staging file.
            $GLOBALS['dbip_test_failure'] = 'write';
            $second = new DbIpProvider();
            if ($second->updateDatabase()) { throw new \RuntimeException('Overlapping failed download reported success'); }
            $GLOBALS['dbip_test_failure'] = 'overlap';
        }
        return 'rename' === $GLOBALS['dbip_test_failure'] ? false : \rename($from, $to);
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    function wp_is_writable($path) { return is_writable($path); }
function __($message, $domain = '') { return $message; }
    function trailingslashit($path) { return rtrim($path, '/') . '/'; }
    function wp_tempnam($path, $dir = '') {
        if ('' !== $dir && $dir !== $GLOBALS['dbip_test_dir'] . '/') { throw new \RuntimeException('Staging must stay on the destination filesystem'); }
        if ('' !== $dir && 'stage' === $GLOBALS['dbip_test_failure']) { return false; }
        if ('' !== $dir && 'unreserved' === $GLOBALS['dbip_test_failure']) { return $GLOBALS['dbip_unreserved']; }
        return tempnam($GLOBALS['dbip_test_dir'], 'download');
    }
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
        foreach (['write', 'short', 'read', 'empty', 'rename', 'none', 'overlap', 'stage', 'unreserved', 'fallback', 'permissions'] as $failure) {
            $GLOBALS['dbip_test_failure'] = $failure;
            if ('unreserved' === $failure) {
                // wp_tempnam can return a path it failed to reserve, subsequently owned by another attempt.
                $GLOBALS['dbip_unreserved'] = $GLOBALS['dbip_test_dir'] . '/other-attempt.tmp';
                file_put_contents($GLOBALS['dbip_unreserved'], 'another validated download');
            }
            file_put_contents($database, 'last valid database');
            $updated = $provider->updateDatabase();
            if (!$updated && '' === $provider->getLastError()) { throw new \RuntimeException('Failed update must explain recovery'); }
            $success = in_array($failure, ['none', 'overlap', 'unreserved'], true);
            if ($updated !== $success || file_get_contents($database) !== ($success ? $bytes : 'last valid database')) {
                throw new \RuntimeException('GeoIP replacement or result incorrect after ' . $failure);
            }
            if ('unreserved' === $failure) {
                if (!is_file($GLOBALS['dbip_unreserved']) || file_get_contents($GLOBALS['dbip_unreserved']) !== 'another validated download') {
                    throw new \RuntimeException('An unreserved filename overwrote or removed another attempt');
                }
                unlink($GLOBALS['dbip_unreserved']);
            }
            if ('fallback' === $failure && file_exists($GLOBALS['dbip_fallback'])) { throw new \RuntimeException('Fallback staging file leaked'); }
            if (count(glob($GLOBALS['dbip_test_dir'] . '/*')) !== 2) { throw new \RuntimeException('Temporary download files leaked after ' . $failure); }
        }
        echo "PASS: failed, partial, overlapping and successful GeoIP streams preserve atomic replacement without temporary-file leaks\n";
    } finally {
        foreach (glob($GLOBALS['dbip_test_dir'] . '/*') as $file) { unlink($file); }
        rmdir($GLOBALS['dbip_test_dir']);
    }
}

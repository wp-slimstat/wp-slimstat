<?php
/** A successful provider update clears stale GeoIP diagnostics, without clean-site writes. */
declare(strict_types=1);

use SlimStat\Services\Geolocation\GeolocationService;

if (PHP_SAPI !== 'cli') { exit(1); }

function get_option($name, $default = false) { return $GLOBALS['geo_options'][$name] ?? $default; }

class wp_slimstat
{
    public static function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['geo_writes'][] = [$name, $value, $autoload];
        $GLOBALS['geo_options'][$name] = $value;
        return true;
    }
}

require __DIR__ . '/../src/Tracker/Utils.php';
require __DIR__ . '/../src/Services/Geolocation/GeolocationService.php';

class DiagnosticTestService extends GeolocationService
{
    public function __construct($provider) { $this->provider = $provider; }
}

$stale = ['error' => 'The DB-IP database is missing or unreadable.', 'time' => 123];
$failures = [];
$assertions = 0;
foreach ([true, false] as $success) {
    foreach (['stale' => $stale, 'empty' => [], 'absent' => null] as $state => $diagnostic) {
        $GLOBALS['geo_options'] = null === $diagnostic ? [] : ['slimstat_geoip_error' => $diagnostic];
        $GLOBALS['geo_writes'] = [];
        $provider = new class($success) {
            private $success;
            public $calls = 0;
            public function __construct($success) { $this->success = $success; }
            public function updateDatabase() { ++$this->calls; return $this->success; }
        };
        $result = (new DiagnosticTestService($provider))->updateDatabase();
        $expected = $success && 'stale' === $state ? [] : $diagnostic;
        $writes = $success && 'stale' === $state ? [['slimstat_geoip_error', [], null]] : [];
        foreach ([
            'provider result' => $success === $result,
            'one update' => 1 === $provider->calls,
            'diagnostic' => $expected === get_option('slimstat_geoip_error', null),
            'writes' => $writes === $GLOBALS['geo_writes'],
        ] as $check => $ok) {
            ++$assertions;
            if (!$ok) { $failures[] = ($success ? 'success' : 'failure') . "/{$state}: {$check}"; }
        }
    }
}

$GLOBALS['geo_options'] = ['slimstat_geoip_error' => $stale];
$GLOBALS['geo_writes'] = [];
// Providers such as Cloudflare do not have a downloadable database.
if (false !== (new DiagnosticTestService(new stdClass()))->updateDatabase()
    || $stale !== get_option('slimstat_geoip_error') || [] !== $GLOBALS['geo_writes']) {
    $failures[] = 'a provider without an updater must preserve the diagnostic';
}
++$assertions;

if ($failures) {
    fwrite(STDERR, "FAIL: geolocation update diagnostics\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "PASS: geolocation update diagnostics ({$assertions} assertions)\n";

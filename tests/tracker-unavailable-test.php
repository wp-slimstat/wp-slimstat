<?php
namespace SlimStat\Tracker {
    class Utils { public static function logError($code) { return -$code; } }
}
namespace {
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
class wp_slimstat { const DEGRADATION_TRACKING = 'tracking'; public static $settings = ['is_tracking' => 'on']; public static $wpdb, $degradations = []; public static function record_degradation($key, $message, $severity) { self::$degradations[$key] = $severity; } }
function status_header($status) { $GLOBALS['tracking_status'] = $status; }
require dirname(__DIR__) . '/src/Tracker/Ajax.php';
$GLOBALS['wpdb'] = (object) ['dbh' => true];
wp_slimstat::$wpdb = (object) ['dbh' => null];
if (-503 !== \SlimStat\Tracker\Ajax::process() || 503 !== $GLOBALS['tracking_status']) {
    throw new RuntimeException('A disconnected external database must return 503 before processing.');
}
if ((wp_slimstat::$degradations['analytics database unavailable'] ?? '') !== 'tracking') throw new RuntimeException('The unavailable provider must create a tracking outage.');
wp_slimstat::$degradations = [];
wp_slimstat::$settings['is_tracking'] = 'off';
if (-204 !== \SlimStat\Tracker\Ajax::process()) throw new RuntimeException('Disabled tracking must stay disabled.');
if (wp_slimstat::$degradations) throw new RuntimeException('Disabled tracking must not record an outage.');
$rest = file_get_contents(dirname(__DIR__) . '/src/Controllers/Rest/TrackingRestController.php');
if (false === strpos($rest, "-503 === (int) \$result ? 503 : 200")) throw new RuntimeException('REST must preserve the retryable status.');
echo "PASS: disconnected analytics returns retryable 503 before processing; disabled tracking and REST status preserved\n";
}

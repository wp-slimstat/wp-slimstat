<?php

declare(strict_types=1);

$assertions = 0;

function assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require_once __DIR__ . '/stubs/tracking-stubs.php';
require_once __DIR__ . '/stubs/slimstat-tracker-stub.php';
require_once __DIR__ . '/../src/Interfaces/RestControllerInterface.php';
require_once __DIR__ . '/../src/Tracker/Utils.php';
require_once __DIR__ . '/../src/Controllers/Rest/TrackingRestController.php';

use SlimStat\Controllers\Rest\TrackingRestController;

// ─── Section 1: sanitize_integer_param tests ─────────────────────

assert_same(0, TrackingRestController::sanitize_integer_param('0', null, 'tz'), 'timezone zero should sanitize to 0');
assert_same(-60, TrackingRestController::sanitize_integer_param('-60', null, 'tz'), 'negative timezone offsets must be preserved');
assert_same(120, TrackingRestController::sanitize_integer_param('120', null, 'tz'), 'positive timezone offsets should sanitize to int');
assert_same(0, TrackingRestController::sanitize_integer_param('bad-value', null, 'tz'), 'invalid timezone values should sanitize to 0');

// ─── Section 2: handle_tracking merge behavior (#174) ────────────

$controller = new TrackingRestController();

// Helper: call handle_tracking and catch the TrackerStubExitException
// that the Tracker stub throws to prevent exit() from killing the test process.
function call_handle_tracking($controller, $request): void {
    try {
        ob_start();
        $controller->handle_tracking($request);
    } catch (\SlimStat\Tracker\TrackerStubExitException $e) {
        // Expected — the stub throws instead of letting exit() run
    } finally {
        ob_end_clean();
    }
}

// Test 2a: sendBeacon — init-parsed data preserved when REST returns empty
wp_slimstat::$raw_post_array = [
    'action' => 'slimtrack',
    'id'     => '42.abc',
    'res'    => 'aHR0cHM6Ly9leGFtcGxlLmNvbQ==',
    'pos'    => '100,200',
    'no'     => 'eyJ0eXBlIjoiY2xpY2sifQ==',
    'fh'     => 'fp123',
];
$request = new WP_REST_Request([]); // empty — simulates text/plain sendBeacon
call_handle_tracking($controller, $request);
assert_same('42.abc', wp_slimstat::$raw_post_array['id'], 'sendBeacon: id preserved');
assert_same('aHR0cHM6Ly9leGFtcGxlLmNvbQ==', wp_slimstat::$raw_post_array['res'], 'sendBeacon: res preserved');
assert_same('100,200', wp_slimstat::$raw_post_array['pos'], 'sendBeacon: pos preserved');
assert_same('eyJ0eXBlIjoiY2xpY2sifQ==', wp_slimstat::$raw_post_array['no'], 'sendBeacon: no preserved');
assert_same('fp123', wp_slimstat::$raw_post_array['fh'], 'sendBeacon: fh preserved');
assert_same('slimtrack', wp_slimstat::$raw_post_array['action'], 'sendBeacon: action forced to slimtrack');

// Test 2b: XHR — REST params override init-parsed data for overlapping keys
wp_slimstat::$raw_post_array = [
    'action' => 'slimtrack',
    'id'     => '42.abc',
    'res'    => 'old_value',
    'pos'    => '100,200',
];
$request = new WP_REST_Request(['id' => '99.xyz', 'res' => 'new_value']);
call_handle_tracking($controller, $request);
assert_same('99.xyz', wp_slimstat::$raw_post_array['id'], 'XHR: REST id overrides init id');
assert_same('new_value', wp_slimstat::$raw_post_array['res'], 'XHR: REST res overrides init res');
assert_same('100,200', wp_slimstat::$raw_post_array['pos'], 'XHR: init-only keys preserved');

// Test 2c: Empty init — REST params used directly
wp_slimstat::$raw_post_array = [];
$request = new WP_REST_Request(['id' => '7.def', 'ref' => 'some_ref']);
call_handle_tracking($controller, $request);
assert_same('7.def', wp_slimstat::$raw_post_array['id'], 'empty init: REST id used');
assert_same('some_ref', wp_slimstat::$raw_post_array['ref'], 'empty init: REST ref used');
assert_same('slimtrack', wp_slimstat::$raw_post_array['action'], 'empty init: action forced');

echo "All {$assertions} assertions passed in tracking-rest-controller-test.php\n";

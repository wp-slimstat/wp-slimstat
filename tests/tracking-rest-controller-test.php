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

require_once __DIR__ . '/../src/Interfaces/RestControllerInterface.php';
require_once __DIR__ . '/../src/Controllers/Rest/TrackingRestController.php';

use SlimStat\Controllers\Rest\TrackingRestController;

assert_same(0, TrackingRestController::sanitize_integer_param('0', null, 'tz'), 'timezone zero should sanitize to 0');
assert_same(-60, TrackingRestController::sanitize_integer_param('-60', null, 'tz'), 'negative timezone offsets must be preserved');
assert_same(120, TrackingRestController::sanitize_integer_param('120', null, 'tz'), 'positive timezone offsets should sanitize to int');
assert_same(0, TrackingRestController::sanitize_integer_param('bad-value', null, 'tz'), 'invalid timezone values should sanitize to 0');

echo "All {$assertions} assertions passed in tracking-rest-controller-test.php\n";

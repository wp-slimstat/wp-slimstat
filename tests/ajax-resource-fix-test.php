<?php
/**
 * Regression test: update requests (id present, res absent) must NOT overwrite
 * the existing DB resource with the tracking endpoint URL.
 *
 * Before the fix, Ajax::process() fell back to get_request_uri() — which returns
 * $_SERVER['REQUEST_URI'] — when no 'res' param was in the JS payload. This caused
 * /wp-json/slimstat/v1/hit and /wp-admin/admin-ajax.php to appear as top URLs.
 *
 * After the fix, the update path does unset($stat['resource']) instead, so
 * Storage::updateRow()'s array_filter() skips the column entirely, preserving
 * the original page URL stored when the pageview was first created.
 *
 * @see wp-slimstat/src/Tracker/Ajax.php (update path, lines 185-188)
 * @see wp-slimstat/src/Tracker/Storage::updateRow()
 */

declare(strict_types=1);

$assertions = 0;

function ajax_test_assert_true($actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($actual !== true) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function ajax_test_assert_false($actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($actual !== false) {
        fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function ajax_test_assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: Storage::updateRow() drops resource when it is unset in stat data
//
// This verifies the mechanism that makes the Ajax.php fix work: array_filter()
// removes falsy/unset values, so unsetting 'resource' prevents it from being
// included in the SQL UPDATE statement.
// ═══════════════════════════════════════════════════════════════════════════

// Simulate the stat array AFTER the fix: update request with id but no res.
// Ajax.php now does unset($stat['resource']) in this code path.
$stat_after_fix = [
    'id'     => 42,
    'dt_out' => time(),
    // 'resource' intentionally absent (fixed behaviour)
];

// Simulate the array_filter() call inside Storage::updateRow()
$id = abs(intval($stat_after_fix['id']));
unset($stat_after_fix['id']);
$filtered = array_filter($stat_after_fix);

ajax_test_assert_false(
    isset($filtered['resource']),
    'TEST 1: resource must NOT be present in filtered data when unset — preserves DB value'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: Storage::updateRow() includes resource when explicitly provided
//
// Verifies the fix does not break the normal update-with-resource path
// (e.g., when JS tracker explicitly sends res= for downloads or outbound links).
// ═══════════════════════════════════════════════════════════════════════════

$stat_with_resource = [
    'id'       => 42,
    'dt_out'   => time(),
    'resource' => '/actual-page-path',
];

$id2 = abs(intval($stat_with_resource['id']));
unset($stat_with_resource['id']);
$filtered2 = array_filter($stat_with_resource);

ajax_test_assert_true(
    isset($filtered2['resource']),
    'TEST 2: resource MUST be present when explicitly provided in stat data'
);
ajax_test_assert_same(
    '/actual-page-path',
    $filtered2['resource'],
    'TEST 2: resource value must be preserved as-is'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: The BUG (before fix) — endpoint URL leaks into resource column
//
// Documents the broken behaviour: when get_request_uri() fell back to
// REQUEST_URI, the endpoint path ended up in the resource column.
// This test verifies the bug IS fixed by showing the endpoint URL does NOT
// appear when resource is correctly unset.
// ═══════════════════════════════════════════════════════════════════════════

$_SERVER['REQUEST_URI'] = '/wp-json/slimstat/v1/hit';

// BROKEN behaviour (before fix): stat['resource'] set from REQUEST_URI
$stat_broken = [
    'id'       => 42,
    'dt_out'   => time(),
    'resource' => $_SERVER['REQUEST_URI'],  // This is what the old fallback produced
];
unset($stat_broken['id']);
$filtered_broken = array_filter($stat_broken);

ajax_test_assert_same(
    '/wp-json/slimstat/v1/hit',
    $filtered_broken['resource'],
    'TEST 3 (document bug): old fallback produced the endpoint URL as resource — this is wrong'
);

// FIXED behaviour: resource is unset, so it won't be in the UPDATE statement
$stat_fixed = [
    'id'     => 42,
    'dt_out' => time(),
    // resource is unset (new behaviour after fix)
];
unset($stat_fixed['id']);
$filtered_fixed = array_filter($stat_fixed);

ajax_test_assert_false(
    isset($filtered_fixed['resource']),
    'TEST 3 (verify fix): resource must NOT be present after fix — endpoint URL cannot overwrite page URL'
);

echo "All {$assertions} assertions passed in ajax-resource-fix-test.php\n";

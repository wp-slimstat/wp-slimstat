<?php
/**
 * MU-Plugin: E2E Correlation Logger
 *
 * Appends a run-scoped correlation tag to the `notes` field of every tracked
 * pageview, allowing Playwright tests to query:
 *
 *   WHERE notes LIKE '%[e2e:{RUN_ID}]%'
 *
 * This prevents cross-test data pollution when multiple test runs share the
 * same WordPress database (e.g. local dev or CI without DB teardown).
 *
 * Activation: set E2E_RUN_ID constant in wp-config.php or the $_ENV superglobal
 * before WordPress loads. If E2E_RUN_ID is empty this plugin is a no-op so it
 * is safe to leave installed in non-test environments.
 *
 * Hook: slimstat_filter_pageview_stat
 *   Fires in SlimStat\Tracker\Processor::process() after all built-in notes
 *   are appended (lines ~317 in Processor.php) but before the notes array is
 *   collapsed into a string and inserted into wp_slim_stats.notes.
 */

// Resolve the run ID from constant or environment variable.
$_e2e_run_id = '';
if (defined('E2E_RUN_ID')) {
    $_e2e_run_id = (string) E2E_RUN_ID;
} elseif (!empty($_ENV['E2E_RUN_ID'])) {
    $_e2e_run_id = (string) $_ENV['E2E_RUN_ID'];
}

// No run ID → nothing to do.
if ('' === $_e2e_run_id) {
    return;
}

// Capture in a local variable for the closure (PHP 7.4+ compatible).
$_e2e_tag = 'e2e:' . sanitize_key($_e2e_run_id);

/**
 * Append the correlation tag to the notes array.
 *
 * At this point in the tracking pipeline $stat['notes'] is still an array of
 * strings. SlimStat will later join them with '][' and wrap them in outer
 * brackets, producing e.g. "[user:1][e2e:run_abc123]" in the DB column.
 *
 * @param array $stat Pageview data assembled by Processor::process().
 * @return array
 */
add_filter('slimstat_filter_pageview_stat', function (array $stat) use ($_e2e_tag): array {
    if (!isset($stat['notes']) || !is_array($stat['notes'])) {
        $stat['notes'] = [];
    }

    $stat['notes'][] = $_e2e_tag;

    return $stat;
}, 99); // Late priority to run after all built-in notes are appended.

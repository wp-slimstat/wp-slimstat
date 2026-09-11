<?php
/**
 * limit_results / start_from must leave parse_filters() as integers.
 *
 * Regression for the posts-column SQL injection: admin/index.php's
 * init_data_for_column() concatenates
 * wp_slimstat_db::$filters_normalized['misc']['limit_results'] into the FIRST
 * argument of $wpdb->prepare() — the template, which prepare() does not escape.
 * parse_filters() used to store whatever `fs[limit_results]` carried, so
 *
 *     /?x=edit.php&fs[limit_results]=equals 1 UNION SELECT 1,2 --
 *
 * reached the query verbatim for ANY logged-in user (wp-slimstat.php loads the
 * admin library behind is_user_logged_in(), not is_admin(), so the `wp` hook it
 * registers does fire on the front end) once the non-default add_posts_column
 * setting was on.
 *
 * Every other consumer happens to intval() on read, which is why the defect
 * survived: the guard existed everywhere except the one path that mattered.
 * This pins the normalisation at the single point they all route through.
 */

require __DIR__ . '/lib/source-scan.php';

function absint($value)
{
    return abs((int) $value);
}

$source = file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-db.php');

eval('class Slimstat_Filter_Parser {
    public static $valueless_operators = ["is_empty", "is_not_empty"];
    public const NON_COLUMN_FILTER_KEYS = [
        "strtotime", "minute", "hour", "day", "month", "year",
        "interval", "interval_hours", "interval_minutes", "limit_results", "start_from",
    ];
    public static $all_columns_names = [
        "limit_results" => ["Max Results", "int"],
        "start_from"    => ["Offset", "int"],
        "browser"       => ["Browser", "varchar"],
    ];
    public static function parse_filters($_filters_raw) {' . slimstat_function_body($source, 'parse_filters') . '}
}');

$failures = [];

// The payloads the request path can actually deliver (fs[limit_results]=<op> <value>).
$hostile = [
    'equals 1 UNION SELECT 1,2 -- ',
    'equals 1; DROP TABLE wp_slim_stats',
    'equals 1 OR 1=1',
    'equals (SELECT 1)',
    'equals 1%20UNION%20SELECT%201',
    'equals &#039;1&#039;',
    'equals 1\\,2',
    'equals -1',
    'equals abc',
];

foreach ($hostile as $payload) {
    foreach (['limit_results', 'start_from'] as $key) {
        $parsed = Slimstat_Filter_Parser::parse_filters($key . ' ' . $payload);
        $value  = $parsed['misc'][$key] ?? null;

        if (null === $value) {
            continue; // Dropped entirely is safe too.
        }

        if (!is_int($value) || $value < 0) {
            $failures[] = sprintf('%s kept an injectable value for "%s": %s', $key, $payload, var_export($value, true));
        }
    }
}

// Legitimate values still survive, or the fix would have broken pagination.
foreach ([['limit_results', 'equals 50', 50], ['start_from', 'equals 100', 100], ['limit_results', 'equals 1', 1]] as [$key, $payload, $expected]) {
    $parsed = Slimstat_Filter_Parser::parse_filters($key . ' ' . $payload);
    $value  = $parsed['misc'][$key] ?? null;

    if ($value !== $expected) {
        $failures[] = sprintf('legitimate %s was mangled: expected %d, got %s', $key, $expected, var_export($value, true));
    }
}

if ([] !== $failures) {
    throw new RuntimeException("limit_results/start_from normalisation:\n  - " . implode("\n  - ", $failures));
}

echo 'PASS: limit_results/start_from leave parse_filters() as integers (' . count($hostile) . " payloads x2 keys, 3 legitimate values)\n";

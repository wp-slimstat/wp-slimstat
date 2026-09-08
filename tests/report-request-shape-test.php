<?php
// Execute the real shared request parser; isolate date normalization and SQL reads.
require __DIR__ . '/lib/source-scan.php';
class wp_slimstat { public static function now() { return 1767227400; } public static $settings = ['geolocation_country' => 'off', 'restrict_authors_view' => 'on']; }
function __($text, $domain) { return $text; }
function apply_filters($hook, $value) { return $value; }
function sanitize_key($value) { return is_scalar($value) ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value)) : ''; }
function sanitize_text_field($value) { return is_scalar($value) ? strip_tags((string) $value) : ''; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function current_user_can($capability) { return false; }
function absint($value) { return abs((int) $value); }
$body = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-db.php'), 'init');
eval('class wp_slimstat_db {
 public static $columns_names, $operator_names, $all_columns_names, $filters_normalized, $pageviews;
 public static function init_filters($raw) { return $raw; }
 public static function count_records() { return 7; }
 public static function init($_filters = "") {' . $body . '}
}');
$GLOBALS['current_user'] = (object) ['user_login' => 'author-fixture'];
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
$cases = [
 ['post' => ['hour' => ['0']], 'request' => ['page' => ['slimview1']]],
 ['get' => ['type' => ['custom'], 'from' => ['2026-01-01'], 'to' => ['2026-01-02']]],
 ['get' => ['type' => 'custom', 'from' => ['2026-01-01'], 'to' => '2026-01-02']],
 ['post' => ['f' => ['browser'], 'o' => 'equals', 'v' => 'Firefox']],
 ['post' => ['f' => 'browser', 'o' => ['equals'], 'v' => 'Firefox']],
 ['post' => ['f' => 'browser', 'o' => 'equals', 'v' => ['Firefox']]],
 ['request' => ['fs' => ['browser' => ['equals Firefox']]]],
];
foreach ($cases as $case) {
 $_GET = $case['get'] ?? []; $_POST = $case['post'] ?? []; $_REQUEST = $case['request'] ?? [];
 wp_slimstat_db::init();
 if ('author equals author-fixture' !== wp_slimstat_db::$filters_normalized) { throw new RuntimeException('Malformed filter became an unintended constraint'); }
}
$_GET = []; $_POST = ['hour' => '0', 'f' => 'browser', 'o' => 'equals', 'v' => "O\\'Brien"];
$_REQUEST = ['page' => 'slimview1', 'fs' => ['country' => 'equals gb']];
wp_slimstat_db::init('resource contains news');
foreach (['hour equals 0', "browser equals O'Brien", 'country equals gb', 'resource contains news', 'author equals author-fixture'] as $filter) {
 if (false === strpos(wp_slimstat_db::$filters_normalized, $filter)) { throw new RuntimeException('Valid filter lost: ' . $filter); }
}
if (7 !== wp_slimstat_db::$pageviews) { throw new RuntimeException('Report initialization skipped'); }
echo "PASS: malformed request shapes ignored without fatal errors; valid zero, custom, author and explicit filters preserved\n";

// Run the shared date parser too: valid wall-clock dates survive, malformed dates do not become 1970.
$parse_body = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-db.php'), 'parse_filters');
eval('class DateParser extends wp_slimstat_db { const NON_COLUMN_FILTER_KEYS = ["strtotime"]; public static $valueless_operators = []; public static function parse_filters($_filters_raw) {' . $parse_body . '} }');
DateParser::$all_columns_names = ['strtotime' => 'Date'];
$original_zone = date_default_timezone_get();
date_default_timezone_set('UTC'); // WordPress core configures UTC; wp_slimstat::now already contains the site offset.
try {
    $valid = DateParser::parse_filters('strtotime equals 2026-01-02 03:04:00');
    if ($valid['date'] !== ['minute' => 4, 'hour' => 3, 'day' => 2, 'month' => 1, 'year' => 2026]) { throw new RuntimeException('Valid wall-clock date changed'); }
    $relative = DateParser::parse_filters('strtotime equals yesterday');
    if ($relative['date']['year'] !== 2025 || $relative['date']['day'] !== 31 || $relative['date']['month'] !== 12) { throw new RuntimeException('Relative year boundary changed'); }
    $invalid = DateParser::parse_filters('strtotime equals invalid-date-fixture');
    if ($invalid['date'] !== []) { throw new RuntimeException('Invalid date became an epoch filter'); }
} finally { date_default_timezone_set($original_zone); }
echo "PASS: date filters preserve legacy site clock and reject unparseable dates\n";

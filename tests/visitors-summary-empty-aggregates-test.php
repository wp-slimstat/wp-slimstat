<?php
// AVG()/MAX() over zero matching rows return one row of NULLs, not zero rows, so the
// empty() fallback in get_visitors_summary() never fires. Before Migration on an upgraded
// site (no visit_id > 0 yet) that NULL reached number_format() and logged a PHP 8.1+
// deprecation on every Dashboard load. Runs the real body; the number_format_i18n stub
// rejects null on any PHP version, so this fails on 7.4 too.
require __DIR__ . '/lib/source-scan.php';

class wp_slimstat_db {
    public static $metrics = [];
    public static function count_records(...$args) { return 0; }
    public static function count_records_having(...$args) { return 0; }
    public static function get_max_and_average_pages_per_visit() { return self::$metrics; }
}
function __($text, $domain = '') { return $text; }
function number_format_i18n($number, $decimals = 0) {
    if (!is_int($number) && !is_float($number) && !(is_string($number) && is_numeric($number))) {
        throw new \RuntimeException('number_format_i18n() got ' . var_export($number, true));
    }
    return number_format((float) $number, $decimals);
}

$source = file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-db.php');
eval('class SummaryProbe { public static function run() {' . slimstat_function_body($source, 'get_visitors_summary') . '} }');

$cases = [
    'no visits yet (row of NULLs)' => [[['avghits' => null, 'maxhits' => null]], '0.00', '0 pageviews'],
    'no row at all'                => [[], '0.00', '0 pageviews'],
    'visits recorded'              => [[['avghits' => '2.5000', 'maxhits' => '7']], '2.50', '7 pageviews'],
];
foreach ($cases as $name => [$metrics, $avg, $max]) {
    wp_slimstat_db::$metrics = $metrics;
    $results = SummaryProbe::run();
    if ($results[6]['value'] !== $avg || $results[7]['value'] !== $max) {
        throw new \RuntimeException("$name: got '{$results[6]['value']}' / '{$results[7]['value']}'");
    }
}
echo "PASS: visitors summary renders zero, not a null deprecation, when no visit has been recorded\n";

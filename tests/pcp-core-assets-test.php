<?php
/** The picker must reuse WordPress's Moment instance and preserve dependency ordering. */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/lib/source-scan.php';
$source = file_get_contents(__DIR__ . '/../admin/index.php');
$body = slimstat_function_body($source, 'wp_slimstat_enqueue_scripts');
$start = strpos($body, 'if ($should_load_datepicker)');
$end = strpos($body, '// Shared wp.i18n accessor', $start);
$should_load_datepicker = true;
$registered = [];
function wp_enqueue_script($handle, $src = '', $deps = [], $version = false, $footer = false) { $GLOBALS['registered'][$handle] = $deps; }
function wp_enqueue_style(...$args) {}
function wp_localize_script(...$args) {}
function plugins_url($path, $plugin) { return $path; }
function admin_url($path) { return $path; }
function wp_create_nonce($action) { return 'fixture'; }
class DateRangeHelper {
    public static function get_wp_timezone() { return 'UTC'; }
    public static function get_week_start() { return 1; }
    public static function get_date_format() { return 'Y-m-d'; }
    public static function get_localized_strings() { return []; }
}
define('SLIMSTAT_ANALYTICS_VERSION', 'fixture');
eval(substr($body, $start, $end - $start));
if (!in_array('moment', $registered['slimstat-daterangepicker'] ?? [], true)
    || isset($registered['slimstat-moment'])
    || file_exists(__DIR__ . '/../admin/assets/js/daterangepicker/moment.min.js')) {
    fwrite(STDERR, "FAIL: date picker must use core Moment, without a bundled duplicate\n");
    exit(1);
}
echo "PASS: date picker uses WordPress Moment in dependency order\n";

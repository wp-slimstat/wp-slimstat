<?php
/** Run with wp eval-file in a disposable site with the candidate plugin active. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
if (!class_exists('WP_Hook') || !class_exists('wp_slimstat')) { fwrite(STDERR, "Load WordPress and the candidate plugin with wp eval-file.\n"); exit(2); }
if (!class_exists('wp_slimstat_db')) {
    require_once dirname((new ReflectionClass('wp_slimstat'))->getFileName()) . '/admin/view/wp-slimstat-db.php';
}
if (!class_exists('wp_slimstat_db')) { fwrite(STDERR, "Candidate report database class is unavailable.\n"); exit(2); }

$calls = [];
$object = new class($calls) {
    private $calls;
    public function __construct(&$calls) { $this->calls = &$calls; }
    public function __invoke($date, $format) { $this->calls[] = ['object', $format]; return $date; }
    public function method($date) { $this->calls[] = ['method']; return $date; }
};
$closure = static function ($date, $format, $timestamp, $gmt) use (&$calls) {
    $calls[] = ['closure', $format, $timestamp, $gmt];
    return $date;
};

remove_all_filters('date_i18n');
add_filter('date_i18n', $closure, 3, 4);
add_filter('date_i18n', $object, 19, 2);
add_filter('date_i18n', [$object, 'method'], 31, 1);
$expected = $GLOBALS['wp_filter']['date_i18n']->callbacks;

$value = wp_slimstat::date_i18n('Y', 1767225600);
if ('2026' !== $value || [] !== $calls || $expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('date_i18n wrapper did not suppress and exactly restore mixed callbacks');
}

wp_slimstat::toggle_date_i18n_filters(false);
wp_slimstat::toggle_date_i18n_filters(false);
wp_slimstat::toggle_date_i18n_filters(true);
if (has_filter('date_i18n')) {
    throw new RuntimeException('nested suspension restored callbacks before its outer scope ended');
}
wp_slimstat::toggle_date_i18n_filters(true);
if ($expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('nested suspension did not restore the exact callback registry');
}

$thrown = new RuntimeException('qualification exception');
$throwing = static function () use ($thrown) { throw $thrown; };
add_filter('pre_option_gmt_offset', $throwing);
try {
    wp_slimstat::date_i18n('U');
    throw new RuntimeException('exception control did not throw');
} catch (RuntimeException $caught) {
    if ($caught !== $thrown) { throw $caught; }
} finally {
    remove_filter('pre_option_gmt_offset', $throwing);
}
if ($expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
    throw new RuntimeException('exception path did not restore the exact callback registry');
}

$assert_restored = static function ($path) use ($expected) {
    if ($expected !== $GLOBALS['wp_filter']['date_i18n']->callbacks) {
        throw new RuntimeException($path . ' did not restore the exact callback registry');
    }
};

$parse_exception = new RuntimeException('parse exception');
$throwing_wp_date = static function () use ($parse_exception) { throw $parse_exception; };
add_filter('wp_date', $throwing_wp_date);
try {
    wp_slimstat_db::parse_filters('day equals tomorrow');
    throw new RuntimeException('parse exception control did not throw');
} catch (RuntimeException $caught) {
    if ($caught !== $parse_exception) { throw $caught; }
} finally {
    remove_filter('wp_date', $throwing_wp_date);
}
$assert_restored('filter parsing exception path');

$saved_settings = wp_slimstat::$settings;
$saved_columns = wp_slimstat_db::$all_columns_names;
wp_slimstat::$settings['limit_results'] = 10;
wp_slimstat::$settings['use_current_month_timespan'] = 'off';
wp_slimstat::$settings['posts_column_day_interval'] = 30;
wp_slimstat_db::$all_columns_names['interval_hours'] = ['Interval hours', 'int'];
$init_exception = new RuntimeException('initialization exception');
$throwing_bucket = static function () use ($init_exception) { throw $init_exception; };
add_filter('slimstat_live_window_bucket_seconds', $throwing_bucket);
try {
    wp_slimstat_db::init_filters('interval_hours equals -1');
    throw new RuntimeException('filter initialization exception control did not throw');
} catch (RuntimeException $caught) {
    if ($caught !== $init_exception) { throw $caught; }
} finally {
    remove_filter('slimstat_live_window_bucket_seconds', $throwing_bucket);
    wp_slimstat::$settings = $saved_settings;
    wp_slimstat_db::$all_columns_names = $saved_columns;
}
$assert_restored('filter initialization exception path');

$saved_pageviews = wp_slimstat_db::$pageviews;
$saved_filters = wp_slimstat_db::$filters_normalized;
wp_slimstat_db::$pageviews = 1;
wp_slimstat_db::$filters_normalized = ['utime' => ['start' => 0, 'end' => 86400]];
$summary_exception = new RuntimeException('summary exception');
$throwing_format = static function () use ($summary_exception) { throw $summary_exception; };
add_filter('number_format_i18n', $throwing_format);
try {
    wp_slimstat_db::get_overview_summary();
    throw new RuntimeException('summary exception control did not throw');
} catch (RuntimeException $caught) {
    if ($caught !== $summary_exception) { throw $caught; }
} finally {
    remove_filter('number_format_i18n', $throwing_format);
    wp_slimstat_db::$pageviews = $saved_pageviews;
    wp_slimstat_db::$filters_normalized = $saved_filters;
}
$assert_restored('overview summary exception path');

echo "PASS: real WP_Hook callbacks are restored across wrapper, filter parsing, initialization and overview exception paths\n";

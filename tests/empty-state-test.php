<?php

/**
 * Audit E1–E3, E5, E7: every legacy report's empty state says which of three things is true
 * (never tracked, filters exclude everything, empty date range) and what to do next.
 *
 * It replaced a bare "No data to display" that read the same whether tracking was broken,
 * a filter was too narrow, or the site was simply quiet. Each variant is rendered here
 * against a stubbed database, so a branch that stops firing fails by name.
 *
 * Run: php tests/empty-state-test.php
 */

declare(strict_types=1);

$failures = [];
function es_assert(bool $cond, string $label, array &$failures): void
{
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

if (!function_exists('__')) {
    function __($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }
}
if (!function_exists('esc_url')) {
    function esc_url($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return 'date_format' === $name ? 'Y-m-d' : $default;
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $ts = false)
    {
        return gmdate($format, (int) $ts);
    }
}

$GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

class es_wpdb
{
    public $max_dt = 0;
    public $count = 0;
    public $queries = 0;
    public function get_var($sql)
    {
        $this->queries++;
        if (false !== strpos($sql, 'COUNT(')) {
            return (string) min($this->count, false !== strpos($sql, 'LIMIT 50') ? 50 : PHP_INT_MAX);
        }
        return $this->max_dt ?: null;   // MAX() of an empty table is NULL
    }
}

$es_can = true;
if (!function_exists('current_user_can')) {
    function current_user_can($cap)
    {
        return $GLOBALS['es_can'];
    }
}
if (!function_exists('_n')) {
    function _n($one, $many, $n, $d = 'default')
    {
        return 1 === (int) $n ? $one : $many;
    }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($n)
    {
        return number_format((float) $n);
    }
}
class es_integration
{
    public static $on = false;
    public static function available()
    {
        return self::$on;
    }
}
class_alias('es_integration', 'SlimStat\\Ecommerce\\Integration');

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $wpdb;
        public static $settings = [];
    }
}
if (!class_exists('wp_slimstat_db')) {
    class wp_slimstat_db
    {
        public static $filters_normalized = ['columns' => [], 'date' => []];
    }
}
if (!class_exists('wp_slimstat_admin')) {
    class wp_slimstat_admin
    {
        public static $current_screen = 'slimview4';
        public static function show_message($m, $type, $handle)
        {
            echo '<div id="slimstat-notice-' . $handle . '">' . $m . '</div>';
        }
    }
}

require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';

$render = static function (int $max_dt, array $columns = [], int $interval = -28, string $hint = '', string $title = ''): string {
    wp_slimstat::$wpdb                      = new es_wpdb();
    wp_slimstat::$wpdb->max_dt              = $max_dt;
    wp_slimstat_reports::$last_pageview_dt  = null;
    wp_slimstat_db::$filters_normalized     = ['columns' => $columns, 'date' => ['interval' => $interval]];
    ob_start();
    wp_slimstat_reports::empty_state($hint, $title);
    return (string) ob_get_clean();
};

$last = gmmktime(12, 0, 0, 3, 14, 2026);

// Never tracked: the tracker itself is the suspect, whatever the report or the filters say.
$html = $render(0, ['browser' => ['equals', 'Firefox']], -28, 'Report hint', 'Visits appear here as they happen.');
es_assert(false !== strpos($html, 'No pageviews recorded yet.'), 'empty table -> "No pageviews recorded yet."', $failures);
es_assert(false !== strpos($html, 'private window'), 'empty table -> tells the owner how to test tracking', $failures);
es_assert(false === strpos($html, 'filters') && false === strpos($html, 'Last 90 days'), 'empty table -> no filter blame, no date-range button', $failures);

// Filters set: say so, and nothing about the date range.
$html = $render($last, ['browser' => ['equals', 'Firefox']]);
es_assert(false !== strpos($html, 'No pageviews match these filters.'), 'filters active -> blames the filters', $failures);
es_assert(false === strpos($html, 'Last 90 days'), 'filters active -> no date-range button', $failures);

// Empty range, no filters: never mention filters; offer 90 days and the real last date (C6).
$html = $render($last);
es_assert(false !== strpos($html, 'No pageviews in this date range.'), 'empty range -> "No pageviews in this date range."', $failures);
es_assert(false === stripos($html, 'filter'), 'empty range without filters -> the word "filter" never appears (E2)', $failures);
es_assert(false !== strpos($html, 'The last pageview was on 2026-03-14.'), 'empty range -> names the last pageview date', $failures);
es_assert(false !== strpos($html, 'href="https://example.test/wp-admin/admin.php?page=slimview4&amp;type=last_90_days"'), 'empty range -> "Last 90 days" links to this screen', $failures);

// Already 90+ days: the button would lead to the same empty card.
es_assert(false === strpos($render($last, [], -90), 'Last 90 days'), 'range already 90 days -> no button', $failures);

// A report's own hint replaces the date line (E3); a fixed title replaces the range line (E5).
$html = $render($last, [], -28, 'Shows posts that have categories.');
es_assert(false !== strpos($html, 'Shows posts that have categories.') && false === strpos($html, 'The last pageview'), 'report hint is shown in place of the date line', $failures);
$html = $render($last, [], -28, '', 'Visits appear here as they happen.');
es_assert(false !== strpos($html, 'Visits appear here as they happen.') && false === strpos($html, 'Last 90 days'), 'Access Log title -> one line, no range button', $failures);

// Contracts other code relies on.
es_assert(1 === preg_match('~^<div class="slimstat-empty"><p class="nodata">[^<]+</p>~', $html), 'keeps p.nodata (E2E specs key on it) inside .slimstat-empty', $failures);
es_assert('' === preg_replace('~<div class="slimstat-empty">.*?</div>~s', '', $render($last)), 'the parity harness strip removes the whole block (no nested div)', $failures);
es_assert(false !== strpos($render($last, [], -28, '<b>x</b>'), '&lt;b&gt;x&lt;/b&gt;'), 'hint is escaped', $failures);

wp_slimstat_reports::$last_pageview_dt = null;
wp_slimstat::$wpdb->queries            = 0;
ob_start();
wp_slimstat_reports::empty_state();
wp_slimstat_reports::empty_state();
ob_end_clean();
es_assert(1 === wp_slimstat::$wpdb->queries, 'MAX(dt) runs once per request, not once per empty card', $failures);

// A report that ignores the date range (the 5-minute online cards) must not say "this date range"
// or offer "Last 90 days": each carries its own empty_title, and raw_results_to_html passes it.
require_once __DIR__ . '/lib/source-scan.php';
$reports_src = slimstat_blank_comments((string) file_get_contents(dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php'));
es_assert(substr_count($reports_src, "'use_date_filters' => false") === preg_match_all("/'empty_title'\s*=>/", $reports_src), 'every report without date filters has an empty_title', $failures);
es_assert(false !== strpos($reports_src, "self::empty_state(\$_args['empty_hint'] ?? '', \$_args['empty_title'] ?? '')"), 'raw_results_to_html passes empty_title to empty_state()', $failures);

// E7: the Get started card. Overview only, admins only, under 50 pageviews, until dismissed.
$card = static function (int $count, string $screen = 'slimview2', bool $can = true, string $setting = 'on', bool $woo = false): string {
    $GLOBALS['es_can']                  = $can;
    es_integration::$on                 = $woo;
    wp_slimstat::$settings              = ['notice_getstarted' => $setting];
    wp_slimstat_admin::$current_screen  = $screen;
    wp_slimstat::$wpdb                  = new es_wpdb();
    wp_slimstat::$wpdb->count           = $count;
    ob_start();
    wp_slimstat_reports::get_started();
    return (string) ob_get_clean();
};
$html = $card(3);
es_assert(false !== strpos($html, 'id="slimstat-notice-getstarted"'), 'card renders with the getstarted dismiss handle', $failures);
es_assert(false !== strpos($html, 'SlimStat has recorded 3 pageviews so far.'), 'card states the real pageview count (C6)', $failures);
es_assert(false !== strpos($html, 'page=slimview6') && false !== strpos($html, 'page=slimview5#slimstat-utm-builder') && false !== strpos($html, 'page=slimview1'), 'card links to Real-time, Goals and the UTM builder', $failures);
es_assert(false === strpos($html, 'slimview7'), 'no Ecommerce link without WooCommerce', $failures);
es_assert(false !== strpos($card(1), 'recorded 1 pageview so far.'), 'singular pageview', $failures);
es_assert(false !== strpos($card(0, 'slimview2', true, 'on', true), 'page=slimview7'), 'Ecommerce link when WooCommerce is available', $failures);
es_assert('' === $card(49 + 1), '50 pageviews -> no card', $failures);
es_assert('' !== $card(49), '49 pageviews -> card', $failures);
es_assert('' === $card(3, 'slimview3'), 'other screens -> no card', $failures);
es_assert('' === $card(3, 'slimview2', false), 'non-admin -> no card', $failures);
es_assert('' === $card(3, 'slimview2', true, 'no'), 'dismissed -> no card', $failures);
es_assert(0 === (function () use ($card) { $card(3, 'slimview2', true, 'no'); return wp_slimstat::$wpdb->queries; })(), 'dismissed -> no query', $failures);

if ($failures) {
    fwrite(STDERR, count($failures) . " failure(s)\n");
    exit(1);
}
echo "empty-state: all passed\n";

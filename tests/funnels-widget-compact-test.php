<?php

/**
 * Fix 2 — the compact Funnels widget must render ALL funnels with switchable
 * tabs, not just the first.
 *
 * Root cause: show_funnels_compact() hard-rendered $funnels[0] only ("widgets
 * don't have tab UI"), so a site with multiple funnels saw just one in the
 * dashboard widget with no way to switch.
 *
 * Fix: render every funnel as its own .slimstat-funnel-chart panel inside a
 * .slimstat-funnel-widget wrapper, with a unique-classed tab strip
 * (.slimstat-funnel-wtab — distinct from the main page's .slimstat-gf-tab so
 * the two delegated handlers never collide) when there is more than one funnel.
 * Panels render visible by default so no-JS consumers (email/CSV) still see all
 * funnels stacked; goals-funnels.js hides the inactive panels and wires tabs.
 *
 * Run: php tests/funnels-widget-compact-test.php
 */

declare(strict_types=1);

$failures = [];
function fwc_assert(bool $cond, string $label, array &$failures): void
{
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// --- WP + DB shims used by show_funnels_compact() (call time only) ---
if (!function_exists('esc_html')) {
    function esc_html($v)
    {
        return $v;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($v)
    {
        return $v;
    }
}
if (!function_exists('__')) {
    function __($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($n)
    {
        return number_format((float) $n);
    }
}

// The widget renderer bounds how many funnels it computes per render, reading the
// limit through slimstat_widget_max_entries. Steerable so one case can exercise the
// bound rather than every case quietly disabling it. (D40)
$GLOBALS['fwc_widget_budget'] = null;   // null = leave the tier default alone

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args)
    {
        if ('slimstat_widget_max_entries' === $hook && null !== $GLOBALS['fwc_widget_budget']) {
            return $GLOBALS['fwc_widget_budget'];
        }
        return $value;
    }
}

// Test double for the DB — the same get_funnel_results() the main page uses, so
// "widget data == main-page data source" holds by construction.
if (!class_exists('wp_slimstat_db')) {
    class wp_slimstat_db
    {
        public static array $result = [
            ['name' => 'Home',    'visitors' => 200, 'pct' => 100],
            ['name' => 'Pricing', 'visitors' => 34,  'pct' => 17.0],
        ];
        /** How many funnel chains were actually computed — the compute budget's contract. */
        public static int $calls = 0;

        public static function get_funnel_results(array $funnel): array
        {
            self::$calls++;
            return self::$result;
        }
    }
}

$plugin_root = dirname(__DIR__);
require_once $plugin_root . '/admin/view/wp-slimstat-reports.php';

$render = static function (int $max_funnels, array $funnels): string {
    $m = new ReflectionMethod('wp_slimstat_reports', 'show_funnels_compact');
    // Required on PHP < 8.1 to invoke a private method; on 8.1+ it's a no-op and
    // is deprecated in 8.5, so only call it where it's actually needed.
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    ob_start();
    wp_slimstat_db::$calls = 0;
    $m->invoke(null, $max_funnels, $funnels);
    return (string) ob_get_clean();
};

$two = [
    ['id' => 1, 'name' => 'Checkout', 'steps' => [['name' => 'Home'], ['name' => 'Pricing']]],
    ['id' => 2, 'name' => 'Signup',   'steps' => [['name' => 'Home'], ['name' => 'Pricing']]],
];

// --- multi-funnel: every funnel renders, with a unique-classed tab strip ---
$html = $render(10, $two);
fwc_assert(substr_count($html, 'class="slimstat-funnel-chart"') === 2, 'two funnels -> two .slimstat-funnel-chart panels', $failures);
fwc_assert(substr_count($html, 'data-funnel-index="0"') >= 1 && substr_count($html, 'data-funnel-index="1"') >= 1, 'each panel is tagged with its funnel index', $failures);
fwc_assert(strpos($html, 'slimstat-funnel-widget') !== false, 'panels are wrapped in the .slimstat-funnel-widget container', $failures);
fwc_assert(substr_count($html, 'slimstat-funnel-wtab') >= 2, 'a unique-classed tab strip (.slimstat-funnel-wtab) is emitted, one per funnel', $failures);
fwc_assert(strpos($html, 'slimstat-gf-tab') === false, 'the widget does NOT reuse the main-page .slimstat-gf-tab class (no handler collision)', $failures);
fwc_assert(strpos($html, 'Checkout') !== false && strpos($html, 'Signup') !== false, 'both funnel names appear', $failures);
fwc_assert(strpos($html, 'role="tablist"') !== false && strpos($html, 'role="tab"') !== false, 'tab strip carries tablist/tab roles (a11y)', $failures);
fwc_assert(
    strpos($html, 'aria-controls="slimstat-funnel-wpanel-') !== false
        && strpos($html, 'aria-labelledby="slimstat-funnel-wtab-') !== false,
    'tabs and panels are linked via aria-controls / aria-labelledby',
    $failures
);
fwc_assert(strpos($html, 'title="Checkout"') !== false, 'tab carries a title attr (full name for the ellipsized label)', $failures);
// No-JS graceful: panels are not hidden server-side (JS hides inactive on load).
fwc_assert(strpos($html, '<div class="slimstat-funnel-chart" data-funnel-index="1"') !== false
    && !preg_match('/data-funnel-index="1"[^>]*hidden/', $html), 'inactive panel is visible by default (no-JS shows all funnels stacked)', $failures);

// --- single funnel: no tab strip, still one tagged panel ---
$single = $render(10, [$two[0]]);
fwc_assert(substr_count($single, 'class="slimstat-funnel-chart"') === 1, 'single funnel -> one panel', $failures);
fwc_assert(strpos($single, 'slimstat-funnel-wtab') === false, 'single funnel -> no tab strip', $failures);

// --- locked (free) + empty states preserved ---
$locked = $render(0, []);
fwc_assert(strpos($locked, 'slimstat-funnel--locked') !== false, 'free tier still shows the locked upsell', $failures);
$empty = $render(10, []);
fwc_assert(strpos($empty, 'nodata') !== false, 'pro + no funnels still shows the empty state', $failures);

// --- polish: a zero-visitor / unreachable step keeps the muted fill (data-zero) ---
// First step must be non-zero or the renderer shows the "no visitors" summary and
// skips bars entirely; a later step dropping to 0 is the realistic drop-off case.
wp_slimstat_db::$result = [
    ['name' => 'Home',    'visitors' => 100, 'pct' => 100],
    ['name' => 'Pricing', 'visitors' => 0,   'pct' => 0],
];
$zeroHtml = $render(10, [$two[0]]);
fwc_assert(substr_count($zeroHtml, 'slimstat-funnel-bar-fill" data-zero') === 1, 'a zero-visitor step renders the fill with data-zero (muted, not brand)', $failures);
fwc_assert(substr_count($zeroHtml, 'class="slimstat-funnel-bar-fill" style=') === 1, 'the non-zero step fill has no data-zero (brand color)', $failures);
wp_slimstat_db::$result = [ // restore for any later assertions
    ['name' => 'Home',    'visitors' => 200, 'pct' => 100],
    ['name' => 'Pricing', 'visitors' => 34,  'pct' => 17.0],
];

// --- compute budget: at most N chains built, and nothing dropped (D40) -------
//
// The renderer runs on whatever request draws the widget, including an anonymous
// frontend pageview via [slimstat f=widget w=slim_p9_02]. Each funnel is a temp-table
// chain, so the count computed per render is bounded — but a funnel past the bound
// must still be LISTED, or the widget silently shows 2 of 5 funnels.
$five = [];
for ($i = 0; $i < 5; $i++) {
    $five[] = ['id' => $i + 1, 'name' => 'Funnel ' . ($i + 1), 'steps' => [['name' => 'Home'], ['name' => 'Pricing']]];
}

$GLOBALS['fwc_widget_budget'] = 2;
$capped = $render(10, $five);

fwc_assert(wp_slimstat_db::$calls === 2, 'a budget of 2 builds exactly 2 funnel chains, not 5 (got ' . wp_slimstat_db::$calls . ')', $failures);
fwc_assert(substr_count($capped, 'slimstat-funnel-deferred') === 3, 'the other 3 funnels render as deferred panels', $failures);
for ($i = 1; $i <= 5; $i++) {
    fwc_assert(strpos($capped, 'Funnel ' . $i) !== false, "funnel {$i} is still listed by name past the budget", $failures);
}
// A deferred panel must keep the ARIA wiring its tab points at, or the tab strip's
// aria-controls references an element that does not exist.
fwc_assert(substr_count($capped, 'role="tabpanel"') === 5, 'every panel, deferred or not, carries role="tabpanel"', $failures);
// Every aria-controls the tab strip emits must resolve to a panel id that exists.
preg_match_all('/aria-controls="([^"]+)"/', $capped, $controls);
$dangling = array_values(array_filter(
    $controls[1],
    static fn(string $id): bool => strpos($capped, 'id="' . $id . '"') === false
));
fwc_assert($dangling === [], 'no tab points at a panel id that does not exist (got: ' . implode(', ', $dangling) . ')', $failures);
// And the budget must not fire when it is not exceeded.
$GLOBALS['fwc_widget_budget'] = 10;
$uncapped = $render(10, $five);
fwc_assert(wp_slimstat_db::$calls === 5, 'a budget above the funnel count computes all of them (got ' . wp_slimstat_db::$calls . ')', $failures);
fwc_assert(strpos($uncapped, 'slimstat-funnel-deferred') === false, 'nothing is deferred when the budget is not exceeded', $failures);
$GLOBALS['fwc_widget_budget'] = null;

// --- styling de-cramp + tab strip (goals-funnels.css uses tokens, no tight values) ---
$css = (string) file_get_contents($plugin_root . '/admin/assets/css/goals-funnels.css');
// Isolate the legacy compact block (from .slimstat-funnel-bars onward) so the
// "no tight values" scan can't be fooled by unrelated rules elsewhere.
$barsPos   = strpos($css, '.slimstat-funnel-bars');
$barsBlock = $barsPos !== false ? substr($css, $barsPos) : '';
fwc_assert($barsBlock !== '' && strpos($barsBlock, 'gap: var(--ss-space-3)') !== false, 'funnel bars use token gap (--ss-space-3), not 4px', $failures);
fwc_assert($barsBlock !== '' && strpos($barsBlock, 'height: 32px') !== false, 'funnel bar track is 32px (matches main page), not 28px', $failures);
fwc_assert($barsBlock !== '' && strpos($barsBlock, 'min-width: 6px') !== false, 'funnel bar fill min-width is 6px, not 2px', $failures);
fwc_assert($barsBlock !== '' && strpos($barsBlock, 'transition: width var(--ss-duration-slow)') !== false, 'funnel bar transition uses the slow duration token, not 0.3s', $failures);
fwc_assert($barsBlock !== '' && preg_match('/\.slimstat-funnel-bar-fill:not\(\[data-zero\]\)\s*\{[^}]*--ss-brand-500/', $barsBlock) === 1, 'only non-zero fills get the brand color (zero-state parity with main page)', $failures);
foreach (['gap: 4px', 'height: 28px', 'min-width: 2px', 'transition: width 0.3s'] as $tight) {
    fwc_assert(strpos($barsBlock, $tight) === false, "cramped value removed: {$tight}", $failures);
}
fwc_assert(strpos($css, '.slimstat-funnel-wtab') !== false && strpos($css, '.slimstat-funnel-wtab:focus-visible') !== false, 'the compact tab strip is styled with a focus-visible ring', $failures);
// Many/long funnels: the strip scrolls horizontally on one line, never wraps.
$wtabsPos   = strpos($css, '.slimstat-funnel-wtabs');
$wtabsBlock = $wtabsPos !== false ? substr($css, $wtabsPos, 700) : '';
fwc_assert(strpos($wtabsBlock, 'flex-wrap: nowrap') !== false, 'the tab strip never wraps (flex-wrap: nowrap)', $failures);
fwc_assert(strpos($wtabsBlock, 'overflow-x: auto') !== false, 'the tab strip scrolls horizontally when funnels overflow (overflow-x: auto)', $failures);
fwc_assert((bool) preg_match('/\.slimstat-funnel-widget\s*\{[^}]*padding:\s*15px/', $css), 'the funnel widget has internal padding (not flush against the postbox edges)', $failures);
fwc_assert((bool) preg_match('/\.slimstat-goals-table th,\s*\.slimstat-goals-table td\s*\{[^}]*padding: var\(--ss-space-2\) var\(--ss-space-3\)/', $css), 'goals table cells use token padding, not 8px 12px', $failures);

// --- regression: the sole-first-funnel render is gone from the source ---
$reports = (string) file_get_contents($plugin_root . '/admin/view/wp-slimstat-reports.php');
fwc_assert(preg_match('/\$funnel\s*=\s*\$funnels\[0\]\s*;/', $reports) === 0, 'show_funnels_compact no longer hard-renders only $funnels[0]', $failures);
fwc_assert((bool) preg_match('/foreach\s*\(\s*\$funnels\s+as\s+\$idx\s*=>\s*\$funnel\)/', $reports), 'show_funnels_compact iterates all funnels (panel loop)', $failures);

// --- the compact-widget tab handler exists in the admin JS ---
$js = (string) file_get_contents($plugin_root . '/admin/assets/js/goals-funnels.js');
fwc_assert(strpos($js, 'slimstat-funnel-wtab') !== false, 'goals-funnels.js wires the .slimstat-funnel-wtab tab handler', $failures);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);

<?php

/**
 * View::load() hands a template the WHOLE array it was called with as $args.
 *
 * load($view, $args) extracts the allowed keys with EXTR_SKIP, and its own
 * parameter is already named $args, so the extract never replaces it: inside a
 * template, $args === the array passed to load(). There is no inner 'args' key
 * to reach for. AbstractReport::render_content() wrapped the callback args as
 * ['args' => ...] and views/reports/live-analytics.php read $args['data'] — the
 * two agreed only while a sibling 'data' key was also passed; 6fa072c8 removed
 * that key for performance and Real-time painted 0 / 0 / 0 and an empty chart
 * on every first load until the AJAX refresh. (Found while shooting the 6.0.0
 * wp.org screenshots, 2026-10-25.)
 *
 * Both halves are checked: the runtime contract through a probe template, and
 * the call shape in AbstractReport, so neither side can drift back alone.
 *
 * Run: php tests/view-args-contract-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

if (!defined('ABSPATH')) {
    define('ABSPATH', $plugin_root . '/'); // View.php exits silently (status 0) without it
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        return $text;
    }
}
require_once $plugin_root . '/src/Components/View.php';

// ── Runtime: a probe template reports what $args resolves to ────────────────
$tmp = sys_get_temp_dir() . '/slimstat-view-args-' . getmypid();
mkdir($tmp . '/views', 0777, true);
file_put_contents(
    $tmp . '/views/probe.php',
    '<?php echo isset($args[\'data\']) ? \'data=\' . $args[\'data\'] : \'missing\';'
);

$flat    = \SlimStat\Components\View::load('probe', ['data' => 'live'], true, $tmp);
$wrapped = \SlimStat\Components\View::load('probe', ['args' => ['data' => 'live']], true, $tmp);

unlink($tmp . '/views/probe.php');
rmdir($tmp . '/views');
rmdir($tmp);

if ($flat !== 'data=live') {
    $failures[] = "View::load(view, [data => ...]) must expose \$args['data'] to the template; got " . var_export($flat, true);
}
if ($wrapped !== 'missing') {
    // The assertion below only means something while the wrapped shape is
    // actually broken; if View::load() ever starts unwrapping 'args', the
    // AbstractReport scan is what still has to hold.
    echo "NOTE: View::load() now resolves an inner 'args' key (" . var_export($wrapped, true) . "); the wrapper shape is no longer broken.\n";
}

// ── Call shape: render_content() passes the callback args unwrapped ─────────
$abstract = file_get_contents($plugin_root . '/src/Reports/Abstracts/AbstractReport.php');
$render   = slimstat_function_body($abstract, 'render_content');
if ($render === '') {
    $failures[] = 'AbstractReport::render_content() not found — re-derive the call shape this test guards';
} else {
    $render = slimstat_blank_comments($render); // the fix's own comment mentions the broken shape
    if (!preg_match('/View::load\(\s*[\'"]reports\/[\'"]\s*\.\s*\$renderer\s*,\s*\$this->get_callback_args\(\)\s*\)/', $render)) {
        $failures[] = "AbstractReport::render_content() must call View::load( 'reports/' . \$renderer, \$this->get_callback_args() ) — the callback args are the template's \$args";
    }
    if (preg_match('/[\'"]args[\'"]\s*=>/', $render)) {
        $failures[] = "AbstractReport::render_content() wraps the callback args in an 'args' key again; templates read \$args['data'] and will get nothing";
    }
}

// ── The renderer this bit: it must still read the flat shape ────────────────
$view_src = file_get_contents($plugin_root . '/views/reports/live-analytics.php');
$view     = slimstat_blank_comments($view_src);
if (!preg_match('/\$data\s*=\s*\$args\[[\'"]data[\'"]\]/', $view)) {
    $failures[] = "views/reports/live-analytics.php no longer reads \$args['data']; update this contract test with the new shape";
}

if ($failures !== []) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: {$f}\n");
    }
    exit(1);
}
echo "OK: View::load() \$args contract holds (probe + AbstractReport::render_content shape)\n";

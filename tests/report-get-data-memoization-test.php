<?php
/**
 * A report's get_data() must not be recomputed within one request.
 *
 * `to_array()` calls `get_callback_args()`, and `render_content()` calls
 * `get_callback_args()` AND `get_data()` again (AbstractReport::321-333, :340-356).
 * Where a report's `get_callback_args()` itself returns `get_data()` — which is
 * exactly what LiveAnalyticsReport does — one render executes the whole data
 * gather THREE times.
 *
 * For Live Analytics that is not cheap. `get_data()` runs `get_all_live_counts()`,
 * which is uncached and issues two queries: a session count over a 30-minute
 * window, and a `COUNT(DISTINCT resource), COUNT(DISTINCT country)` over the same
 * window. Six uncached queries per render, on a report that is also constructed
 * on any `page=slim*` screen and on every `slimstat_load_report` AJAX call —
 * including screens that never display it.
 *
 * Asserted statically rather than by rendering, because instantiating the report
 * needs a full admin request context. The invariant is narrow and durable: if a
 * report's get_callback_args() returns get_data(), then get_data() must memoise.
 *
 * @see tests/bench/lib/report-matrix.php (measured cost per report)
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

// ── The structural fact that makes memoisation necessary ────────────────────
$abstract = file_get_contents($plugin_root . '/src/Reports/Abstracts/AbstractReport.php');
if ($abstract === false) {
    fwrite(STDERR, "FAIL: cannot read src/Reports/Abstracts/AbstractReport.php\n");
    exit(1);
}

$to_array = slimstat_function_body($abstract, 'to_array');
$render   = slimstat_function_body($abstract, 'render_content');

$calls_in_render = preg_match_all('/\$this->get_(callback_args|data)\s*\(/', $render);
if ($to_array === '' || $render === '') {
    $failures[] = 'AbstractReport::to_array()/render_content() not found — the call shape this '
        . 'test reasons about has changed; re-derive it before trusting this file';
} elseif ($calls_in_render < 2) {
    // render_content() no longer passes 'data' separately — that call was dead,
    // because every renderer reads $args['data']. Amplification is down from
    // three executions to two (to_array + render_content, each via
    // get_callback_args), so the memo is still required, just less costly
    // without it.
    echo "NOTE: AbstractReport::render_content() now makes a single get_data() call path; "
        . "amplification is 2x rather than 3x, and memoisation is still required.\n";
}

// ── Every report whose get_callback_args() returns get_data() must memoise ───
// PHP's glob() has no recursive `**`; iterate instead, or a report one level
// deeper than expected is silently never scanned.
$report_files = [];
$types_dir    = $plugin_root . '/src/Reports/Types';
if (is_dir($types_dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($types_dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
            $report_files[] = $entry->getPathname();
        }
    }
}
$checked      = 0;

foreach (array_unique($report_files) as $file) {
    $source = (string) file_get_contents($file);
    $name   = basename($file);

    $callback_args = slimstat_function_body($source, 'get_callback_args');
    if ($callback_args === '' || !preg_match('/\$this->get_data\s*\(/', $callback_args)) {
        continue; // get_data() is not amplified for this report
    }

    $get_data = slimstat_function_body($source, 'get_data');
    if ($get_data === '') {
        $failures[] = "{$name}: get_callback_args() calls get_data(), but get_data() is not "
            . 'defined in this file — cannot verify memoisation';
        continue;
    }
    $checked++;

    // A memo is one named store that is BOTH returned early and assigned.
    //
    // An earlier version accepted "get_data() mentions a property in a
    // conditional", which passes on `if ($this->is_tracking_enabled())` — the
    // single most likely opening line of a report's get_data(), and one this very
    // class uses elsewhere. It also passed a memo that was read but never
    // written. Both were verified false positives, so the property NAME is
    // captured and the same name must appear on both sides.
    $memoises = false;
    if (preg_match_all('/\$this->(\w+)\s*(?:\)|!==\s*null|\?\?|===\s*null)/', $get_data, $m)) {
        foreach (array_unique($m[1]) as $prop) {
            $q          = preg_quote($prop, '/');
            $returned   = preg_match('/return\s+\$this->' . $q . '\s*;/', $get_data);
            $assigned   = preg_match('/\$this->' . $q . '\s*=[^=]/', $get_data);
            if ($returned && $assigned) {
                $memoises = true;
                break;
            }
        }
    }

    if (!$memoises) {
        $failures[] = "{$name}: get_callback_args() returns get_data(), so one render executes it "
            . 'three times (to_array + render_content x2). get_data() must memoise its result for '
            . 'the request.';
    }
}

if ($checked === 0 && $failures === []) {
    echo "NOTE: no report currently returns get_data() from get_callback_args().\n";
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: report get_data() memoisation (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: report get_data() memoisation (%d report(s) that amplify get_data() all memoise)\n", $checked);
exit(0);

<?php
/**
 * A screen that renders the shared header must enqueue the stylesheet that styles it.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * `src/view/migration-page.php` calls `wp_slimstat_admin::get_template('header', …)`, which
 * emits `class="slimstat-header slimstat-header--modern"`. Every rule that styles that markup
 * lives in `admin/assets/css/header-modern.css`, and the migration screen enqueued
 * `admin.css` + `migration.css` and nothing else.
 *
 * The markup therefore fell through to admin.css's older `.slimstat-header` rule, which sets a
 * dark background (#2B2B2B) and no foreground colour — so the brand, "Online Visitors" and
 * "Premium" inherited WordPress's admin grey (#3C434A). Measured in a browser on 2026-09-04:
 * a contrast ratio of **1.41:1**, where WCAG AA asks 4.5:1 for body text and 3:1 for large
 * text. It is not a preference; the text is not readable, and the screenshot that started this
 * shows exactly that.
 *
 * The failure mode is worth naming: nothing was broken, missing or misspelled. Two correct
 * files simply were not introduced to each other, and the fallback was silent — a legacy rule
 * for the same class name, still present, still valid CSS.
 *
 * ── WHAT THIS ASSERTS ───────────────────────────────────────────────────────────────────────
 *
 * Every file that renders the header template is enumerated from source and must be CLASSIFIED:
 * either it owns its enqueue path — in which case the handle it registers header-modern.css
 * under must actually be enqueued — or it is declared as going through wp_slimstat_stylesheet(),
 * which enqueues both files together. A renderer in neither bucket fails.
 *
 * Two literals here are NOT derived, and saying otherwise would be the defect this repo keeps
 * finding: `$header_css` and the `slimstat-header--modern` marker are hard-coded. What the gate
 * does is confirm they still appear where they are expected to — a staleness detector, not a
 * derivation. The marker check exists so a rename fails loudly here rather than silently
 * emptying the classification.
 *
 * ── WHAT IT DOES NOT ESTABLISH ──────────────────────────────────────────────────────────────
 *
 * It cannot compute contrast, and it does not try: that needs a browser. It asserts the pairing
 * whose absence produced the bad contrast, which is the part a static gate can hold.
 *
 * Run: php tests/admin-header-assets-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

// EVERY header renderer is CLASSIFIED, not listed. A hand-written map says "the screens we
// remembered enqueue the stylesheet"; it cannot say "this is every screen that renders the
// header". The one thing that makes such a map stale is exactly what caused the bug — a screen
// registered outside admin/index.php's add_menus() loop, which is where the migration page came
// from. So the renderers are enumerated from source, and each must fall into one of these two
// buckets or fail as unclassified.
//
// Verified when this was written: add_menus() hooks wp_slimstat_stylesheet on load-{$hook} for
// every $screens_info entry, and that function enqueues admin.css AND header-modern.css
// together. MigrationAdmin::registerPage() is the only add_submenu_page in the plugin outside
// that loop.
$owns_its_enqueue = [
    'src/view/migration-page.php' => 'src/Migration/Admin/MigrationAdmin.php',
];

$covered_by_shared_enqueuer = [
    // Sidebar screens: add_menus() hooks wp_slimstat_stylesheet() on each one's load-{$hook}.
    'admin/view/index.php'         => 'slimview1-6, via wp_slimstat_stylesheet()',
    'admin/view/layout.php'        => 'slimlayout, via wp_slimstat_stylesheet()',
    'admin/config/index.php'       => 'slimconfig, via wp_slimstat_stylesheet()',
    'admin/view/upgrade-pro.php'   => 'slimpro, via wp_slimstat_stylesheet()',
    'admin/view/email-report.php'  => 'slimemail, via wp_slimstat_stylesheet()',
    // add_header() returns the header for slimlayout/slimconfig and has ZERO callers in either
    // repo. Left in place rather than deleted — it is a public static on a public class, so
    // removing it is an API decision, not a cleanup — but recorded so it is not mistaken for a
    // live renderer the map forgot.
    'admin/index.php'              => 'add_header(), dead: no callers in free or pro',
];

$renderers = [];
foreach (slimstat_own_php_files([$plugin_root . '/admin', $plugin_root . '/src'], $plugin_root . '/vendor') as $file) {
    if (false !== strpos((string) file_get_contents($file), "get_template('header'")) {
        $renderers[] = ltrim(str_replace($plugin_root, '', $file), '/');
    }
}

// VACUITY FLOOR. If the scan finds nothing, every classification below passes by iterating an
// empty set — and the plugin certainly renders a header somewhere.
if (count($renderers) < 2) {
    $failures[] = sprintf('found only %d file(s) rendering the header template; the scan is '
        . 'broken, and the classification below would pass by looking at nothing', count($renderers));
}

foreach ($renderers as $rel) {
    if (!isset($owns_its_enqueue[$rel]) && !isset($covered_by_shared_enqueuer[$rel])) {
        $failures[] = sprintf('%s renders the shared header and is in neither bucket. Either it '
            . 'goes through wp_slimstat_stylesheet() — say so in $covered_by_shared_enqueuer — or '
            . 'it owns its enqueue path and must be checked here. An unclassified renderer is how '
            . 'the migration screen shipped with no header stylesheet at all', $rel);
    }
}

// A stale entry is its own defect: it tells the next reader a file renders the header when it
// no longer does, which is the map drifting in the other direction.
foreach (array_keys($covered_by_shared_enqueuer) as $rel) {
    if (!in_array($rel, $renderers, true)) {
        $failures[] = sprintf('%s is listed as a header renderer covered by the shared enqueuer, '
            . 'but no longer renders the header — remove the entry', $rel);
    }
}

$screens = $owns_its_enqueue;

$header_css = 'admin/assets/css/header-modern.css';

if (!is_file($plugin_root . '/' . $header_css)) {
    fwrite(STDERR, "FAIL: {$header_css} is missing — this gate would pass by asserting about a "
        . "file that does not exist\n");
    exit(1);
}

// The class the header template emits, found by scanning rather than by a glob. The first
// version used glob('admin/view/**/header*.php'), and PHP's glob() treats `**` as a single-
// segment `*` — it matched admin/view/partials/header.php only because that is exactly one
// directory down. Move the file and the glob returns nothing, which would have made this check
// fail for the right reason by accident rather than by design.
$tpl_source = '';
foreach (slimstat_own_php_files([$plugin_root . '/admin/view'], $plugin_root . '/vendor') as $candidate) {
    if (false !== strpos(basename($candidate), 'header')) {
        $tpl_source .= (string) file_get_contents($candidate);
    }
}

if (false === strpos($tpl_source, 'slimstat-header--modern')) {
    // Not a failure of the product: the marker moved. Say so rather than passing quietly.
    $failures[] = 'no header template under admin/view/ emits `slimstat-header--modern`. Either '
        . 'the class was renamed — in which case update this gate and header-modern.css together '
        . '— or the header markup moved somewhere this gate cannot see it';
}

// VACUITY FLOOR. An empty screen map makes every check below pass by iterating nothing.
if (!$screens) {
    $failures[] = 'the screen map is empty, so this gate checks no page at all';
}

foreach ($screens as $view => $enqueuer) {
    $view_path     = $plugin_root . '/' . $view;
    $enqueuer_path = $plugin_root . '/' . $enqueuer;

    if (!is_file($view_path) || !is_file($enqueuer_path)) {
        $failures[] = sprintf('%s or %s is missing; the pairing this gate exists to hold cannot '
            . 'be checked, and a missing file must not read as a pass', $view, $enqueuer);
        continue;
    }

    $view_src = (string) file_get_contents($view_path);

    if (false === strpos($view_src, "get_template('header'")) {
        // The view stopped rendering the header. That is allowed — but then it should leave this
        // map, and saying so is cheaper than a reader wondering why the entry is here.
        $failures[] = sprintf('%s no longer renders the header template, so its entry in this '
            . 'gate is stale — remove it', $view);
        continue;
    }

    // COMMENTS BLANKED, strings kept. The first version of this gate scanned the raw file, and
    // its own mutation came back GREEN: the explanatory comment above the enqueue names
    // header-modern.css, so the comment satisfied the check. That is PITFALLS 112 — a guard whose
    // pass condition is the presence of a comment — reproduced inside the guard written to catch
    // a silent asset fallback. Found by the mutation, not by reading the gate.
    $enqueuer_src = slimstat_blank_comments((string) file_get_contents($enqueuer_path));

    if (false === strpos($enqueuer_src, 'header-modern.css')) {
        $failures[] = sprintf('%s renders the shared header, but %s never enqueues %s. The markup '
            . 'then falls back to admin.css\'s legacy `.slimstat-header` rule — a dark background '
            . 'with no foreground colour, measured at 1.41:1 contrast against WCAG AA\'s 4.5:1. '
            . 'Nothing is broken or misspelled in that state; two correct files are simply never '
            . 'introduced, and the fallback is silent', $view, $enqueuer, $header_css);
        continue;
    }

    // THE HANDLE, not any handle. The first version asked only whether the file called
    // wp_enqueue_style() at all — and it enqueues its base stylesheet unconditionally two lines
    // earlier, so that check was true no matter what. Deleting the header enqueue left the gate
    // green, proven by deleting it. A register without an enqueue is a file the browser never
    // requests, which is the same invisible header with a different cause.
    if (!preg_match('/wp_register_style\(\s*[\'"]([a-z0-9-]+)[\'"][^;]*header-modern\.css/i', $enqueuer_src, $handle)) {
        $failures[] = sprintf('%s names %s but not in a wp_register_style() call this gate can '
            . 'read, so the handle it is registered under cannot be checked against the enqueue',
            $enqueuer, $header_css);
    } elseif (!preg_match('/wp_enqueue_style\(\s*[\'"]' . preg_quote($handle[1], '/') . '[\'"]/i', $enqueuer_src)) {
        $failures[] = sprintf('%s registers %s as `%s` and never enqueues that handle. A '
            . 'registered-but-not-enqueued stylesheet is a file the browser never requests, so '
            . 'the header renders exactly as it did before this was fixed',
            $enqueuer, $header_css, $handle[1]);
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: admin header assets (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: %d header renderer(s) classified — %d own their enqueue and load %s, %d go "
    . "through wp_slimstat_stylesheet()\n",
    count($renderers), count($owns_its_enqueue), $header_css, count($covered_by_shared_enqueuer));

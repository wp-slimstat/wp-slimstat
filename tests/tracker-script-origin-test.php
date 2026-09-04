<?php
/**
 * Source-level: the tracker script is served from the site, never from a third party.
 *
 * THE CDN OPTION WAS A SILENT TOTAL-TRACKING-LOSS SWITCH.
 *
 * `enable_cdn` registered the tracker from
 * `https://cdn.jsdelivr.net/wp/wp-slimstat/tags/{SLIMSTAT_ANALYTICS_VERSION}/wp-slimstat.min.js`.
 * That path mirrors wp.org's SVN tags, so it resolves only for a version wp.org has ALREADY
 * published. On any unreleased build — every beta, and the whole window between tagging and
 * the wp.org sync — it is a 404.
 *
 * With no fallback. No `onerror`, no `wp_script_is` re-register, no recorded degradation. The
 * tracker just never loads: the site records nothing at all, on every page, until somebody
 * notices the reports are empty and works out why. It also passed `null` as the version
 * argument, so the one thing a CDN is for was unmanaged as well.
 *
 * The External Pages snippet carried the same URL and was WORSE: users paste that onto sites
 * this plugin never sees again, and it 404s regardless of the toggle, so nothing on the
 * WordPress side could ever have corrected it.
 *
 * The option key survives in init_options() — removing it would break the
 * `array_merge(init_options(), $settings)` invariant that default-change-safety pins, and
 * would need a settings migration for nothing. It is simply never read.
 *
 * Comments are blanked and strings are KEPT: the subject of this gate is a string literal,
 * so stripping strings would erase exactly what it exists to find.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$sources = array_merge(
    [$plugin_root . '/wp-slimstat.php'],
    slimstat_own_php_files([$plugin_root . '/src', $plugin_root . '/admin'], $plugin_root . '/src/Dependencies')
);

$scanned = 0;
$hits    = 0;

foreach ($sources as $file) {
    $lit = slimstat_blank_comments((string) file_get_contents($file));
    $scanned++;

    // The version-interpolated tag path is the specific hazard: it can only resolve for an
    // already-published release, so it is guaranteed broken on precisely the builds testers
    // and developers run.
    if (preg_match('#cdn\.jsdelivr\.net/wp/wp-slimstat/tags/#', $lit)) {
        $failures[] = slimstat_rel_path($plugin_root, $file) . ' serves the tracker from '
            . "jsDelivr's wp.org tag mirror. That URL only resolves for a version already "
            . 'published on wp.org, so on any unreleased build it 404s — and there is no '
            . 'fallback, so the site silently records nothing at all';
        $hits++;
    }
}

// VACUITY FLOOR. A scan that reads no files passes, and would keep passing after a rename.
if ($scanned < 20) {
    $failures[] = sprintf(
        'only %d shipped PHP file(s) were scanned — the file list has stopped resolving, so a '
            . 'clean result here means nothing',
        $scanned
    );
}

// The tracker must still be registered from somewhere, and that somewhere must be the site.
// Without this, deleting the enqueue entirely would satisfy the assertion above.
$mainLit = slimstat_blank_comments((string) file_get_contents($plugin_root . '/wp-slimstat.php'));
$enqueue = slimstat_find_function_body($mainLit, 'enqueue_tracker');

if (null === $enqueue) {
    $failures[] = 'enqueue_tracker() not found in wp-slimstat.php — re-anchor this gate rather '
        . 'than deleting it; "no external URL" is trivially true of a file that registers no '
        . 'script at all';
} else {
    if (false === strpos($enqueue, 'plugins_url')) {
        $failures[] = 'enqueue_tracker() no longer registers the tracker with plugins_url(), so '
            . 'it is not being served from the site';
    }

    // Anything absolute is a third party. There is deliberately NO exemption: an earlier
    // draft carried a negative lookahead meant to allow home_url()-built URLs, which exempted
    // nothing real — home_url() never emits an 'http:// literal — while it DID exempt
    // `'https://' . $cdn . '/t.js'`, i.e. this very defect written as a concatenation.
    if (preg_match('#[\'"]https?://#', $enqueue, $m)) {
        $failures[] = 'enqueue_tracker() contains an absolute URL literal (' . $m[0] . '), which '
            . 'means the tracker can be served from somewhere the site does not control';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: tracker script origin (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: the tracker is served from the site in every path; ' . $scanned
    . " shipped file(s) carry no third-party tracker URL\n";

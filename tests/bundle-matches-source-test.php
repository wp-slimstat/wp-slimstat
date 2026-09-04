<?php
/**
 * The shipped tracker bundle must be built from the tracker source.
 *
 * `wp-slimstat.js` is not loaded by anything at runtime — every visitor runs
 * `wp-slimstat.min.js`, built from it by `npm run build`. So the source is only a
 * proposal; the bundle is the product. Nothing enforced that they agreed, and they
 * stopped agreeing: the bundle was last built from the source as it stood on
 * 2026-03-31, and the tel:/mailto:/submit click detection added on 2026-04-12 has
 * therefore never run on a single visitor's browser.
 *
 * Two checks, because they catch different things and only one of them can run
 * everywhere:
 *
 * 1. MAP vs SOURCE — always runs, needs nothing but a JSON parser. `--sourcemap`
 *    makes esbuild embed the full text of every input it compiled under
 *    `sourcesContent`, so the committed map states what the committed bundle was
 *    made from. This is what catches the failure that actually happened: someone
 *    edited the source and did not rebuild. It runs in the PHP-only CI lanes,
 *    which is the whole point.
 *
 *    What it CANNOT catch, stated plainly so nobody trusts it further than it
 *    goes: it never reads the bundle's own code. A hand-edited or merge-damaged
 *    `wp-slimstat.min.js` sitting beside an honest map passes check 1.
 *
 * 2. BUNDLE vs REBUILD — runs only where esbuild is installed, and is the real
 *    invariant: rebuild from source and compare bytes. It closes the hole above.
 *    It is skipped rather than failed when node_modules is absent, and the PASS
 *    line says which checks ran, so a skip is never mistaken for a verification.
 *    esbuild is pinned to an exact version in package.json for this reason — a
 *    floating minifier would make byte comparison meaningless.
 *
 * A stale map fails this test, which is the intended direction — the fix is always
 * the same one command.
 *
 * Defect id (D46) lives in the workspace performance notes, outside this
 * repository — deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$failures    = [];

$source_file = $plugin_root . '/wp-slimstat.js';
$bundle_file = $plugin_root . '/wp-slimstat.min.js';
$map_file    = $plugin_root . '/wp-slimstat.min.js.map';

foreach (['wp-slimstat.js' => $source_file, 'wp-slimstat.min.js' => $bundle_file, 'wp-slimstat.min.js.map' => $map_file] as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: {$label} is missing — the tracker cannot be verified\n");
        exit(1);
    }
}

$source = file_get_contents($source_file);
$bundle = file_get_contents($bundle_file);
$map    = json_decode((string) file_get_contents($map_file), true);

if (!is_array($map) || !isset($map['sources'], $map['sourcesContent'])) {
    $failures[] = 'wp-slimstat.min.js.map is not a source map with sourcesContent — rebuild with '
        . '`npm run build`, which passes --sourcemap';
} else {
    // esbuild records inputs by path relative to the working directory it was run
    // from. Match on basename so running the build from a different cwd does not
    // silently skip the comparison and report a pass.
    $index = null;
    foreach ($map['sources'] as $i => $name) {
        if (basename((string) $name) === 'wp-slimstat.js') {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        $failures[] = 'the source map does not list wp-slimstat.js as an input — the shipped bundle '
            . 'was not built from the tracker source at all (inputs: '
            . implode(', ', array_map('strval', $map['sources'])) . ')';
    } elseif (!isset($map['sourcesContent'][$index]) || !is_string($map['sourcesContent'][$index])) {
        $failures[] = 'the source map carries no sourcesContent for wp-slimstat.js — it cannot '
            . 'attest to what was built';
    } elseif ($map['sourcesContent'][$index] !== $source) {
        // Name what drifted. "Rebuild the bundle" is not actionable on its own; the
        // reviewer needs to know which behaviour is missing from what visitors run.
        //
        // The byte delta is reported alongside the line preview because array_diff is
        // a set difference on line content: a source that differs only in the ORDER of
        // its lines, or in how many times an identical line appears, produces an empty
        // preview. The byte count is never empty when the contents differ.
        $built = explode("\n", $map['sourcesContent'][$index]);
        $added = array_values(array_diff(explode("\n", $source), $built));

        $detail = sprintf(
            'wp-slimstat.min.js was built from a different wp-slimstat.js (%d source line(s) absent '
                . 'from the bundle; %+d bytes). Everything below runs for no visitor. Rebuild with '
                . '`npm run build`.',
            count($added),
            strlen($source) - strlen($map['sourcesContent'][$index])
        );
        foreach (array_slice($added, 0, 8) as $line) {
            $detail .= "\n      + " . trim($line);
        }
        $failures[] = $detail;
    }
}

// The bundle must actually point at the map this test just trusted. Without this,
// a build that emitted no map would leave the previous map in place, and the
// comparison above would be attesting to a file the bundle has nothing to do with.
if (strpos($bundle, 'sourceMappingURL=wp-slimstat.min.js.map') === false) {
    $failures[] = 'wp-slimstat.min.js does not reference wp-slimstat.min.js.map — the map this test '
        . 'compares against may describe a different build. Build with `npm run build` (--sourcemap)';
}

// ── 2. Bundle vs rebuild — the real invariant, where the toolchain exists ───
//
// Check 1 above never reads the bundle's own code, so on its own it would pass a
// hand-edited or merge-damaged wp-slimstat.min.js sitting beside an honest map.
// Rebuilding and comparing bytes is the only thing that actually verifies what
// ships. It needs esbuild, so it is conditional — and the PASS line below reports
// whether it ran, because a check that silently skips is worse than no check.
$esbuild  = $plugin_root . '/node_modules/.bin/esbuild';
$rebuilt  = false;

if (is_file($esbuild) && is_executable($esbuild)) {
    $out = tempnam(sys_get_temp_dir(), 'slimstat-bundle-') . '.js';
    // Same flags as `npm run build`, minus --sourcemap: the map is check 1's
    // business, and omitting it keeps the sourceMappingURL comment out of the
    // comparison instead of having to strip it.
    $cmd = escapeshellarg($esbuild) . ' ' . escapeshellarg($plugin_root . '/wp-slimstat.js')
        . ' --bundle --minify --outfile=' . escapeshellarg($out) . ' 2>&1';

    $output = [];
    $status = 0;
    exec($cmd, $output, $status);

    if ($status !== 0) {
        $failures[] = 'esbuild could not rebuild the tracker: ' . implode(' ', array_slice($output, 0, 3));
    } else {
        $rebuilt  = true;
        $expected = (string) file_get_contents($out);
        // The committed bundle carries a sourceMappingURL comment the rebuild does
        // not; everything before it must match byte for byte.
        $actual = preg_replace('{\s*//# sourceMappingURL=\S*\s*$}', '', $bundle);

        if (trim((string) $actual) !== trim($expected)) {
            $failures[] = sprintf(
                'wp-slimstat.min.js is not what building wp-slimstat.js produces (%d bytes shipped '
                    . 'vs %d rebuilt). The committed bundle has been edited or damaged — it is not '
                    . 'the output of `npm run build`.',
                strlen((string) $actual),
                strlen($expected)
            );
        }
    }

    @unlink($out);
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: tracker bundle does not match its source (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: tracker bundle matches its source (map attests to the working-tree wp-slimstat.js; '
    . ($rebuilt
        ? 'bundle bytes reproduced by rebuilding)'
        : 'rebuild check SKIPPED — no node_modules/.bin/esbuild, so the bundle\'s own bytes were '
            . 'not verified)')
    . "\n";
exit(0);

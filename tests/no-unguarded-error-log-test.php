<?php
/**
 * Source-level: free's own error_log() calls must be behind WP_DEBUG.
 *
 * Pro has carried this gate since its `ServiceProvider` was found writing to the production
 * log on every request of a broken install; free did not, and its five call sites were guarded
 * by convention alone. A sixth, unguarded one would have landed with nothing in CI able to say
 * so. On a shared host that keeps `error_log` pointed at a real file, an unguarded per-request
 * write is a disk-filling bug wearing the costume of a diagnostic.
 *
 * HOW THE GUARD IS DETERMINED. `slimstat_guarded_block_ranges($tokens, 'WP_DEBUG')` returns
 * the token ranges of every `if`/`elseif` block whose condition names WP_DEBUG; a call is
 * guarded when its token index lies inside one. Containment by TOKEN INDEX, not by a line
 * window — a comment mentioning WP_DEBUG, an `if` that has already closed, and the guard on
 * a sibling branch all fail it. The first version of this file ported Pro's backward brace
 * walk instead, on the belief that the lib helper matched only CALL names; it does not, and
 * the walk had a defect the helper lacks: a guarded call nested one block deeper (inside a
 * `foreach` or `try` within the WP_DEBUG block) read as UNGUARDED — a gate red where nothing
 * is wrong. The helper needed `elseif`, which it now has, with a fixture.
 *
 * Names resolve through the lib's name-token types, so `\error_log(` counts — one of the five
 * is spelled that way (`ConditionTagEvaluator.php`).
 *
 * PINNED, NOT FLOORED. Five sites exist. A floor of "at least 2" cannot see the scan drop from
 * five sites to three; a pin can, and a genuine sixth site costs one deliberate bump.
 *
 * Run: php tests/no-unguarded-error-log-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

$plugin_root = dirname(__DIR__);
$failures    = [];

$sources = slimstat_own_php_files([
    $plugin_root . '/src',
    $plugin_root . '/admin',
    $plugin_root . '/wp-slimstat.php',
    $plugin_root . '/uninstall.php',
], $plugin_root . '/src/Dependencies');

$name_types = slimstat_name_token_types();
$sites      = [];
$scanned    = 0;

foreach ($sources as $file) {
    $source = (string) file_get_contents($file);
    if (false === strpos($source, 'error_log')) {
        continue;
    }
    $scanned++;

    $tokens = slimstat_tokenize($source, true);
    $count  = count($tokens);
    $ranges = slimstat_guarded_block_ranges($tokens, 'WP_DEBUG');

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || !isset($name_types[$tok[0]]) || 'error_log' !== slimstat_last_name_segment($tok[1])) {
            continue;
        }
        // A CALL, not a mention: the next significant token is '('.
        $next = slimstat_next_significant($tokens, $i);
        if ($next >= $count || '(' !== $tokens[$next]) {
            continue;
        }

        $where   = slimstat_rel_path($plugin_root, $file) . ':' . $tok[2];
        $sites[] = $where;

        $guarded = false;
        foreach ($ranges as [$open, $close]) {
            if ($i > $open && $i < $close) {
                $guarded = true;
                break;
            }
        }

        if (!$guarded) {
            $failures[] = sprintf('%s calls error_log() outside a WP_DEBUG guard; on a host whose '
                . 'error_log is a real file this writes on every request that reaches it', $where);
        }
    }
}

$expected_sites = 5;

if (count($sites) !== $expected_sites) {
    $failures[] = sprintf(
        'found %d error_log() call site(s) across %d file(s); this gate is pinned at %d. A new '
            . 'site is fine once confirmed WP_DEBUG-guarded — bump $expected_sites in the same '
            . 'commit. Zero means the resolver or the walk broke and the guard check passed over '
            . 'nothing. Sites: %s',
        count($sites),
        $scanned,
        $expected_sites,
        $sites ? implode(', ', $sites) : '(none)'
    );
}

if ($failures) {
    fwrite(STDERR, 'FAIL: unguarded error_log (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all ' . count($sites) . " error_log() call site(s) in free's own code sit inside a "
    . "WP_DEBUG guard\n";

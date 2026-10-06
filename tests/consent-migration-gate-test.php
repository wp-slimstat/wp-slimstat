<?php
/**
 * Source-level: the 5.3.x consent-intent migration only runs for pre-5.4.7 installs.
 *
 * PINS FIX (S2). The `--- Consent intent detection ---` block — in wp_slimstat::init() until
 * 2026-09-05, now SlimStat\Migration\LegacySettings5460::apply(), which init() delegates to —
 * sat inside the outer `_migration_5460` gate, which fires whenever the stored flag is
 * older than SLIMSTAT_ANALYTICS_VERSION — i.e. on EVERY version bump — while the
 * one-time resets beside it were correctly bounded to `< 5.4.7`.
 *
 * So a 5.5.1 -> 6.0.0 upgrade re-entered it and rewrote three consent keys from legacy
 * 5.3.x evidence that no longer described the site's choices. Verified against the
 * shipped defaults (gdpr_enabled 'off', use_slimstat_banner 'off', consent_integration
 * '', display_opt_out 'no'), it moves in BOTH directions:
 *
 *   - a site that enabled GDPR through the 5.4+ UI has no legacy opt-out/opt-in keys
 *     and no third-party CMP, so it fell to the else branch and had gdpr_enabled set
 *     to 'off' — consent silently switched OFF, a compliance regression on upgrade;
 *   - a site carrying a stale display_opt_out = 'on' that had deliberately turned GDPR
 *     off had it forced back on, and with GDPR on and no consent cookie tracking STOPS.
 *
 * Fresh installs are unaffected either way: the else branch writes exactly the shipped
 * defaults, so running or skipping it is the same outcome.
 *
 * WHY THIS IS A CONSTRUCT SCAN, NOT A VOCABULARY SCAN
 *
 * It does not look for '5.4.7' anywhere, nor for the key names anywhere. It tokenises,
 * finds the byte range of every block guarded by a version_compare against the
 * boundary, and asserts every ASSIGNMENT to a consent key falls inside one.
 *
 * It also counts what it checked and fails when that count is zero. Without that, the
 * obvious refactor — extracting the block to a method — empties the violation list
 * while the sibling reset gate keeps a gate range matched, and the test would report
 * success while asserting nothing.
 *
 * Mutations this rejects, each of which a name-matching scan would accept:
 *   - moving an assignment out of the gate while leaving '5.4.7' in the file
 *   - widening the gate to a later version
 *   - adding a fourth consent key write outside the gate
 *   - deleting or relocating every assignment (caught by the checked-count floor)
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$file        = $plugin_root . '/src/Migration/LegacySettings5460.php';

/** The keys the consent-intent migration decides. */
$guarded_keys = ['gdpr_enabled', 'use_slimstat_banner', 'consent_integration'];

/** The migration boundary. Installs at or above this must not be re-migrated. */
$boundary = '5.4.7';

$source = (string) @file_get_contents($file);
if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

$tokens = token_get_all($source);
$count  = count($tokens);

// ── Scope: the whole of LegacySettings5460 ─────────────────────────────────────
// Until 2026-09-05 this anchored on the `$_migration_ran` variable inside wp_slimstat::init(),
// because the migration was an inline block beside a legitimate consent-sync block that must
// NOT be version-gated. The migration now owns a file, so the file is the scope — and the
// scanner asserts it is looking at that class rather than at whatever the path holds, since a
// scope that silently became empty is the failure this test's checked-count floor exists for.
if (false === strpos($source, 'class LegacySettings5460')) {
    fwrite(STDERR, "FAIL: {$file} does not declare LegacySettings5460 — the migration moved again; re-scope this test\n");
    exit(1);
}
$block_start = 0;
$block_end   = $count;

// ── Every block inside it that is guarded by the boundary ───────────────────
// The guard may be written inline (version_compare(...)) or hoisted to a boolean, so
// a block counts when its condition mentions the boundary literal OR a variable that
// was assigned from a version_compare against it.
$boundary_vars = [];
for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || T_VARIABLE !== $tokens[$i][0]) {
        continue;
    }
    $j = slimstat_next_significant($tokens, $i);
    if ($j >= $count || '=' !== $tokens[$j]) {
        continue;
    }
    // Does the right-hand side, up to the statement end, compare against the boundary?
    for ($k = $j; $k < $count && ';' !== $tokens[$k]; $k++) {
        if (is_array($tokens[$k]) && T_CONSTANT_ENCAPSED_STRING === $tokens[$k][0]
            && trim($tokens[$k][1], "'\"") === $boundary) {
            $boundary_vars[$tokens[$i][1]] = true;
            break;
        }
    }
}

$gate_ranges = [];
for ($i = $block_start; $i < $block_end; $i++) {
    if (!is_array($tokens[$i]) || T_IF !== $tokens[$i][0]) {
        continue;
    }

    $cond_end = slimstat_token_paren_end($tokens, $i, $block_end);
    if (null === $cond_end) {
        continue;
    }

    $guards = false;
    for ($k = $i; $k < $cond_end; $k++) {
        if (is_array($tokens[$k]) && T_CONSTANT_ENCAPSED_STRING === $tokens[$k][0]
            && trim($tokens[$k][1], "'\"") === $boundary) {
            $guards = true;
            break;
        }
        if (is_array($tokens[$k]) && T_VARIABLE === $tokens[$k][0]
            && isset($boundary_vars[$tokens[$k][1]])) {
            $guards = true;
            break;
        }
    }
    if (!$guards) {
        continue;
    }

    $range = slimstat_token_block_range($tokens, $cond_end, $block_end);
    if (null !== $range) {
        $gate_ranges[] = $range;
    }
}

if ([] === $gate_ranges) {
    fwrite(STDERR, "FAIL: LegacySettings5460 contains no block guarded by the {$boundary} boundary.\n"
        . "  The consent-intent migration must be bounded, or every version bump re-runs it.\n");
    exit(1);
}

// ── Assert every consent-key assignment sits inside one ─────────────────────
$violations = [];
$checked    = 0;

for ($i = $block_start; $i < $block_end; $i++) {
    if (!is_array($tokens[$i]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[$i][0]) {
        continue;
    }
    $key = trim($tokens[$i][1], "'\"");
    if (!in_array($key, $guarded_keys, true)) {
        continue;
    }

    // `[...'key']` followed by `=` is an assignment; anything else is a read.
    $j = slimstat_next_significant($tokens, $i);
    if ($j >= $count || ']' !== $tokens[$j]) {
        continue;
    }
    $j = slimstat_next_significant($tokens, $j);
    if ($j >= $count || '=' !== $tokens[$j]) {
        continue;
    }

    $checked++;

    $inside = false;
    foreach ($gate_ranges as [$from, $to]) {
        if ($i > $from && $i < $to) {
            $inside = true;
            break;
        }
    }
    if (!$inside) {
        $violations[] = sprintf("line %d: writes '%s' outside any %s-gated block", $tokens[$i][2], $key, $boundary);
    }
}

if (0 === $checked) {
    fwrite(STDERR, "FAIL: found no consent-key assignment to check inside LegacySettings5460.\n"
        . "  Either the migration moved (update this test's scope) or the scanner is broken.\n"
        . "  Reporting PASS here would assert nothing — which is the failure mode this guards.\n");
    exit(1);
}

if ($violations !== []) {
    fwrite(STDERR, 'FAIL: consent migration is not bounded (' . count($violations) . ' of '
        . $checked . " assignment(s))\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    fwrite(STDERR, "\n  Rule: every consent-key write in LegacySettings5460 must sit inside a\n"
        . "  block gated on the {$boundary} boundary. Outside it, the block re-runs on every\n"
        . "  version bump and rewrites the site's consent configuration.\n");
    exit(1);
}

printf(
    "PASS: %d consent-key assignment(s) in the migration block are all bounded to pre-%s installs (%d gated block(s))\n",
    $checked,
    $boundary,
    count($gate_ranges)
);
exit(0);

<?php
/**
 * Source-level: the degradation notice names what broke, and its remedy is true of it.
 *
 * THE NOTICE TOLD EVERY DRIFTED INSTALL TO REINSTALL, AND COULD NEVER CLEAR.
 *
 * `show_degradation_notice()` rendered one `error`-severity notice for every record, under
 * copy reading "These features failed to load and were disabled … reinstalling the plugin
 * and flushing your PHP opcache normally clears it." That sentence is exactly right for the
 * #325 class it was written for — a feature whose file would not load — and false for every
 * operational record that reaches the same channel. Reinstalling does not add a missing
 * column, does not finish a purge, and does not convert a character set.
 *
 * `src/Migration/AbstractMigration.php` records rejecting this channel for precisely that
 * reason. The drift reporter used it anyway, so an install upgraded from below 4.8.2 — whose
 * `email` column is permanently one character narrow, by design — showed a permanent red
 * banner on every wp-admin page telling its owner to reinstall a plugin that was fine.
 *
 * It could not clear either. `refresh_column_drift_notice()` REPLAYED a stored list at
 * admin_init priority 98, ahead of the pruner at 99, so DEGRADATION_TTL could never retire
 * it — and nothing recomputed that list after a migration successfully added the column.
 *
 * THE FIRST FIX PUT THE CLASSIFICATION IN THE WRONG PLACE, and this gate is scoped to where
 * it ended up. Severity was briefly reconstructed renderer-side from a hand-kept list of 14
 * step names plus two `strpos` prefixes — a second registry to keep in sync with two dozen
 * call sites by discipline, which cannot cover the three keys built by concatenation at
 * runtime, and which drifted in BOTH directions inside the very commit that introduced it:
 * it gained a label for a step with no producer, and still lacked one for `purge (no
 * successful run)`, which `get_degradations()` synthesises without any call site at all.
 * So the kind is now recorded at the catch block that knows it, and this gate exists to
 * keep it there.
 *
 * Scoped to constructs where a name would do, and to literals where the assertion is about
 * string contents — comments are blanked in both, so neither can match its own explanation.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$adminRaw  = (string) file_get_contents($plugin_root . '/admin/index.php');
$adminLit  = slimstat_blank_comments($adminRaw);
$adminCode = slimstat_strip_comments_and_strings($adminRaw);
$mainLit   = slimstat_blank_comments((string) file_get_contents($plugin_root . '/wp-slimstat.php'));

// ── The population: every step key the notice can ever be asked to render ──────────────
// Derived from the tree, never hand-listed. A hand-listed population is how the map came to
// be 13 keys short of the thing it maps.
//
// Runtime-built keys are separated from fixed ones by what follows the closing quote.
// `'activation (blog ' . $blog_id . ')'` is a PREFIX, not a key — no map can hold it, so
// demanding a label for one would be demanding the impossible. Those are covered by the
// humanising fallback, whose existence this split is what makes load-bearing.
$sources = array_merge(
    [$plugin_root . '/wp-slimstat.php'],
    slimstat_own_php_files([$plugin_root . '/src', $plugin_root . '/admin'], 'src/Dependencies')
);

$steps        = [];
$runtimeBuilt = [];
foreach ($sources as $file) {
    $lit = slimstat_blank_comments((string) file_get_contents($file));
    if (preg_match_all('/record_degradation\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'(\s*\.)?/', $lit, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $step = stripslashes($hit[1]);
            if (isset($hit[2])) {
                $runtimeBuilt[$step] = true;
                continue;
            }
            $steps[$step] = true;
        }
    }
}

// SYNTHESISED RECORDS HAVE NO CALL SITE. `get_degradations()` manufactures
// 'purge (no successful run)' from the last-success stamp because the real failure ages out
// of a 3-hour TTL while the purge runs twice daily. A scan that only reads
// record_degradation() arguments is structurally blind to it — and that blindness is exactly
// why it shipped unlabelled. Read those keys too.
if (preg_match_all('/\$current\[\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\]\s*=/', $mainLit, $m)) {
    foreach ($m[1] as $step) {
        $steps[stripslashes($step)] = true;
    }
}

$steps        = array_keys($steps);
$runtimeBuilt = array_keys($runtimeBuilt);

// VACUITY FLOOR. If the regexes stop matching — a refactor to a constant, a rename, a changed
// call shape — this test would pass by finding nothing to check. 25 keys were counted on
// 2026-09-01; the floor is what makes "found none" a failure instead of a pass.
$found = count($steps) + count($runtimeBuilt);
if ($found < 25) {
    $failures[] = sprintf(
        'only %d degradation step keys were found across wp-slimstat.php, src/ and admin/ '
            . '(expected at least 25) — the scan has stopped seeing its own subject, so every '
            . 'assertion below is vacuous',
        $found
    );
}

// The fallback is the ONLY thing that covers runtime-built keys, so their existence is what
// makes the humanising assertion below load-bearing rather than decorative.
if ([] === $runtimeBuilt) {
    $failures[] = 'no runtime-built degradation step keys were found. Three existed on '
        . '2026-09-01; if they are genuinely gone, the humanising fallback no longer has a '
        . 'subject and this scan should be re-derived rather than left standing';
}

// ── Every fixed key is labelled ────────────────────────────────────────────────────────
$noticeBody = slimstat_find_function_body($adminLit, 'show_degradation_notice');

if (null === $noticeBody) {
    $failures[] = 'show_degradation_notice() not found in admin/index.php';
} else {
    $labelled = [];
    if (preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'\s*=>\s*__\(/', $noticeBody, $m)) {
        foreach ($m[1] as $key) {
            $labelled[stripslashes($key)] = true;
        }
    }

    foreach ($steps as $step) {
        if (!isset($labelled[$step])) {
            $failures[] = sprintf(
                "degradation step '%s' has no label, so the notice prints its raw slug",
                $step
            );
        }
    }

    // A label for a step nothing produces is the same registry-drift defect pointing the other
    // way, and it is how a phantom 'purge (archive schema)' entry shipped.
    //
    // Tested against every string literal in the tree, NOT against the call-site population
    // above. Three real keys reach record_degradation() indirectly — 'migration_db_unreachable'
    // through AbstractMigration::PROBE_DEGRADATION_KEY, and the two migration ids through a
    // $degradationKey argument — so requiring a direct call site would flag three keys that are
    // produced perfectly well. What is genuinely damning is a label whose text appears nowhere
    // else at all: nothing can produce a key that is not written down anywhere.
    // The label map is itself in one of the sources, so every key appears at least once by
    // definition. A producer means a SECOND occurrence somewhere in the tree.
    $occurrences = array_fill_keys(array_keys($labelled), 0);
    foreach ($sources as $file) {
        $lit = slimstat_blank_comments((string) file_get_contents($file));
        foreach ($occurrences as $key => $_) {
            $occurrences[$key] += substr_count($lit, "'" . $key . "'");
        }
    }

    foreach ($occurrences as $key => $count) {
        if ($count < 2) {
            $failures[] = sprintf(
                "the label map carries '%s', and that text appears nowhere else in the tree — "
                    . 'nothing can produce it, so it is a registry entry with no producer',
                $key
            );
        }
    }

    // ── Severity is READ, not reconstructed ────────────────────────────────────────────
    if (false === strpos($noticeBody, "['severity']")) {
        $failures[] = 'show_degradation_notice() does not read the recorded severity. Deciding '
            . 'it here means a second registry of step names kept in sync with two dozen call '
            . 'sites by discipline, and it cannot cover the runtime-built keys at all';
    }

    if (preg_match('/strpos\(\s*\$step/', $noticeBody)) {
        $failures[] = 'show_degradation_notice() prefix-matches $step to classify it. That '
            . 'couples the renderer to a string literal assembled elsewhere, with nothing '
            . 'enforcing the coupling — rename the concatenation and the record silently '
            . 'demotes and is given advice that is false for it';
    }

    // ── The remedy sentence must be inside a branch ────────────────────────────────────
    // The defect was not the wording. It was that ONE wording was unconditional.
    if (false === strpos($noticeBody, 'reinstalling the plugin')) {
        $failures[] = 'the reinstall remedy has vanished from show_degradation_notice(); it is '
            . 'correct for the #325 load-failure class and should still be said to it';
    } elseif (!preg_match('/if\s*\([^)]*load_items[^)]*\)/', $noticeBody)) {
        $failures[] = 'the reinstall remedy is not inside a load-failure branch — it is printed '
            . 'over operational records (schema drift, a failed purge, a migration) for which '
            . 'reinstalling the plugin does nothing at all';
    }

    if (!preg_match('/\'warning\'/', $noticeBody)) {
        $failures[] = 'show_degradation_notice() emits no warning-severity notice, so every '
            . 'operational record is still painted red as a load failure';
    }

    if (!preg_match('/\?\?\s*ucfirst\(|:\s*ucfirst\(/', $noticeBody)) {
        $failures[] = 'the unknown-key fallback does not humanise the step name, so the '
            . 'runtime-built keys still surface as raw slugs — and those can never be covered '
            . 'by making the label map longer';
    }
}

// ── The severity constants exist and both are used ─────────────────────────────────────
foreach (['DEGRADATION_LOAD', 'DEGRADATION_OPERATIONAL'] as $const) {
    if (!preg_match('/const\s+' . $const . '\s*=/', $mainLit)) {
        $failures[] = "wp_slimstat::{$const} is not declared";
    }
}

$operationalUses = 0;
foreach ($sources as $file) {
    $operationalUses += substr_count(
        slimstat_strip_comments_and_strings((string) file_get_contents($file)),
        'DEGRADATION_OPERATIONAL'
    );
}

// One declaration plus the call sites. If only the declaration survives, every operational
// record has quietly reverted to being announced as a load failure.
if ($operationalUses < 10) {
    $failures[] = sprintf(
        'only %d references to DEGRADATION_OPERATIONAL across the tree. The purge, migration, '
            . 'schema and tracker-write records are all operational; if they stopped saying so '
            . 'they are being told to reinstall the plugin, which fixes none of them',
        $operationalUses
    );
}

// ── The drift notice must RE-DERIVE, not replay ────────────────────────────────────────
$refresh = slimstat_find_function_body($adminCode, 'refresh_column_drift_notice');

if (null === $refresh) {
    $failures[] = 'refresh_column_drift_notice() not found';
} else {
    if (false === strpos($refresh, 'observe_column_drift')) {
        $failures[] = 'refresh_column_drift_notice() replays the stored drift list instead of '
            . 're-deriving it. Nothing else recomputes that list after a migration adds the '
            . 'column, and this runs at admin_init priority 98 — ahead of the TTL pruner — so a '
            . 'notice for drift that no longer exists cannot clear until the next release';
    }

    // NOT "the name observe_column_drift appears". That is satisfied by a call whose result is
    // discarded, or by one sitting after an early return — which is exactly the shape the first
    // mutation written against this gate took, and it survived. What must be true is that the
    // announced list is the RE-DERIVED one: announcing the stored list is the replay defect
    // itself, whatever else the function also does.
    if (preg_match('/announce_column_drift\(\s*\$stored\s*\)/', $refresh)) {
        $failures[] = 'refresh_column_drift_notice() announces the STORED list. That is the '
            . 'replay defect regardless of whether it also re-derives — the stored list is '
            . 'written only by init_tables(), so a migration that fixed the schema cannot clear '
            . 'the notice it left behind';
    }
}

$observe = slimstat_find_function_body($adminCode, 'observe_column_drift');

if (null === $observe) {
    $failures[] = 'observe_column_drift() not found';
} elseif (false !== strpos($observe, 'Schema::ensure')) {
    // F4's standing rule: reporting drift must never be able to repair it. ensure() creates
    // tables and builds indexes; an ALTER on admin_init is the hazard S7 removed.
    $failures[] = 'observe_column_drift() calls Schema::ensure(), which performs DDL. '
        . 'Re-observing drift on every admin_init must not be able to change the schema';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: degradation notice honesty (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: ' . $found . ' degradation steps, all labelled; severity is recorded at the call '
    . "site, not guessed; drift is re-derived, not replayed\n";

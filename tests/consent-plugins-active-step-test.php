<?php
/**
 * The Tier 2 lane asserts, at run time, that the consent plugins it installed are ACTIVE.
 *
 * E0 installs `wp-consent-api` and `cookie-law-info` on every Tier 2 lane so that the specs
 * gating on them run instead of self-skipping. Two gates already pin that: the static one in
 * ci-matrix-coverage-test.php (the override step names both zips) and a runtime step in ci.yml
 * (`wp plugin is-active` for each slug, `exit 1` if not). The static gate cannot see the
 * runtime step, and the runtime step was protected by nothing — delete it and every source-level
 * check stays green while the lane silently returns to "requested, not active", the exact state
 * in which a census measures CI setup rather than test health (DoD 9a).
 *
 * WHAT IS PINNED: every slug the override step installs is checked by some `wp plugin is-active`
 * step, and every such step fails the lane (`exit 1`) when its plugin is not active and is not
 * soft. The slug list is DERIVED from the override step's zip URLs, so a third plugin added to
 * the lane must also be asserted active, or this goes red.
 *
 * COVERING SET, not one step. The lane installs `woocommerce` on one WP version only (the lane
 * that runs `woocommerce-purchase-journey.spec.ts`, which needs a WordPress newer than the
 * full-suite lane ships), so its activation check carries an `if:` the unconditional consent
 * check must not have. Requiring exactly one step would force the two into one block, and the
 * merged block would then have to be soft or conditional to survive the lanes without
 * WooCommerce -- which is precisely the "requested, not active" hole this gate exists to close.
 * Deleting either step still turns this red, because a slug loses its cover.
 *
 * Run: php tests/consent-plugins-active-step-test.php
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

$ci_code = slimstat_yaml_strip_comments((string) file_get_contents($plugin_root . '/.github/workflows/ci.yml'));
$steps   = slimstat_ci_steps($ci_code);

// ── 1. The slugs the lane installs, from the override step's zip URLs ───────────────────
// Every override step joined, not the first. The nightly writes one too and names no plugin
// zips, so it contributes nothing; a loop that breaks on the first match could not see a second
// lane's set at all.
preg_match_all(
    '#downloads\.wordpress\.org/plugin/([a-z0-9-]+)\.[0-9.]+\.zip#',
    implode("\n", slimstat_ci_steps_containing($steps, '.wp-env.override.json')),
    $m
);
$slugs = array_values(array_unique($m[1]));

if (count($slugs) < 2) {
    $failures[] = sprintf('found %d consent plugin zip(s) in the wp-env override step; E0 installs '
        . 'two. The URL scan has stopped matching, so the activation check below has nothing '
        . 'to require', count($slugs));
}

// ── 2. A runtime step asserts each is active, and fails the lane if not ─────────────────
$active_steps = slimstat_ci_steps_containing($steps, 'wp plugin is-active');

if (!$active_steps) {
    $failures[] = 'no ci.yml step runs `wp plugin is-active` after wp-env starts. The override '
        . 'step REQUESTS the plugins; nothing else proves they are active';
} else {
    foreach ($slugs as $slug) {
        $covered = false;
        foreach ($active_steps as $step) {
            // Named anywhere in the step: the consent check passes its slugs through a shell
            // `for slug in ...` loop, so the literal `is-active <slug>` never appears.
            if (false !== strpos($step, $slug)) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            $failures[] = sprintf('no `wp plugin is-active` step names `%s`, which the override '
                . 'step installs', $slug);
        }
    }
    foreach ($active_steps as $i => $step) {
        $name = preg_match('/^\s*-\s*name:\s*(.+)$/m', $step, $nm) ? trim($nm[1]) : "step #{$i}";
        if (false === strpos($step, 'exit 1')) {
            $failures[] = sprintf('activation step "%s" does not `exit 1` when a plugin is '
                . 'inactive', $name);
        }
        if (false !== strpos($step, 'continue-on-error')) {
            $failures[] = sprintf('activation step "%s" is soft; a check that cannot fail the lane '
                . 'cannot protect the census denominator', $name);
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: consent plugins active step (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: the Tier 2 lane asserts ' . implode(' and ', $slugs) . " are active at run time, "
    . "and fails the lane if not\n";

<?php
/**
 * Source-level: subsite table creation reaches every path that creates a site — D10.
 *
 * PINS FIX (D10, F8). Three defects, one mechanism — the registration lived in the wrong
 * file, on the wrong hook, reading the wrong parameter:
 *
 *   1. `new_blog()` hung off `wpmu_new_blog`, deprecated since WP 5.1. Core still fires it
 *      through a compat shim — but only `if (has_action('wpmu_new_blog'))` at the moment
 *      `wp_initialize_site()` runs.
 *   2. That registration sat in admin/index.php, which wp-slimstat.php includes only when
 *      `is_admin()` — false under WP-CLI and REST. So `wp site create`, REST site creation
 *      and any programmatic `wp_insert_site()` found no callback registered: the compat
 *      shim skipped the deprecated hook and the new subsite got NO TABLES. Every pageview
 *      on it was lost until someone visited its wp-admin (C38's safety net) — on a network
 *      whose sites are provisioned by CLI, potentially forever.
 *   3. `on_activate()` ignored `$network_wide`: a network activation initialized the
 *      CURRENT blog only, so every existing subsite started in the same tables-less state.
 *
 * The fix: `add_action('wp_initialize_site', ...)` registered UNCONDITIONALLY in
 * wp-slimstat.php (the same lesson as C38 — lifecycle hooks must not live behind
 * `is_admin()`), `wpmu_new_blog` gone from the tree, and `on_activate()` reading
 * `$network_wide` and walking `get_sites()`.
 *
 * Also pins the free API this seam ships: `wp_slimstat::table()` / `::tables()` delegate
 * to the Schema manifest (F2), because a second list of table names is how C11 happened.
 *
 * Asserts CONSTRUCTS via the token stream, not text — a commented-out registration must
 * not pass, and a renamed variable must not sneak past a substring check.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$main_source  = (string) @file_get_contents($plugin_root . '/wp-slimstat.php');
$admin_source = (string) @file_get_contents($plugin_root . '/admin/index.php');

if ('' === $main_source || '' === $admin_source) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php / admin/index.php\n");
    exit(1);
}

/**
 * Every string-literal value in a file's token stream. Comments cannot contribute (they are
 * T_COMMENT tokens), so a commented-out `add_action('wpmu_new_blog', ...)` does not count —
 * and neither does prose in a docblock explaining the history.
 *
 * @return string[] lowercased literal values, quotes stripped
 */
$string_literals = static function (string $source): array {
    $values = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $values[] = strtolower(trim($token[1], "'\""));
        }
    }

    return $values;
};

// ── 1. The deprecated hook is GONE, from both files ─────────────────────────────────────
//
// Checked in both because the defect was precisely that each file assumed the other had it:
// a re-registration in either one restores the admin-only blind spot (admin/index.php) or
// starts emitting deprecation notices on every site creation (wp-slimstat.php).
foreach (['admin/index.php' => $admin_source, 'wp-slimstat.php' => $main_source] as $file => $source) {
    if (in_array('wpmu_new_blog', $string_literals($source), true)) {
        $failures[] = "{$file} still names the 'wpmu_new_blog' hook — deprecated since WP 5.1, and "
            . 'fired by core only when a callback is already registered at wp_initialize_site() '
            . 'time, which an is_admin()-gated bundle cannot guarantee on CLI/REST requests';
    }
}

// ── 2. wp_initialize_site is registered, and NOT behind is_admin() ──────────────────────
//
// Same construct-level check as activation-hooks-registered-test.php, through the SHARED
// slimstat_guarded_block_ranges() helper. This file's first version carried its own copy
// of that walk — and tested containment with a cumulative BYTE offset against ranges that
// are TOKEN indexes, so the "is it guarded?" half could never fire. Found by review, not
// by the gate going red: absence of the registration reads the same under both units, so
// the gate passed for the right verdict via a check that could not have caught the wrong
// one. The helper's docblock now records the drift; containment below is by token index.
$tokens = token_get_all($main_source);
$count  = count($tokens);

$admin_only_ranges = slimstat_guarded_block_ranges($tokens);

// Vacuity guard, same policy as activation-hooks-registered-test.php: wp-slimstat.php is
// known to contain is_admin() blocks, so an empty range list means the token walk broke —
// and with no ranges, "not inside a guarded block" is true of everything.
if ([] === $admin_only_ranges) {
    fwrite(STDERR, "FAIL: found no is_admin() block in wp-slimstat.php — the guarded-range scan is\n"
        . "  broken (the file has several), and without ranges the containment check asserts nothing\n");
    exit(1);
}

$registration_found   = false;
$registration_guarded = false;
$add_action_types = slimstat_name_token_types();

for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || !isset($add_action_types[$tokens[$i][0]])
        || 'add_action' !== slimstat_last_name_segment($tokens[$i][1])) {
        continue;
    }

    // First string literal inside this call's parens names the hook.
    $paren_end = slimstat_token_paren_end($tokens, $i, $count);
    if (null === $paren_end) {
        continue;
    }

    for ($k = $i; $k < $paren_end; $k++) {
        if (is_array($tokens[$k]) && T_CONSTANT_ENCAPSED_STRING === $tokens[$k][0]
            && 'wp_initialize_site' === strtolower(trim($tokens[$k][1], "'\""))) {
            $registration_found = true;
            foreach ($admin_only_ranges as [$from, $to]) {
                if ($i > $from && $i < $to) {
                    $registration_guarded = true;
                }
            }
            break;
        }
    }
}

if (!$registration_found) {
    $failures[] = "wp-slimstat.php does not register a callback on 'wp_initialize_site' — a "
        . 'subsite created by WP-CLI, REST or wp_insert_site() gets no analytics tables until '
        . 'someone loads its wp-admin';
} elseif ($registration_guarded) {
    $failures[] = "the 'wp_initialize_site' registration is inside an is_admin()-guarded block — "
        . 'that is the C38 defect again: is_admin() is false on exactly the requests that '
        . 'create sites programmatically';
}

// ── 3. on_activate() honours $network_wide ──────────────────────────────────────────────
//
// The parameter must be declared AND read, and the body must enumerate sites. A declared-
// but-ignored parameter is what PHP's surplus-argument silence makes invisible (F8's own
// date_i18n defect), so presence of the name in the signature proves nothing by itself.
$body = slimstat_find_function_body($main_source, 'on_activate');

if (null === $body) {
    $failures[] = 'wp-slimstat.php no longer declares on_activate()';
} else {
    // The body only — slimstat_find_function_body() excludes the signature, so any
    // occurrence here is a READ, not the declaration. (The first version of this check
    // demanded two occurrences on the assumption the signature was included, and failed
    // against the fixed tree: an assertion proven RED in both directions.)
    $stripped = slimstat_strip_comments_and_strings($body);
    if (0 === substr_count($stripped, '$network_wide')) {
        $failures[] = 'on_activate() does not read $network_wide — a network activation would '
            . 'initialize only the current blog and leave every subsite tables-less';
    }
    if (false === strpos($stripped, 'get_sites')) {
        $failures[] = 'on_activate() never calls get_sites() — the network-wide branch has '
            . 'nothing to walk';
    }
}

// ── 4. The free API exists and delegates to the manifest ────────────────────────────────
//
// wp_slimstat::table()/tables() are the prerequisite for F9/D20-D22 and Phase G. They must
// resolve names THROUGH Schema — a hand-written list here would be a tenth creator of the
// thing C11 counted nine of.
foreach (['table', 'tables'] as $method) {
    $body = slimstat_find_function_body($main_source, $method);
    if (null === $body) {
        $failures[] = "wp_slimstat::{$method}() is not declared — Pro's per-blog rewrite (D20-D22) "
            . 'has no free API to call';
        continue;
    }
    // Comments and strings blanked BEFORE matching: a body that interpolates the name
    // itself while a comment still says "Schema::tableName(...)" is the name-only
    // mutation every source assertion must be dead to.
    $body = slimstat_strip_comments_and_strings($body);
    if (!preg_match('/Schema::tableNames?\s*\(/', $body)) {
        $failures[] = "wp_slimstat::{$method}() does not delegate to the Schema manifest — a "
            . 'second list of table names is how six index creators became nine (C11)';
    }
    if (false === strpos($body, 'get_blog_prefix')) {
        $failures[] = "wp_slimstat::{$method}() does not resolve the prefix via get_blog_prefix() — "
            . 'interpolating $wpdb->prefix is exactly what made init_tables() unable to target '
            . 'a blog';
    }
}

echo "\n";
if ([] !== $failures) {
    fwrite(STDERR, 'FAIL: subsite table hooks (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: wp_initialize_site registered unguarded, wpmu_new_blog gone, on_activate walks the "
    . "network, table()/tables() delegate to Schema\n";
exit(0);

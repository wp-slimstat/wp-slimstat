<?php
/**
 * Every migration probe reachable from an admin page load must consult a cache first — and
 * everything that caches must be invalidated by forgetProbe().
 *
 * ── The defect this exists to catch ──────────────────────────────────────────────────────────
 *
 * `MigrationAdmin::registerPage()` guards on `!needsMigration() && [] === getOfferedMigrations()`.
 * `needsMigration()` is memoised per request AND cached in a 12-hour transient — it was measured
 * at 18 queries / 24.7 ms per admin page and fixed. `getOfferedMigrations()` was not cached at
 * all — and the LEFT operand of that guard is a NEGATION, true exactly when nothing is owed, so
 * the right side runs precisely in the healthy steady state after "Apply All" has been run.
 * (`&&` short-circuits normally; what costs is which side the negation puts first.)
 *
 * So on every wp-admin page load of a healthy install:
 *
 *   ConvertTablesToUtf8mb4::shouldRun()  → tableStates() → one information_schema.COLUMNS
 *                                          aggregate per declared table = SIX queries
 *   AddUserAgentDimension::shouldRun()   → THREE SHOW COLUMNS (columnState() memoises
 *                                          nothing, so wp_slim_stats is asked twice), then
 *                                          `SELECT 1 … WHERE ua_id IS NULL LIMIT 1` — `ua_id`
 *                                          sits in no manifest index, so once every row is
 *                                          keyed that predicate matches nothing and the
 *                                          LIMIT saves nothing: it scans the fact table
 *
 * `information_schema` is fast on MySQL 8's data dictionary and notoriously slow on 5.6/5.7, both
 * of which are inside the declared floor. And it is permanent: the release notes tell owners not
 * to run the optional migrations, so the offered set stays non-empty forever.
 *
 * It is reached from `registerPage()` on `admin_menu`, so every admin page load pays it, and
 * twice more on the migration screen itself (`enqueueAssets`, `renderPage`). maybeShowNotice()
 * on `admin_notices` asks needsMigration(), NOT this — the "twice per admin page" line belongs
 * to that method and must not be copied here. Within a request the two optional migrations
 * behave differently:
 * `ConvertTablesToUtf8mb4::shouldRun()` memoises on `$shouldRunCache` so its six aggregates are
 * paid once, while `AddUserAgentDimension::shouldRun()` does not and re-probes on the second call.
 * A budget test that exercises only `registerPage()` therefore PASSES on the existing per-instance
 * memo while the defect stands. That is why the behavioural half of this
 * (`tests/Unit/Migration/MigrationProbeCacheTest.php`) drives both call sites in one request with
 * a cold transient, and why this file checks structure rather than trying to count queries without
 * a database.
 *
 * ── Section 3 is the one that is easy to leave out ───────────────────────────────────────────
 *
 * A cache that nothing invalidates is worse than no cache: running a migration would leave the
 * screen offering it for twelve hours. `forgetProbe()` is the single invalidation point, and it
 * must clear every transient the probes populate. Adding a cache and forgetting to add its
 * deletion is silent, survives every test that only reads, and is exactly the shape this gate
 * exists to make loud.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$manager_path = $plugin_root . '/src/Migration/MigrationManager.php';
$source       = (string) file_get_contents($manager_path);

if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read src/Migration/MigrationManager.php\n");
    exit(1);
}

// Comments and strings blanked before every check below: a docblock explaining the cache, or a
// transient NAME appearing as a string literal, must not satisfy a check for the cache itself.
$code = slimstat_strip_comments_and_strings($source, true);

// ── 1 & 2. Both probes must consult a cache before touching the database ─────────────
//
// needsMigration() is the CONTROL. It already does this, so a run where section 2 fails and
// section 1 passes proves the check discriminates rather than rejecting everything.

$probes = [
    'needsMigration'       => 'the required-migration probe, asked twice per admin page load '
        . '(registerPage on admin_menu, maybeShowNotice on admin_notices)',
    'getOfferedMigrations' => 'the offered-migration probe, asked from registerPage() on '
        . 'admin_menu — so every admin page pays it — and evaluated precisely when '
        . 'needsMigration() is false, which is the healthy steady state',
];

foreach ($probes as $method => $role) {
    // slimstat_function_body() THROWS when the method is absent rather than returning '' — so a
    // rename surfaces as a loud uncaught RuntimeException here, and an `'' === $body` guard would
    // be unreachable code claiming to catch it. migration-runner-reachable-test.php already
    // removed exactly that dead condition; this file does not re-add it.
    $method_body = slimstat_function_body($code, $method);

    // It must actually probe, or there is nothing to cache and this gate is asserting over the
    // wrong method.
    if (false === strpos($method_body, 'shouldRun')) {
        $failures[] = "{$method}() no longer calls shouldRun(), so this gate is checking a method "
            . 'that does not probe. Re-point it rather than deleting the check';
        continue;
    }

    // BOTH SIDES. A method that READS a cache nothing ever writes is a permanent miss — the
    // defect fully restored — and every other check in this file stays green on it. The sibling
    // gate migration-runner-reachable-test.php asserts both halves for needsMigration(); dropping
    // the write half here would have made this the weaker of the two.
    if (false === strpos($method_body, 'set_transient')) {
        $failures[] = sprintf(
            '%s() never calls set_transient() — %s. A probe that reads a cache nothing writes is '
                . 'a permanent miss: the queries run on every page load exactly as before, while '
                . 'the code reads as cached',
            $method,
            $role
        );
    }

    if (false === strpos($method_body, 'get_transient')) {
        $failures[] = sprintf(
            '%s() calls shouldRun() without consulting a transient first — %s. Its probes then '
                . 'run on EVERY admin page load, permanently: six information_schema aggregates, '
                . 'three SHOW COLUMNS, and an unindexed `WHERE ua_id IS NULL` over the fact '
                . 'table. needsMigration() already carries this cache',
            $method,
            $role
        );
    }
}

// ── 3. Everything that caches must be invalidated in ONE place ───────────────────────

$forget = slimstat_function_body($code, 'forgetProbe');

{
    // Every TRANSIENT_* class constant the file declares must be deleted by forgetProbe(). Derived
    // from the declarations rather than listed here: a hand-written list is one more thing to
    // forget, and forgetting is the defect.
    preg_match_all('/private\s+const\s+(TRANSIENT_[A-Z_]+)\s*=/', $code, $declared);
    $names = $declared[1] ?? [];

    // VACUITY FLOOR. No transient constants found means the pattern stopped matching and section 3
    // asserts nothing — which is also what a rename to a different convention looks like.
    if (count($names) < 1) {
        $failures[] = 'no TRANSIENT_* constant found in MigrationManager.php; section 3 would pass '
            . 'by having nothing to check. If the naming convention changed, re-point this scan';
    }

    foreach ($names as $constant) {
        if (false === strpos($forget, $constant)) {
            $failures[] = sprintf(
                'forgetProbe() does not delete %s. A probe cache that nothing invalidates is '
                    . 'worse than no cache: after a migration runs, the screen keeps offering it '
                    . 'until the transient expires — twelve hours by PROBE_TTL — and the admin '
                    . 'has no way to force a re-probe',
                $constant
            );
        }
    }

    // Per-request memos die with the request, so they are not a correctness risk across requests —
    // but forgetProbe() runs mid-request, after a migration has changed the answer, and a stale
    // memo would outlive the change it exists to notice.
    // An optional type between `private` and the name: the tree already uses typed properties
    // elsewhere, and `private ?bool $needsMemo` would otherwise match nothing and take this
    // half of section 3 silently vacuous.
    preg_match_all('/private\s+(?:\??[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*\s+)?\$([a-zA-Z]*Memo)\b/', $code, $memos);
    foreach ($memos[1] ?? [] as $memo) {
        if (false === strpos($forget, $memo)) {
            $failures[] = sprintf(
                'forgetProbe() does not clear $%s. It is called immediately after a migration '
                    . 'runs, in the same request that then re-asks whether more work remains, so '
                    . 'a memo it leaves behind answers about the schema as it was before the run',
                $memo
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration probe query budget (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: both migration probes consult a cache before querying, and forgetProbe() clears every "
    . "cache they populate\n";

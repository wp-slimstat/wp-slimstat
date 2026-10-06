<?php
/**
 * A manifest index whose column a MIGRATION adds must be built by that same migration.
 *
 * ── The defect this exists to catch ──────────────────────────────────────────────────────────
 *
 * `Schema::ensure()` skips an index whose columns are not all present yet
 * (`Schema.php:976-990`) and reports the skip in `indexes_skipped_missing_column` — a key
 * nothing reads. On an upgrading install the order is:
 *
 *   1. `admin_init` → update_tables_and_options() → init_tables() → Schema::ensure().
 *      `vid_hash` does not exist, so `idx_vid_hash_dt` is SKIPPED.
 *   2. The same request stamps `version = 6.0.0` (admin/index.php:1312).
 *   3. The admin later clicks Migration; `AddVisitIdentity` adds the COLUMN.
 *   4. Nothing builds the index. `ensure()` is reached only through the version gate at
 *      admin/index.php:269, and the version already matches.
 *
 * Measured: `get_index_definitions()` (admin/index.php:4017-4046) hardcodes six action→index
 * pairs and `idx_vid_hash_dt` is not among them, so no admin-facing path offers it either. A
 * fresh install gets the index inline in `CREATE TABLE`; an upgraded one does not get it until
 * the NEXT version bump re-opens the version gate. That is C39 — the fresh/upgraded index
 * divergence the Schema manifest exists to prevent — reopened through the skip mechanism.
 *
 * ── Why the gate is shaped this way ──────────────────────────────────────────────────────────
 *
 * It asserts over the MANIFEST and the migrations' own calls, not over a hand-written list of
 * index names. A list would have to be edited every time an index is declared, and the edit that
 * forgets is exactly the defect. Deriving the obligation from `Schema::indexes()` means declaring
 * an index over a migration-added column turns this red the moment it lands.
 *
 * Tokenised, never raw text: `tests/source-scan-strength-test.php` refuses a new raw-text scanner
 * of production source, and it is right to — `addManifestColumn('slim_stats', 'vid_hash'` inside
 * a docblock would satisfy a bare preg_match, and whether the call is REACHED is the question.
 *
 * ── Scope, stated because the omission is deliberate ─────────────────────────────────────────
 *
 * Only tables where `Schema::reconciles()` is true. `slim_stats_archive` declares the same index
 * set and reconciles NONE of it, by design — its manifest entry says so. Requiring a migration to
 * build thirteen indexes on cold storage would be this gate inventing an obligation the schema
 * explicitly declines.
 *
 * Optional indexes are also out of scope: an index the site owner switched off through Maintenance
 * must not be rebuilt behind their back by a migration.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use SlimStat\Schema\Schema;

$plugin_root = dirname(__DIR__);
$failures    = [];

/**
 * Every CALL to $method in $tokens, as its arguments resolved to string literals.
 *
 * A slot that is not exactly one quoted string resolves to null rather than being skipped, so
 * positions never shift. Collecting literals in encounter order instead — the first draft of this
 * closure — reads `foo($suffix, 'vid_hash', 'k')` as slot 0 = `vid_hash`, and every assertion
 * built on that map then checks the wrong pair while the gate reports PASS. Caught in review
 * before it landed; slimstat_call_args() exists so it cannot be rebuilt.
 *
 * A declaration is not a call: `function reconcileColumnIndexes(` and `$this->method(` both put a
 * name token before a `(`, so T_FUNCTION and T_OBJECT_OPERATOR/T_DOUBLE_COLON are distinguished
 * explicitly. Only the glob keeps AbstractMigration.php — where both methods are DECLARED — out of
 * this scan today, and a glob is not an argument.
 *
 * @return array<int, array<int, string|null>>
 */
$call_args = static function (array $tokens, string $method): array {
    $out        = [];
    $name_types = slimstat_name_token_types();
    $count      = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || !isset($name_types[$tok[0]])) {
            continue;
        }
        if ($method !== slimstat_last_name_segment($tok[1])) {
            continue;
        }

        // A declaration defines the obligation; it does not discharge it.
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && T_WHITESPACE === $tokens[$prev][0]) {
            $prev--;
        }
        if ($prev >= 0 && is_array($tokens[$prev]) && T_FUNCTION === $tokens[$prev][0]) {
            continue;
        }

        $open = slimstat_next_significant($tokens, $i);
        if ($open >= $count || '(' !== slimstat_token_text($tokens[$open])) {
            continue; // a mention, not a call
        }

        $close = slimstat_token_paren_end($tokens, $open, $count);
        if (null === $close) {
            continue;
        }

        $out[] = array_map('slimstat_arg_string', slimstat_call_args($tokens, $open, $close));
    }

    return $out;
};

// ── What each migration adds, and what it reconciles ─────────────────────────────────
//
// $adds[$suffix][$column] = relative file    — the migration that ADDs that column
// $builds[$file][$suffix] = true             — that migration reconciles that table's indexes

$files = glob($plugin_root . '/src/Migration/Migrations/*.php') ?: [];

$adds   = [];
$builds = [];

foreach ($files as $file) {
    $rel    = slimstat_rel_path($plugin_root, $file);
    $tokens = slimstat_tokenize((string) file_get_contents($file), true);

    foreach ($call_args($tokens, 'addManifestColumn') as $args) {
        // Both slots must resolve. A variable table or column is a call this gate cannot reason
        // about, and recording a half-resolved pair would be worse than recording nothing.
        if (isset($args[0], $args[1])) {
            $adds[$args[0]][$args[1]] = $rel;
        }
    }

    foreach ($call_args($tokens, 'reconcileColumnIndexes') as $args) {
        // BOTH slots, not just the table. Recording only slot 0 accepts
        // reconcileColumnIndexes('slim_stats', 'no_such_column', ...) as discharging the
        // obligation for every column on that table — the argument was parsed and thrown away,
        // which review demonstrated by perturbation.
        if (isset($args[0], $args[1])) {
            $builds[$rel][$args[0]][$args[1]] = true;
        }
    }
}

// VACUITY FLOOR 1. No migration adds a column ⇒ the tokeniser, the glob or the argument reader
// broke, and every assertion below would pass by having nothing to assert over.
$added_columns = 0;
foreach ($adds as $columns) {
    $added_columns += count($columns);
}
// The count is pinned, not floored loosely. The tree declares FOUR: vid_hash and ua_id, each on
// slim_stats and slim_stats_archive. A floor of 2 — the first draft — passes while half the scan
// has stopped parsing, and a message naming four pairs beside an assertion of two is the kind of
// number nothing checks.
if ($added_columns < 4) {
    $failures[] = 'found only ' . $added_columns . ' addManifestColumn() target(s) across '
        . count($files) . ' migration file(s); expected at least 4 — vid_hash and ua_id, each on '
        . 'slim_stats and slim_stats_archive. The scan is broken and its PASS is empty';
}

// ── The obligation ───────────────────────────────────────────────────────────────────

// Iterates what the migrations ADD, which is this gate's actual subject, and asks production's
// own Schema::indexesForColumn() what that obliges. Deriving the same predicate here instead —
// walking tables, filtering optional groups, re-reading column lists — would put a second copy of
// it inside the gate whose whole subject is two declarations of one fact drifting apart.
$examined = 0;

foreach ($adds as $suffix => $columns) {
    if (!Schema::reconciles($suffix)) {
        continue; // declared, deliberately not reconciled — see the scope note above
    }

    foreach ($columns as $column => $adder) {
        foreach (Schema::indexesForColumn($suffix, $column) as $index) {
            $examined++;

            if (!isset($builds[$adder][$suffix][$column])) {
                $failures[] = sprintf(
                    '%s adds `%s` to `%s`, and the manifest declares index `%s` over it — but that '
                        . 'migration never calls reconcileColumnIndexes(\'%s\', \'%s\', …). Schema::ensure() '
                        . 'skipped the index on the upgrade pass because the column did not exist '
                        . 'yet, and the version stamp means ensure() will not run again until the '
                        . 'next release. So every UPGRADED install runs without `%s` while every '
                        . 'FRESH install has it',
                    $adder,
                    $column,
                    $suffix,
                    $index,
                    $suffix,
                    $column,
                    $index
                );
            }
        }
    }
}

// VACUITY FLOOR 2. If no manifest index depends on a migration-added column, this gate examined
// nothing. That is a legitimate state for the schema to reach — but it must be noticed, not
// mistaken for a pass, because it is also what a broken $adds map looks like.
if ($examined < 1) {
    $failures[] = 'no manifest index on a reconciling table depends on a migration-added column, '
        . 'so this gate asserted nothing. Either the schema genuinely has no such index — delete '
        . 'this floor deliberately and say so — or $adds is empty and the scan is broken';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: upgrade index convergence (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all ' . $examined . " manifest index/migration-added-column pair(s) are built by the "
    . "migration that adds the column\n";

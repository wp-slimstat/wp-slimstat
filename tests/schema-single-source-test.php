<?php
/**
 * Source-level: the analytics schema is declared in exactly one place.
 *
 * C39 — THE INDEX BASELINE WAS MEASURED ON A SCHEMA NO FRESH INSTALL WILL EVER HAVE.
 * `init_tables()` emitted raw `CREATE TABLE IF NOT EXISTS`, which can only create and never
 * reconcile, and the upgrade path emitted its own DDL — so the two are mutually exclusive by
 * construction: a fresh install stamps the current version before any admin page renders, so
 * the upgrade gate is false forever; an update never fires activation, so the create gate is
 * false forever. Counted: fresh got 11 secondary indexes on slim_stats, upgraded got 13. That
 * is not two schema states but at least four, and every measured index/row-read claim on the
 * branch was baselined on whichever one the reference install happened to be.
 *
 * C11 — six independent creators, and the Phase E drops would be silently re-created by
 * whichever of them the install happens to run. Enumerated: the inline INDEX clauses in
 * `CREATE TABLE`, `init_environment()`'s four probe-and-create blocks, `init_tables()`'s
 * `$index_defs`, the <4.8.4 `ALTER` block, the goals/funnels block, the eight
 * `Create*Index` migration classes, and `get_index_definitions()` behind the AJAX retry UI.
 *
 * C42 — `ENGINE=InnoDB` was never emitted on any modern server. `SHOW VARIABLES LIKE
 * 'have_innodb'` returns empty on MySQL >= 5.6.1 (the variable was removed), so the clause was
 * omitted from every `CREATE TABLE` and the engine came from the host's default. On a MyISAM
 * host the `slim_events` FOREIGN KEY is silently ignored — and since Phase G hard-refuses
 * MyISAM, v6 would refuse to migrate an install v6 itself created.
 *
 * C11b — a fresh v6 install was still created `COLLATE utf8_general_ci`, so it was born needing
 * the charset migration (C41: and born showing its notice) and could never converge with an
 * upgraded install.
 *
 * C16 — the uninstall drop list was hardcoded, in FK-safe order, in a file that cannot see the
 * table inventory. Every new table needs four registrations and nothing enforced that.
 *
 * WHY THE SCANS BLANK COMMENTS BUT KEEP STRING CONTENTS: the constructs under test ARE string
 * literals — `CREATE TABLE`, `ADD INDEX`, `utf8_general_ci`. Running these through
 * slimstat_strip_comments_and_strings() would blank exactly what they look for and every
 * assertion below would pass on any tree (that mistake shipped once already, in
 * migration-ui-honesty-test.php). Comments are still blanked, so no assertion can be satisfied
 * by the prose above explaining it.
 *
 * 7.4-safe: pure source analysis plus a direct require of Schema.php, which is deliberately
 * dependency-free. Loads no WordPress and no vendor tree.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$schema_rel  = 'src/Schema/Schema.php';
$schema_path = $plugin_root . '/' . $schema_rel;

// ── 0. The single source exists and is loadable without WordPress ───────────
// Loadable-without-WordPress is not a nicety: uninstall.php runs with the plugin unloaded and
// no autoloader, and the PHP-only CI lanes have no vendor tree. If Schema.php ever needs a WP
// function at require time, the inventory stops being reachable from the one file that most
// needs it and C16 comes straight back.
if (!is_file($schema_path)) {
    fwrite(STDERR, "FAIL: {$schema_rel} does not exist — there is no single source of schema truth\n");
    exit(1);
}

require_once $schema_path;

if (!class_exists('SlimStat\\Schema\\Schema')) {
    fwrite(STDERR, "FAIL: {$schema_rel} does not declare SlimStat\\Schema\\Schema\n");
    exit(1);
}

$schema = 'SlimStat\\Schema\\Schema';

foreach (['tables', 'columns', 'indexes', 'engine', 'createTableSql', 'ensure'] as $method) {
    if (!method_exists($schema, $method)) {
        $failures[] = "Schema::{$method}() is missing — the manifest cannot be the single source "
            . 'for a fact it does not expose';
    }
}

// ── 1. Nothing else in the tree emits table or index DDL ────────────────────
//
// Scanned as a whole-file literal search rather than per-construct, because the six creators
// spelled their DDL six different ways: an interpolated heredoc-ish double-quoted string, a
// sprintf template, an `ALTER TABLE … ADD INDEX` fragment with the table supplied separately,
// and a builder in AbstractIndexMigration. A rule keyed on any one of those shapes would have
// let the other five through.
$own_files = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src'],
    $plugin_root . '/src/Dependencies'
);
$own_files[] = $plugin_root . '/wp-slimstat.php';
$own_files[] = $plugin_root . '/uninstall.php';

// AbstractIndexMigration builds `CREATE INDEX %s ON %s (%s)` from values it is handed; that is
// the runner for the manifest, not a second declaration of it. Assertion 4 pins that its
// subclasses supply those values from Schema rather than restating them.
$ddl_exempt = [
    $schema_rel,
    'src/Migration/AbstractIndexMigration.php',
];

// What is forbidden is a DDL statement carrying a CONCRETE object name — a slim_ table, or an
// index called idx_something / something_idx. A builder whose objects arrive as %s is not a
// second declaration of the schema; it is the thing that renders the first one. Every creator
// this seam removed embedded a concrete name; none of the survivors do.
//
// Two deliberate narrowings, each of which a blunter rule got wrong on this tree:
//
//  - Case-SENSITIVE keywords. A case-insensitive scan matched the translated UI string
//    'Unable to add index.' in admin/index.php and reported it as a schema creator. All real
//    DDL here is uppercase; English prose is not.
//  - A bounded window rather than the whole file, so a keyword in one statement cannot pair
//    with a table name three hundred lines away and invent a violation.
$object_name = '/(?:slim_|idx_|_idx)/';
$emitters    = [];

foreach ($own_files as $file) {
    $rel = ltrim(str_replace($plugin_root, '', $file), '/');
    if (in_array($rel, $ddl_exempt, true)) {
        continue;
    }

    // Comments blanked, string CONTENTS kept: the constructs under test ARE string literals.
    $src = slimstat_blank_comments((string) file_get_contents($file), false);

    if (!preg_match_all('/CREATE TABLE|CREATE INDEX|ADD INDEX|DROP INDEX/', $src, $hits, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    foreach ($hits[0] as [$keyword, $offset]) {
        $window = substr($src, $offset, 160);

        if (preg_match($object_name, $window)) {
            $emitters[] = sprintf('%s: %s ... %s', $rel, $keyword, trim(substr($window, 0, 70)));
        }
    }
}

if ($emitters !== []) {
    $failures[] = sprintf(
        "%d DDL statement(s) name a concrete table or index outside %s:\n      %s\n    Every "
            . 'table and index definition must come from the manifest, or a Phase E drop is '
            . 'silently re-created by whichever of the creators the install happens to run',
        count($emitters),
        $schema_rel,
        implode("\n      ", $emitters)
    );
}

// Vacuity guard. Every assertion above is satisfied by a tree where the scan simply found
// nothing — including one where Schema.php is an empty shell. Pin the positive side.
$schema_src = slimstat_blank_comments((string) file_get_contents($schema_path), false);

if (!preg_match('/CREATE TABLE/i', $schema_src)) {
    $failures[] = 'Schema.php emits no CREATE TABLE — the scan above proves nothing, because '
        . '"no creator anywhere" satisfies it exactly as well as "one creator here"';
}

// ── 2. The engine is unconditional (C42) ────────────────────────────────────
// Checked on the STRIPPED source, unlike the DDL scans: here the hazard is the *construct*
// `have_innodb` being probed, and the docblock above legitimately names it.
foreach ($own_files as $file) {
    $rel = ltrim(str_replace($plugin_root, '', $file), '/');
    if (false !== strpos(slimstat_strip_comments_and_strings((string) file_get_contents($file), false), 'have_innodb')) {
        $failures[] = "{$rel} still probes have_innodb. The variable was removed in MySQL 5.6.1, "
            . 'so the probe returns empty and the ENGINE clause is dropped on every modern '
            . 'server — which is how an install created by v6 ends up MyISAM and refused by '
            . "Phase G's own migration";
    }
}

if (method_exists($schema, 'engine') && 'InnoDB' !== $schema::engine()) {
    $failures[] = 'Schema::engine() does not return InnoDB. The slim_events FOREIGN KEY is '
        . 'silently ignored on MyISAM, so the refusal has to happen at CREATE time';
}

// ── 3. Fresh installs are born at the target charset (C11b/C40) ─────────────
foreach ($own_files as $file) {
    $rel = ltrim(str_replace($plugin_root, '', $file), '/');
    $src = slimstat_blank_comments((string) file_get_contents($file), false);
    if (preg_match('/utf8_general_ci|CHARACTER SET utf8[^m]/i', $src)) {
        $failures[] = "{$rel} names a utf8mb3 collation in code. A fresh install created there "
            . 'is born needing the charset migration, so it can never converge with an upgraded '
            . 'one, and C41 shows it the migration notice on a site with no pageviews';
    }
}

// C40's arithmetic, computed from the manifest rather than asserted about it. The claim that
// unlocked "create fresh at utf8mb4 with zero column right-sizing" is that the declared VARCHAR
// widths fit the 65,535-byte row limit at 4 bytes/char. If a later seam widens a column past
// that, the CREATE TABLE starts failing on real installs and this is where it is caught.
if (method_exists($schema, 'declaredCharBytes')) {
    $row_bytes = $schema::declaredCharBytes('slim_stats', 4);

    if ($row_bytes >= 65535) {
        $failures[] = sprintf(
            'slim_stats declares %d bytes of VARCHAR at utf8mb4, over the 65,535-byte row limit. '
                . 'The table cannot be created at the target charset',
            $row_bytes
        );
    }

    // Not merely "under the limit" — under it by the margin C40 computed. A regression that
    // ate the headroom without crossing the limit is exactly the kind that surfaces later as
    // an unexplained CREATE failure on one install.
    if ($row_bytes < 40000) {
        $failures[] = sprintf(
            'slim_stats declares only %d bytes of VARCHAR at utf8mb4, far below the ~55,740 C40 '
                . 'measured. Columns have been removed or the parse is broken — either way the '
                . 'headroom claim in DECISIONS.md no longer describes this manifest',
            $row_bytes
        );
    }
}

// The 767-byte COMPACT index-prefix limit, which `resource(191)` sits 3 bytes under at utf8mb4.
// A margin of 3 is not a margin anyone should rediscover by hand on a customer's install.
if (method_exists($schema, 'indexes')) {
    foreach ($schema::tables() as $suffix) {
        foreach ($schema::indexes($suffix) as $name => $columns) {
            if (preg_match_all('/\((\d+)\)/', (string) $columns, $prefixes)) {
                foreach ($prefixes[1] as $chars) {
                    if (((int) $chars * 4) > 767) {
                        $failures[] = sprintf(
                            'index %s on %s uses a %d-character prefix — %d bytes at utf8mb4, over '
                                . 'the 767-byte COMPACT row-format limit. The CREATE INDEX fails '
                                . 'outright on any install not using DYNAMIC',
                            $name,
                            $suffix,
                            $chars,
                            (int) $chars * 4
                        );
                    }
                }
            }
        }
    }
}

// ── 4. The consumers derive, they do not restate ────────────────────────────
$consumers = [
    'uninstall.php'                                       => 'the uninstall drop list',
    'admin/index.php'                                     => 'the installer and wpmu_drop_tables',
    'src/Migration/Migrations/ConvertTablesToUtf8mb4.php'  => 'the charset migration',
];

foreach ($consumers as $rel => $what) {
    $src = slimstat_strip_comments_and_strings((string) file_get_contents($plugin_root . '/' . $rel), false);
    if (false === strpos($src, 'Schema::')) {
        $failures[] = "{$rel} does not consume Schema:: — {$what} still carries its own copy of "
            . 'the table inventory, which is the C16 shape exactly';
    }
}

// Every Create*Index migration must name an index and nothing else about it. The shape comes
// from AbstractIndexMigration reading the manifest; a subclass that overrides getIndexName(),
// getIndexColumns() or getTableName() has taken the declaration back, and the manifest becomes a
// seventh creator rather than the only one.
//
// An earlier form of this required each subclass to contain `Schema::` — which pinned the code
// into the shape where all eight consume the manifest INDIVIDUALLY, i.e. it would have failed
// the moment the derivation was lifted into the base class where it belongs. A gate that forbids
// its own fix is worse than no gate; that mistake shipped once already in migration-ui-honesty.
$migration_dir = $plugin_root . '/src/Migration/Migrations';
$index_keys    = [];

foreach ((array) glob($migration_dir . '/Create*Index.php') as $file) {
    $rel = 'src/Migration/Migrations/' . basename($file);
    $src = slimstat_strip_comments_and_strings((string) file_get_contents($file), false);

    foreach (['getIndexName', 'getIndexColumns', 'getTableName'] as $override) {
        if (preg_match('/function\s+' . $override . '\s*\(/', $src)) {
            $failures[] = "{$rel} overrides {$override}(), taking the index shape back out of "
                . 'the manifest. A subclass declares WHICH index it owns; the base class reads '
                . 'what that index IS';
        }
    }

    // The key, read from the ORIGINAL source — stripping blanks string contents, and the key is
    // a string literal. Two views on purpose; the single-view version of this could not fire.
    $literal = slimstat_blank_comments((string) file_get_contents($file), false);

    if (preg_match('/function\s+getIndexKey\s*\(\s*\)\s*:\s*string\s*\{\s*return\s+\'([^\']+)\'/s', $literal, $m)) {
        $index_keys[$rel] = $m[1];
        continue;
    }

    $failures[] = "{$rel} does not declare a getIndexKey() returning a literal manifest key";
}

if (count($index_keys) < 8) {
    $failures[] = 'only ' . count($index_keys) . ' Create*Index migration classes yielded an '
        . 'index key (expected at least 8) — the glob or the parse is stale, so the check above '
        . 'ran on almost nothing';
}

// Both remaining manifest checks want the same index map; build it once.
if (method_exists($schema, 'indexes')) {
    $all = [];
    foreach ($schema::tables() as $suffix) {
        $all += $schema::indexes($suffix);
    }

    // A declared key must EXIST in the manifest. This is the assertion the source scan cannot
    // make: a typo'd key passes every structural check above and is an undefined-index fatal
    // the first time a user clicks the retry button.
    foreach ($index_keys as $rel => $key) {
        if (!isset($all[$key])) {
            $failures[] = "{$rel} names index \"{$key}\", which the manifest does not declare — "
                . 'an undefined-index fatal the first time the retry button is clicked';
        }
    }

    // The 15 indexes an upgraded install carries, which a fresh install must now also get.
    // Written out rather than derived: this list is the statement of what C39 found, and
    // deriving it from the manifest would make it true by construction.
    $required = [
        '{prefix}slim_stats_dt_idx',
        '{prefix}stats_resource_idx',
        '{prefix}stats_browser_idx',
        '{prefix}stats_searchterms_idx',
        '{prefix}stats_fingerprint_idx',
        '{prefix}stats_dt_visit_idx',
        'idx_country_dt',
        'idx_dt_screen_width_screen_height',
        'idx_dt_browser_browser_version',
        'idx_dt_platform',
        'idx_dt_out',
        'idx_goal_queries',
        'idx_funnel_queries',
        'idx_events_notes_dt',
        '{prefix}slim_stat_events_idx',
    ];

    $missing = array_values(array_diff($required, array_keys($all)));

    if ($missing !== []) {
        $failures[] = 'the manifest is missing ' . implode(', ', $missing) . '. An install that '
            . 'had these before v6 would silently lose them, and fresh/upgraded diverge again';
    }
}

// ── 5. uninstall.php holds no table name of its own (C16) ───────────────────
//
// The rule is "this file names no table", checked over the WHOLE file — not "no DROP statement
// contains a table name", which is what the first version of this assertion checked and which
// went permanently vacuous the moment the DROP became `sprintf('DROP TABLE IF EXISTS %s%s')`.
// The quote terminated the character class immediately, so three hardcoded names one line above
// were invisible and the assertion could never fire again. It certified exactly the property it
// had stopped checking — PITFALLS entry 19, inside the seam that added entry 19.
$uninstall = slimstat_blank_comments((string) file_get_contents($plugin_root . '/uninstall.php'), false);

if (preg_match_all('/[\'"](slim_[a-z0-9_]+)[\'"]/', $uninstall, $named)) {
    $failures[] = 'uninstall.php names ' . implode(', ', array_unique($named[1])) . ' directly. '
        . 'Every table it drops must come from the manifest — live tables from Schema::tables(), '
        . 'retired ones from Schema::legacyTables() — or the next table added is the one nobody '
        . 'removes, on the code path where nobody looks afterwards';
}

// Vacuity guard for the assertion above: it passes trivially on a file that drops nothing at
// all. Prove the loops are still there.
if (!preg_match('/Schema::tables\(\)/', $uninstall) || !preg_match('/Schema::legacyTables\(/', $uninstall)) {
    $failures[] = 'uninstall.php no longer derives its drop list from Schema::tables() and '
        . 'Schema::legacyTables() — the check above would pass on a file that drops nothing';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: schema single source (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: one manifest owns every table, column, index, engine and collation\n";

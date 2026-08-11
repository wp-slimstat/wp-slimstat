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

// ── 1b. Nothing else in the tree emits COLUMN DDL either ────────────────────
//
// THE HALF THE GATE ABOVE COULD NOT SEE, and it stayed unseen for the whole of F2. That scan
// looks for CREATE TABLE, CREATE INDEX, ADD INDEX and DROP INDEX — every DDL keyword except the
// ones that change a column. Three real divergences were live in the tree while it ran green:
//
//   ua_id       added by AddUserAgentDimension, declared in no manifest. A fresh install was
//               born without it and paid a fact-table ALTER to catch up; C41's notice offered
//               that rebuild on an install with no rows (PITFALLS 30).
//   email       VARCHAR(255) in admin/index.php's 4.8.2 block, VARCHAR(256) in the manifest.
//   fingerprint VARCHAR(256) on slim_stats and VARCHAR(255) on slim_stats_archive, declared in
//               two ADJACENT LINES of the same block.
//
// So this is C39 exactly — fresh and upgraded installs converging on different schemas — on a
// column instead of an index, and the reason it survived is that the gate policed "there is one
// DECLARATION" while the defect was an ABSENCE. An absence is not a second declaration; nothing
// the scan matched could ever point at it.
//
// KEYED ON `ALTER TABLE`, NOT ON A LIST OF COLUMN KEYWORDS — and that is the lesson of the
// paragraph above rather than a detail of this one. The first draft of this section scanned for
// `(?:ADD|MODIFY|CHANGE)\s+COLUMN`, which is the SAME MECHANISM that failed: an enumeration of
// literal spellings. And it has the same kind of hole, because MySQL's grammar is `ADD [COLUMN]`,
// `DROP [COLUMN]`, `MODIFY [COLUMN]`, `CHANGE [COLUMN]` — the word is OPTIONAL in all four. So
//
//     ALTER TABLE `{$stats}` ADD ua_id BINARY(8) NULL
//
// is valid, is one word shorter than what actually shipped, and is invisible to every keyword
// scan here. The gate would have advertised "no column DDL outside the manifest" while enforcing
// "none written with the optional keyword present", and the divergence class it lets through is
// identical to the three above.
//
// `ALTER TABLE` has no optional spelling, and every statement that reshapes a table contains it
// exactly once. Keying on it is complete on this axis by construction, rather than by re-auditing
// the MySQL grammar each time someone extends a list.
//
// Also unconditional — no concrete-object-name requirement, unlike section 1 — because the shape
// that actually shipped interpolates the table and carries no literal `slim_` for a window to
// find. Requiring one would reproduce the exact blind spot this section exists to close.
//
// Three files legitimately ALTER without declaring a schema. They are NAMED, with the reason, not
// pattern-matched: a pattern loose enough to admit them is loose enough to admit a column.
$alter_exempt = [
    'src/Migration/Migrations/ConvertTablesToUtf8mb4.php' => 'CONVERT TO CHARACTER SET, over the '
        . 'tables the manifest names, to the collation the manifest targets. Declares nothing.',
    'admin/view/wp-slimstat-db.php' => 'RENAME TO, between two temp tables of its own making '
        . 'inside one function. Names no persistent object.',
    'admin/config/index.php' => 'DROP INDEX with both the table and the index arriving as %s from '
        . 'the manifest — the runner for a declaration, the same exemption AbstractIndexMigration '
        . 'has in section 1.',
];

// ONE pass, three questions. The first draft walked $own_files twice with byte-identical
// scaffolding — same skip list, same slimstat_blank_comments() — and that is not merely the
// ~25 ms: it is two exclusion lists that must agree about which files the column gate covers,
// inside a gate whose whole subject is two declarations of one fact drifting apart.
$altering   = [];
$call_sites = 0;
$drop_sites = 0;

foreach ($own_files as $file) {
    $rel = ltrim(str_replace($plugin_root, '', $file), '/');
    if ($rel === $schema_rel) {
        continue;
    }

    // Comments blanked, string CONTENTS kept — as in section 1, and for the same reason: both
    // the DDL under test and the call arguments under test ARE string literals.
    $src = slimstat_blank_comments((string) file_get_contents($file), false);

    if (!isset($alter_exempt[$rel]) && preg_match_all('/ALTER\s+TABLE/', $src, $hits, PREG_OFFSET_CAPTURE)) {
        foreach ($hits[0] as [, $offset]) {
            $altering[] = sprintf('%s: %s', $rel, trim(substr($src, $offset, 70)));
        }
    }

    // Every literal call site must name a column the manifest declares. The scan above proves
    // only that the statement is CENTRALISED; this proves the centralised call is ASKING FOR
    // SOMETHING REAL. Without it, `addColumnSql('slim_stats', 'ua_id', …)` against a manifest
    // with no `ua_id` passes every structural check and throws on the upgrade path — the C39
    // index-key failure (a declared key absent from the manifest) one level down, and the
    // assertion that would have caught ua_id on the commit that introduced it.
    //
    // dropColumnSql() is checked in the SAME loop and asserted the OTHER WAY: a dropped column
    // must NOT be declared, or the upgrade removes something every fresh install is created with.
    // addManifestColumn() is AbstractMigration's hoisted wrapper around addColumnSql()
    // (D68's review): its literal (table, column) arguments carry the SAME contract —
    // they must name a manifest-declared pair — so it resolves in the same loop, as an
    // 'add'. Without this, hoisting the wrapper silently removed ua_id's literal call
    // site and the manifest-deletion mutation would have SURVIVED the next full run.
    if (!preg_match_all('/(add|drop)(?:ColumnSql|ManifestColumn)\(\s*\'([a-z_]+)\'\s*,\s*\'([a-z_]+)\'/', $src, $calls, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($calls as [, $verb, $suffix, $column]) {
        if (!in_array($suffix, $schema::tables(), true)) {
            $failures[] = "{$rel} calls {$verb}ColumnSql() for table \"{$suffix}\", which the "
                . 'manifest does not declare';
            continue;
        }

        $declared = isset($schema::columns($suffix)[$column]);

        if ('add' === $verb) {
            $call_sites++;

            if (!$declared) {
                $failures[] = "{$rel} adds column \"{$column}\" to {$suffix}, which the manifest "
                    . 'does not declare — so a fresh install is born without it and an upgraded '
                    . 'one has it, and the migration notice offers to rebuild a table with no '
                    . 'rows (C41)';
            }

            continue;
        }

        $drop_sites++;

        if ($declared) {
            $failures[] = "{$rel} drops column \"{$column}\" from {$suffix}, which the manifest "
                . 'still declares. An upgraded install loses a column every fresh install is born '
                . 'with — C39 reached from the other side';
        }
    }
}

if ($altering !== []) {
    $failures[] = sprintf(
        "%d ALTER TABLE statement(s) outside %s and outside the recorded exemptions:\n      %s\n"
            . '    A column definition must come from the manifest via Schema::addColumnSql() or '
            . '::dropColumnSql(), or a fresh install and an upgraded one end up with differently '
            . 'shaped tables — which is C39, and it happened three times on columns while this '
            . 'gate watched only indexes',
        count($altering),
        $schema_rel,
        implode("\n      ", $altering)
    );
}

foreach (['ADD COLUMN', 'DROP COLUMN'] as $rendered) {
    if (false === strpos($schema_src, $rendered)) {
        $failures[] = "Schema.php emits no {$rendered} — as above, \"nobody does this anywhere\" "
            . 'satisfies the scan exactly as well as "only the manifest does"';
    }
}

// The call-site loop is satisfied by a tree where every call site is written some other way, and a
// literal-argument regex is exactly the kind of parse that goes quietly stale. Nine adds: email,
// fingerprint and tz_offset on both slim_stats and its archive in the legacy upgrade block (six
// addColumnSql), ua_id via addManifestColumn() in AddUserAgentDimension, and vid_hash via two
// literal addManifestColumn() calls in AddVisitIdentity (live + archive, deliberately unrolled
// from a loop so this gate can resolve them). One drop: `plugins`, retired in 4.8.4.1. A fall
// below either means the parse stopped seeing them — not that the call sites went away.
foreach ([['add', $call_sites, 9], ['drop', $drop_sites, 1]] as [$verb, $found, $floor]) {
    if ($found < $floor) {
        $failures[] = sprintf(
            'only %d literal %sColumnSql() call site(s) parsed (expected at least %d) — the check '
                . 'above ran on almost nothing, which is how an assertion certifies a property it '
                . 'has stopped testing',
            $found,
            $verb,
            $floor
        );
    }
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

// Matched ANYWHERE, not as a wholly-quoted string. The second attempt at this assertion
// required the name to sit between quotes — and a registered mutation restoring the real defect
// SURVIVED it, because the actual shape embeds the name in a SQL fragment
// (`'DROP TABLE IF EXISTS %sslim_stats'`), where the character before it is `s`, not a quote.
// Two rewrites of one assertion, both of which read correctly and neither of which could fire.
// The mutation is the only reason either was found.
//
// Safe as a bare substring search: every identifier in this file is `slimstat_`-prefixed
// (`$slimstat_table`, `SlimStat\Schema\Schema`, `_transient_slimstat_goal_`), none of which
// contains `slim_` — verified to return zero matches on the reconciled file.
if (preg_match_all('/slim_[a-z0-9_]+/', $uninstall, $named)) {
    $failures[] = 'uninstall.php names ' . implode(', ', array_unique($named[0])) . ' directly. '
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

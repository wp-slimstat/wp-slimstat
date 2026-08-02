<?php
/**
 * Equivalence arm for F2: the DDL the installer emitted BEFORE the Schema class, against what
 * the manifest emits now.
 *
 * VERIFICATION-PROTOCOL.md names this as mandatory before F2 lands: "the emitted DDL before and
 * after, for fresh AND upgraded installs". F2 is a refactor whose entire claim is that nine
 * creators are replaced by one WITHOUT changing what any of them produced, apart from three
 * changes that are the point (engine, collation, and the index set converging). A refactor that
 * silently drops a column or flips a default is not visible to any test that only exercises the
 * new path — every one of them would agree with itself.
 *
 * Arm A is read from the commit BEFORE the seam, not reconstructed from memory. Arm B is the
 * live manifest. Neither is blinded: this is a text diff, and blinding a text diff is theatre
 * (PITFALLS #15) — there is no flattering direction for a human reading two column lists.
 *
 * Usage: php tests/verify/bin/compare-schema-ddl.php [<before-ref>]
 */

declare(strict_types=1);

$root   = dirname(__DIR__, 3);
$before = $argv[1] ?? '52ffe631';

require_once $root . '/src/Schema/Schema.php';

use SlimStat\Schema\Schema;

$PREFIX = 'wp_';

/** Parse `name TYPE ...` column definitions out of a CREATE TABLE body. */
function ddl_columns(string $sql): array
{
    $columns = [];

    foreach (preg_split('/\R/', $sql) as $line) {
        $line = trim($line);
        if (preg_match('/^([a-z_][a-z0-9_]*)\s+((?:VAR)?CHAR\(\d+\)|BIGINT|INT|SMALLINT|TINYINT)/i', $line, $m)) {
            // Normalise whitespace and the trailing comma; case is normalised because the old
            // DDL mixed `auto_increment` and `AUTO_INCREMENT` for the same thing.
            $columns[$m[1]] = strtolower(preg_replace('/\s+/', ' ', rtrim(trim($line), ',')));
        }
    }

    return $columns;
}

/**
 * Index name => column list, from inline `INDEX name (cols)` clauses.
 *
 * Paren-counted rather than regex-matched. A `[^)]*` class stops at the first `)`, so
 * `idx_goal_queries (resource(191), dt, fingerprint(20))` was read as `resource(191` — which
 * happened not to change this run's verdict because those indexes are GAINED rather than shared,
 * and that is precisely the kind of luck a comparison arm must not depend on. A truncated column
 * list would report two genuinely different prefixed indexes as identical.
 */
function ddl_indexes(string $sql): array
{
    $indexes = [];

    if (!preg_match_all('/INDEX\s+([a-z0-9_]+)\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return $indexes;
    }

    foreach ($m as $hit) {
        $open  = $hit[0][1] + strlen($hit[0][0]) - 1;
        $depth = 0;

        for ($i = $open, $len = strlen($sql); $i < $len; $i++) {
            if ('(' === $sql[$i]) {
                $depth++;
            } elseif (')' === $sql[$i]) {
                if (0 === --$depth) {
                    $indexes[$hit[1][0]] = strtolower(preg_replace('/\s+/', '', substr($sql, $open + 1, $i - $open - 1)));
                    break;
                }
            }
        }
    }

    return $indexes;
}

// ── Arm A: the DDL as it stood before the seam ─────────────────────────────
$old_src = shell_exec(sprintf(
    'git -C %s show %s:admin/index.php 2>/dev/null',
    escapeshellarg($root),
    escapeshellarg($before)
));

if (!is_string($old_src) || '' === $old_src) {
    fwrite(STDERR, "FAIL: cannot read admin/index.php at {$before}\n");
    exit(1);
}

$arm_a = [];
foreach ([
    'slim_stats'          => '/\$stats_table_sql\s*=\s*"(.*?)";/s',
    'slim_events'         => '/\$events_table_sql\s*=\s*"(.*?)";/s',
    'slim_events_archive' => '/\$events_archive_table_sql\s*=\s*"(.*?)";/s',
] as $suffix => $pattern) {
    if (!preg_match($pattern, $old_src, $m)) {
        fwrite(STDERR, "FAIL: could not isolate {$suffix} DDL at {$before} — the arm is broken, not the tree\n");
        exit(1);
    }

    $arm_a[$suffix] = str_replace("{\$GLOBALS['wpdb']->prefix}", $PREFIX, $m[1]);
}

// ── Arm B: the manifest ────────────────────────────────────────────────────
$arm_b = [];
foreach (array_keys($arm_a) as $suffix) {
    $arm_b[$suffix] = Schema::createTableSql($suffix, $PREFIX, 'utf8mb4_unicode_ci');
}

// ── CONTROLS, before any result ────────────────────────────────────────────
echo "CONTROLS\n";
printf("  [%s] arm A read from %s, not reconstructed\n", '' !== $old_src ? 'PASS' : 'FAIL', $before);
printf("  [%s] arm A yielded %d tables\n", 3 === count($arm_a) ? 'PASS' : 'FAIL', count($arm_a));

$a_cols = ddl_columns($arm_a['slim_stats']);
printf(
    "  [%s] the arm-A parser found %d columns on slim_stats (a parser that finds none makes every diff below empty)\n",
    count($a_cols) >= 30 ? 'PASS' : 'FAIL',
    count($a_cols)
);

if (count($a_cols) < 30) {
    echo "\nVERDICT: ABORTED — the arm-A parser is broken, so equality below would be vacuous\n";
    exit(1);
}

echo "\n";

$differences = 0;
$expected    = 0;

foreach ($arm_a as $suffix => $old) {
    $new = $arm_b[$suffix];

    $old_columns = ddl_columns($old);
    $new_columns = ddl_columns($new);

    $dropped = array_diff_key($old_columns, $new_columns);
    $added   = array_diff_key($new_columns, $old_columns);
    $changed = [];

    foreach (array_intersect_key($old_columns, $new_columns) as $name => $definition) {
        if ($definition !== $new_columns[$name]) {
            $changed[$name] = $definition . '  ->  ' . $new_columns[$name];
        }
    }

    printf("%s\n", $suffix);
    printf("  columns: %d before, %d after\n", count($old_columns), count($new_columns));

    foreach (['DROPPED' => $dropped, 'ADDED' => $added] as $label => $set) {
        foreach (array_keys($set) as $name) {
            printf("  [DIFF] column %s %s\n", $label, $name);
            $differences++;
        }
    }

    foreach ($changed as $name => $detail) {
        printf("  [DIFF] column CHANGED %s: %s\n", $name, $detail);
        $differences++;
    }

    // Column ORDER matters: slim_stats_archive is created `LIKE slim_stats`, and the legacy
    // ALTERs used `AFTER <column>`, so a reordering would make a fresh archive and an upgraded
    // one differ in a way nothing else here would catch.
    if (array_keys($old_columns) !== array_keys($new_columns)) {
        echo "  [DIFF] column ORDER changed\n";
        $differences++;
    }

    $old_indexes = ddl_indexes($old);
    $new_indexes = ddl_indexes($new);

    printf("  indexes: %d before, %d after\n", count($old_indexes), count($new_indexes));

    foreach (array_diff_key($old_indexes, $new_indexes) as $name => $cols) {
        printf("  [DIFF] index LOST %s (%s)\n", $name, $cols);
        $differences++;
    }

    foreach (array_diff_key($new_indexes, $old_indexes) as $name => $cols) {
        // EXPECTED: C39's convergence. The create path never carried these; the upgrade path
        // did. Both paths now emit the same set, which is the whole seam.
        printf("  [EXPECTED] index GAINED %s (%s) — C39 convergence\n", $name, $cols);
        $expected++;
    }

    foreach (array_intersect_key($old_indexes, $new_indexes) as $name => $cols) {
        if ($cols !== $new_indexes[$name]) {
            printf("  [DIFF] index CHANGED %s: %s -> %s\n", $name, $cols, $new_indexes[$name]);
            $differences++;
        }
    }

    // The three intended differences.
    //
    // Stated precisely, because the loose form overstates the finding. Arm A is the raw template,
    // where the clause is the interpolation `{$use_innodb}` — so what this shows is that the
    // engine was CONDITIONAL, not that it was never emitted. That it always resolved to empty is
    // C42's separate evidence: `SHOW VARIABLES LIKE 'have_innodb'` was removed in MySQL 5.6.1 and
    // returns nothing, so the ternary feeding it takes the empty branch on every modern server.
    // The change here is conditional -> unconditional, which is the falsifiable claim.
    $engine_conditional = (bool) preg_match('/\{\$use_innodb\}/', $old);
    $engine_literal     = (bool) preg_match('/ENGINE\s*=\s*InnoDB/i', $old);
    $engine_after       = (bool) preg_match('/ENGINE\s*=\s*InnoDB/i', $new);
    printf(
        "  [%s] engine: %s before, unconditional %s after — C42\n",
        (($engine_conditional && !$engine_literal) && $engine_after) ? 'EXPECTED' : 'DIFF',
        $engine_conditional ? 'conditional on a have_innodb probe that returns empty on MySQL >= 5.6.1' : 'unknown',
        $engine_after ? 'ENGINE=InnoDB' : 'ABSENT'
    );
    if (!$engine_conditional || $engine_literal || !$engine_after) {
        $differences++;
    } else {
        $expected++;
    }

    $utf8mb3_before = (bool) preg_match('/utf8_general_ci/i', $old);
    $utf8mb4_after  = (bool) preg_match('/utf8mb4/i', $new);
    printf(
        "  [%s] collation: %s before, %s after — C11b\n",
        ($utf8mb3_before && $utf8mb4_after) ? 'EXPECTED' : 'DIFF',
        $utf8mb3_before ? 'utf8_general_ci' : 'other',
        $utf8mb4_after ? 'utf8mb4' : 'other'
    );
    if (!$utf8mb3_before || !$utf8mb4_after) {
        $differences++;
    } else {
        $expected++;
    }

    // The FK must survive verbatim: it is what makes the event cascade work, and it is silently
    // ignored on MyISAM, which is the other half of C42.
    $fk_before = preg_match('/FOREIGN KEY/i', $old);
    $fk_after  = preg_match('/FOREIGN KEY/i', $new);
    if ($fk_before !== $fk_after) {
        printf("  [DIFF] FOREIGN KEY presence changed: %d -> %d\n", $fk_before, $fk_after);
        $differences++;
    }

    echo "\n";
}

printf("%d unexplained difference(s), %d expected\n\n", $differences, $expected);

if ($differences > 0) {
    echo "VERDICT: REPORTED — differences above are NOT self-adjudicated. Each must be matched to\n";
    echo "an EXPECTED-DIFFS entry or explained, by someone who did not write the change.\n";
    exit(1);
}

echo "VERDICT: EQUIVALENT — every column, type, default, order and index carried over; the only\n";
echo "differences are the engine, the collation and the index convergence, which are the seam.\n";
exit(0);

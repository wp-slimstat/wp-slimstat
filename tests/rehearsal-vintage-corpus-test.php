<?php
/**
 * Source-level: the vintage rehearsal's version arithmetic, notes transform, index reads,
 * durable verdicts and topology pins (WS4 · H4-H8).
 *
 * WHY THIS EXISTS. tests/rehearsal-column-control-test.php pins the SHAPE question — is this
 * corpus the arm's own vintage. This file pins the five things that had to be built once that
 * question could be answered, and every one of them is a place where a wrong answer looks
 * exactly like a right one:
 *
 *   H4  downgrade-corpus.sh   projects the 5.5 corpus into the vintage's own tables. The
 *                             projection has to be lossless-or-loud: a value that does not fit
 *                             the older column must be an ERROR, because a silent truncation
 *                             produces a corpus that is quietly not the site's data and a cell
 *                             that rehearses an upgrade nobody will take.
 *   H5  the notes transform   the ONE column an upgrade is allowed to rewrite. Dropping it from
 *                             the fingerprint would pass a conversion that emptied it. The
 *                             fingerprint therefore PROJECTS it through the plugin's own forward
 *                             expression before the migration and reads it raw after, so
 *                             equality means the transform was applied correctly.
 *   H6  index_columns         `vid_hash exists` is satisfied by a NULL column with nothing on
 *                             it. The P1 read path needs the composite (vid_hash, dt) in that
 *                             order; reversed, it still reports "index present" and serves no
 *                             range at all. The assertion is the column LIST.
 *   H7  publish_verdict       a cell costs up to 90 minutes and its conclusion was written to
 *                             /tmp. Run 63's two verdicts are gone. The verdict now lands in the
 *                             tracked programme directory, and it carries its subject with it.
 *   H8  rehearsal-cells.tsv   the topology was a DEFAULT that every cell inherited, and a
 *                             default is not a pin: it moves when the default moves, and a
 *                             recorded PASS then describes a topology nobody can reconstruct.
 *
 * The load-bearing check in this file is the byte-equality between what lib.sh RENDERS for the
 * notes transform and what admin/index.php ISSUES. Those are one transform with two writers, and
 * the whole H5 argument — that fingerprint equality means the conversion was correct — collapses
 * the moment they disagree by a character. Nothing else would notice: the cell would compare a
 * projection of one transform against the output of another and call the difference data loss,
 * or worse, agree by accident on the rows in the corpus and not on the rows in the wild.
 *
 * Everything here is source-level or a bash subprocess with mysql_q stubbed: no docker, no
 * database, no network. 7.4-safe: bare PHP, no WordPress, no vendor autoloader.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$lib         = $plugin_root . '/tests/docker/lib.sh';
$rehearse    = $plugin_root . '/tests/docker/rehearse-upgrade.sh';
$downgrade   = $plugin_root . '/tests/docker/downgrade-corpus.sh';
$cells_tsv   = $plugin_root . '/tests/docker/rehearsal-cells.tsv';
$arms_sha    = $plugin_root . '/tests/docker/arms.sha256';
$admin_php   = $plugin_root . '/admin/index.php';
$failures    = [];
$checks      = 0;

/**
 * A shell source with its narration removed: `#` comment lines, and the PHP `/* ... *|/` blocks
 * that live inside the single-quoted `wp eval` payloads.
 *
 * Needed because the sharpest assertions here are ABSENCES — "nothing reads $settings["version"]
 * any more" — and the best comment about a bug quotes the bug. A negative that cannot tell the
 * two apart makes explaining the defect a gate failure, which is a rule that would delete the
 * only record of why the code is shaped this way.
 */
function vc_code_only(string $src): string
{
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $out = [];
    foreach (explode("\n", $src) as $line) {
        if (preg_match('/^\s*#/', $line)) {
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

/** Run one bash snippet with lib.sh loaded. Returns [rc, stdout, stderr]. */
function vc_bash(string $lib, string $body): array
{
    $script = "set -uo pipefail\n" . '. ' . escapeshellarg($lib) . "\n" . $body . "\n";

    $proc = proc_open(
        'bash -s',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return [-1, '', 'could not start bash'];
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), trim($out), trim($err)];
}

/** One value from a bash helper. */
function vc_out(string $lib, string $body): string
{
    [, $out] = vc_bash($lib, $body);

    return $out;
}

$check = function (string $label, bool $ok, string $detail = '') use (&$failures, &$checks) {
    $checks++;
    if ($ok) {
        echo '  ok   ' . $label . "\n";

        return;
    }
    $failures[] = $label . ('' !== $detail ? ' — ' . $detail : '');
    echo '  FAIL ' . $label . ('' !== $detail ? ' — ' . $detail : '') . "\n";
};

$lib_src  = is_file($lib) ? (string) file_get_contents($lib) : '';
$reh_src  = is_file($rehearse) ? (string) file_get_contents($rehearse) : '';
$down_src = is_file($downgrade) ? (string) file_get_contents($downgrade) : '';

echo "SLIMSTAT-REHEARSAL-VINTAGE-CORPUS\n";

// ── H5 · version_lt: the branch every vintage decision hangs off ────────────
// `sort -V` is not portable enough to decide this (BSD sort grew -V late, and this runs on the
// host rather than in the container) and version_compare lives in PHP, so lib.sh does it in awk.
// The case that matters is 4.8.10 vs 4.8.9: a string compare gets it backwards, and the vintages
// this programme installs (4.8.1 … 5.4.12) contain exactly that shape.
echo "\n-- version_lt\n";
$version_cases = [
    ['4.8.1',  '4.8.8',  true,  'the 4.8.1 arm is below the notes conversion'],
    ['4.8.8',  '4.8.8',  false, 'equal is not below'],
    ['4.8.9',  '4.8.10', true,  '9 is below 10 — the case a string compare inverts'],
    ['4.8.10', '4.8.9',  false, 'and 10 is not below 9'],
    ['4.8',    '4.8.1',  true,  'a shorter version is below a longer one that extends it'],
    ['4.8.1',  '4.8',    false, 'and not the other way round'],
    ['5.4.12', '4.8.8',  false, 'the 5.4 arm is at or above the conversion'],
    ['5.1.5',  '4.8.8',  false, 'so is 5.1.5'],
    ['4.8.4.1', '4.8.8', true,  'four components compare component-wise too'],
];
foreach ($version_cases as [$a, $b, $want, $why]) {
    $got = '0' === vc_out($lib, "version_lt '$a' '$b' && echo 0 || echo 1");
    $check("version_lt $a < $b is " . ($want ? 'true' : 'false') . ' — ' . $why, $got === $want);
}

// ── H5 · the transform has ONE writer ──────────────────────────────────────
// admin/index.php::convert_notes_to_brackets() issues the UPDATE. lib.sh renders the same
// expression for the corpus builder's inverse and for the cell's fingerprint projection. If
// those two ever drift, the cell compares a projection of one transform against the output of
// another — and reports the difference as data loss the plugin did not cause. Byte-equality,
// asserted against the plugin source, is the only thing that keeps that from happening quietly.
echo "\n-- the notes transform: one statement, two writers\n";
$forward = vc_out($lib, 'notes_forward notes');
$pending = vc_out($lib, 'notes_pending notes');
$inverse = vc_out($lib, 'notes_inverse notes');
$admin   = is_file($admin_php) ? (string) file_get_contents($admin_php) : '';

$check(
    'lib.sh renders the forward transform admin/index.php actually issues',
    '' !== $forward && false !== strpos($admin, $forward),
    'rendered [' . $forward . ']'
);
// The predicate is compared against its PREPARE-ESCAPED spelling, which is the one difference
// between what lib.sh renders and what the plugin's source contains: the statement goes through
// $wpdb->prepare(), where a literal `%` must be written `%%`. Doubling it here rather than
// stripping it from the source keeps the comparison exact — a collapse of every `%%` in the file
// would also swallow `%d` and make the check pass on a statement that no longer binds anything.
$pending_prepared = str_replace('%', '%%', $pending);
$check(
    'and the predicate that selects the rows it converts',
    '' !== $pending && false !== strpos($admin, $pending_prepared),
    'rendered [' . $pending . '], prepare-escaped [' . $pending_prepared . ']'
);
// The inverse is this harness's own — nothing in the plugin un-brackets — so what is asserted is
// that it inverts the forward one, symbolically: forward(inverse(x)) has to collapse back to a
// REPLACE of the same two tokens in the same two directions.
$check(
    'the inverse strips the outer brackets and turns "][" back into ";"',
    false !== strpos($inverse, "SUBSTRING( notes, 2, CHAR_LENGTH(notes) - 2 )")
        && false !== strpos($inverse, "'][', ';'"),
    'rendered [' . $inverse . ']'
);
$check(
    'the renderers take the column name, so a scratch schema can be projected too',
    false !== strpos(vc_out($lib, 'notes_forward "x.notes"'), 'x.notes')
        && false !== strpos(vc_out($lib, 'notes_inverse "x.notes"'), 'x.notes')
);
// The predicate is what makes the projection SELECTIVE. A forward transform applied to every row
// would double-bracket the rows already converted, and the fingerprint would then be a claim
// about a corpus nobody has.
$check(
    'the predicate excludes rows already in the bracketed form',
    false !== strpos($pending, "NOT LIKE '[%'")
);

// ── H5 · the fingerprint projects, and then stops projecting ───────────────
echo "\n-- the fingerprint's notes projection\n";
$check(
    'the fingerprint template takes the notes expression as a parameter',
    false !== strpos($reh_src, "COALESCE(%s,'~')")
        && false !== strpos($reh_src, 'printf "$FP_SQL_TEMPLATE" "$FP_NOTES_EXPR" "$BASE_MAX_ID"')
);
$check(
    'it is armed only for arms below 4.8.8, from the rendered transform',
    false !== strpos($reh_src, 'version_lt "$ARM_FREE_VERSION" "4.8.8"')
        && false !== strpos($reh_src, 'FP_NOTES_EXPR="IF( $(notes_pending notes), $(notes_forward notes), notes )"')
);
// If it were never disarmed, the AFTER fingerprint would project the already-converted rows a
// second time and every vintage cell would go red on a correct upgrade. Both halves of the
// switch have to be present, and the reset has to come BEFORE the comparison.
$pos_arm     = strpos($reh_src, 'FP_NOTES_EXPR="IF(');
$pos_disarm  = strpos($reh_src, "\nFP_NOTES_EXPR=\"notes\"\n\nFP_1=\$(fingerprint)");
$check(
    'and disarmed before the AFTER fingerprint is taken',
    false !== $pos_arm && false !== $pos_disarm && $pos_arm < $pos_disarm,
    'armed at ' . var_export($pos_arm, true) . ', disarmed at ' . var_export($pos_disarm, true)
);
// Non-vacuity, both ends. A corpus with nothing to convert satisfies the equality trivially.
$check(
    'the cell refuses a corpus with nothing to convert',
    false !== strpos($reh_src, 'check "the corpus gives the 4.8.8 conversion something to convert"')
        && false !== strpos($down_src, 'check "the corpus gives the 4.8.8 conversion something to convert"')
);
$check(
    'and requires the conversion to leave no row in the old form',
    false !== strpos($reh_src, 'check "the 4.8.8 conversion left no row in the old form"')
);
// The eight columns nothing may touch are compared with no projection on either side, so no
// argument about the notes expression can make that comparison pass.
$check(
    'the eight untouchable columns are fingerprinted separately and never projected',
    false !== strpos($reh_src, 'FP_CORE_TEMPLATE=')
        && false === strpos(substr($reh_src, (int) strpos($reh_src, 'FP_CORE_TEMPLATE='), 400), 'notes')
        && false !== strpos($reh_src, 'check "the eight columns nothing may touch are unchanged"')
);

// ── H5 · the code that does the conversion is actually driven ──────────────
// MigrationManager is not the path a 4.8.1 site takes first. The 4.8.2/4.8.4/4.8.4.1/4.8.8
// blocks and the notes conversion live in wp_slimstat_admin::update_tables_and_options(), and a
// cell that only ran MigrationManager rehearsed the second half of an upgrade whose first half
// never happened — with the projection above then reporting corruption in a column nothing had
// touched yet.
echo "\n-- the legacy upgrade path is driven, not assumed\n";
$check(
    'R3 drives wp_slimstat_admin::update_tables_and_options()',
    false !== strpos($reh_src, 'wp_slimstat_admin::update_tables_and_options()')
);
$check(
    'as a user who may run schema DDL, which is what the real path gates on',
    false !== strpos($reh_src, 'wp_set_current_user(1)')
);
// The 4.8.8 block returns FALSE without stamping the version while there is more to convert.
// One call on a 443k-row table therefore means "resume me", not "failed", and asserting a single
// call returns true reads a working resumable upgrade as a broken one.
$check(
    'and LOOPS it, because the notes conversion is resumable by design',
    false !== strpos($reh_src, 'while ($last === false && $passes < 500)')
);
$check(
    'the legacy leg runs BEFORE the MigrationManager leg, as a real site experiences it',
    strpos($reh_src, 'update_tables_and_options()') < strpos($reh_src, '$required = count($m->getRequiredMigrations());')
);

// ── H6 · index_columns / has_index ─────────────────────────────────────────
echo "\n-- index_columns\n";
$idx = static function (string $lib, array $rows) {
    $emit = 'printf ' . escapeshellarg(implode('', array_map(static function ($r) {
        return $r . "\n";
    }, $rows)));

    return vc_out($lib, "mysql_q() { $emit; }\nindex_columns wp_slim_stats idx_vid_hash_dt");
};
$check('the columns come back comma-joined, in the order the server returned them', 'vid_hash,dt' === $idx($lib, ['vid_hash', 'dt']));
// Asserted on the SQL, because a stub cannot tell the orderings apart: the helper returns
// whatever rows it is handed. information_schema.STATISTICS guarantees no row order of its own,
// so ORDER BY SEQ_IN_INDEX is the only thing making "the order the server returned them" mean
// "the order the index is built in" — and ordering by COLUMN_NAME instead would sort `dt` ahead
// of `vid_hash` and make a reversed index read as a correct one.
$check(
    'and the order is the index\'s own, not alphabetical',
    false !== strpos(
        vc_out($lib, "mysql_q() { printf '%s\\n' \"\$1\"; }\nindex_columns t i"),
        'ORDER BY SEQ_IN_INDEX'
    )
);
// The reversed index is the whole reason this reads the LIST rather than asking whether the name
// exists: it is present, it is named correctly, and it does not serve the range on dt.
$check('a reversed index reads back reversed, not equal', 'dt,vid_hash' === $idx($lib, ['dt', 'vid_hash']));
$check('an absent index is empty, not a phantom column', '' === $idx($lib, []));
$check("MySQL's CR and blank lines are stripped", 'vid_hash,dt' === $idx($lib, ["vid_hash\r", '', 'dt']));
$check(
    'has_index is true only when there are columns',
    '0' === vc_out($lib, "mysql_q() { printf 'vid_hash\\ndt\\n'; }\nhas_index t i && echo 0 || echo 1")
        && '1' === vc_out($lib, "mysql_q() { :; }\nhas_index t i && echo 0 || echo 1")
);
// Schema.php is where the composite is declared; the cell asserts that exact list, in that
// order. If the manifest ever changes the order, this check makes the cell change with it
// rather than silently keep asserting the old one.
$schema = (string) @file_get_contents($plugin_root . '/src/Schema/Schema.php');
$check(
    'the cell asserts the composite Schema.php declares',
    1 === preg_match("/'idx_vid_hash_dt'\s*=>\s*'vid_hash, dt'/", $schema)
        && false !== strpos($reh_src, 'if [ "$IDX_COLS" = "vid_hash,dt" ]')
);
$check(
    'and re-asserts it after the rollback',
    false !== strpos($reh_src, 'check "idx_vid_hash_dt survived the rollback intact"')
);

// ── H4 · the projection is lossless-or-loud ────────────────────────────────
echo "\n-- downgrade-corpus.sh\n";
$check('it exists and is executable', is_file($downgrade) && is_executable($downgrade));
// Without STRICT_ALL_TABLES, MySQL truncates a value that does not fit the older, narrower
// column and reports a warning nobody reads — and the corpus is then quietly not the site's data.
$check(
    'the projection runs under STRICT_ALL_TABLES',
    false !== strpos($down_src, "SET SESSION sql_mode='STRICT_ALL_TABLES';")
);
$check(
    'and a failure is reported as a finding about the vintage, not swallowed',
    false !== strpos($down_src, 'This is a finding about $ARM_VERSION, not a harness bug.')
);
// The column list is MEASURED from the arm's own information_schema. Hardcoding it would make
// this script a parser for a version of PHP's heredoc syntax that it cannot see.
$check(
    "the vintage's column list is measured, never hardcoded",
    false !== strpos($down_src, 'a_cols=$(table_columns "$t")')
        && false !== strpos($down_src, 'run_vintage_installer')
        && 0 === preg_match('/\bplugins\b.*\bbrowser\b.*\bcountry\b/', $down_src)
);
// The inverse is NOT total: `[results:a;b]` un-brackets to `results:a;b`, which forwards to
// `[results:a][b]`. Un-bracketing that row would hand the cell a corpus whose migration CANNOT
// reproduce the original, and R3 would then report data loss the plugin did not cause.
$check(
    'the un-bracketing is guarded by the round trip, not applied blindly',
    false !== strpos($down_src, 'AND $(notes_forward "($_inv)") = notes')
        && false !== strpos($down_src, 'CASE WHEN')
);
$check(
    'it refuses a non-vintage arm rather than defaulting to one',
    false !== strpos($down_src, 'downgrade-corpus.sh needs a wp.org:<version> arm')
);
$check(
    'it refuses a post-migration dump as its source',
    false !== strpos($down_src, 'dump_has_v6_columns "$SRC_DUMP"')
);
// The source is hydrated into a SCRATCH schema. Into `wordpress` it would replace the tables the
// arm just built — the exact failure this script exists to prevent, committed by the script that
// prevents it.
$check(
    'the source dump is hydrated into a scratch schema, never the cell\'s own',
    false !== strpos($down_src, 'SRC_SCHEMA="corpus_src"')
        && false !== strpos($down_src, 'import_gz_into_schema "$SRC_DUMP" "$SRC_SCHEMA"')
);
$check(
    'every row is asserted to survive the projection',
    false !== strpos($down_src, 'check "every $t row survived the projection"')
);
// The loop closes: the emitted FILE is re-read with the same helper C1b will use, so a corpus
// that would stall a 90-minute cell fails in this script instead.
$check(
    'the emitted corpus is re-read and checked against the arm, as C1b will',
    false !== strpos($down_src, 'OUT_COLS=$(dump_columns "$OUT" wp_slim_stats)')
        && false !== strpos($down_src, 'this is what C1b will ask')
);

// ── H4 · table_columns takes a schema, and count_columns counts ────────────
echo "\n-- table_columns / count_columns\n";
$q = static function (string $lib, string $call) {
    return vc_out($lib, "mysql_q() { printf '%s\\n' \"\$1\"; }\n" . $call);
};
$check(
    'table_columns defaults to the cell\'s own schema',
    false !== strpos($q($lib, 'table_columns wp_slim_stats'), "TABLE_SCHEMA='wordpress'")
);
$check(
    'and asks the scratch schema when told to',
    false !== strpos($q($lib, 'table_columns wp_slim_stats corpus_src'), "TABLE_SCHEMA='corpus_src'")
);
$check('count_columns counts a set', '3' === vc_out($lib, "count_columns 'a,b,c'"));
// The empty set is the case a `,`-count plus one gets wrong, and it is precisely the case a
// caller is trying to detect: "the arm built no table".
$check('and says 0 for the empty set, not 1', '0' === vc_out($lib, "count_columns ''"));

// ── H7 · the verdict outlives the container ────────────────────────────────
echo "\n-- write_verdict / publish_verdict\n";
$tmp = sys_get_temp_dir() . '/slimstat-vc-' . getmypid();
@mkdir($tmp . '/art', 0777, true);
@mkdir($tmp . '/runs', 0777, true);
vc_bash($lib, 'write_verdict ' . escapeshellarg($tmp . '/art') . ' cell7a 7.4 6.7 PASS "all good" '
    . escapeshellarg('"arm":"wp.org:4.8.1","rows":443543'));
$json    = (string) @file_get_contents($tmp . '/art/cell.json');
$decoded = json_decode($json, true);
$check('the verdict is valid JSON with the extra fragment merged in', is_array($decoded), $json);
$check(
    'and it carries its subject, not just a status',
    is_array($decoded) && 'PASS' === ($decoded['status'] ?? null)
        && 'wp.org:4.8.1' === ($decoded['arm'] ?? null)
        && 443543 === ($decoded['rows'] ?? null)
        && '7.4' === ($decoded['php'] ?? null) && '6.7' === ($decoded['wp'] ?? null)
);
// A quote in the reason would otherwise close the JSON string early and produce a file that
// parses as nothing — from a failure message, which is the moment the verdict matters most.
vc_bash($lib, 'write_verdict ' . escapeshellarg($tmp . '/art') . ' c 7.4 6.7 FAIL '
    . escapeshellarg('the "notes" column moved') . ' ""');
$check(
    'a quote in the reason cannot break the JSON',
    is_array(json_decode((string) @file_get_contents($tmp . '/art/cell.json'), true))
);
// And with no fragment at all, which is what every non-vintage caller passes.
vc_bash($lib, 'write_verdict ' . escapeshellarg($tmp . '/art') . ' c 8.2 6.7 PASS ok');
$check(
    'the fragment is optional — existing callers are unchanged',
    is_array(json_decode((string) @file_get_contents($tmp . '/art/cell.json'), true))
);
[, $dest] = vc_bash(
    $lib,
    'REHEARSAL_RUNS_DIR=' . escapeshellarg($tmp . '/runs') . ' publish_verdict '
        . escapeshellarg($tmp . '/art') . ' upgrade-u1'
);
$check(
    'publish_verdict copies the verdict out of /tmp and names where it went',
    is_file($tmp . '/runs/upgrade-u1/cell.json') && false !== strpos($dest, 'upgrade-u1'),
    $dest
);
$check(
    'the cell publishes its verdict',
    false !== strpos($reh_src, 'publish_verdict "$ART" "$CELL"')
        && false !== strpos($down_src, 'publish_verdict "$ART" "$CELL"')
);
// The default destination is the tracked programme directory. /tmp is where Run 63's verdicts
// went, and they are gone.
$check(
    'and its default destination is the tracked run directory, not /tmp',
    false !== strpos($lib_src, 'runs/run65-rehearsal')
);
array_map('unlink', array_filter([
    $tmp . '/art/cell.json',
    $tmp . '/runs/upgrade-u1/cell.json',
], 'is_file'));
@rmdir($tmp . '/runs/upgrade-u1');
@rmdir($tmp . '/runs');
@rmdir($tmp . '/art');
@rmdir($tmp);

// ── H8 · the topology is a pin ─────────────────────────────────────────────
echo "\n-- rehearsal-cells.tsv\n";
$check('the manifest exists', is_file($cells_tsv));
$rows = [];
foreach (explode("\n", (string) @file_get_contents($cells_tsv)) as $line) {
    if ('' === trim($line) || '#' === substr($line, 0, 1)) {
        continue;
    }
    $rows[] = explode("\t", $line);
}
$check('it declares at least the four vintage cells', count($rows) >= 4, count($rows) . ' rows');
$arms = (string) @file_get_contents($arms_sha);
foreach ($rows as $r) {
    $cell = $r[0] ?? '?';
    // An undeclared pin is the default coming back: the cell would run on whatever
    // rehearse-upgrade.sh happens to default to that month, and the recorded verdict would name
    // a topology that was never chosen.
    $check(
        "cell $cell declares both pins and a reason",
        6 === count($r) && '' !== trim((string) $r[3]) && '' !== trim((string) $r[4]) && '' !== trim((string) $r[5]),
        implode(' | ', $r)
    );
    // A row naming an arm that is not pinned in arms.sha256 is a cell that cannot run:
    // resolve_arm_zip refuses any version absent from that file. Better to learn it here.
    if (0 === strpos((string) ($r[1] ?? ''), 'wp.org:')) {
        $v = substr($r[1], 7);
        $check(
            "and its arm $v is pinned in arms.sha256",
            false !== strpos($arms, 'wp-slimstat.' . $v . '.zip')
        );
    }
}
// The judgement this file records, asserted so it cannot be quietly reverted: a site stuck on a
// 2019 plugin is a site whose core kept auto-updating while its plugins did not, so the 4.8.1
// cell belongs on a MODERN WordPress and the oldest PHP the programme supports.
$c7a = [];
foreach ($rows as $r) {
    if ('7a' === ($r[0] ?? '')) {
        $c7a = $r;
    }
}
$check(
    '7a runs the 4.8.1 arm on modern WordPress, not on 2019 WordPress',
    ['7a', 'wp.org:4.8.1'] === array_slice($c7a, 0, 2) && '6.7' === ($c7a[3] ?? '') && '7.4' === ($c7a[4] ?? ''),
    implode(' | ', $c7a)
);
$check(
    'the cell reads its topology from the manifest, with the environment still able to override',
    false !== strpos($reh_src, 'PHP="${TOPOLOGY_PHP:-${CELL_PHP:-8.2}}"')
        && false !== strpos($reh_src, 'WP="${TOPOLOGY_WP:-${CELL_WP:-6.7}}"')
);
// Keyed off the OLD ref, so a caller states the arm once instead of also naming a cell that has
// to agree with it.
$check(
    'and finds its row from the arm it was given',
    '7a' === vc_out($lib, 'REHEARSAL_CELLS=' . escapeshellarg($cells_tsv) . '; cell_for_ref wp.org:4.8.1')
        && '7.4' === vc_out($lib, 'REHEARSAL_CELLS=' . escapeshellarg($cells_tsv) . '; cell_field 7a 5')
);
// An uncharacterised cell must keep the defaults rather than be blocked by the file that
// characterises the others — this is additive.
$check(
    'a cell with no row is silent, not an error',
    '' === vc_out($lib, 'REHEARSAL_CELLS=' . escapeshellarg($cells_tsv) . '; cell_for_ref wp.org:9.9.9')
        && '' === vc_out($lib, 'REHEARSAL_CELLS=/nonexistent; cell_field 7a 5')
);
$check(
    'the recorded verdict names the topology it was produced on',
    false !== strpos($reh_src, '\\"ws4_cell\\":\\"${CELL_KEY:-unpinned}\\"')
);

// ── The stored version, which is not the merged one (PITFALLS 133) ─────────────────────────
//
// Run 65's first live cell rehearsed a 4.8.1 upgrade in which not one 4.8.x block executed, and
// reported "the legacy upgrade path completed — 1 pass(es), 3.1s" while it happened. The cause is
// one substitution away from invisible: `wp_slimstat::$settings["version"]` is not the stored
// version, it is `array_merge(init_options(), $stored)["version"]`, and init_options() supplies
// the CURRENT release as the default. An arm whose options row has no version key therefore
// introduces itself to its own upgrade path as the newest version there is.
//
// The three checks below pin the fix at each of the three points it has to hold, and the fourth
// pins the PLUGIN-side premise the other three depend on: if init_options() ever stopped
// carrying `version`, or init() stopped merging defaults underneath the stored row, the comments
// in lib.sh and rehearse-upgrade.sh would become a story about code that no longer exists.
$check(
    'the vintage installer leaves the options row its own init_tables only set in memory',
    false !== strpos($lib_src, 'update_option("slimstat_options", wp_slimstat::$settings)')
);
// The arm's own saver cannot be used for it: slimstat_save_options() signs the settings AFTER
// merging defaults over the stored row, so on a request that changed nothing it short-circuits
// and writes nothing. Reaching for it is the obvious repair and it is silently a no-op, which is
// why the negative is asserted rather than left to the comment.
$check(
    'and does not route that through the arm saver, which short-circuits on an unchanged signature',
    false === strpos(vc_code_only($lib_src), 'slimstat_save_options')
);
// Present and absent are different subjects, not a strict/lenient pair: "absent" is the site that
// reproduces PITFALLS 134. A cell that cannot say which one it ran has recorded an ambiguous
// result, so the switch is named in the run output AND carried in the verdict.
$check(
    'the two starting sites are selectable, and the cell records which one it ran',
    false !== strpos($lib_src, 'getenv("REHEARSE_ARM_OPTIONS") !== "absent"')
        && false !== strpos($reh_src, '\\"arm_options\\":\\"${REHEARSE_ARM_OPTIONS:-present}\\"')
);
$check(
    'the absent-options condition reaches Docker and is observed before NEW boot',
    false !== strpos($lib_src, '-e REHEARSE_ARM_OPTIONS=')
        && false !== strpos($reh_src, 'wpc --skip-plugins eval \'delete_option("slimstat_options");\'')
        && false !== strpos($reh_src, '[ "$OPTIONS_BEFORE_NEW" = ABSENT ]')
);
$check(
    'lib.sh can read the STORED version, and reads it from the options row ONLY',
    false !== strpos($lib_src, 'stored_plugin_version()')
        && false !== strpos($lib_src, 'get_option("slimstat_options", [])')
        // No back door. A fallback to $settings when the row has no key restores the whole bug
        // and looks like defensive coding while doing it: the caller gets a version, it is the
        // current one, and the "unstamped" branch that exists to handle exactly this case is
        // stepped over on the way past.
        && false === strpos(vc_code_only($lib_src), 'wp_slimstat::$settings["version"]')
);
// The negative half is the load-bearing one: the fix is not "also read the row", it is "never
// ask $settings this question", and only an absence can say that.
$check(
    'and the cell asks it that way — nothing reads $settings["version"] any more',
    false !== strpos($reh_src, 'LEGACY_FROM=$(stored_plugin_version)')
        && false === strpos(vc_code_only($reh_src), 'wp_slimstat::$settings["version"]')
);
$check(
    'the legacy leg proves it has something to upgrade FROM before claiming it did',
    false !== strpos($reh_src, 'the legacy upgrade path has something to upgrade FROM')
        && false !== strpos($reh_src, "version_compare('\${LEGACY_FROM:-0}', '\${NEW_VERSION:-0}', '<')")
);
$plugin_src = is_file($plugin_root . '/wp-slimstat.php')
    ? (string) file_get_contents($plugin_root . '/wp-slimstat.php')
    : '';
$check(
    'the premise still holds: init_options() defaults `version` to the running release',
    false !== strpos($plugin_src, "'version'                => SLIMSTAT_ANALYTICS_VERSION,")
        && false !== strpos($plugin_src, 'self::$settings = array_merge(self::init_options(), self::$settings);')
);
// C4 is a required-red control, and a control that borrows R1's reading can only be believed on
// a cell that was already green — on cell 7a it announced a fault in the fingerprint's scope
// while the actual fault was two legs upstream.
$check(
    'the C4 control measures against its own baseline, not against R1',
    false !== strpos($reh_src, 'FP_PRE_C4=$(fingerprint)')
        && false !== strpos($reh_src, 'if [ "$FP_BROKEN" != "$FP_PRE_C4" ]; then')
);

// ── What Run 65's SECOND live pass found, once the legacy path was actually driven ─────────
//
// The options-row fix turned nine failures into three, and all three were the instrument rather
// than the upgrade: a control that broke the schema the next leg measured (PITFALLS 135), and a
// rollback leg asserting through an API convention its own arm does not have (PITFALLS 136).

// C4 drops vid_hash and MySQL removes that column from idx_vid_hash_dt. The plugin now
// detects the malformed definition but deliberately declines automatic destructive repair.
// The control owns its mutation, so it must explicitly remove the damaged index before the
// migration can restore it, then check both column and index before the next leg runs.
$check(
    'the C4 control restores the index its own DROP COLUMN mutilated, not just the column',
    false !== strpos($reh_src, 'DROP INDEX idx_vid_hash_dt ON wordpress.wp_slim_stats')
        && false !== strpos($reh_src, 'IDX_COLS_C4=$(index_columns wp_slim_stats idx_vid_hash_dt)')
        && false !== strpos($reh_src, 'and so was the index the drop took with it')
);
$schema_src = is_file($plugin_root . '/src/Schema/Schema.php')
    ? (string) file_get_contents($plugin_root . '/src/Schema/Schema.php')
    : '';
$check(
    'the index probe validates definitions and separates malformed indexes from missing',
    false !== strpos($schema_src, 'elseif (self::indexMatches($rows, $definition))')
        && false !== strpos($schema_src, "\$state['malformed'][] = \$name;")
);

// 4.8.1's slimtrack() is a filter callback: every return hands back $_argument, never an id. A
// probe reading that return could not report success on the oldest arm under any circumstances,
// so R7's tracking check was structurally red. The row is what both arms produce.
$check(
    'a tracked hit is measured by the row it wrote, not by what slimtrack returned',
    false !== strpos($reh_src, '_th_before=$(scalar_q "SELECT COALESCE(MAX(id),0) FROM wordpress.wp_slim_stats;")')
        && false !== strpos($reh_src, 'if [ "${_th_after:-0}" -gt "${_th_before:-0}" ]; then')
        // The negative: no reading of the return value survives anywhere in the cell.
        && false === strpos(vc_code_only($reh_src), 'is_numeric')
);
// And not by looking the marker up either — hit_resource() asserts the marker as a separate
// check, and a probe keyed on `WHERE resource = ...` would leave that check asserting the row
// its own SELECT had chosen.
$check(
    'and not by the marker, which a later check has to be free to test',
    false !== strpos($reh_src, 'hit_resource() { mysql_q "SELECT resource FROM wordpress.wp_slim_stats WHERE id=$1;"')
        && false === strpos($reh_src, 'WHERE resource=')
);
// R7 claims a property survived the rollback. Nothing had ever read that property BEFORE the
// rollback: R2's and R5's hits are both under v6. Without this reading, "the rollback broke
// tracking" and "this arm never tracked here" are the same red.
$check(
    'R1 reads the OLD arm tracking, so R7 compares rather than assumes',
    false !== strpos($reh_src, 'HIT_0=$(track_hit "rehearse-old-code-baseline")')
        && false !== strpos($reh_src, 'the OLD version tracks before anything is migrated')
);
// That control writes a row, and R2's "it landed exactly once" is a delta. A delta still anchored
// to the hydrated count would swallow the control's row — and a delta that swallows one row
// swallows a duplicate.
$vc_hit0     = strpos($reh_src, 'HIT_0=$(track_hit');
$vc_rebase   = strrpos($reh_src, 'ROWS_0=$(stats_rows)');
$vc_rows1    = strpos($reh_src, 'ROWS_1=$(stats_rows)');
$check(
    'and R2 re-baselines its row delta after that hit, not before it',
    false !== $vc_hit0 && false !== $vc_rebase && false !== $vc_rows1
        && $vc_hit0 < $vc_rebase && $vc_rebase < $vc_rows1
        && strpos($reh_src, 'ROWS_0=$(stats_rows)') !== $vc_rebase
);
// "1 recorded" names no step. The finding was already in the container the failing run threw
// away; learning it cost a second 90-second cell.
$check(
    'the degradation check names what it found, not how much of it there was',
    false !== strpos($reh_src, 'DEG_DETAIL=$(wpc eval')
        && false !== strpos($reh_src, '"$DEGRADED recorded — ${DEG_DETAIL:-unreadable}"')
);

// The drift record is DURABLE and is written by init_tables(), so the copy R3 read was the one the
// deferred window wrote — v6 code on a v5 schema, still naming `vid_hash (absent)` about a column
// the migration had just added. A leg that reports a durable record must first make the product
// re-derive it, the way the admin_init pass does on an admin's next page load.
$vc_refresh  = strpos($reh_src, 'wp_slimstat_admin::refresh_column_drift_notice();');
$vc_degraded = strpos($reh_src, 'DEGRADED=$(wpc eval');
$check(
    'an admin_init refresh is driven before the degradation store is read',
    false !== $vc_refresh && false !== $vc_degraded && $vc_refresh < $vc_degraded
        && false !== strpos($reh_src, 'delete_transient(wp_slimstat_admin::COLUMN_DRIFT_CHECK_TRANSIENT);')
);
// And the drift itself is OBSERVED, not read back. Reading the option would print PASS on any arm
// that never drifted, because there is no option to read and nothing looked at a single column.
$check(
    'the drift leg re-observes the schema instead of reading back the record',
    false !== strpos($reh_src, 'DRIFT_NOW=$(wpc eval')
        && false !== strpos($reh_src, 'SlimStat\Schema\Schema::columnDrift(')
        && false !== strpos($reh_src, 'SlimStat\Schema\Schema::requiredColumnDrift($d, SlimStat\Migration\MigrationManager::completedMigrationIds())')
        && false !== strpos($reh_src, 'the upgrade left no column drift behind')
);
// The PLUGIN-side premise that comment rests on: the refresh is a re-derivation of an EXISTING
// record, never a first observation. If it ever starts observing unconditionally, deciding the
// leg from the option would become sound and the paragraph above becomes wrong rather than stale.
$admin_src = is_file($plugin_root . '/admin/index.php')
    ? (string) file_get_contents($plugin_root . '/admin/index.php')
    : '';
$check(
    'the premise still holds: the notice refresh returns early when there is no record',
    false !== strpos($admin_src, '$stored = get_option(self::COLUMN_DRIFT_OPTION, []);')
        && false !== strpos($admin_src, 'if (!is_array($stored) || [] === $stored) {')
);
// Stored against live. This is the check that catches the stale snapshot on its own terms —
// it fails whether or not the drift the record describes has since healed.
$check(
    'the stored record is compared against the live one, so a stale snapshot fails',
    false !== strpos($reh_src, 'DRIFT_STORED=$(wpc eval')
        && false !== strpos($reh_src, '[ "$DRIFT_STORED" = "$DRIFT_NOW" ]')
);

// Every `wpc eval` is its own process, and admin/index.php is loaded on admin requests only. The
// first draft of the refresh named wp_slimstat_admin:: in a process where the class did not exist:
// a fatal, swallowed by 2>/dev/null, which failed the stale-record leg with `stored 'none'` — the
// right colour for the wrong reason. A silent eval is not evidence that it ran.
$check(
    'the refresh eval loads the admin class and reports that it ran',
    false !== strpos($reh_src, 'DRIFT_REFRESHED=$(wpc eval')
        && false !== strpos($reh_src, 'require_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php";
  delete_transient(wp_slimstat_admin::COLUMN_DRIFT_CHECK_TRANSIENT);')
        && false !== strpos($reh_src, '[ "$DRIFT_REFRESHED" = "refreshed" ]')
);
// The stored record is read by the option's literal name, because a read through the class
// constant can fatal and a fatal returns the empty string — indistinguishable from a healed
// record, on the one leg whose whole job is to tell stale from healed. Pinned against the
// constant so the literal cannot drift away from it silently.
$check(
    'the literal the stale-record leg reads is the constant the plugin writes',
    false !== strpos($reh_src, 'get_option("slimstat_schema_column_drift", [])')
        && false !== strpos($admin_src, "const COLUMN_DRIFT_OPTION = 'slimstat_schema_column_drift';")
);

echo "\nSLIMSTAT-REHEARSAL-VINTAGE-CORPUS checks=" . $checks . ' failures=' . count($failures) . "\n";
if ([] !== $failures) {
    fwrite(STDERR, "FAIL: rehearsal vintage corpus\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo 'PASS: rehearsal vintage corpus — ' . $checks . " checks\n";

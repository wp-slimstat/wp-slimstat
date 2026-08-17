<?php
/**
 * SlimStat Analytics — S3: the SQLite export preserves VALUES, proven across two languages.
 *
 * @package wp-slimstat
 * @license GPL-2.0-or-later
 *
 * Copyright (C) 2026 VeronaLabs <info@veronalabs.com>
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * THE CLAIM (assumption A-6): the SQLite export preserves the values, so an oracle reading the
 * export is reading the corpus. Everything downstream rests on it.
 *
 * HOW IT IS PROVEN, AND WHY IT IS NOT CIRCULAR. The export stores TYPED RAW VALUES — strings as
 * BLOB, integers as INTEGER, SQL NULL as NULL — and never ENCODING_V1 tokens. PHP writes the
 * export and computes the chain from the values it wrote; Python opens the export and computes
 * the chain by RE-ENCODING those values from the spec. Equality is then evidence about the
 * export. Had the export stored tokens, equality would prove a token survived a copy, and a
 * value mangled in transit beside its intact token would pass.
 *
 * NO MYSQL. The rows are supplied directly, which is what lets this run on every CI lane rather
 * than only inside a container. The MySQL half — that `slimstat_fp2_row_sql()` evaluated by the
 * server produces the same bytes as `slimstat_fp2_encode_row()` in PHP — is proven separately by
 * tests/docker/verify-sql-encoder.php, which needs a live server.
 *
 * SO BE PRECISE ABOUT WHAT THIS FILE PROVES: PHP-over-supplied-rows against
 * Python-over-the-export. MySQL does not participate in THIS comparison. The end-to-end claim —
 * a fingerprint taken over MySQL equalling one taken over the export of that same corpus — needs
 * a live server and is the remaining half of S3.
 *
 * The corpus below is adversarial on purpose: every row is a value that has broken something in
 * this programme or is one type-coercion away from doing so.
 */

require_once __DIR__ . '/bench/lib/export-snapshot.php';

$failures = [];
$passed   = 0;

$check = function ($label, $got, $want) use (&$failures, &$passed) {
    if ($got === $want) {
        $passed++;
        return;
    }
    $failures[] = sprintf("%s\n      got  %s\n      want %s", $label, var_export($got, true), var_export($want, true));
};

// A stand-in table whose columns cover every rule ENCODING_V1 has, using the real declared types
// from the manifest so the shapes are the ones that actually ship.
$columns = [
    ['id',         'INT UNSIGNED',     false],
    ['ip',         'VARCHAR(39)',      true],
    ['tz_offset',  'SMALLINT',         true],
    ['content_id', 'BIGINT UNSIGNED',  true],
    ['browser',    'VARCHAR(40)',      true],
    ['type',       'TINYINT UNSIGNED', true],
];

$rows = [
    // NULL vs empty string vs '0' — the distinction the CRC32 probe layer loses entirely.
    [1, null,        null,   null,                   '',        null],
    [2, '',          0,      0,                      '0',       0],
    // 4-byte UTF-8, and a byte sequence that is NOT valid UTF-8. A TEXT column would be entitled
    // to transcode the first and mangle the second; BLOB is why neither happens.
    // The BIGINT UNSIGNED maximum is a STRING here, and that is not incidental: written as a
    // bare PHP literal it becomes a float (1.8446744073709552E+19) and the encoder rightly
    // refuses it rather than hashing a rounded value. MySQL hands these back as strings anyway.
    // Third appearance of this hazard — json_decode in the S2 gate, PHP literals here.
    [3, "\u{00E9}\u{1D11E}", -720, '18446744073709551615', "\xC3\x28\xFF", 255],
    // Leading zeros and a trailing space: '007' compared as a number is 7, and MySQL's
    // non-binary comparison ignores trailing spaces. The encoding must keep both.
    [4, '007',       -1,     1,                      'x ',      1],
    // The signed-64-bit boundary, on both sides. 9223372036854775808 is one past PHP_INT_MAX and
    // past SQLite's INTEGER: it is why BIGINT UNSIGNED is stored as bytes, not narrowed.
    [5, '203.0.113.7', 32767, '9223372036854775808', 'Chrome',  0],
    [6, '::1',       -32768, '9223372036854775807',  'Firefox', 254],
];

$tmp = sys_get_temp_dir() . '/slimstat-export-fidelity-' . getmypid() . '.sqlite';
@unlink($tmp);

try {
    $sqlite = slimstat_fp2_export_open($tmp, 'wp_');
    $php    = slimstat_fp2_export_table($sqlite, 'slim_stats', $columns, 'id', $rows);
    $sqlite->close();

    $check('the export wrote every row', $php['rows'], count($rows));

    // ── The STORAGE CLASS is asserted, because the read path cannot see it ────────────────
    // This block exists because its absence was a vacuous guard. The spec requires strings as
    // BLOB so SQLite can never reinterpret the bytes — but the Python reader sets
    // `text_factory = bytes`, which returns TEXT and BLOB identically. A mutation storing
    // strings as SQLITE3_TEXT therefore PASSED the whole cross-language comparison: the BLOB
    // rule was written, documented, and proven by nothing. `typeof()` is what can tell them
    // apart, so the claim is made where it is decidable.
    $verify = new SQLite3($tmp, SQLITE3_OPEN_READONLY);
    $classes = $verify->querySingle(
        'SELECT typeof("ip") AS s, typeof("id") AS i, typeof("content_id") AS w FROM "slim_stats" WHERE "id" = 5',
        true
    );
    $check('strings are stored as BLOB, never TEXT', $classes['s'], 'blob');
    $check('narrow integers are stored as INTEGER', $classes['i'], 'integer');
    // BIGINT UNSIGNED past 2^63-1 does not fit SQLite's signed INTEGER, so it is stored as the
    // raw bytes of its decimal rendering. Storing it as INTEGER would silently narrow it — the
    // json_decode defect from S2, one layer down.
    $check('integers wider than SQLite INTEGER are stored as bytes', $classes['w'], 'blob');
    $nulls = $verify->querySingle('SELECT typeof("ip") AS s FROM "slim_stats" WHERE "id" = 1', true);
    $check('SQL NULL is stored as NULL, not as an empty string', $nulls['s'], 'null');
    $verify->close();

    // ── The cross-language half ───────────────────────────────────────────────────────────
    $py   = escapeshellarg(__DIR__ . '/oracle/read_export_cli.py');
    $read = function ($path) use ($py) {
        $raw = (string) shell_exec('python3 ' . $py . ' ' . escapeshellarg($path) . ' slim_stats id 2>&1');
        return [json_decode($raw, true), $raw];
    };
    list($decoded, $out) = $read($tmp);

    if (!is_array($decoded) || !isset($decoded['chained_hash'])) {
        $failures[] = "the Python reader produced no result\n      output: " . trim((string) $out);
    } else {
        $check('rows agree across languages', $decoded['rows'], $php['rows']);
        $check('MANIFEST HASH agrees across languages', $decoded['manifest_hash'], $php['manifest_hash']);
        // The one that matters: PHP encoded the values it wrote, Python re-encoded the values it
        // read, and the two chains are the same bytes.
        $check('CHAINED HASH agrees across languages', $decoded['chained_hash'], $php['chained_hash']);
        // The field that exists so ENCODING_V2 cannot be mistaken for ENCODING_V1. Both sides
        // emit it; comparing only the other three would leave the version claim unchecked.
        $check('SPEC agrees across languages', $decoded['spec'], $php['spec']);
    }

    // ── The gate must be able to fail: a corrupted export must NOT match ──────────────────
    // Without this the equality above could be two implementations agreeing on nothing.
    $poisoned = sys_get_temp_dir() . '/slimstat-export-poisoned-' . getmypid() . '.sqlite';
    @unlink($poisoned);
    copy($tmp, $poisoned);
    $p = new SQLite3($poisoned);
    // Flip ONE byte of ONE value: the trailing space becomes a non-breaking space. A TEXT-affinity
    // export, or a reader that decoded and re-encoded, could easily miss this.
    $p->exec('UPDATE "slim_stats" SET "browser" = CAST(\'x\' || char(160) AS BLOB) WHERE "id" = 4');
    $p->close();
    list($decoded2, ) = $read($poisoned);
    $check(
        'a one-byte change in the export MOVES the fingerprint',
        is_array($decoded2) && isset($decoded2['chained_hash']) && $decoded2['chained_hash'] !== $php['chained_hash'],
        true
    );
    @unlink($poisoned);

    // ── Refusals ──────────────────────────────────────────────────────────────────────────
    try {
        slimstat_fp2_export_open($tmp, 'wp_');
        $check('exporting over an existing file throws', 'no exception', 'RuntimeException');
    } catch (RuntimeException $e) {
        $passed++;
    }
} finally {
    @unlink($tmp);
}

// ── The pinned set is DERIVED, not transcribed ────────────────────────────────────────────
$stats  = slimstat_fp2_pinned_columns('slim_stats');
$events = slimstat_fp2_pinned_columns('slim_events');
$names  = array_column($stats, 0);

$check('slim_stats pins the v5-era set', count($stats), 33);
$check('slim_events pins its whole set', count($events), 7);
foreach (slimstat_fp2_v6_added_columns() as $v6) {
    $check("the v6-added {$v6} is EXCLUDED by pinning", in_array($v6, $names, true), false);
}
// Every pinned type must be one ENCODING_V1 has a rule for. This is the assertion that would
// fire if a future migration added, say, a DATETIME to the pinned set: the spec deliberately
// writes no rule for it, so the encoder throws rather than guessing, and it must throw HERE
// rather than mid-capture.
foreach (array_merge($stats, $events) as $col) {
    try {
        slimstat_fp2_kind($col[1]);
        $passed++;
    } catch (InvalidArgumentException $e) {
        $failures[] = "pinned column {$col[0]} has type {$col[1]}, which ENCODING_V1 has no rule for";
    }
}
// NOTE: "the exclusion list still names a real column" is NOT asserted here. It cannot fail —
// slimstat_fp2_pinned_columns() above throws on exactly that, and it is called outside the
// try/finally, so a failure is an uncaught fatal and this line is unreachable. Two phantom
// passes inflating the count is worse than no assertion.
// event_id is declared INT(10) — display width in the manifest itself, which is exactly why the
// manifest hash canonicalises. Pin that the derived set carries the raw declaration through.
$event_id_type = $events[0][1];
$check('the derived set carries declared display width through', $event_id_type, 'INT(10)');
// (That the manifest hash is BLIND to display width is gated over every pair in
// tests/fingerprint-v2-encoding-test.php; repeating it here would be a fifth copy.)

// A counter nothing checks is decoration: without this, deleting assertions leaves the gate
// printing PASS with a smaller number nobody reads. Both sibling gates carry the same floor.
$expected_assertions = 56;
if ($passed + count($failures) !== $expected_assertions) {
    $failures[] = sprintf(
        'assertion floor — ran %d, expected %d. Update the floor deliberately when adding or '
        . 'removing an assertion; never let it drift.',
        $passed + count($failures),
        $expected_assertions
    );
}

if ($failures) {
    fwrite(STDERR, "FAIL: export fidelity (" . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "PASS: export fidelity — {$passed} assertions (PHP wrote the values, Python re-encoded them, "
    . "and the chains agree; a one-byte change moves the hash)\n";

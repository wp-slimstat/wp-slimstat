<?php
/**
 * SlimStat Analytics — ENCODING_V1 golden-fixture gate (PHP half).
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
 * The PHP encoder against tests/oracle/golden-encoding-fixtures.json — the SAME fixture file
 * the Python encoder is held to (tests/oracle/encoding_v1_test.py).
 *
 * That shared fixture is the entire point. Two encoders written from one spec, checked against
 * expectations that were HAND-DERIVED from that spec rather than captured from either
 * implementation's output. A fixture blessed from an implementation proves only that the
 * implementation is self-consistent, and S3's export-fidelity claim would then be circular: it
 * would show a token survived a copy, not that the data did.
 */

require_once __DIR__ . '/bench/lib/fingerprint-v2.php';

$fixtures_path = __DIR__ . '/oracle/golden-encoding-fixtures.json';
// JSON_BIGINT_AS_STRING is load-bearing, and it cost a red run to learn: without it PHP decodes
// the BIGINT UNSIGNED maximum (18446744073709551615) as a FLOAT, and the nearest double is
// 18446744073709551616 — the fixture came back one larger than it was written. Python's json
// module has arbitrary-precision ints, so the same fixture passed there and failed here, which
// is precisely the kind of cross-language disagreement two independent encoders exist to
// surface. Had the fixture been blessed from either implementation's output, both sides would
// have agreed on a wrong number.
$fixtures = json_decode((string) file_get_contents($fixtures_path), true, 512, JSON_BIGINT_AS_STRING);
if (!is_array($fixtures) || empty($fixtures['field_cases'])) {
    fwrite(STDERR, "FAIL: cannot read {$fixtures_path}\n");
    exit(1);
}

$passed = 0;
$failed = 0;

$check = function ($label, $got, $want) use (&$passed, &$failed) {
    if ($got === $want) {
        $passed++;
        return;
    }
    $failed++;
    fwrite(STDERR, sprintf(
        "FAIL: %s\n      got  %s\n      want %s\n",
        $label,
        var_export($got, true),
        var_export($want, true)
    ));
};

foreach ($fixtures['field_cases'] as $case) {
    $values = [];
    $types  = [];
    foreach ($case['columns'] as $column) {
        // With JSON_BIGINT_AS_STRING above, oversized integers already arrive as strings. Only
        // ordinary ints need rendering; a float reaching here means the guard was removed, and
        // it fails loudly rather than silently encoding a rounded value.
        $value = $column['value'];
        if (is_float($value)) {
            fwrite(STDERR, "FAIL: fixture value decoded as float — JSON_BIGINT_AS_STRING lost\n");
            exit(1);
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        $values[] = $value;
        $types[]  = $column['type'];
    }
    $check($case['name'] . ' row encoding', slimstat_fp2_encode_row($values, $types), $case['row_encoding']);
    $check(
        $case['name'] . ' chained hash',
        slimstat_fp2_chain([$case['row_encoding']]),
        $case['chained_hash_single_row']
    );
}

$order = $fixtures['order_dependence'];
$check('chain in declared order', slimstat_fp2_chain($order['rows_in_order']), $order['chained_hash']);
$check('chain reversed', slimstat_fp2_chain(array_reverse($order['rows_in_order'])), $order['chained_hash_reversed']);
// Asserted, not implied by two hashes printed side by side.
$check('order actually changes the hash', $order['chained_hash'] !== $order['chained_hash_reversed'], true);

$empty = $fixtures['empty_table'];
$check('empty table hashes to the seed alone', slimstat_fp2_chain([]), $empty['chained_hash']);
$check(
    'empty table is NOT sha256 of nothing',
    slimstat_fp2_chain([]) !== $empty['sha256_of_empty_string_for_contrast'],
    true
);

$manifest = $fixtures['manifest'];
// One definition of the `name|type|NULL` grammar. It was parsed in two places once the 5.6
// case landed, which means a fixture-format change has two edit sites and the second copy
// would go on silently parsing under the old rule.
$to_columns = function (array $lines) {
    $columns = [];
    foreach ($lines as $line) {
        list($name, $type, $nullable) = explode('|', $line);
        $columns[] = [$name, $type, 'NULL' === $nullable];
    }
    return $columns;
};
$columns = $to_columns($manifest['lines']);
$check('manifest hash', slimstat_fp2_manifest_hash($columns, $manifest['order_by']), $manifest['manifest_hash']);

$widened = [];
foreach ($columns as $column) {
    $widened[] = [$column[0], 'ip' === $column[0] ? 'varchar(45)' : $column[1], $column[2]];
}
$check(
    'a widened type moves the manifest hash',
    slimstat_fp2_manifest_hash($widened, $manifest['order_by']),
    $manifest['manifest_hash_if_ip_widened_to_varchar_45']
);
$check(
    'a different ORDER BY moves the manifest hash',
    slimstat_fp2_manifest_hash($columns, 'dt'),
    $manifest['manifest_hash_if_ordered_by_dt']
);
// The SAME schema as MySQL 5.6 spells it must hash the SAME. Non-vacuous: every other manifest
// line here is 8.0 spelling — see tests/mutations/S2-manifest-hashes-raw-type-01.
$check(
    'the 5.6 spelling of one schema hashes the same as the 8.0 spelling',
    slimstat_fp2_manifest_hash($to_columns($manifest['lines_as_mysql_56_spells_them']), $manifest['order_by']),
    $manifest['manifest_hash']
);
// ...and the same property across EVERY pair, not just the one hand-written line above. The
// single 5.6 line proves only that manifest_hash canonicalises SOMEHOW; a partial
// canonicalisation — dropping int width but skipping the lowercase/whitespace normalisation
// canonical_type() also performs — passes it while `INT UNSIGNED` still hashes differently from
// `int unsigned`. This asserts what actually matters: that manifest_hash COMPOSES with
// canonical_type, over the pair list that already covers the class.
foreach ($fixtures['canonical_type']['same_after_canonicalisation'] as $pair) {
    $check(
        "manifest_hash is blind to {$pair[0]} vs {$pair[1]}",
        slimstat_fp2_manifest_hash([['c', $pair[0], true]], 'c'),
        slimstat_fp2_manifest_hash([['c', $pair[1], true]], 'c')
    );
}
foreach ($fixtures['canonical_type']['different_after_canonicalisation'] as $pair) {
    $check(
        "manifest_hash still separates {$pair[0]} from {$pair[1]}",
        slimstat_fp2_manifest_hash([['c', $pair[0], true]], 'c')
            !== slimstat_fp2_manifest_hash([['c', $pair[1], true]], 'c'),
        true
    );
}

// Routed through $check rather than bumping the counter directly: the assertions proving the
// "fail loudly" rule is a property of the code — and not a sentence in a document — must not be
// the ones the counter does not own.
$check_throws = function ($label, callable $fn) use ($check) {
    try {
        $fn();
        $check($label, 'no exception', 'InvalidArgumentException');
    } catch (InvalidArgumentException $e) {
        $check($label, 'InvalidArgumentException', 'InvalidArgumentException');
    }
};

// The type table comes from the SHARED fixture, not a constant copied into each language. It is
// the one thing both encoders must agree on that a value-based fixture cannot see: add 'float'
// to one side's accept-list alone and every value assertion still passes, which is the silent
// fallback the raise exists to prevent, relocated one level up.
foreach ($fixtures['type_kinds'] as $declared => $want_kind) {
    $check("kind of {$declared}", slimstat_fp2_kind($declared), $want_kind);
}
foreach ($fixtures['types_that_must_raise'] as $declared) {
    $check_throws("{$declared} must throw", function () use ($declared) {
        slimstat_fp2_encode_field(1, $declared);
    });
    // A NULL in an unsupported column is still unsupported; returning \NUL early would hide it.
    $check_throws("{$declared} must throw even for NULL", function () use ($declared) {
        slimstat_fp2_encode_field(null, $declared);
    });
}

// Integer DISPLAY WIDTH is not schema identity — 8.0.19 removed it, so one schema reports two
// spellings across the 5.6/5.7/8.0 cells run-rollup-floor.sh compares, and hashing the raw
// SHOW COLUMNS string would report drift where there is none. String length IS identity.
foreach ($fixtures['canonical_type']['same_after_canonicalisation'] as $pair) {
    $check(
        "canonical_type({$pair[0]}) == canonical_type({$pair[1]})",
        slimstat_fp2_canonical_type($pair[0]),
        slimstat_fp2_canonical_type($pair[1])
    );
}
foreach ($fixtures['canonical_type']['different_after_canonicalisation'] as $pair) {
    $check(
        "canonical_type({$pair[0]}) != canonical_type({$pair[1]})",
        slimstat_fp2_canonical_type($pair[0]) !== slimstat_fp2_canonical_type($pair[1]),
        true
    );
}

// SHAPE ONLY, AND THAT IS A WEAK CHECK — said plainly rather than left to be discovered.
// These three assert that a builder ten lines away emitted the literals it was written to
// emit. They cannot fail for anything that actually matters: MySQL's HEX/CAST semantics,
// sql_mode, the connection charset, CHAR_LENGTH versus strlen — none of which are visible in a
// string. The real gate is a container run that feeds the SAME rows through the SQL path and
// the pure-PHP path and compares the bytes. THAT CHECK DOES NOT EXIST YET; S3 owns it. Until
// it lands, the SQL path is unproven and no fingerprint taken through it should be cited.
$sql_int = slimstat_fp2_row_sql([['visit_id', 'int unsigned']]);
$sql_str = slimstat_fp2_row_sql([['ip', 'varchar(39)']]);
$check('int columns cast to CHAR before HEX', false !== strpos($sql_int, 'HEX(CAST(`visit_id` AS CHAR))'), true);
$check('string columns cast to BINARY before HEX', false !== strpos($sql_str, 'HEX(CAST(`ip` AS BINARY))'), true);
$check('NULL is tested before the empty-string case', strpos($sql_str, 'IS NULL') < strpos($sql_str, "= ''"), true);
// CONCAT, never CONCAT_WS. CONCAT_WS is the exact function the spec indicts for silently
// skipping NULLs — using it as the joiner would mean a dropped or reordered NULL guard yields a
// row with one fewer field and no error: a silently shorter encoding, hashed as if correct.
$check('the row joiner is NULL-propagating CONCAT', false === strpos($sql_str, 'CONCAT_WS'), true);

$expected = $fixtures['expected_assertions']['php'];
if ($passed + $failed !== $expected) {
    fwrite(STDERR, sprintf(
        "FAIL: assertion floor — ran %d, fixture declares %d. A shrunk fixture must not print green.\n",
        $passed + $failed,
        $expected
    ));
    $failed++;
}

if ($failed) {
    fwrite(STDERR, "FAIL: fingerprint v2 encoding — {$failed} failed, {$passed} passed\n");
    exit(1);
}
echo "PASS: fingerprint v2 ENCODING_V1 — {$passed} assertions against the hand-derived golden fixtures\n";

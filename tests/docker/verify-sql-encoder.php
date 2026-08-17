<?php
// Does MySQL's evaluation of slimstat_fp2_row_sql() produce the same BYTES as the pure-PHP
// slimstat_fp2_encode_row()?
//
//   wp eval-file tests/docker/verify-sql-encoder.php
//
// WHY THIS FILE EXISTS. S2 shipped two encoder implementations proven against hand-derived
// fixtures — but both of those are PHP-side or Python-side *value* encoders. The SQL builder was
// checked only at the SHAPE level: three assertions that it emitted the literals it was written
// to emit, which cannot fail for anything that matters. HEX/CAST semantics, sql_mode, the
// connection charset, CHAR_LENGTH versus strlen — none of them are visible in a string. So the
// fingerprint's fastest path, the one that will actually run over 443k rows, was the one nothing
// had executed. S2 recorded that debt; this pays it.
//
// READ-ONLY BY CONSTRUCTION. The corpus is built as a derived table of literals inside a single
// SELECT — no CREATE, no TEMPORARY TABLE, no INSERT. It can be pointed at a production database
// without touching it, which matters because the local install holds the only copy of the parity
// dataset.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file.

require_once dirname(__DIR__) . '/bench/lib/fingerprint-v2.php';

$db = (class_exists('wp_slimstat') && wp_slimstat::$wpdb instanceof wpdb) ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];

// ONE declaration for both halves. Transcribed twice — once for the PHP encoder, once inside
// the slimstat_fp2_row_sql() call — the two sides can be handed DIFFERENT declared types and
// still compare equal on these particular values, which is the drift this whole file exists to
// rule out one layer down.
$cols  = [['v', 'VARCHAR(64)'], ['n', 'INT'], ['b', 'BIGINT UNSIGNED']];
$types = array_column($cols, 1);

// Every row is a value that has broken something in this programme, or is one coercion away.
$rows  = [
    [null, null, null],                                   // NULL is not empty, in three columns
    ['', 0, 0],                                           // '' vs 0 vs the NULLs above
    ['0', -720, '18446744073709551615'],                  // string '0' == int 0 by design; BIGINT max
    ['007', 2147483647, '9223372036854775808'],           // leading zeros; INT max; past 2^63-1
    ['x ', -2147483648, 1],                               // trailing space; INT min
    ["\u{00E9}\u{1D11E}", 1, 0],                          // 2-byte and 4-byte UTF-8
    ["\xC3\x28\xFF", 1, 0],                               // NOT valid UTF-8 — bytes must survive
];

$select = [];
foreach ($rows as $i => $row) {
    if (null === $row[0]) {
        $v = 'NULL';
    } elseif ('' === $row[0]) {
        $v = "_binary ''";          // `_binary 0x` with no digits is a syntax error
    } else {
        $v = '_binary 0x' . bin2hex($row[0]);
    }
    $n = null === $row[1] ? 'NULL' : (int) $row[1];
    $b = null === $row[2] ? 'NULL' : 'CAST(' . $row[2] . ' AS UNSIGNED)';
    $select[] = "SELECT {$i} AS i, {$v} AS v, {$n} AS n, {$b} AS b";
}

$row_sql = slimstat_fp2_row_sql($cols);
$query   = 'SELECT ' . $row_sql . ' AS e FROM (' . implode(' UNION ALL ', $select) . ') t ORDER BY i';

$from_mysql = $db->get_col($query);
$failures   = [];

if (count($from_mysql) !== count($rows)) {
    $failures[] = sprintf('MySQL returned %d rows, expected %d: %s', count($from_mysql), count($rows), $db->last_error);
}

foreach ($rows as $i => $row) {
    if (!isset($from_mysql[$i])) {
        continue;
    }
    $php = slimstat_fp2_encode_row($row, $types);
    if ($php !== $from_mysql[$i]) {
        $failures[] = sprintf("row %d encodes differently\n    php   %s\n    mysql %s", $i, $php, $from_mysql[$i]);
    }
}

// The control. Two encoders that both returned nothing would agree perfectly, so prove the
// comparison could have failed: one deliberately wrong expectation must be caught.
$poisoned = slimstat_fp2_encode_row(['x', 1, 0], $types);
if (isset($from_mysql[0]) && $poisoned === $from_mysql[0]) {
    $failures[] = 'CONTROL FAILED: a deliberately wrong encoding compared equal — the comparison proves nothing';
}

// sql_mode is part of the claim, not context: NO_BACKSLASH_ESCAPES changes what '\\NUL' means to
// the server, so the tokens would diverge silently.
$mode = (string) $db->get_var('SELECT @@SESSION.sql_mode');
if (false !== stripos($mode, 'NO_BACKSLASH_ESCAPES')) {
    $failures[] = 'NO_BACKSLASH_ESCAPES is set; the sentinel tokens cannot round-trip. sql_mode: ' . $mode;
}

printf("SLIMSTAT-SQL-ENCODER server=%s charset=%s rows=%d\n",
    $db->get_var('SELECT VERSION()'),
    $db->get_var("SELECT @@SESSION.character_set_connection"),
    count($from_mysql));

if ($failures) {
    fwrite(STDERR, "FAIL: the SQL path and the PHP path disagree (" . count($failures) . ")\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "PASS: SQL path == PHP path over " . count($rows) . " adversarial rows (NULL, '', '0', leading "
    . "zeros, trailing space, 4-byte UTF-8, invalid UTF-8, INT bounds, BIGINT UNSIGNED past 2^63)\n";

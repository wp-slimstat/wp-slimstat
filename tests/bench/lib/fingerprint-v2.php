<?php
// ENCODING_V1 / fingerprint v2 — the PHP half, written from tests/oracle/encoding-spec.md.
//
// This is the DATA-identity fingerprint. tests/bench/lib/fingerprint.php is the ENVIRONMENT
// fingerprint and answers a different question ("are these two results comparable at all");
// the names are close and the claims are not, so neither file should be reached for by
// analogy with the other.
//
// Two layers, and the difference matters:
//
//   * slimstat_fp2_encode_field() / _row() — pure PHP, no database. This is what the golden
//     fixtures gate, and what proves the PHP side agrees with the Python side of the spec.
//   * slimstat_fp2_row_sql() — builds the SQL expression MySQL evaluates, so the real chain
//     streams from the server without a GROUP_CONCAT length ceiling.
//
// The two must produce identical tokens. That is not assumed: the container-level check feeds
// the same rows through both and compares (H2/H3).
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file, where a declare()
// is not the first statement of the script and fatals. PHP 7.4 floor — no PHP 8 syntax.

if (!function_exists('slimstat_fp2_encode_field')) {

    define('SLIMSTAT_FP2_SPEC', 'ENCODING_V1');

    /**
     * The pinned set is integers and varchars. Anything else RAISES — see below.
     *
     * The lists are EXACTLY the spec's pinned set and no wider. An earlier version also
     * accepted `mediumint`, `integer`, `char` and `text`, none of which the spec authorises,
     * none of which any fixture exercises, and none of which the real schema declares — which
     * contradicted the comment three lines below it. Widening the accept-list widens the only
     * safety property this function has: a TEXT column would have sailed through the guard the
     * spec says must stop it.
     */
    function slimstat_fp2_kind($declared_type)
    {
        // MEMOISED, and the numbers are why: the export routes every cell through
        // encode_field(), which calls this on the SAME 16 type strings for every row — 14.6M
        // calls over a 443k-row corpus, measured at 126 ns each, 1.84 s and 23% of the export.
        // The keys are declared types, so the cache is bounded by the schema, not the corpus.
        // The raise-on-unknown-type property is untouched: only successful lookups are stored,
        // so an unrecognised type raises every time it is asked for.
        static $memo = [];
        if (isset($memo[$declared_type])) {
            return $memo[$declared_type];
        }

        $base = strtolower(trim((string) $declared_type));
        $base = preg_replace('/[\s(].*$/', '', $base);   // 'int unsigned' / 'varchar(39)' -> base
        $ints = ['tinyint', 'smallint', 'int', 'bigint'];
        $strs = ['varchar'];
        if (in_array($base, $ints, true)) {
            $kind = 'int';
        } elseif (in_array($base, $strs, true)) {
            $kind = 'str';
        } else {
            // Deliberately fatal rather than a default branch. A silent fallback is how a
            // column's meaning changes without any hash changing — which is the one thing a
            // fingerprint exists to make impossible. Adding a type means adding a golden fixture
            // that exercises it; an unexercised branch is one nobody has tested.
            //
            // This throws BEFORE the single cache-write below, which is what keeps the failure
            // path uncached: the memo is written on exactly one line, so "only successes are
            // stored" is visible rather than a property of two branches agreeing.
            throw new InvalidArgumentException(
                "ENCODING_V1 has no rule for type '{$declared_type}'. The pinned set is integers "
                . "and varchars only; add a golden fixture with the rule."
            );
        }

        return $memo[$declared_type] = $kind;
    }

    /**
     * MySQL's CAST(col AS CHAR) renders an integer canonically: no leading zeros, no '+',
     * and '-0' does not occur. PHP ints are 64-bit signed and BIGINT UNSIGNED overflows them,
     * so the normalisation is done on the STRING and never through (int).
     */
    function slimstat_fp2_canonical_int($value)
    {
        // `0*` is greedy but `[0-9]+` must keep one digit, so all-zero input canonicalises to
        // '0'. Everything stays on the STRING — no (int) anywhere, which is what keeps the
        // BIGINT UNSIGNED ceiling reachable. var_export prints the UNTRIMMED value, so a
        // failure caused by an embedded space does not render in the message as though clean.
        if (!preg_match('/^([+-]?)0*([0-9]+)$/', trim((string) $value), $m)) {
            throw new InvalidArgumentException('not an integer rendering: ' . var_export($value, true));
        }
        return ('-' === $m[1] && '0' !== $m[2]) ? '-' . $m[2] : $m[2];   // -0 is 0
    }

    /**
     * Canonicalise a DECLARED TYPE for the manifest hash.
     *
     * MySQL 8.0.19 removed integer display width, so the same column reports `int unsigned`
     * on 8.x and `int(10) unsigned` on the 5.6 floor. Hashing the raw SHOW COLUMNS string
     * would therefore make the SCHEMA-identity half of the fingerprint read as drift between
     * two servers holding an identical schema — on `run-rollup-floor.sh`, which runs one corpus
     * across 8.0, 5.7 and 5.6, every pinned integer column would trip it.
     *
     * This is the rule `Schema::charLength()` already documents and applies for the same
     * reason: char/varchar keep their declared length on every server in ADR-2's range,
     * integer display widths do not. Width is dropped for integers and KEPT for strings, where
     * a narrowed column is real (a truncating varchar is data loss, and must move the hash).
     */
    function slimstat_fp2_canonical_type($declared_type)
    {
        $type = strtolower(trim(preg_replace('/\s+/', ' ', (string) $declared_type)));
        if ('int' === slimstat_fp2_kind($type)) {
            $type = preg_replace('/\s*\(\s*\d+\s*\)/', '', $type);
        }
        return $type;
    }

    /** One column -> one token. The field-encoding table in the spec, verbatim. */
    function slimstat_fp2_encode_field($value, $declared_type)
    {
        $kind = slimstat_fp2_kind($declared_type);   // validate the type even when NULL
        if (null === $value) {
            return '\\NUL';
        }
        if ('int' === $kind) {
            return strtoupper(bin2hex(slimstat_fp2_canonical_int($value)));
        }
        $raw = (string) $value;
        if ('' === $raw) {
            return '\\EMPTY';
        }
        return strtoupper(bin2hex($raw));           // the STORED bytes, never a re-rendering
    }

    /** Length-prefixed fields joined by '|'. */
    function slimstat_fp2_encode_row(array $values, array $declared_types)
    {
        if (count($values) !== count($declared_types)) {
            throw new InvalidArgumentException(
                count($values) . ' values against ' . count($declared_types) . ' declared types'
            );
        }
        // array_values() re-keys 0..n-1, so the loop's own key IS the counter a hand-maintained
        // $i was duplicating — and a hand-maintained one desynchronises the moment anyone adds
        // a `continue`, pairing value n with type n-1 and producing a wrong-but-well-formed
        // fingerprint. This is the PHP 7.4 analogue of the Python side's zip().
        $parts = [];
        $types = array_values($declared_types);
        foreach (array_values($values) as $i => $value) {
            $token   = slimstat_fp2_encode_field($value, $types[$i]);
            $parts[] = strlen($token) . ':' . $token;
        }
        return implode('|', $parts);
    }

    /**
     * h := SHA256(h || row), seeded with the spec version so ENCODING_V2 cannot collide.
     *
     * Takes any iterable, NOT an array. The `array` hint it used to carry meant a 443k-row
     * export could not stream through it without materialising every encoding first, so the
     * export path open-coded the chain instead — and with it the `{len}:{token}` grammar, which
     * then had five implementations. The Python sibling `encoding_v1.chain()` has always
     * accepted any iterable for exactly this reason.
     */
    function slimstat_fp2_chain($row_encodings)
    {
        $h = hash('sha256', SLIMSTAT_FP2_SPEC, true);
        foreach ($row_encodings as $row) {
            $h = hash('sha256', $h . $row, true);
        }
        return bin2hex($h);
    }

    /** Schema identity, separate from data identity. $columns = [[name, type, nullable], ...]. */
    function slimstat_fp2_manifest_hash(array $columns, $order_by)
    {
        $lines = [];
        foreach ($columns as $col) {
            list($name, $type, $nullable) = $col;
            $lines[] = $name . '|' . slimstat_fp2_canonical_type($type) . '|' . ($nullable ? 'NULL' : 'NOT NULL');
        }
        return hash('sha256', implode("\n", $lines) . "\n" . 'ORDER BY ' . $order_by);
    }

    /**
     * The SQL expression that makes MySQL emit one row encoding per row.
     *
     * HEX() is not one function, which is the whole reason this is spelled out: given a NUMBER
     * it renders the value in base 16 (HEX(255)='FF'), given a STRING it renders that string's
     * bytes (HEX('255')='323535'). CAST(... AS CHAR) first fixes which is meant. For strings
     * the explicit CAST(... AS BINARY) is what stops the server transcoding between the column
     * and connection charsets.
     */
    function slimstat_fp2_row_sql(array $columns)
    {
        $fields = [];
        foreach ($columns as $col) {
            list($name, $type) = $col;
            $quoted = '`' . str_replace('`', '``', $name) . '`';
            if ('int' === slimstat_fp2_kind($type)) {
                $token = "HEX(CAST({$quoted} AS CHAR))";
            } else {
                $token = "IF({$quoted} = '', '\\\\EMPTY', HEX(CAST({$quoted} AS BINARY)))";
            }
            // NULL first: it outranks the empty-string case and has no type.
            $token    = "IF({$quoted} IS NULL, '\\\\NUL', {$token})";
            $fields[] = "CONCAT(CHAR_LENGTH({$token}), ':', {$token})";
        }
        // CONCAT, not CONCAT_WS, and the separator is a literal argument. CONCAT_WS is the
        // exact function encoding-spec.md indicts for SILENTLY SKIPPING NULLs — the reason the
        // old CRC32 probe cannot clear identity. Using it here is safe only while every field
        // is IF(... IS NULL, ...)-guarded, so a future edit that drops or reorders one guard
        // would yield a row with one fewer field and no error: a silently shorter encoding,
        // hashed as if correct, at the one point where a drop is invisible. CONCAT propagates
        // NULL instead, so the same mistake produces a NULL row the loop below rejects.
        $joined = [];
        foreach ($fields as $i => $field) {
            if ($i > 0) {
                $joined[] = "'|'";
            }
            $joined[] = $field;
        }
        return 'CONCAT(' . implode(', ', $joined) . ')';
    }

    /**
     * Stream the chain from the server in ONE unbuffered pass.
     *
     * The first version paginated with LIMIT/OFFSET while its own docblock claimed it batched
     * by primary key — a comment naming the defect three lines above the code that had it.
     * The cost was not theoretical: MySQL has no ordinal skip in a B-tree, so `OFFSET n`
     * traverses n rows every time. Over the 443k-row corpus at batch 5000 that is 89 data
     * queries discarding 5000×(0+…+88) = 19,580,000 rows, plus a 90th query that walks all
     * 443,000 to return nothing — ~20.5M row reads against a 443k minimum, **46×**. No
     * covering index can help: the select list touches six VARCHAR(2048) columns, so every
     * discarded row is fully materialised before being thrown away.
     *
     * Keyset pagination would fix the keyed tables, but the spec (encoding-spec.md) also
     * permits ORDER BY over the full pinned tuple for a table with no unique key, and a
     * row-constructor keyset is wrong there twice over: no composite index exists so it
     * degrades to a scan plus filesort anyway, and the pinned tuple is mostly NULLable —
     * `(a,b) > (x,NULL)` is NULL, so those rows drop out SILENTLY and the chain would cover
     * fewer rows than the corpus. That is precisely the failure a fingerprint exists to catch.
     *
     * One unbuffered pass is correct for both cases and needs no branch: one query, one
     * filesort at most, each row read exactly once, and O(1) PHP memory because
     * MYSQLI_USE_RESULT streams rather than materialising. `wpdb::get_col()` is avoided
     * deliberately — it buffers the whole result, copies it into stdClass objects, and then
     * runs array_values(get_object_vars()) PER ROW to reach the one property already there.
     *
     * Bench-only, so reaching past wpdb to the handle is acceptable; nothing may touch that
     * handle until the result is drained, which is why the loop does nothing else and the
     * result is freed in `finally`.
     */
    function slimstat_fp2_table_fingerprint(wpdb $db, $table, array $columns, $order_by)
    {
        $row_sql = slimstat_fp2_row_sql($columns);
        // ORDER BY is part of the identity, so it is passed through verbatim and recorded in
        // the manifest hash rather than chosen here.
        $sql = "SELECT {$row_sql} AS e FROM `{$table}` ORDER BY {$order_by}";

        $dbh = $db->dbh;
        if (!($dbh instanceof mysqli)) {
            // Fail loudly rather than silently falling back to a buffered path: a fingerprint
            // that quietly used more memory than it claimed is a smaller problem than one
            // whose measurement conditions nobody can reconstruct.
            throw new RuntimeException('fingerprint v2 needs the mysqli handle; wpdb->dbh is ' . gettype($dbh));
        }

        // The \NUL and \EMPTY sentinels reach the server as SQL string literals containing a
        // backslash, and MySQL resolves '\\NUL' to \NUL only while NO_BACKSLASH_ESCAPES is OFF.
        // With it on, the server yields \\NUL — five characters, not four — so both the token
        // and its length prefix diverge from the PHP encoder while the chain happily hashes
        // whatever it received. Silent, and the only sql_mode dependency in the scheme.
        $mode = (string) $db->get_var("SELECT @@SESSION.sql_mode");
        if (false !== stripos($mode, 'NO_BACKSLASH_ESCAPES')) {
            throw new RuntimeException(
                'NO_BACKSLASH_ESCAPES is set; the \\NUL/\\EMPTY sentinels would encode differently '
                . 'in SQL than in PHP and the divergence would be silent. sql_mode: ' . $mode
            );
        }

        $h    = hash('sha256', SLIMSTAT_FP2_SPEC, true);
        $rows = 0;
        if (!$dbh->real_query($sql)) {
            throw new RuntimeException('fingerprint query failed: ' . $dbh->error);
        }
        $result = $dbh->use_result();
        if (!$result) {
            throw new RuntimeException('fingerprint query returned no result set: ' . $dbh->error);
        }
        try {
            while ($row = $result->fetch_row()) {
                $h = hash('sha256', $h . $row[0], true);
                $rows++;
            }
        } finally {
            $result->free();
        }

        return [
            'rows'          => $rows,
            'chained_hash'  => bin2hex($h),
            'manifest_hash' => slimstat_fp2_manifest_hash($columns, $order_by),
            'spec'          => SLIMSTAT_FP2_SPEC,
        ];
    }
}

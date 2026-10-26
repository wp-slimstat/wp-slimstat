<?php
// Export the pinned corpus from MySQL into SQLite, storing TYPED RAW VALUES.
//
// WHAT IS STORED, AND WHY IT IS NOT TOKENS
//
// This file writes VALUES, never ENCODING_V1 tokens. Storing the tokens would be easier and
// would make S3's equality check CIRCULAR: it would prove a token survived a copy, not that the
// DATA survived the export. A value mangled in transit beside its intact token would pass, which
// is the precise failure the two-encoder design exists to prevent one layer up.
//
// So the Python side re-encodes INDEPENDENTLY from the stored values, and the fingerprints are
// then evidence about the export.
//
//   strings   -> BLOB, the raw bytes
//   integers  -> INTEGER
//   SQL NULL  -> NULL
//
// BLOB for strings is load-bearing, not fussiness. SQLite has TYPE AFFINITY: a TEXT column
// coerces what it is given, and a TEXT value carries an encoding SQLite believes it may
// transcode (a UTF-16 connection would rewrite it). BLOB is the one storage class SQLite is
// contractually forbidden to reinterpret, so the bytes MySQL held are the bytes Python reads —
// which is the whole claim. It also keeps a 4-byte codepoint and an invalid-UTF-8 sequence
// distinguishable, and the corpus contains both by construction (seed-profile-verify.json).
//
// Integers stay INTEGER rather than TEXT so the Python side has to render them itself and can
// therefore DISAGREE with PHP — that disagreement is the test. SQLite's INTEGER is a signed
// 64-bit value, so BIGINT UNSIGNED above 2^63-1 cannot round-trip through it; those columns are
// stored as BLOB of their decimal rendering and the reader is told so by the type manifest.
// Silently narrowing them would reproduce the json_decode defect S2 caught, one layer down.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s bench libs.

if (!function_exists('slimstat_fp2_export_snapshot')) {

    require_once __DIR__ . '/pinned-columns.php';
    require_once __DIR__ . '/fingerprint-v2.php';

    /**
     * Columns whose MySQL range does not fit SQLite's signed 64-bit INTEGER. Stored as the raw
     * bytes of their decimal rendering instead, and declared to the reader so it does not guess.
     */
    function slimstat_fp2_wider_than_sqlite_integer($type)
    {
        // Reuses the canonicaliser rather than adding a FOURTH independent regex over a declared
        // type string. It normalises case, whitespace and display width first, so BIGINT(20)
        // UNSIGNED and `bigint  unsigned` both land — where a bespoke pattern with `.*` between
        // the anchors was looser than the canonicaliser in one direction and stricter in another.
        return 'bigint unsigned' === slimstat_fp2_canonical_type($type);
    }

    /** Open a fresh export and create the carried manifest + meta tables. */
    function slimstat_fp2_export_open($sqlite_path, $db_prefix)
    {
        if (!class_exists('SQLite3')) {
            throw new RuntimeException('the SQLite3 extension is required to export a snapshot');
        }
        if (file_exists($sqlite_path)) {
            // Never merge into an existing export: two runs sharing one file is how a superseded
            // corpus gets cited as the current one (PITFALLS 60, one layer over).
            throw new RuntimeException("{$sqlite_path} already exists — export to a fresh path");
        }

        $sqlite = new SQLite3($sqlite_path);
        $sqlite->exec('PRAGMA journal_mode = OFF');
        $sqlite->exec('PRAGMA synchronous = OFF');

        // The manifest travels WITH the data. Without it the reader would have to re-derive the
        // pinned set and the types, which is a second copy of the very thing this export exists
        // to carry across a language boundary.
        $sqlite->exec('CREATE TABLE _manifest (tbl TEXT, ord INTEGER, name TEXT, type TEXT, nullable INTEGER, wide INTEGER)');
        $sqlite->exec('CREATE TABLE _meta (k TEXT PRIMARY KEY, v TEXT)');

        $meta = $sqlite->prepare('INSERT INTO _meta (k, v) VALUES (:k, :v)');
        // `spec` is READ back by the reader, which refuses an export written under another
        // version. `db_prefix` is provenance for a human opening the file months later — it is
        // deliberately not read by anything, and saying so here stops the next person hunting
        // for the consumer.
        foreach (['spec' => SLIMSTAT_FP2_SPEC, 'db_prefix' => $db_prefix] as $k => $v) {
            $meta->bindValue(':k', $k, SQLITE3_TEXT);
            $meta->bindValue(':v', (string) $v, SQLITE3_TEXT);
            $meta->execute();
            $meta->reset();
        }
        return $sqlite;
    }

    /**
     * Write one table's manifest and rows, and return the PHP-side fingerprint over the SAME
     * values that were written — the reference the Python re-encode must reproduce.
     *
     * $rows is any traversable of positional arrays. Taking rows rather than a wpdb is what lets
     * the fidelity gate run without MySQL: a claim testable only inside a container is a claim
     * nobody re-checks.
     */
    function slimstat_fp2_export_table(SQLite3 $sqlite, $suffix, array $columns, $order_by, $rows)
    {
        // Per-COLUMN facts, decided ONCE. The storage class depends only on the declared type,
        // so deciding it per CELL is 443k rows x 33 columns = 14.6M preg_ calls recomputing an
        // answer that never varies down a column — and the manifest loop below was computing
        // `wide` a third time, after encode_field() had already computed the kind a second.
        $types = $wide = $as_integer = [];
        foreach ($columns as $i => $col) {
            $types[$i]      = $col[1];
            $wide[$i]       = slimstat_fp2_wider_than_sqlite_integer($col[1]);
            $as_integer[$i] = ('int' === slimstat_fp2_kind($col[1]) && !$wide[$i]);
        }

        $man = $sqlite->prepare('INSERT INTO _manifest (tbl, ord, name, type, nullable, wide) VALUES (:t, :o, :n, :ty, :nu, :w)');
        foreach ($columns as $i => $col) {
            list($name, $type, $nullable) = $col;
            $man->bindValue(':t', $suffix, SQLITE3_TEXT);
            $man->bindValue(':o', $i, SQLITE3_INTEGER);
            $man->bindValue(':n', $name, SQLITE3_TEXT);
            $man->bindValue(':ty', $type, SQLITE3_TEXT);
            $man->bindValue(':nu', $nullable ? 1 : 0, SQLITE3_INTEGER);
            $man->bindValue(':w', $wide[$i] ? 1 : 0, SQLITE3_INTEGER);
            $man->execute();
            $man->reset();
        }

        $sqlite_cols = [];
        foreach ($columns as $col) {
            $sqlite_cols[] = '"' . str_replace('"', '""', $col[0]) . '"';
        }
        $sqlite->exec('CREATE TABLE "' . $suffix . '" (' . implode(', ', $sqlite_cols) . ')');
        $insert = $sqlite->prepare(
            'INSERT INTO "' . $suffix . '" VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );

        // Bind to SQLite and yield the row's ENCODING, so the chain comes from
        // slimstat_fp2_encode_row() rather than a fourth hand-written copy of the `{len}:{token}`
        // grammar — which also restores encode_row()'s values-versus-types length guard, silently
        // absent while the loop built the parts itself.
        $count = 0;
        $write_and_encode = function () use ($rows, $types, $as_integer, $insert, &$count) {
            foreach ($rows as $row) {
                foreach ($types as $i => $type) {
                    $value = $row[$i];
                    if (null === $value) {
                        $insert->bindValue($i + 1, null, SQLITE3_NULL);
                    } elseif ($as_integer[$i]) {
                        $insert->bindValue($i + 1, (int) $value, SQLITE3_INTEGER);
                    } else {
                        // Strings, and integers too wide for SQLite's signed 64-bit INTEGER:
                        // raw bytes, which SQLite must not reinterpret.
                        $insert->bindValue($i + 1, (string) $value, SQLITE3_BLOB);
                    }
                }
                $insert->execute();
                $insert->reset();
                $count++;
                yield slimstat_fp2_encode_row($row, $types);
            }
        };

        // One transaction. journal_mode=OFF and synchronous=OFF already remove the per-commit
        // costs a bounded batch exists to amortise — there is no rollback journal to cap and no
        // fsync to defer — so the modulo bookkeeping this used to carry bought nothing.
        $sqlite->exec('BEGIN');
        try {
            $chained = slimstat_fp2_chain($write_and_encode());
        } finally {
            $sqlite->exec('COMMIT');
        }

        return [
            'rows'          => $count,
            'chained_hash'  => $chained,
            'manifest_hash' => slimstat_fp2_manifest_hash($columns, $order_by),
            // The field that exists so ENCODING_V2 cannot be mistaken for ENCODING_V1 — dropped
            // here while both siblings emitted it, so the cross-language check could compare only
            // three of four keys.
            'spec'          => SLIMSTAT_FP2_SPEC,
        ];
    }

    /** Stream one table out of MySQL, unbuffered — 443k rows must not be held in PHP memory. */
    function slimstat_fp2_mysql_rows(wpdb $db, $table, array $columns, $order_by)
    {
        $dbh = $db->dbh;
        if (!($dbh instanceof mysqli)) {
            throw new RuntimeException('export needs the mysqli handle; wpdb->dbh is ' . gettype($dbh));
        }
        $quoted = [];
        foreach ($columns as $col) {
            $quoted[] = '`' . str_replace('`', '``', $col[0]) . '`';
        }
        $sql = 'SELECT ' . implode(', ', $quoted) . " FROM `{$table}` ORDER BY {$order_by}";
        if (!$dbh->real_query($sql)) {
            throw new RuntimeException('export query failed: ' . $dbh->error);
        }
        $res = $dbh->use_result();
        if (!$res) {
            throw new RuntimeException('export query returned no result set: ' . $dbh->error);
        }
        try {
            while ($row = $res->fetch_row()) {
                yield $row;
            }
        } finally {
            $res->free();
        }
    }

    /**
     * @return array{tables: array<string, array{rows:int, chained_hash:string, manifest_hash:string}>}
     */
    function slimstat_fp2_export_snapshot(wpdb $db, $sqlite_path, array $suffixes = ['slim_stats', 'slim_events'])
    {
        $sqlite = slimstat_fp2_export_open($sqlite_path, $db->prefix);
        $result = ['tables' => []];
        foreach ($suffixes as $suffix) {
            $columns  = slimstat_fp2_pinned_columns($suffix);
            $order_by = slimstat_fp2_order_by($suffix);
            $result['tables'][$suffix] = slimstat_fp2_export_table(
                $sqlite,
                $suffix,
                $columns,
                $order_by,
                slimstat_fp2_mysql_rows($db, $db->prefix . $suffix, $columns, $order_by)
            );
        }
        $sqlite->close();
        return $result;
    }
}

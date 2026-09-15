<?php
// Does the fingerprint taken over MySQL equal the one recomputed, in Python, over the SQLite
// export of that same corpus?
//
//   wp --exec="define('DISABLE_WP_CRON', true);" eval-file tests/docker/verify-export-fingerprint.php
//
// The --exec is not decoration — see READ-ONLY below, and CONTROL 5, which refuses to certify a
// run made without it.
//
// WHY THIS FILE EXISTS. S3 obligation 4, and it is the half nobody had run.
// tests/export-fidelity-test.php proves PHP and Python agree over SIX SUPPLIED ROWS with no
// database in the loop; tests/docker/verify-sql-encoder.php proves the SQL encoder and the PHP
// encoder emit the same bytes over seven adversarial values. Neither has ever seen the corpus.
// The end-to-end claim — that the number the bench reports for a real table is the number an
// INDEPENDENT reader gets back out of the export of that table — needs a live server, so it was
// asserted from the shape of the two halves rather than measured.
//
// WHERE IT RUNS. `tests/docker/run-rollup-floor.sh` calls it on EVERY cell — mysql 8.0, 5.7 and
// 5.6 over one corpus — because `HEX`, `CAST` and `CHAR_LENGTH` semantics are exactly what
// differs across those servers, and a claim proven once on 8.0 leaves the floor versions
// asserting a shape nobody executed. Getting there took two changes recorded here because an
// earlier draft of this paragraph said the opposite and was true when written: Dockerfile.wp now
// installs python3 (and asserts it, and the stdlib sqlite3 module, at build time), and that
// runner asserts one [OK] line per control number up to tests/docker/reachability/CONTROL-FLOOR,
// and a `forced=0` header, rather than trusting exit 0. The same floor file is ratcheted with no
// container at all by `composer test:control-wiring`, so a control cannot be deleted quietly
// between docker runs.
//
// It also runs on the HOST, by hand, against whatever install it is pointed at — including the
// one holding the only copy of the parity dataset (`jaan-to/bin/slimstat-db.sh env` names the
// same database). That is the invocation READ-ONLY below and CONTROL 5 are written for.
//
// THE TWO SIDES, AND WHY THEY ARE INDEPENDENT
//
//   MySQL side   slimstat_fp2_table_fingerprint(): every row encoding is built by
//                slimstat_fp2_row_sql() and evaluated BY THE SERVER. PHP only folds sha256; it
//                never calls slimstat_fp2_encode_row() on this path.
//   Export side  the export writes TYPED RAW VALUES, never tokens, and tests/oracle/encoding_v1.py
//                re-encodes them from the spec, in another process, in another language. Storing
//                the tokens would make this circular — it would prove a token survived a copy,
//                not that the data did.
//
// Both paragraphs are a READING OF THE SOURCE. CONTROL 3 and CONTROL 4 evaluate as much of them
// as is reachable from outside those two functions, and the remainder is named under WHAT IT DOES
// NOT PROVE. The first one used to be PRINTED, verbatim, as a passing control while nothing in
// that control's predicate evaluated any of it — the "comment claiming a scoping the next lines
// did not perform" shape this tree keeps re-finding, reproduced inside the machinery built to
// rule it out.
//
// AN EQUALITY BETWEEN TWO THINGS THAT CANNOT DIFFER PROVES NOTHING, which is the shape under
// most of PITFALLS.md, so nine controls are printed BEFORE any result and each of them can fail
// the run on its own: the corpus is not empty, a one-byte change IS detected (demonstrated in
// process, not asserted), both sides actually ran, the row encoding really is evaluated by the
// server, nothing scheduled could rewrite the corpus underneath the run, the schema both
// manifest hashes describe is the one the server actually holds AND orders that corpus TOTALLY,
// the reader's ORDER BY is what fixes the sequence rather than decoration over a file that was
// already in that order (demonstrated by re-reading the same export under a different ordering),
// the SQL encoder's empty-string branch agrees with the byte-length test the other two encoders
// apply over every pinned varchar of this corpus, and the number of rows folded is the number
// the server says the table holds.
//
// WHAT IT DOES NOT PROVE. Nothing about whether the PINNED SET is the right set, and — with one
// exception, now measured here — nothing about values this particular corpus does not happen to
// contain. That adversarial coverage is the job of the two gates named above; the exception is
// the one place their delegation was FALSE. verify-sql-encoder.php binds every string as
// `_binary 0x…`, and the binary collation is NO PAD and byte-comparing, so the branch it cannot
// reach at all is row_sql()'s `IF(col = '', '\EMPTY', …)` evaluated under a real column's
// collation — where `'  ' = ''` is TRUE on every PAD SPACE collation, including the
// utf8mb3_general_ci this corpus's varchars carry. CONTROL 8 is that reach. This file also
// compares two reads taken at two instants: against a corpus being written to concurrently the
// sides may legitimately disagree. An earlier draft of this sentence said a `rows` mismatch is
// the shape that would take, which is true only of INSERT and DELETE. SlimStat's more frequent
// writer is Storage::updateRow(), an in-place UPDATE on every dt_out heartbeat, and that leaves
// `rows` EQUAL while moving chained_hash — the same topology as a defective SQL encoder. The
// mismatch branch below no longer guesses between them: it re-reads the MySQL side and asks
// whether it agrees with itself. Six more residues, each of which reads like something this file
// checks:
//
//   * The pinned TYPES are single-sourced through PHP. slimstat_fp2_pinned_columns() parses
//     Schema::columns() ONCE, and that single parse picks the SERVER's branch in row_sql(), picks
//     the SQLite storage class in export_table(), and is written verbatim into `_manifest` —
//     which is all the Python side has. Python re-derives the ENCODING from a declared type; it
//     never re-derives the TYPE, so the two manifest_hash implementations CANNOT disagree about
//     one: a mis-split declaration (`VARCHAR(2048)` read as `VARCHAR(204)`) reaches both
//     identically and both agree. The VALUE half is genuinely two-implementation; the SCHEMA half
//     was one implementation observed twice. CONTROL 6 is what keeps that from being certified —
//     it asks the SERVER's own catalogue what each pinned column is and compares, so the third
//     party the schema half was missing is information_schema rather than Python. Two residues
//     survive it: slimstat_fp2_canonical_type() is applied to BOTH sides of CONTROL 6's
//     comparison, so a bug inside IT — dropping a varchar length, say — is invisible to that
//     control; and column ORDER is not compared, because manifest order is PHP's on both sides
//     by construction and the server's physical order is nothing either side reads.
//
//     An earlier draft extended the first residue to "and to both manifest hashes alike", which
//     is FALSE and understated the coverage. encoding_v1.py canonicalises independently, and the
//     export carries the RAW declared type, so a bug in the PHP canonicaliser alone moves only
//     the PHP manifest hash and the reader refuses the export outright. The canonicaliser and
//     the hasher genuinely are two implementations; only their INPUT is single-sourced. What no
//     layer can see is a bug in the canonicalisation RULE — both implementations normalising the
//     same declaration the same wrong way — and that is the residue, not the one first written.
//   * `manifest_hash` and `spec` are NOT compared here, because the reader will not return a
//     fingerprint until they match: read_export_cli.py raises on a manifest other than the one
//     passed in, and open_export() raises on a `_meta.spec` other than the version it implements.
//     Anything reaching the comparison below has already satisfied both, so re-comparing them
//     would be exactly the equality-that-cannot-differ this file exists to avoid. They are
//     reported as what they are — preconditions — and a refusal is translated into a located
//     failure instead of being printed as a bare interpreter error.
//   * That PHP never encodes a row on the MySQL path is read from fingerprint-v2.php, not
//     measured. CONTROL 4 measures what is reachable from out here: the expression is SQL, every
//     pinned field is NULL-guarded inside it, and the SERVER evaluating it over a real row of
//     this corpus returns bytes identical to slimstat_fp2_encode_row(). It cannot see which of
//     the two slimstat_fp2_table_fingerprint() then folded — a refactor replacing the streaming
//     SQL with a PHP fold would still agree with the Python side on every field and change
//     nothing this file prints. Making that observable means having that function return the SQL
//     it issued, which is a change in fingerprint-v2.php, not here. The one route that would NOT
//     have needed that change — reading the statement back out of
//     performance_schema.events_statements_history and asserting its SQL_TEXT still contains
//     `HEX(CAST(` — is closed on the install this gate is pointed at: `SELECT @@performance_schema`
//     answers 0 there, so those tables are empty and a control built on them would be a guard that
//     never ran. Measured before being ruled out, not assumed away.
//   * slimstat_fp2_table_fingerprint()'s loop does not reject a NULL row encoding — `[null]` is a
//     non-empty array and therefore truthy — so a dropped IS NULL guard in row_sql() would fold
//     the empty string and count the row as encoded. Here that is caught twice over (CONTROL 4's
//     per-column guard check, and the Python side moving away), which is precisely why it remains
//     a live hazard for that function's OTHER callers, none of which have a second encoder.
//   * CONTROL 8 does not make the two empty-string tests EQUIVALENT, and must not be read as
//     saying so. Under a PAD SPACE collation `IF(col = '', …)` and `strlen($raw) === 0` are
//     different predicates, and this server's are exactly that: the control measures that the
//     class on which they differ — a value the server calls empty whose bytes are not — is EMPTY
//     over this corpus, across every pinned varchar, and it FAILS the run the moment one exists.
//     A run that passes therefore says the two encoders agree over these 443k rows, not that
//     row_sql() implements ENCODING_V1's empty rule. Fixing that is an edit to fingerprint-v2.php
//     (`OCTET_LENGTH(col) = 0`, which is byte-exact under every collation), not to this file.
//   * CONTROL 7 shows the reader's ORDER BY GOVERNS the sequence it folds; it does not show that
//     SQLite's sort and MySQL's agree on TIES, and they need not — MySQL breaks a tie arbitrarily
//     and SQLite's sorter generally leaves equal keys in scan order, which is the export's write
//     order, which is MySQL's order. The two would then agree for no reason at all. What removes
//     that is CONTROL 6 refusing an ORDER BY column the server's catalogue does not hold a
//     single-column UNIQUE index on, so ties cannot arise; that half is a CATALOGUE claim about
//     the table, not a scan of the rows, and it is the pair that closes it, not either alone.
//
// READ-ONLY against the corpus — but the BOOT that runs this file is not. Every statement either
// side issues is a SELECT: no CREATE, no INSERT, no ALTER. `wp eval-file` however boots WordPress
// with the plugin active, and core hooks wp_cron() on `init`, so unless DISABLE_WP_CRON is
// defined that boot can spawn wp_slimstat_purge() — which DELETEs rows past the auto_purge
// horizon and, having removed any, OPTIMIZE-TABLEs both analytics tables, under the very run that
// is certifying them. Hence the invocation at the top of this file and CONTROL 5. The one thing
// written is a temporary SQLite export, removed in `finally`. Control 2 corrupts THAT file in
// place after the clean read rather than copying it: the export of a 443k-row corpus is not worth
// duplicating to flip one byte, and the clean hash is already in hand.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file.

require_once dirname(__DIR__) . '/bench/lib/export-snapshot.php';

$db = (class_exists('wp_slimstat') && wp_slimstat::$wpdb instanceof wpdb) ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];

$suffixes    = ['slim_stats', 'slim_events'];
$reader      = dirname(__DIR__) . '/oracle/read_export_cli.py';
$sqlite_path = sys_get_temp_dir() . '/slimstat-export-fp-' . getmypid() . '.sqlite';
// Continuation lines inside a CONTROLS detail, aligned under the detail column: 2 spaces of print
// indent + '[OK] ' + 'N ' + the 13-wide name + 1.
$indent      = "\n" . str_repeat(' ', 23);

// Provenance is read HERE, before either streaming pass. Both slimstat_fp2_table_fingerprint()
// and the export hold the mysqli handle open with use_result(), and a wpdb query issued against
// an undrained handle is answered with "Commands out of sync", not a value.
$server  = (string) $db->get_var('SELECT VERSION()');
$charset = (string) $db->get_var('SELECT @@SESSION.character_set_connection');
$py_raw  = trim((string) shell_exec('python3 --version 2>&1'));
// The greppable line below is key=value, so an absent interpreter must not paste a shell error
// ("sh: 1: python3: not found") into it with spaces in the middle of a field.
$py_ver  = (false !== strpos($py_raw, 'Python 3')) ? preg_replace('/^Python\s+/i', '', $py_raw) : 'NOT-FOUND';

// CONTROL 5's inputs, sampled BEFORE either pass — as close to the boot that may have spawned the
// job as this file can get. wp_next_scheduled() reads wp_options, and $settings is already in
// memory, so neither touches an analytics table and the read-only claim above still holds.
$cron_off   = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$purge_at   = function_exists('wp_next_scheduled') ? wp_next_scheduled('wp_slimstat_purge') : false;
$auto_purge = (class_exists('wp_slimstat') && isset(wp_slimstat::$settings['auto_purge']))
    ? (int) wp_slimstat::$settings['auto_purge']
    : null;
// Any ONE of these makes the purge inert: no cron this request, nothing scheduled, or a retention
// of 0 (which wp_slimstat_purge() returns on before reading a row). Unknown settings count as
// capable — the safe direction for a claim about the only copy of the parity dataset.
$c5_ok = $cron_off || false === $purge_at || (null !== $auto_purge && $auto_purge <= 0);

$failures = $controls = $results = [];
$mysql = $python = $php = $pinned = $verdict = $exportable = $server_side = $live_schema = [];
$empty_exact = $row_count = [];

// FAULT INJECTION — the one thing that turns "these controls are wired" from a paragraph into a
// measurement. `SLIMSTAT_FP_FORCE_CONTROL_FAIL=<n>` forces control <n> to FAIL.
//
// Two properties are claimed for every control above: its call site is REACHED on a normal run,
// and its failure CHANGES THE EXIT CODE. Both are properties of this file's control flow, and
// every previous attempt in this tree to establish that class of property by reading produced
// prose rather than evidence (PITFALLS 61-64: a guard the fixture could not tell was absent, a
// read path that could not observe the property, a mechanism announced in a comment and never
// written). With this hook the claim is measured instead: force each n in turn and the run must
// exit 1 with exactly `[!!] n` — a control whose call site is unreachable produces no such line
// and exits 0, and a control whose failure does not reach $failures exits 0 while printing it.
//
// The direction is safe by construction: it can only turn an [OK] into a [!!]. There is no value
// of the variable that makes this run pass something it would otherwise have failed. A run made
// under it still says so in its header line (`forced=`) and in the control's own detail, so a
// self-test artifact can never be read as a certifying one.
$forced = (int) getenv('SLIMSTAT_FP_FORCE_CONTROL_FAIL');

// ONE call renders the CONTROLS line AND registers the failure. Splitting them is precisely how a
// control comes to be displayed as met while enforcing nothing — the defect these controls exist
// to rule out one layer up, so it is not reproduced in the reporting of them.
$control = function ($ok, $n, $name, $detail) use (&$controls, &$failures, $forced) {
    if ($forced === $n) {
        $ok     = false;
        $detail = 'FORCED FAIL (SLIMSTAT_FP_FORCE_CONTROL_FAIL=' . $n . '): this run is a self-test '
            . 'of the control wiring and certifies nothing. Original detail follows. ' . $detail;
    }
    $controls[] = sprintf('[%s] %d %-13s %s', $ok ? 'OK' : '!!', $n, $name, $detail);
    if (!$ok) {
        $failures[] = sprintf('CONTROL %d (%s) UNMET: %s', $n, $name, preg_replace('/\s*\n\s*/', ' ', $detail));
    }
};

$compare = function ($suffix, $field, $mysql_side, $export_side) use (&$failures) {
    if ($mysql_side === $export_side) {
        return true;
    }
    $failures[] = sprintf(
        '%s: %s differs — MySQL %s, export-via-Python %s',
        $suffix, $field, var_export($mysql_side, true), var_export($export_side, true)
    );
    return false;
};

// The expected manifest hash is derived HERE (from Schema, via slimstat_fp2_pinned_columns) and
// passed in, so the reader cannot vouch for a schema it read out of the same file it is checking.
$read = function ($table, $order_by, $expect_manifest) use ($reader, $sqlite_path) {
    $raw = (string) shell_exec(
        'python3 ' . escapeshellarg($reader)
        . ' ' . escapeshellarg($sqlite_path)
        . ' ' . escapeshellarg($table)          // the BARE suffix, as written into the export
        . ' ' . escapeshellarg($order_by)       // a bare column name; the reader quotes it
        . ' ' . escapeshellarg($expect_manifest) . ' 2>&1'
    );
    $decoded = json_decode($raw, true);
    return [is_array($decoded) ? $decoded : null, trim($raw)];
};

// CONTROL 4's evidence, gathered per table while the handle is drained. Returns [ok, one line].
// It NEVER throws: a probe that cannot run must fail its own control, not take down the
// comparison of the table it was probing.
$probe_server_side = function ($table, array $columns, $order_by) use ($db) {
    try {
        $row_sql = slimstat_fp2_row_sql($columns);

        // (a) Every pinned field is inside its OWN NULL guard. row_sql() chooses CONCAT over
        // CONCAT_WS precisely because CONCAT_WS drops a NULL argument silently, leaving a row one
        // field short and hashed as if correct — and that choice is only safe while the guards
        // are complete. Completeness is a property of the emitted TEXT, so it is checked rather
        // than trusted, and checked PER COLUMN: counting cannot catch one dropped guard among 30
        // columns, because each column contributes two occurrences (CHAR_LENGTH(t) and t).
        // One pass builds both the guard census and the select list, quoting each identifier ONCE
        // — two loops quoting the same names is two places to disagree about what an identifier is.
        $unguarded = $names = [];
        foreach ($columns as $col) {
            $q       = '`' . str_replace('`', '``', $col[0]) . '`';
            $names[] = $q;
            if (false === strpos($row_sql, "IF({$q} IS NULL, '\\\\NUL'")) {
                $unguarded[] = $col[0];
            }
        }
        $hex    = substr_count($row_sql, 'HEX(CAST(');
        $guards = substr_count($row_sql, "IS NULL, '\\\\NUL'");
        if ($unguarded || 0 === $hex || $hex !== $guards) {
            return [false, sprintf('%s: the emitted expression is not server-side and fully guarded '
                . '— %d HEX(CAST( against %d NULL guards over %d columns%s',
                $table, $hex, $guards, count($columns),
                $unguarded ? '; unguarded: ' . implode(', ', $unguarded) : '')];
        }

        // (b) The SERVER's evaluation of that expression against a REAL row of this corpus,
        // beside the PHP encoder's, from ONE statement so they cannot be reading different rows.
        // verify-sql-encoder.php makes this comparison over seven literals in a derived table
        // with the types transcribed by hand; this makes it over the pinned schema, this server's
        // HEX/CAST semantics and this corpus's bytes.
        $sql  = 'SELECT ' . $row_sql . ' AS e, ' . implode(', ', $names)
            . " FROM `{$table}` ORDER BY {$order_by} LIMIT 1";
        $prev = $db->suppress_errors(true);
        try {
            $row = $db->get_row($sql, ARRAY_N);
        } finally {
            $db->suppress_errors($prev);
        }
        if (!is_array($row)) {
            return [false, sprintf('%s: the server returned no row to probe — %s',
                $table, $db->last_error ?: 'the query yielded nothing')];
        }

        $server_token = (string) array_shift($row);
        $php_token    = slimstat_fp2_encode_row($row, array_column($columns, 1));
        if ($server_token === $php_token) {
            return [true, sprintf('%s: %d columns guarded, first row by %s — server and PHP '
                . 'encoders agree on all %d bytes', $table, count($columns), $order_by, strlen($php_token))];
        }
        // Locate it rather than dumping two multi-kilobyte tokens: the byte they part at, with a
        // window either side, is what says which column and which rule.
        $n = min(strlen($server_token), strlen($php_token));
        for ($at = 0; $at < $n && $server_token[$at] === $php_token[$at]; $at++) {
            // deliberately empty — the loop condition IS the comparison
        }
        return [false, sprintf('%s: server and PHP encoders part at byte %d of %d/%d — server %s… '
            . 'php %s…', $table, $at, strlen($server_token), strlen($php_token),
            substr($server_token, $at, 24), substr($php_token, $at, 24))];
    } catch (Throwable $e) {
        return [false, sprintf('%s: %s: %s', $table, get_class($e), $e->getMessage())];
    }
};

// CONTROL 6's evidence, gathered per table under the same contract: it NEVER throws, and returns
// [ok, one line]. Everything else in this run reads the pinned types from ONE PHP parse of
// Schema::columns() — row_sql() branches on it, the export's storage classes come from it, and
// `_manifest` carries it verbatim to the only place Python can learn a type — so the two
// manifest_hash implementations agreeing says the string survived the trip, not that it is true.
// This asks the DATABASE, which is the one party neither encoder shares.
$probe_live_schema = function ($table, array $columns, $order_by) use ($db) {
    try {
        $live = $db->get_results(
            $db->prepare(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ),
            ARRAY_N
        );
        if (!$live) {
            return [false, sprintf('%s: the server reports no columns for this table — %s',
                $table, $db->last_error ?: 'information_schema returned nothing')];
        }
        $actual = [];
        foreach ($live as $row) {
            $actual[$row[0]] = [(string) $row[1], 'YES' === strtoupper((string) $row[2])];
        }

        // Compared THROUGH canonical_type(), because that is the form the manifest hashes and the
        // manifest is what this control defends. A raw comparison would be worse than none: MySQL
        // 8.0.19 removed integer display width, so every pinned integer would read as drift
        // between two servers holding an identical schema — the false positive canonical_type()
        // exists to prevent. Widths are dropped for integers and KEPT for varchars, where a
        // narrowed column is real data loss and must move the hash.
        $drift = [];
        foreach ($columns as $col) {
            list($name, $type, $nullable) = $col;
            if (!isset($actual[$name])) {
                $drift[] = sprintf('%s is pinned but the table has no such column', $name);
                continue;
            }
            list($live_type, $live_null) = $actual[$name];
            $want = slimstat_fp2_canonical_type($type);
            try {
                $got = slimstat_fp2_canonical_type($live_type);
            } catch (Throwable $e) {
                // The server holds a type ENCODING_V1 has no rule for. That is drift, and the
                // loudest kind — reported per column rather than thrown, so the remaining
                // columns are still compared instead of the first one ending the census.
                $drift[] = sprintf('%s declared %s, server has %s — a type the spec has no rule for',
                    $name, $want, strtolower($live_type));
                continue;
            }
            if ($want !== $got) {
                $drift[] = sprintf('%s declared %s, server has %s', $name, $want, $got);
            } elseif ($nullable !== $live_null) {
                $drift[] = sprintf('%s declared %s, server has %s', $name,
                    $nullable ? 'NULL' : 'NOT NULL', $live_null ? 'NULL' : 'NOT NULL');
            }
        }

        // That census walks the PINNED set, so a column the server HOLDS and Schema::columns()
        // does not declare is invisible to every line above — while this control's name says the
        // schema being hashed is the schema the server holds. Reachable, not hypothetical:
        // measure-arms.sh swaps code arms from git worktrees against ONE shared database, so the
        // older arm's manifest meets the newer arm's table. The only permitted extras are the v6
        // migrations the pinned set excludes ON PURPOSE, and they are already named in one place,
        // so the difference is exactly an array_diff against that name list.
        $names = array_column($columns, 0);
        $extra = array_diff(array_keys($actual), $names, slimstat_fp2_v6_added_columns());
        foreach ($extra as $undeclared) {
            $drift[] = sprintf('%s is on the server and the manifest declares no such column '
                . '(the only permitted extras are the v6-added %s)',
                $undeclared, implode(' and ', slimstat_fp2_v6_added_columns()));
        }

        // The ORDER BY is hashed into the manifest verbatim and the chain is order-dependent, so
        // two further properties of it belong to the catalogue, and neither was ever asked.
        //
        // (a) It must be a PINNED column. One outside the pinned set — `vid_hash`, which that set
        // deliberately excludes — is not carried by the export at all, and SQLite resolves a
        // double-quoted token that is not a column as a STRING LITERAL rather than erroring, so
        // the reader would sort by a constant and agree anyway. CONTROL 7 measures that behaviour
        // against the export itself; this is the same hole seen from the manifest side.
        //
        // (b) It must be TOTAL. MySQL breaks a tie arbitrarily and SQLite's sorter generally
        // leaves equal keys in scan order — which is the export's write order, which is MySQL's
        // order — so a tied ORDER BY is the one shape in which both sides agree while the
        // fingerprint is not a function of the corpus at all: the same rows read twice could fold
        // in two orders. Restore a dump without its PRIMARY KEY and that is the state. A
        // single-column UNIQUE index over a NOT NULL column forbids the tie; UNIQUE alone does
        // not, because a nullable unique column admits many NULLs and those tie.
        if (!in_array($order_by, $names, true)) {
            $drift[] = sprintf('ORDER BY %s is not a pinned column, so the export does not carry '
                . 'it and the reader cannot sort by it', $order_by);
        }
        $unique = $db->get_results($db->prepare(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0',
            $table
        ), ARRAY_N);
        $width = $covers = [];
        foreach ((array) $unique as $row) {
            $idx         = (string) $row[0];
            $width[$idx] = isset($width[$idx]) ? $width[$idx] + 1 : 1;
            if (1 === (int) $row[1] && (string) $row[2] === (string) $order_by) {
                $covers[] = $idx;
            }
        }
        $total = '';
        foreach ($covers as $idx) {
            if (1 === $width[$idx]) {        // one column wide, so it is unique on ITS OWN
                $total = $idx;
                break;
            }
        }
        if ('' === $total) {
            $drift[] = sprintf('ORDER BY %s is not covered by a single-column UNIQUE index (%s), '
                . 'so the ordering admits ties and the chained hash is not a function of the corpus',
                $order_by,
                $width ? 'the unique indexes here are ' . implode(', ', array_keys($width))
                    : 'this table has none');
        } elseif (!isset($actual[$order_by]) || $actual[$order_by][1]) {
            $drift[] = sprintf('ORDER BY %s is UNIQUE via %s but the server holds it NULLable, and '
                . 'a UNIQUE index admits many NULLs — the ordering still has ties', $order_by, $total);
        }

        if ($drift) {
            return [false, sprintf('%s: the manifest describes a schema this server does not have — %s',
                $table, implode('; ', $drift))];
        }
        return [true, sprintf('%s: all %d pinned columns match the server catalogue on canonical '
            . 'type and nullability, the server holds no column the manifest does not declare '
            . '(%d live, %d of them the v6-added exclusions), and ORDER BY %s is NOT NULL under '
            . 'the single-column UNIQUE index %s, so the ordering is total',
            $table, count($columns), count($actual), count($actual) - count($columns),
            $order_by, $total)];
    } catch (Throwable $e) {
        return [false, sprintf('%s: %s: %s', $table, get_class($e), $e->getMessage())];
    }
};

// CONTROL 8's evidence, same contract: it NEVER throws, returns [ok, one line], and is gathered
// in the same drained window as the other probes.
//
// The three encoders do NOT apply the same empty-string test. PHP asks `'' === $raw` and Python
// `len(raw) == 0` — both BYTE LENGTH — while slimstat_fp2_row_sql() emits
// `IF(col = '', '\EMPTY', …)`, and `=` in MySQL is a COLLATION operation, not a byte comparison.
// Under any PAD SPACE collation `'  ' = ''` is TRUE, so a pad-only varchar encodes as \EMPTY on
// the server and as 2020 in both other encoders — two implementations that are genuinely
// independent and not equivalent. The gate this file's own prose used to delegate that class to,
// verify-sql-encoder.php, cannot reach it: it binds every string as `_binary 0x…`, and the binary
// collation is NO PAD and byte-comparing, so such a literal takes the HEX branch there.
//
// The class is therefore measured HERE, over the corpus, per column: rows the server calls empty
// whose bytes are not. One aggregate pass, and it decides the run — one such row makes the two
// sides genuinely disagree, and this says which column and how many instead of leaving a bare
// chained-hash mismatch to be read as "suspect the schema".
//
// The comparison has to be the COLUMN's and not the SESSION's, and on this install those differ
// in exactly the direction that would have made a session-collation probe worthless: the
// connection is utf8mb4_0900_ai_ci (NO PAD), which answers `' ' = ''` FALSE, while every pinned
// varchar is utf8mb3_general_ci, which answers TRUE. `CONCAT(LEFT(COALESCE(col,''),0), ' ')` is a
// one-space string built FROM the column, so it carries that column's collation by MySQL's
// coercibility rules and the server answers for the column rather than for the connection this
// run happens to hold. COLLATION() of the same expression is read back so the detail names it.
$probe_empty_exact = function ($table, array $columns) use ($db) {
    try {
        $varchars = [];
        foreach ($columns as $col) {
            if ('str' === slimstat_fp2_kind($col[1])) {
                $varchars[] = $col[0];
            }
        }
        if (!$varchars) {
            return [true, sprintf('%s: pins no varchar, so the empty-string branch never runs for it', $table)];
        }

        $select = [];
        foreach ($varchars as $name) {
            $q        = '`' . str_replace('`', '``', $name) . '`';
            $probe    = "CONCAT(LEFT(COALESCE({$q}, ''), 0), ' ')";
            $select[] = "COALESCE(SUM({$q} = '' AND OCTET_LENGTH({$q}) > 0), 0)";
            $select[] = "COALESCE(MAX({$probe} = ''), -1)";
            $select[] = "COALESCE(MAX(COLLATION({$probe})), '?')";
        }
        $sql  = 'SELECT ' . implode(', ', $select) . " FROM `{$table}`";
        $prev = $db->suppress_errors(true);
        try {
            $row = $db->get_row($sql, ARRAY_N);
        } finally {
            $db->suppress_errors($prev);
        }
        if (!is_array($row)) {
            return [false, sprintf('%s: the divergence scan returned no row — %s',
                $table, $db->last_error ?: 'the query yielded nothing')];
        }

        $hits = $pad = [];
        foreach ($varchars as $i => $name) {
            $n = (int) $row[$i * 3];
            if ($n > 0) {
                $hits[] = sprintf('%s (%d rows)', $name, $n);
            }
            if (1 === (int) $row[$i * 3 + 1]) {
                $pad[$name] = (string) $row[$i * 3 + 2];
            }
        }
        if ($hits) {
            return [false, sprintf('%s: the server calls a value empty whose bytes are not, in %s — '
                . 'row_sql() emits \\EMPTY there while the PHP and Python encoders emit its hex, so '
                . 'the two sides of this run encode that row differently', $table, implode(', ', $hits))];
        }
        if (!$pad) {
            return [true, sprintf('%s: %d pinned varchars, and none of their collations calls a '
                . 'non-empty value empty — the branch is byte-exact on this server',
                $table, count($varchars))];
        }
        $names = array_keys($pad);
        return [true, sprintf('%s: 0 rows across %d pinned varchars where the server calls the value '
            . 'empty and its bytes are not, so the two tests agree over THIS corpus — but %d of those '
            . 'columns are PAD SPACE (%s is %s, which answers \' \' = \'\' TRUE), so they are not the '
            . 'same predicate and one pad-only value would part them',
            $table, count($varchars), count($pad), $names[0], $pad[$names[0]])];
    } catch (Throwable $e) {
        return [false, sprintf('%s: %s: %s', $table, get_class($e), $e->getMessage())];
    }
};

// CONTROL 9's evidence: the only row count in this run that does not come out of a streaming
// `while ($row = $result->fetch_row())`.
//
// Both streaming passes use that idiom — fingerprint-v2.php over the encodings, export-snapshot.php
// over the values — and WordPress sets mysqli_report(MYSQLI_REPORT_OFF), so mysqli never throws:
// a mid-stream failure of an unbuffered result returns FALSE, which the idiom cannot tell from
// "no more rows", and the pass ends NORMALLY having folded a PREFIX. A position-deterministic read
// error — a corrupt InnoDB page, a KILL QUERY fired at a fixed offset — stops both passes at the
// same row over the same table under the same ORDER BY, so `rows` and `chained_hash` agree exactly
// and the run prints PASS over that prefix, with the truncated number itself supplying the
// coverage claim. Two counts of one idiom are not evidence of coverage; this is the third number.
$probe_row_count = function ($table) use ($db) {
    $prev = $db->suppress_errors(true);
    try {
        $n = $db->get_var("SELECT COUNT(*) FROM `{$table}`");
    } finally {
        $db->suppress_errors($prev);
    }
    return (null === $n) ? null : (int) $n;
};

try {
    // ── The MySQL side, one table at a time ───────────────────────────────────────────────────
    // Fully drained before the next begins: the fingerprint streams unbuffered, so nothing may
    // touch that handle until it returns. Per-table try/catch on purpose — a table this install
    // does not have must not take down the comparison of the table it does.
    foreach ($suffixes as $suffix) {
        try {
            $columns  = slimstat_fp2_pinned_columns($suffix);
            $order_by = slimstat_fp2_order_by($suffix);

            $pinned[$suffix] = [$columns, $order_by];
            $mysql[$suffix]  = slimstat_fp2_table_fingerprint($db, $db->prefix . $suffix, $columns, $order_by);
            $exportable[]    = $suffix;

            // All four probes run HERE: immediately after, while the handle is drained and before
            // the export opens it again. Two of them are not gated on rows — an empty table still
            // has a schema, and a manifest hash over a type the server does not hold is exactly
            // as wrong when nobody has written a row yet; COUNT(*) over an empty table is the
            // number that says the fingerprint's zero was the corpus and not a truncated read.
            $live_schema[$suffix] = $probe_live_schema($db->prefix . $suffix, $columns, $order_by);
            $row_count[$suffix]   = $probe_row_count($db->prefix . $suffix);

            // Only for a table that HAS a row: an empty table cannot supply one to probe, and
            // failing CONTROL 4 for that would fail the run for the reason CONTROL 1 already
            // reports as UNPROVEN. The divergence scan is gated with it for the same reason —
            // MAX(COLLATION(…)) over zero rows answers NULL, which is "not measured", and a
            // SUM over zero rows is 0 for a reason that is not evidence.
            if ($mysql[$suffix]['rows'] > 0) {
                $server_side[$suffix] = $probe_server_side($db->prefix . $suffix, $columns, $order_by);
                $empty_exact[$suffix] = $probe_empty_exact($db->prefix . $suffix, $columns);
            }
        } catch (Throwable $e) {
            $verdict[$suffix] = 'ERROR';
            $failures[]       = sprintf('%s: the MySQL side produced no fingerprint — %s: %s',
                $suffix, get_class($e), $e->getMessage());
        }
    }

    // ── The export side ───────────────────────────────────────────────────────────────────────
    if (!$exportable) {
        $failures[] = 'no table produced a MySQL fingerprint, so there was nothing to export or compare';
    } else {
        // export_open() refuses an existing path, which is the guard against citing a stale
        // export as the current one. Clear it rather than let a leftover from a killed run throw.
        @unlink($sqlite_path);
        $export = slimstat_fp2_export_snapshot($db, $sqlite_path, $exportable);

        foreach ($exportable as $suffix) {
            list($columns, $order_by) = $pinned[$suffix];
            $php[$suffix] = $export['tables'][$suffix];

            list($decoded, $raw) = $read($suffix, $order_by, $mysql[$suffix]['manifest_hash']);
            if (null === $decoded || !isset($decoded['chained_hash'])) {
                // The reader ENFORCES two of the four equalities as preconditions: it raises when
                // the export's manifest is not the one derived here, and open_export() raises
                // when `_meta.spec` is not the version it implements. Both therefore arrive as a
                // REFUSAL, never as a difference — and a refusal printed raw reads as a crashed
                // interpreter, sending the next reader to look at python3 first. So it is named.
                $err  = isset($decoded['error']) ? (string) $decoded['error'] : $raw;
                $what = (false !== stripos($err, 'manifest'))
                    ? 'the SCHEMA carried by the export is not the one derived here from '
                        . 'Schema::columns() — the reader refuses that as a precondition, so it '
                        . 'surfaces as this refusal and not as a manifest_hash difference'
                    : ((false !== stripos($err, 'written under'))
                        ? 'the export declares a different ENCODING_V spec than the reader '
                            . 'implements — again a precondition, not a comparison'
                        : 'the Python reader returned no fingerprint');
                $verdict[$suffix] = 'ERROR';
                $failures[]       = sprintf("%s: %s\n      %s", $suffix, $what, $err);
                continue;
            }
            $python[$suffix] = $decoded;

            // `rows` is the only one of the four compared HERE, and that is deliberate.
            // `manifest_hash` and `spec` were enforced upstream (see the refusal path above), so
            // reaching this line already means they matched: comparing them again would be an
            // equality incapable of receiving unequal arguments, printed as though it had been
            // checked — the shape the CONTROLS block exists to keep out of this file.
            $ok = $compare($suffix, 'rows', $mysql[$suffix]['rows'], $decoded['rows']);

            // The chained hash is the claim, so it is compared by hand — a third number is in
            // hand (PHP encoded the values as it wrote them) and it LOCALISES the drift for free.
            //
            // The localiser used to be pure inference over those three numbers, and it named a
            // cause it structurally excluded. `$php` is encoded from the SAME rows the export
            // wrote (export-snapshot.php yields encode_row($row) for the row it just bound), so
            // a corpus that changed between pass 1 and pass 2 moves `$mysql` ALONE — which is
            // byte-for-byte the topology of a defective SQL encoder. Meanwhile the only branch
            // that mentioned "a write to the corpus mid-run" required all three to differ, which
            // a mid-run write cannot produce. So the sentence naming the cause sat in the one
            // branch the cause could never reach, and the branch the cause DOES reach told the
            // operator their SQL encoder was broken. SlimStat's tracker updates rows in place
            // (Storage::updateRow on every dt_out heartbeat), so this is the ordinary shape of a
            // concurrent write here, not an exotic one — and `rows` stays equal through it.
            //
            // A THIRD READ settles it instead of guessing, and costs nothing on a passing run
            // because it only happens once the hashes have already disagreed: re-run the MySQL
            // side. If it reproduces its own first answer the corpus held still and the SQL path
            // is the outlier; if it does not, the corpus moved under the run and no comparison
            // taken across those two instants means anything.
            if ($decoded['chained_hash'] !== $mysql[$suffix]['chained_hash']) {
                $ph = $php[$suffix]['chained_hash'];
                if ($ph === $mysql[$suffix]['chained_hash']) {
                    $where = 'the export write or the Python re-encode';
                } else {
                    $reread = null;
                    try {
                        $again  = slimstat_fp2_table_fingerprint($db, $db->prefix . $suffix, $columns, $order_by);
                        $reread = $again['chained_hash'];
                    } catch (Throwable $e) {
                        $reread = 'ERROR: ' . $e->getMessage();
                    }
                    $stable = ($reread === $mysql[$suffix]['chained_hash']);
                    $where  = $stable
                        ? (($ph === $decoded['chained_hash'])
                            ? 'the SQL encoder path — the MySQL side re-read identically, so the corpus did not move'
                            : 'all three — suspect the schema; the MySQL side re-read identically, so it was not a mid-run write')
                        : sprintf('THE CORPUS, not the code — the MySQL side does not agree with itself '
                            . '(re-read %s). Nothing compared across those two instants is evidence', $reread);
                }
                $failures[] = sprintf(
                    "%s: CHAINED HASH differs — this is the claim\n"
                    . "      mysql  (SQL encoder)        %s\n"
                    . "      python (re-encoded export)  %s\n"
                    . "      php    (wrote the export)   %s\n"
                    . "      so the drift is in %s",
                    $suffix, $mysql[$suffix]['chained_hash'], $decoded['chained_hash'], $ph, $where
                );
                $ok = false;
            }

            if (!$ok) {
                $verdict[$suffix] = 'FAIL';
            } elseif (0 === (int) $mysql[$suffix]['rows']) {
                // Equal, and equal for a reason that is not evidence. Reported as such.
                $verdict[$suffix] = 'UNPROVEN';
            } else {
                $verdict[$suffix] = 'PASS';
            }
        }
    }

    // ── One result line per table, rendered in ONE place ──────────────────────────────────────
    // Including the tables that failed or errored: a table missing from the RESULTS block reads
    // as a table that was fine, and the only place a reader looks is this block.
    $proven = $shape = [];
    foreach ($suffixes as $suffix) {
        $state   = isset($verdict[$suffix]) ? $verdict[$suffix] : 'ERROR';
        $rows    = isset($mysql[$suffix]) ? (int) $mysql[$suffix]['rows'] : -1;
        $shape[] = sprintf('%s=%d(%s)', $suffix, $rows, $state);
        if ('PASS' === $state) {
            $proven[] = $suffix;
        }

        if ('UNPROVEN' === $state) {
            $results[] = sprintf('[%-8s] %-12s rows=0 on both sides — a chain over zero rows is sha256(spec) '
                . 'by construction, so nothing about this table was compared', $state, $suffix);
        } elseif (isset($python[$suffix])) {
            $results[] = sprintf('[%-8s] %-12s rows=%d  chained=%s  manifest=%s  spec=%s',
                $state, $suffix, $rows, $mysql[$suffix]['chained_hash'],
                $mysql[$suffix]['manifest_hash'], $mysql[$suffix]['spec']);
        } else {
            $results[] = sprintf('[%-8s] %-12s no comparison was made — see the FAIL block', $state, $suffix);
        }
    }

    // ── CONTROL 1 — the corpus is not empty ───────────────────────────────────────────────────
    $control(
        count($proven) > 0, 1, 'NON-VACUOUS',
        'needs at least one table compared with rows>0 — ' . implode(' ', $shape)
        . $indent . 'a chain over zero rows is sha256(spec) on BOTH sides, equal by construction; an empty'
        . $indent . 'table is reported UNPROVEN rather than PASS and cannot carry this run on its own'
    );

    // ── CONTROL 7's evidence — gathered HERE, BEFORE control 2 corrupts the export ────────────
    // The chain is order-dependent, so the ORDER BY is part of the identity, and the reader is
    // handed it OUT OF BAND precisely so an export written under a different ordering could be
    // detected — read_export.py says so in as many words. Nothing ever tested that argument, and
    // it is weaker than it reads. The export is written in MySQL's order, so SQLite rowid order
    // ALREADY equals `ORDER BY id`: a reader whose sort did nothing at all would return the same
    // rows in the same sequence and the same hash, and the gate would still print PASS. Worse,
    // SQLite resolves a double-quoted token that is not a column as a STRING LITERAL rather than
    // erroring — measured below on this build, not quoted from the documentation — so an order_by
    // naming a column the export does not carry (`vid_hash`, which the pinned set deliberately
    // excludes) would sort by a constant, silently, while the MySQL side genuinely ordered by it.
    // CONTROL 2 cannot see any of this: it flips a VALUE byte and never perturbs an ORDER.
    //
    // Two things are therefore measured. (a) the ORDER BY column IS a column of the export, per
    // table, read out of the file's own catalogue. (b) the reader's ORDER BY GOVERNS the sequence
    // it folds: the same export is read again under a DIFFERENT pinned column — one whose first
    // row under that ordering is unambiguous and is a different row — and the chained hash must
    // MOVE while `rows` stays put, which is a pure reordering of one multiset. The expected
    // manifest hash is re-derived here for that ordering, because the manifest hashes
    // `ORDER BY <col>` verbatim and the reader refuses a read whose manifest it was not given.
    //
    // (b) costs one more full Python re-encode, so it is done over the SMALLEST proven table.
    $c7     = 'no table produced an export, so nothing about the ordering could be measured';
    $c7_ok  = false;
    $c7_dqs = 'the double-quoted-identifier behaviour of this SQLite was not measured';
    $vcols  = [];
    if ($exportable) {
        $sqlite = new SQLite3($sqlite_path);

        // (a) Quoted the way export-snapshot.php quotes them, so the two cannot disagree about
        // what an identifier is.
        $absent = [];
        foreach ($exportable as $suffix) {
            $qt   = '"' . str_replace('"', '""', $suffix) . '"';
            $have = [];
            $info = $sqlite->query('PRAGMA table_info(' . $qt . ')');
            while ($info && ($r = $info->fetchArray(SQLITE3_ASSOC))) {
                $have[] = $r['name'];
            }
            if (!in_array($pinned[$suffix][1], $have, true)) {
                $absent[] = sprintf('%s: ORDER BY %s is not one of the %d columns the export carries, '
                    . 'so the reader sorts by a token that is not a column',
                    $suffix, $pinned[$suffix][1], count($have));
            }
        }
        // Why (a) is not decoration, on THIS build rather than in principle.
        $dqs    = @$sqlite->query('SELECT 1 FROM "' . str_replace('"', '""', $exportable[0])
            . '" ORDER BY "no such column" LIMIT 1');
        $c7_dqs = (false === $dqs)
            ? 'this SQLite REJECTS `ORDER BY "no such column"`, so an order_by the export does not '
                . 'carry would error rather than degenerate'
            : 'this SQLite ACCEPTS `ORDER BY "no such column"` as a string literal and returns scan '
                . 'order, so an order_by the export does not carry would sort by a constant, silently';

        // (b) A pure reordering, built on the smallest proven table that can supply one.
        $alt = $alt_first = $ord_first = '';
        // EVERY proven table is tried, smallest first, until one yields a reordering — not just
        // the smallest. Committing to one table before the candidate search is the same edge the
        // candidate CAP sat on one level down, and it is reachable rather than theoretical: a
        // table holding ONE row (an install with a single recorded event — the ordinary state of
        // a fresh site, and this file advertises being pointed at whatever install you like)
        // rejects every candidate, because one row is trivially its own unambiguous minimum AND
        // is the same row under every ordering. The run would then exit 1 with CONTROL 7 unmet
        // while the equality it exists to test held on every table — a failure for a reason that
        // is not a defect. Worse in the harness: the control self-test requires that forcing
        // control n fails EXACTLY one control, so a legitimately-unmet 7 would make all nine
        // forced runs report two failures and attribute none of them.
        $order  = $proven;
        usort($order, function ($a, $b) use ($mysql) {
            return $mysql[$a]['rows'] - $mysql[$b]['rows'];
        });
        $tried  = [];
        $vt     = null;
        foreach ($order as $cand_table) {
            list($vcols, $vob) = $pinned[$cand_table];
            $qt        = '"' . str_replace('"', '""', $cand_table) . '"';
            $qo        = '"' . str_replace('"', '""', $vob) . '"';
            $ord_first = (string) $sqlite->querySingle(
                'SELECT ' . $qo . ' FROM ' . $qt . ' ORDER BY ' . $qo . ' LIMIT 1');
            // EVERY pinned column is tried, in manifest order, until one qualifies. A cap here
            // would fail the control for a reason that is not the property under test, and that
            // is not hypothetical: this loop first carried a cap of six, and on this corpus the
            // only qualifying candidate in slim_events is the SIXTH — every earlier one is either
            // tied at the front or first at the same row. The cap sat exactly on the edge of a
            // false FAIL. Two SQLite queries per rejected candidate is the price of not having it.
            foreach ($vcols as $c) {
                if ($c[0] === $vob) {
                    continue;
                }
                $tried[] = $cand_table . '.' . $c[0];
                $qc      = '"' . str_replace('"', '""', $c[0]) . '"';
                // `IS`, not `=`: SQLite sorts NULLs first, so the first row under a candidate may
                // hold NULL — and `= NULL` matches nothing, which would read as "unambiguous".
                $ties = (int) $sqlite->querySingle('SELECT COUNT(*) FROM ' . $qt . ' WHERE ' . $qc
                    . ' IS (SELECT ' . $qc . ' FROM ' . $qt . ' ORDER BY ' . $qc . ' LIMIT 1)');
                if (1 !== $ties) {
                    // Ties at the front mean the reader's first row is whichever the sorter picked,
                    // so a hash that failed to move would be ambiguous rather than evidence.
                    continue;
                }
                $first = (string) $sqlite->querySingle(
                    'SELECT ' . $qo . ' FROM ' . $qt . ' ORDER BY ' . $qc . ' LIMIT 1');
                if ($first === $ord_first) {
                    continue;
                }
                $vt        = $cand_table;
                $alt       = $c[0];
                $alt_first = $first;
                break 2;
            }
        }
        $sqlite->close();

        if ($absent) {
            $c7 = implode('; ', $absent);
        } elseif (!$proven) {
            $c7 = 'every exported table carries its ORDER BY column, but no table was compared with '
                . 'rows>0, so the re-read that shows the reader SORTS could not be made';
        } elseif ('' === $alt) {
            $c7 = sprintf('every exported table carries its ORDER BY column, but across ALL %d proven '
                . 'table(s) none of the %d candidate columns has a first row that is both unambiguous '
                . 'and a different row (tried %s), so no pure reordering could be built anywhere',
                count($proven), count($tried), implode(', ', $tried));
        } else {
            list($alt_read, $alt_raw) = $read($vt, $alt, slimstat_fp2_manifest_hash($vcols, $alt));
            if (null === $alt_read || !isset($alt_read['chained_hash'])) {
                $c7 = sprintf('the reader returned no fingerprint for %s re-read under ORDER BY %s — %s',
                    $vt, $alt, $alt_raw);
            } else {
                $moved = $alt_read['chained_hash'] !== $python[$vt]['chained_hash'];
                $same  = (int) $alt_read['rows'] === (int) $python[$vt]['rows'];
                $c7_ok = $moved && $same;
                $c7    = sprintf('every exported table carries its ORDER BY column; %s re-read under '
                    . 'ORDER BY %s, whose first row is a different one (%s %s, not %s), over %d rows '
                    . 'against %d', $vt, $alt, $pinned[$vt][1], $alt_first, $ord_first,
                    (int) $alt_read['rows'], (int) $python[$vt]['rows'])
                    . $indent . sprintf('python chained %s -> %s%s',
                        substr($python[$vt]['chained_hash'], 0, 16),
                        substr($alt_read['chained_hash'], 0, 16),
                        $moved
                            ? ($same
                                ? ', so the sequence folded is the one the reader was told to use'
                                : ' — but `rows` moved too, so this was not a pure reordering')
                            : ' — IT DID NOT MOVE, so the ORDER BY the reader was handed did nothing');
            }
        }
    }

    // ── CONTROL 2 — the comparison could have failed ──────────────────────────────────────────
    // Demonstrated, not asserted: flip one stored value in the export and the Python re-encode
    // must move away from the number MySQL produced. If it does not move, the equality above was
    // not capable of detecting a difference and the whole run is worthless.
    $c2 = 'no table was compared with rows>0, so nothing could be corrupted to prove the comparison bites';
    $c2_ok = false;
    if ($proven) {
        // The SMALLEST proven table, for the same reason CONTROL 7 picks one: this costs a whole
        // Python re-encode of whatever it lands on, and the claim — "a one-byte flip at MIN(rowid)
        // moves the chain" — has a reach of exactly one row in either table. Measured on the floor
        // corpus that is 9,000 x 7 cells instead of 30,000 x 33, and the self-test lane pays it
        // fourteen more times; on the 443k corpus it is ~0.6 s against ~7.7 s.
        $victim = $proven[0];
        foreach ($proven as $s) {
            if ($mysql[$s]['rows'] < $mysql[$victim]['rows']) {
                $victim = $s;
            }
        }
        list($columns, $order_by) = $pinned[$victim];

        // A varchar column, and not the ORDER BY one — perturbing the ordering column would
        // reorder the chain, which is a different (and weaker) claim than changing a value.
        $col = null;
        foreach ($columns as $c) {
            if ('str' === slimstat_fp2_kind($c[1]) && $c[0] !== $order_by) {
                $col = $c[0];
                break;
            }
        }
        if (null === $col) {
            $c2 = sprintf('%s pins no varchar column outside its ORDER BY, so no byte-level '
                . 'corruption was built', $victim);
        } else {
            // Quoted the way export-snapshot.php quotes them, so the two cannot disagree about
            // what an identifier is.
            $qt     = '"' . str_replace('"', '""', $victim) . '"';
            $qc     = '"' . str_replace('"', '""', $col) . '"';
            $sqlite = new SQLite3($sqlite_path);
            $rowid  = (int) $sqlite->querySingle('SELECT MIN(rowid) FROM ' . $qt);
            $before = $sqlite->querySingle('SELECT ' . $qc . ' FROM ' . $qt . ' WHERE rowid = ' . $rowid);
            // XOR of the last byte, so the result DIFFERS whatever the value was. NULL and '' have
            // no byte to flip and encode as sentinels, so they become one byte instead: \NUL and
            // \EMPTY versus '00' are three distinct tokens.
            $after = (null === $before || '' === $before)
                ? "\x00"
                : substr($before, 0, -1) . chr(ord(substr($before, -1)) ^ 0x01);

            $upd = $sqlite->prepare('UPDATE ' . $qt . ' SET ' . $qc . ' = :v WHERE rowid = :r');
            $upd->bindValue(':v', $after, SQLITE3_BLOB);
            $upd->bindValue(':r', $rowid, SQLITE3_INTEGER);
            $upd->execute();
            $changed = $sqlite->changes();
            $sqlite->close();

            if (1 !== $changed) {
                $c2 = sprintf('the corruption did not apply (%d rows changed in %s.%s at rowid %d)',
                    $changed, $victim, $col, $rowid);
            } else {
                // Same expected manifest hash: the schema is untouched, so a moved hash can only
                // be the DATA. A reader that errored would also "not match", which is why the
                // presence of a fingerprint is required before the inequality counts.
                list($poison, $praw) = $read($victim, $order_by, $mysql[$victim]['manifest_hash']);
                if (null === $poison || !isset($poison['chained_hash'])) {
                    $c2 = sprintf('the reader errored on the corrupted export instead of returning '
                        . 'a moved hash — %s', $praw);
                } else {
                    $c2_ok = $poison['chained_hash'] !== $python[$victim]['chained_hash']
                        && $poison['chained_hash'] !== $mysql[$victim]['chained_hash'];
                    $c2 = sprintf(
                        'flipped one byte of %s.%s at rowid %d: %s -> %s',
                        $victim, $col, $rowid,
                        substr(bin2hex((string) $before), -16) ?: '(null)', substr(bin2hex($after), -16)
                    )
                    . $indent . sprintf('python chained %s -> %s%s',
                        substr($python[$victim]['chained_hash'], 0, 16),
                        substr($poison['chained_hash'], 0, 16),
                        $c2_ok ? ', and it no longer equals MySQL — the comparison bites' : ' — IT DID NOT MOVE');
                }
            }
        }
    }
    $control($c2_ok, 2, 'CAN-FAIL', $c2);

    // ── CONTROL 3 — two implementations actually ran ──────────────────────────────────────────
    // A gate that silently does not run is this workspace's most repeated defect, and a missing
    // python3 is exactly that shape: shell_exec returns the shell's not-found message,
    // json_decode returns null, and the run would report an absence as agreement.
    //
    // The detail says ONLY what the predicate evaluates. It used to print the two strongest
    // sentences in this file — that the MySQL side is evaluated by the server, and that
    // encode_row() is never called on that path — while its predicate observed neither: python3
    // on PATH, a reader file on disk, a table read back. Whichever side had actually encoded, all
    // three stayed true and the [OK] was printed verbatim. Those claims moved to CONTROL 4, which
    // measures the reachable part of them; $server and $charset stay here as PROVENANCE, labelled.
    $c3_ok = (false !== strpos($py_raw, 'Python 3')) && is_file($reader) && count($python) > 0;
    $control(
        $c3_ok, 3, 'INDEPENDENT',
        sprintf('a real interpreter answered and a fingerprint came back: %s, reader=%s, tables-read=%d.',
            $py_raw ?: 'python3 NOT FOUND', $reader, count($python))
        . $indent . 'That the export side re-encodes stored VALUES rather than reading tokens back is a'
        . $indent . 'property of export-snapshot.php and encoding_v1.py, not an observation of this run;'
        . $indent . 'what IS observed here is that the second process ran and returned a fingerprint.'
        . $indent . sprintf('Provenance, printed and not tested: server %s, charset %s.',
            $server ?: '(no VERSION())', $charset)
    );

    // ── CONTROL 4 — the MySQL side is evaluated BY THE SERVER ─────────────────────────────────
    // The claim CONTROL 3 used to assert. What is reachable from outside slimstat_fp2_table_-
    // fingerprint() is measured here: the expression it builds is SQL, every pinned field in it
    // is NULL-guarded, and the server's evaluation of that expression over a real row of this
    // corpus is byte-identical to the PHP encoder's. What is NOT reachable is which of the two
    // that function then folded — said in the detail, and in WHAT IT DOES NOT PROVE.
    $c4_notes = $c4_probed = [];
    foreach ($suffixes as $suffix) {
        if (!isset($server_side[$suffix])) {
            continue;
        }
        list($ok, $note) = $server_side[$suffix];
        $c4_notes[]      = ($ok ? 'ok   ' : 'FAIL ') . $note;
        $c4_probed[]     = $ok;
    }
    // Met only if something WAS probed and everything probed agreed. An empty probe set is
    // "nothing was measured", which is not "nothing disagreed" — that distinction is the whole
    // reason this block exists, so it is not lost in the predicate that reports it.
    $c4_ok = $c4_probed && !in_array(false, $c4_probed, true);
    $control(
        $c4_ok, 4, 'SERVER-SIDE',
        ($c4_notes
            ? implode($indent, $c4_notes)
            : 'no table with rows>0 reached the probe, so nothing was measured about where the '
                . 'encoding happened')
        . $indent . "each pinned field sits in its own IF(col IS NULL, '\\\\NUL', …) guard, checked per"
        . $indent . 'column: CONCAT propagates a NULL argument, CONCAT_WS would DROP it and hash a row one'
        . $indent . 'field short, so the guards are what makes the CONCAT choice safe.'
        . $indent . 'NOT observed: which encoder slimstat_fp2_table_fingerprint() folded. A PHP fold there'
        . $indent . 'would agree with the Python side on every field and move nothing this file prints.'
    );

    // ── CONTROL 5 — nothing scheduled can rewrite the corpus under the run ────────────────────
    // This file only SELECTs; the BOOT that runs it does not. Sampled before either pass, and
    // therefore after the boot that may already have spawned the job — so this is a refusal to
    // certify such a run, not a guard that prevents the write.
    $control(
        $c5_ok, 5, 'CORPUS-STABLE',
        sprintf('DISABLE_WP_CRON=%s, wp_slimstat_purge %s, auto_purge=%s',
            $cron_off ? 'true' : 'NOT DEFINED',
            (false === $purge_at) ? 'not scheduled' : 'scheduled ' . gmdate('Y-m-d H:i', (int) $purge_at) . 'Z',
            null === $auto_purge ? 'unknown' : $auto_purge . ' days')
        . $indent . '`wp eval-file` boots WordPress with the plugin active and core hooks wp_cron() on init,'
        . $indent . 'so that boot can spawn wp_slimstat_purge(): it DELETEs rows past the horizon and then'
        . $indent . 'OPTIMIZE-TABLEs both analytics tables — against the corpus this run is certifying, and'
        . $indent . 'on this install that corpus is the only copy of the parity dataset.'
        . $indent . 'Run it as: wp --exec="define(\'DISABLE_WP_CRON\', true);" eval-file '
            . 'tests/docker/verify-export-fingerprint.php'
        . $indent . 'A purge landing mid-run is also the likeliest cause of a `rows` mismatch below.'
    );

    // ── CONTROL 6 — the schema being hashed is the schema the server holds, and it is ordered ─
    // manifest_hash is derived from Schema::columns() on BOTH sides: PHP parses it, the export
    // carries that parse in `_manifest`, and Python re-hashes what it was handed. So the two
    // agreeing proves a string survived a copy — the circularity this file avoids on the VALUE
    // half by refusing to store tokens, reappearing on the SCHEMA half. The server's own
    // catalogue is the only reader of that schema neither encoder shares, so it is asked — for
    // three things, not one: the pinned columns' canonical type and nullability, that the server
    // holds NO column the manifest fails to declare (the census used to walk only the pinned set,
    // so an undeclared live column was invisible to a control whose name is this heading), and
    // that the ORDER BY the manifest hashes is a pinned column carrying a single-column UNIQUE
    // index over a NOT NULL column — without which the ordering has ties and the chained hash is
    // not a function of the corpus at all.
    $c6_notes = $c6_probed = [];
    foreach ($suffixes as $suffix) {
        if (!isset($live_schema[$suffix])) {
            continue;
        }
        list($ok, $note) = $live_schema[$suffix];
        $c6_notes[]      = ($ok ? 'ok   ' : 'FAIL ') . $note;
        $c6_probed[]     = $ok;
    }
    // Same predicate shape as CONTROL 4, for the same reason: an empty probe set is "nothing was
    // compared", which is not "nothing disagreed".
    $c6_ok = $c6_probed && !in_array(false, $c6_probed, true);
    $control(
        $c6_ok, 6, 'SCHEMA-LIVE',
        ($c6_notes
            ? implode($indent, $c6_notes)
            : 'no table reached the schema probe, so the manifest was compared against nothing')
        . $indent . 'compared through slimstat_fp2_canonical_type(), the form the manifest hashes:'
        . $indent . 'integer display width dropped (8.0.19 removed it, so a raw compare would report'
        . $indent . 'drift between two servers holding one schema) and varchar length KEPT, where a'
        . $indent . 'narrowed column is data loss and must move the hash.'
        . $indent . 'NOT observed HERE: that canonical_type() is itself right — it is applied to BOTH'
        . $indent . 'sides of this comparison, so a bug inside it agrees with itself. It is not blind'
        . $indent . 'everywhere: encoding_v1.py canonicalises the RAW declared type independently, so a'
        . $indent . 'PHP-only bug moves the PHP manifest hash alone and the reader refuses the export.'
        . $indent . 'What no layer sees is both implementations normalising the same wrong way.'
        . $indent . 'The UNIQUE half is the CATALOGUE\'s answer, not a scan: it says ties cannot arise,'
        . $indent . 'not that this corpus happens to have none.'
    );

    // ── CONTROL 7 — the reader's ORDER BY is what fixes the sequence ──────────────────────────
    // Evidence gathered above, before CONTROL 2 corrupted the export. The claim under test is
    // read_export.py's own: that passing the ordering out of band lets it detect an export
    // written under a different one. Over a file whose rowid order already equals that ordering,
    // an inert sort is indistinguishable from a working one — so the run reorders instead.
    $control(
        $c7_ok, 7, 'ORDER-BOUND',
        $c7
        . $indent . $c7_dqs . '.'
        . $indent . 'The export is written in MySQL\'s order, so SQLite rowid order already equals the'
        . $indent . 'pinned ORDER BY: a reader whose sort did nothing would return that same sequence and'
        . $indent . 'the same hash. CONTROL 2 flips a VALUE and never perturbs an order, so nothing else'
        . $indent . 'in this run says the comparison is order-sensitive at all.'
        . $indent . 'NOT observed: that MySQL and SQLite break TIES the same way — nothing here could see'
        . $indent . 'it. CONTROL 6 refusing an ORDER BY without a single-column UNIQUE index is what'
        . $indent . 'removes ties; the two together are what make "the sequence" one sequence.'
    );

    // ── CONTROL 8 — the empty-string branch means the same thing on both sides ────────────────
    // The one adversarial class verify-sql-encoder.php cannot reach, measured over the corpus
    // instead of delegated to a gate that binds every string as _binary.
    $c8_notes = $c8_probed = [];
    foreach ($suffixes as $suffix) {
        if (!isset($empty_exact[$suffix])) {
            continue;
        }
        list($ok, $note) = $empty_exact[$suffix];
        $c8_notes[]      = ($ok ? 'ok   ' : 'FAIL ') . $note;
        $c8_probed[]     = $ok;
    }
    $c8_ok = $c8_probed && !in_array(false, $c8_probed, true);
    $control(
        $c8_ok, 8, 'EMPTY-EXACT',
        ($c8_notes
            ? implode($indent, $c8_notes)
            : 'no table with rows>0 reached the divergence scan, so nothing was measured about the '
                . 'empty-string branch')
        . $indent . 'row_sql() emits IF(col = \'\', …) and `=` is a COLLATION operation; the PHP and'
        . $indent . 'Python encoders both test BYTE LENGTH. Under PAD SPACE those part on a pad-only'
        . $indent . 'value, and verify-sql-encoder.php cannot reach it — it binds strings as _binary,'
        . $indent . 'which is NO PAD, so such a literal takes the HEX branch there instead.'
        . $indent . 'Measured with the COLUMN\'s collation and not the session\'s: those need not agree,'
        . $indent . 'and where they do not the session\'s answer would be about nothing in this corpus.'
        . $indent . 'NOT observed: equivalence. This says the class on which the two tests differ is'
        . $indent . 'EMPTY here — one pad-only value in a pinned varchar fails this control and the run.'
    );

    // ── CONTROL 9 — the chain covers the whole table, not a prefix of it ──────────────────────
    $c9_notes = $c9_probed = [];
    foreach ($suffixes as $suffix) {
        if (!array_key_exists($suffix, $row_count)) {
            continue;
        }
        $n = $row_count[$suffix];
        if (null === $n) {
            $c9_notes[]  = sprintf('FAIL %s: the server did not answer COUNT(*), so coverage was '
                . 'claimed by the streaming count alone', $suffix);
            $c9_probed[] = false;
            continue;
        }
        $folded   = isset($mysql[$suffix]) ? (int) $mysql[$suffix]['rows'] : -1;
        $exported = isset($php[$suffix]) ? (int) $php[$suffix]['rows'] : null;
        $ok       = ($n === $folded) && (null === $exported || $exported === $n);
        $c9_notes[]  = sprintf('%s%s: COUNT(*)=%d, folded=%d, exported=%s',
            $ok ? 'ok   ' : 'FAIL ', $suffix, $n, $folded,
            null === $exported ? '(the export was not reached)' : $exported);
        $c9_probed[] = $ok;
    }
    $c9_ok = $c9_probed && !in_array(false, $c9_probed, true);
    $control(
        $c9_ok, 9, 'WHOLE-CORPUS',
        ($c9_notes
            ? implode($indent, $c9_notes)
            : 'no table reached the row-count probe, so no number outside the streaming loops was read')
        . $indent . 'Both streaming passes drain with `while ($row = $result->fetch_row())`, and WordPress'
        . $indent . 'sets mysqli_report(MYSQLI_REPORT_OFF): a mid-stream failure returns FALSE, which that'
        . $indent . 'idiom cannot tell from end-of-rows. A position-deterministic read error stops BOTH at'
        . $indent . 'the same row, so `rows` agrees over a PREFIX and the truncated number then supplies'
        . $indent . 'the coverage claim. COUNT(*) is the only count in this run outside that idiom.'
        . $indent . 'NOT observed: that the counts were taken at one instant. COUNT(*) is issued right'
        . $indent . 'after the fingerprint pass, in the same drained window, so a concurrent write moves'
        . $indent . 'it. A mismatch here is one of three things and this run separates none of them: a'
        . $indent . 'truncated read, the SCHEDULED purge (which CONTROL 5 does observe), or an ordinary'
        . $indent . 'tracker INSERT, which nothing here observes — CONTROL 5 reads the cron table only.'
    );
} catch (Throwable $e) {
    $failures[] = sprintf('the run aborted — %s: %s (%s:%d)',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
} finally {
    @unlink($sqlite_path);
}

if (!$controls) {
    $controls[] = '[!!] 0 ABORTED       the run aborted before any control could be evaluated — see the FAIL block';
}
if (!$results) {
    $results[] = '(no table was compared)';
}

$rows_compared = 0;
$compared      = [];
foreach ($suffixes as $suffix) {
    if (isset($verdict[$suffix]) && 'PASS' === $verdict[$suffix]) {
        $rows_compared += $mysql[$suffix]['rows'];
        $compared[]     = sprintf('%s (%d rows)', $suffix, $mysql[$suffix]['rows']);
    }
}

// `forced=` is on the greppable line rather than only in the control's detail, because that line
// is what a harness collects and what a person greps six months later. A self-test run that
// looked like a certifying run in the artifact is the same defect as a control that looks met.
printf("SLIMSTAT-EXPORT-FP server=%s charset=%s python=%s spec=%s proven=%d/%d rows=%d forced=%d\n",
    $server, $charset, $py_ver, SLIMSTAT_FP2_SPEC, count($compared), count($suffixes), $rows_compared, $forced);

echo "CONTROLS (before any result, and each one can fail this run on its own)\n";
foreach ($controls as $c) {
    echo '  ' . $c . "\n";
}
echo "RESULTS (mysql = the SQL encoder; export = PHP wrote the values, Python re-encoded them)\n";
foreach ($results as $r) {
    echo '  ' . $r . "\n";
}

if ($failures) {
    fwrite(STDERR, 'FAIL: the MySQL fingerprint and its SQLite export disagree, or a control was unmet ('
        . count($failures) . ")\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo ($forced ? 'PASS (SELF-TEST forced=' . $forced . ', certifies nothing): ' : 'PASS: ')
    . 'the MySQL fingerprint equals the Python re-encode of its export over ' . implode(', ', $compared)
    . " (chained_hash and rows agree HERE; manifest_hash and spec agree because the reader refused\n"
    . "      to return a fingerprint until they did. A one-byte change in the export moved the hash\n"
    . "      and so did re-reading it under another ordering, so the comparison could have failed on\n"
    . "      a value and on a sequence; the rows folded are the rows the server counts)\n";

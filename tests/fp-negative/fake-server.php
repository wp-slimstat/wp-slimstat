<?php
// A MySQL-SHAPED SERVER over SQLite, so tests/docker/verify-export-fingerprint.php can be RUN —
// controls, probes, export, Python reader and all — on a laptop with no container.
//
// WHY THIS EXISTS. The subject's nine controls are only worth what their FAILURE paths are worth,
// and every failure path in that file needs a live server to reach: a corpus, a catalogue, a row
// to probe, a COUNT(*). Reading the file cannot tell an armed control from a decorative one —
// that is PITFALLS' most repeated shape and the reason the subject carries its own
// SLIMSTAT_FP_FORCE_CONTROL_FAIL hook. This harness reaches those paths for real: it drives the
// subject unmodified, with a $wpdb whose statements are actually evaluated, so a negative test
// that "passes because the code never ran" is not available as an outcome.
//
// WHAT IS FAITHFUL, AND WHAT IS NOT — read this before citing a result from it.
//
//   FAITHFUL. The row-encoding expression slimstat_fp2_row_sql() builds is EVALUATED, not
//   stubbed: CONCAT/CHAR_LENGTH/HEX/CAST/IF run against stored bytes and the token comes back to
//   PHP the way it would from mysqld. Backslash-escape folding in string literals is emulated
//   ('\\NUL' -> \NUL) because MySQL does it with NO_BACKSLASH_ESCAPES off and SQLite never does.
//   PAD SPACE equality is emulated (`'  ' = ''` is TRUE) because the corpus's varchars are
//   utf8mb3_general_ci, which is exactly the class CONTROL 8 exists for. Values come back as
//   STRINGS, as mysqli returns them.
//
//   NOT FAITHFUL, and it matters for exactly one control. information_schema here is a WRITTEN
//   FIXTURE (catalogue.php), not a server catalogue. Against a real server CONTROL 6 asks a third
//   party what the schema is; here it compares the manifest against a hand-written table, so a
//   PASS says the two agree, not that any server holds that schema. The harness therefore proves
//   CONTROL 6 is ARMED (drift in the fixture fails the run), never that the schema is right.
//
//   NOT COVERED. Charset/collation transcoding, MySQL 5.6/5.7 HEX and CAST differences, and
//   BIGINT UNSIGNED above 2^63-1 are the container matrix's job (run-rollup-floor.sh) and this
//   harness does not simulate them.
//
// No declare(strict_types=1) and no PHP 8 syntax: the subject is a PHP 7.4-floor `wp eval-file`
// script and everything it loads must stay loadable the same way.

// ── WordPress-shaped surface the subject and the bench libs reach for ────────────────────────
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * One streamed result set, shaped like mysqli_result for the two `while ($r = $x->fetch_row())`
 * loops the subject's two passes are built on.
 *
 * Values are cast to STRING (NULL excepted) because that is what mysqli hands back, and the
 * encoders' integer path is deliberately string-based — handing PHP ints here would test a
 * conversion the real run never performs.
 */
class Slimstat_Fake_Result
{
    private $res;

    public function __construct(SQLite3Result $res)
    {
        $this->res = $res;
    }

    /**
     * One row, cast the way mysqli hands rows back. Both readers in this file go through here,
     * so "what a row from this server looks like" is decided once — the streamed path and the
     * buffered one drifting apart on that would show up as an encoder disagreement and be read
     * as a defect in the subject.
     */
    public static function as_mysqli_row(array $row)
    {
        $out = [];
        foreach ($row as $v) {
            $out[] = (null === $v) ? null : (string) $v;
        }
        return $out;
    }

    public function fetch_row()
    {
        $row = $this->res->fetchArray(SQLITE3_NUM);
        return is_array($row) ? self::as_mysqli_row($row) : false;
    }

    public function free()
    {
        $this->res->finalize();
    }
}

/**
 * The handle. It EXTENDS mysqli because slimstat_fp2_table_fingerprint() and
 * slimstat_fp2_mysql_rows() both refuse anything else — `$dbh instanceof mysqli` — and that
 * refusal is load-bearing in the real file, so it is satisfied rather than removed.
 *
 * mysqli's own declared properties (`error`, `connect_errno`, …) are NOT readable on an instance
 * that never connected: PHP's property handler answers "object is already closed". Nothing on a
 * SUCCESS path reads them (fingerprint-v2.php and export-snapshot.php touch $dbh->error only
 * after real_query()/use_result() have already returned falsy), so this fake never returns falsy
 * from either — a simulated query failure would have to be raised as a Throwable instead.
 */
class Slimstat_Fake_Mysqli extends mysqli
{
    /** @var Slimstat_Fake_Server */
    public $engine;
    /** @var SQLite3Result|null */
    public $pending;

    public function __construct($engine)
    {
        // Deliberately NOT parent::__construct(): that would try to connect.
        $this->engine  = $engine;
        $this->pending = null;
    }

    #[\ReturnTypeWillChange]
    public function real_query($query)
    {
        $this->pending = $this->engine->run($query);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function use_result()
    {
        $res           = $this->pending;
        $this->pending = null;
        return new Slimstat_Fake_Result($res);
    }
}

/**
 * The engine: a SQLite corpus plus the MySQL semantics the subject's SQL depends on.
 */
class Slimstat_Fake_Server
{
    /** @var SQLite3 */
    public $corpus;
    /** @var array */
    public $cfg;
    /** @var string[] every statement this server was asked to evaluate, in order */
    public $log = [];

    /**
     * A write to the corpus BETWEEN the fingerprint pass and the export pass — the shape
     * SlimStat's own tracker produces on every dt_out heartbeat, and the one the subject's drift
     * localiser exists to tell apart from a defective SQL encoder.
     *
     * It has to be driven from in here because the two passes are consecutive statements inside
     * the subject and nothing outside it runs in between. The trigger is the first statement
     * whose select list starts with a quoted identifier: the fingerprint and both probes select
     * an EXPRESSION, and only slimstat_fp2_mysql_rows() selects bare columns.
     *
     * @var callable|null
     */
    public $between_passes = null;
    private $between_fired = false;

    public function __construct(SQLite3 $corpus, array $cfg)
    {
        $this->corpus = $corpus;
        $this->cfg    = $cfg;
        $this->register_functions();
    }

    private function register_functions()
    {
        $cfg = $this->cfg;

        // CONCAT propagates NULL. SQLite's own concat() (3.44+) SKIPS NULL arguments, which is
        // CONCAT_WS's behaviour — the exact function encoding-spec.md indicts and the exact reason
        // row_sql() does not use it. Leaving the built-in in place would have made the harness
        // agree with a defect the subject exists to detect.
        $this->corpus->createFunction('concat', function () {
            $args = func_get_args();
            foreach ($args as $a) {
                if (null === $a) {
                    return null;
                }
            }
            return implode('', array_map('strval', $args));
        }, -1);

        // CHAR_LENGTH counts CHARACTERS. Every value it is applied to here is an ASCII token
        // (a hex rendering, \NUL or \EMPTY), so this and OCTET_LENGTH differ only where the
        // subject means them to.
        $this->corpus->createFunction('char_length', function ($v) {
            return (null === $v) ? null : mb_strlen((string) $v, 'UTF-8');
        }, 1);

        $this->corpus->createFunction('octet_length', function ($v) {
            return (null === $v) ? null : strlen((string) $v);
        }, 1);

        $this->corpus->createFunction('left', function ($v, $n) {
            return (null === $v) ? null : substr((string) $v, 0, (int) $n);
        }, 2);

        // COLLATION() is metadata about the expression, so it answers from configuration — the
        // one place this harness cannot compute what a server would.
        $this->corpus->createFunction('collation', function ($v) use ($cfg) {
            return $cfg['collation'];
        }, 1);

        // `= ''` under the COLUMN's collation. utf8mb3_general_ci is PAD SPACE: trailing spaces
        // are ignored, so `'  ' = ''` is TRUE while both byte-length encoders say otherwise.
        // That divergence is CONTROL 8's entire subject, so it is modelled rather than assumed
        // away — flip 'pad' to 'nopad' to get the binary-collation behaviour instead.
        $pad = ('pad' === $cfg['collation_pad']);
        $this->corpus->createFunction('slim_eq_empty', function ($v) use ($pad) {
            if (null === $v) {
                return null;
            }
            $s = (string) $v;
            return ($pad ? ('' === rtrim($s, ' ')) : ('' === $s)) ? 1 : 0;
        }, 1);

        // A server whose HEX() renders in lower case. Not hypothetical decoration: HEX/CAST
        // semantics are precisely what run-rollup-floor.sh runs three server versions to pin,
        // and this is the cheapest way to make the SQL encoder and the PHP encoder part company
        // without touching either implementation.
        if (!empty($cfg['hex_lowercase'])) {
            $this->corpus->createFunction('hex', function ($v) {
                return strtolower(bin2hex((string) $v));
            }, 1);
        }
    }

    /**
     * MySQL text -> SQLite text. Every rewrite here is a SEMANTIC one MySQL performs and SQLite
     * does not; none of them touches which rows are read or in what order.
     */
    public function translate($sql)
    {
        // MySQL resolves backslash escapes inside string literals whenever NO_BACKSLASH_ESCAPES
        // is off — which fingerprint-v2.php refuses to run without. So the server sees \NUL and
        // \EMPTY (4 and 6 characters), and their CHAR_LENGTH prefixes depend on it.
        $sql = str_replace(["'\\\\NUL'", "'\\\\EMPTY'"], ["'\\NUL'", "'\\EMPTY'"], $sql);

        // CAST target names.
        $sql = preg_replace('/\bAS\s+CHAR\b/i', 'AS TEXT', $sql);
        $sql = preg_replace('/\bAS\s+BINARY\b/i', 'AS BLOB', $sql);

        // IF() -> iif().
        $sql = preg_replace('/(?<![A-Za-z0-9_])IF\s*\(/i', 'iif(', $sql);

        // Collation-sensitive equality against the empty string, wherever the subject or the
        // encoder writes it. Both forms are matched: a bare column (row_sql's \EMPTY branch and
        // CONTROL 8's divergence count) and CONTROL 8's collation probe, which is built FROM the
        // column precisely so the answer is the column's and not the session's.
        $sql = preg_replace_callback(
            "/(`(?:[^`]|``)+`|CONCAT\\(LEFT\\(COALESCE\\(`(?:[^`]|``)+`, ''\\), 0\\), ' '\\))\\s*=\\s*''/i",
            function ($m) {
                return 'slim_eq_empty(' . $m[1] . ')';
            },
            $sql
        );

        return $sql;
    }

    /** Evaluate one statement against the corpus. Throws rather than returning falsy — see above. */
    public function run($sql)
    {
        if (null !== $this->between_passes && !$this->between_fired
            && preg_match('/^SELECT\s+`/i', ltrim((string) $sql))) {
            $this->between_fired = true;
            call_user_func($this->between_passes, $this);
        }
        $this->log[] = $sql;
        $translated  = $this->translate($sql);
        $res         = @$this->corpus->query($translated);
        if (false === $res) {
            throw new RuntimeException(
                'fake server could not evaluate: ' . $this->corpus->lastErrorMsg()
                . "\n  mysql:  " . $sql . "\n  sqlite: " . $translated
            );
        }
        return $res;
    }

    /** @return array[] rows as positional arrays of string|null */
    public function rows($sql)
    {
        $res = $this->run($sql);
        $out = [];
        while (($row = $res->fetchArray(SQLITE3_NUM)) !== false) {
            $out[] = Slimstat_Fake_Result::as_mysqli_row($row);
        }
        $res->finalize();
        return $out;
    }
}

/**
 * The $wpdb the subject picks up out of $GLOBALS. It must be CLASS `wpdb`: both bench libs
 * type-hint it, and the subject's own first line tests `instanceof wpdb`.
 */
class wpdb
{
    public $prefix     = 'wp_';
    public $last_error = '';
    public $dbh;
    public $suppress_errors = false;

    /** @var Slimstat_Fake_Server */
    public $engine;

    public function __construct(Slimstat_Fake_Server $engine)
    {
        $this->engine = $engine;
        $this->dbh    = new Slimstat_Fake_Mysqli($engine);
    }

    public function suppress_errors($suppress = true)
    {
        $prev                  = $this->suppress_errors;
        $this->suppress_errors = (bool) $suppress;
        return $prev;
    }

    /** wpdb::prepare, reduced to the two placeholders the subject uses. */
    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = isset($args[$i]) ? $args[$i] : '';
            $i++;
            return ('%d' === $m[0]) ? (string) (int) $v : "'" . str_replace("'", "''", (string) $v) . "'";
        }, $query);
    }

    public function get_var($query)
    {
        $q = trim((string) $query);
        $c = $this->engine->cfg;

        if (preg_match('/^SELECT\s+VERSION\(\)/i', $q)) {
            return $c['server_version'];
        }
        if (false !== stripos($q, 'character_set_connection')) {
            return $c['charset'];
        }
        if (false !== stripos($q, 'sql_mode')) {
            return $c['sql_mode'];
        }
        if (preg_match('/^SELECT\s+COUNT\(\*\)\s+FROM\s+`([^`]+)`$/i', $q, $m)) {
            // The one number in the run that does not come out of a streaming fetch loop, so it
            // is the one this harness has to be able to move independently of the corpus.
            if (isset($c['count_override'][$m[1]])) {
                return $c['count_override'][$m[1]];
            }
        }
        $rows = $this->engine->rows($q);
        return isset($rows[0][0]) ? $rows[0][0] : null;
    }

    public function get_row($query, $output = OBJECT)
    {
        $rows = $this->results_for($query);
        return isset($rows[0]) ? $rows[0] : null;
    }

    public function get_results($query, $output = OBJECT)
    {
        return $this->results_for($query);
    }

    /** information_schema is answered from the written catalogue; everything else is evaluated. */
    private function results_for($query)
    {
        $q = trim((string) $query);
        $c = $this->engine->cfg;

        if (preg_match('/information_schema\.COLUMNS.*TABLE_NAME\s*=\s*\'([^\']+)\'/is', $q, $m)) {
            $out = [];
            foreach ($c['catalogue'][$m[1]] as $name => $spec) {
                $out[] = [$name, $spec[0], $spec[1] ? 'YES' : 'NO'];
            }
            return $out;
        }
        if (preg_match('/information_schema\.STATISTICS.*TABLE_NAME\s*=\s*\'([^\']+)\'/is', $q, $m)) {
            $out = [];
            foreach ($c['unique_indexes'][$m[1]] as $idx) {
                list($index_name, $seq, $column) = $idx;
                $out[] = [$index_name, (string) $seq, $column];
            }
            return $out;
        }
        return $this->engine->rows($q);
    }
}

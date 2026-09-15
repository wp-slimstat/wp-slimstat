<?php
/**
 * Source-level: a vintage rehearsal cell proves the CORPUS belongs to the ARM before it imports it.
 *
 * WHY THIS EXISTS. A cell in the 7a-7d family installs an old plugin, lets that old plugin build
 * its own tables, hydrates a dump into them, and then watches the migration run. Every assertion
 * downstream of the import is a claim about "the 4.8.1 upgrade path" — and not one of them can
 * tell whether the rows it is looking at came from a 4.8-shaped dump or a 5.5-shaped one.
 *
 * Feed a 5.5 dump to a 4.8.1 arm and the cell goes green having tested nothing: mysqldump's
 * CREATE TABLE replaces the arm's table with the dump's, so every ADD COLUMN in the 4.8 migration
 * blocks is a no-op against a column that was already there, every DROP finds nothing to drop,
 * and the DDL path reports success having executed none of itself. Row counts match. Fingerprints
 * match. The plugin version is right. Nothing else a cell emits can see it. That is the failure
 * this file exists to make impossible.
 *
 * WHAT IT PINS:
 *
 *   dump_columns          reads the column set a dump FILE declares — from the file, because
 *                         after the import the table IS the dump's shape and the question can no
 *                         longer be asked. Index lines carry backticks too, and a dump holds more
 *                         than one table; both are cases here rather than assumptions.
 *   table_columns         reads the column set that EXISTS, from information_schema, so the
 *                         subject is the schema the arm built and not the schema we believe it
 *                         builds.
 *   columns_missing_from  names the difference. A count would say "these disagree"; the cells
 *                         need "the arm has no ua_id and the dump does", which is the sentence a
 *                         human acts on. Comma-delimited on both sides, so `id` is not found
 *                         inside `ua_id`.
 *   ordering              the control runs after the arm's installer and BEFORE the hydration.
 *                         Placed one line later it is not a weaker check, it is a vacuous one:
 *                         it would be comparing the dump against itself.
 *   H2 wiring             the installer include is vintage-aware. 4.8.1 ships no admin/index.php;
 *                         it keeps the same class and the same init_tables() signature in
 *                         admin/wp-slimstat-admin.php. include_once on a missing path warns
 *                         rather than fatals, so the hard-coded path would have produced a cell
 *                         with no tables of its own at all — and then the dump silently supplies
 *                         them, which is exactly the failure above, arrived at from the other end.
 *
 * The bash is driven as a SUBPROCESS against fixture dumps and a stubbed `mysql_q`, so this needs
 * no docker, no database and no network, and can guard every commit.
 *
 * 7.4-safe: bare PHP, no WordPress, no vendor autoloader.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$lib         = $plugin_root . '/tests/docker/lib.sh';
$rehearse    = $plugin_root . '/tests/docker/rehearse-upgrade.sh';
$failures    = [];
$checks      = 0;

/** Run one bash snippet with lib.sh loaded. Returns [rc, stdout, stderr]. */
function run_bash(string $lib, string $body): array
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

/** dump_columns() over a gzipped fixture. */
function dump_columns(string $lib, string $gz, string $table): string
{
    [, $out] = run_bash($lib, 'dump_columns ' . escapeshellarg($gz) . ' ' . escapeshellarg($table));

    return $out;
}

/** columns_missing_from() over two comma-joined sets. */
function missing_from(string $lib, string $a, string $b): string
{
    [, $out] = run_bash($lib, 'columns_missing_from ' . escapeshellarg($a) . ' ' . escapeshellarg($b));

    return $out;
}

/**
 * table_columns() with mysql_q stubbed to the rows a real server would return. The helper lives
 * in lib.sh and mysql_q lives in the cell that loads it, which is the harness's own idiom; the
 * stub is defined AFTER the source so it wins, exactly as a cell's definition does.
 */
function table_columns(string $lib, array $rows): string
{
    $emit = 'printf ' . escapeshellarg(implode('', array_map(static function ($r) {
        return $r . "\n";
    }, $rows)));
    [, $out] = run_bash($lib, "mysql_q() { $emit; }\ntable_columns wp_slim_stats");

    return $out;
}

/** Write a gzipped SQL fixture and return its path. */
function fixture_dump(string $dir, string $name, string $sql): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, (string) gzencode($sql, 6));

    return $path;
}

$tmp = sys_get_temp_dir() . '/slimstat-colctl-' . getmypid();
@mkdir($tmp, 0777, true);

// A 5.5-shaped dump: the columns a live v5 site has, plus a second table, plus index lines that
// carry backticks in exactly the way a naive extractor mistakes for columns.
$dump_55 = <<<'SQL'
-- MySQL dump 10.13
DROP TABLE IF EXISTS `wp_slim_stats`;
CREATE TABLE `wp_slim_stats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) DEFAULT '',
  `resource` varchar(2048) DEFAULT NULL,
  `browser_id` int(11) NOT NULL DEFAULT '0',
  `dt` int(10) unsigned DEFAULT '0',
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `dt_idx` (`dt`),
  UNIQUE KEY `ip_idx` (`ip`,`dt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS `wp_slim_browsers`;
CREATE TABLE `wp_slim_browsers` (
  `browser_id` int(11) NOT NULL AUTO_INCREMENT,
  `browser` varchar(40) DEFAULT '',
  PRIMARY KEY (`browser_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_slim_stats` VALUES (1,'127.0.0.1','/',1,0,NULL);
SQL;

// A 4.8-shaped dump: no browser_id, and a `country` the later line dropped.
$dump_48 = <<<'SQL'
DROP TABLE IF EXISTS `wp_slim_stats`;
CREATE TABLE `wp_slim_stats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) DEFAULT '',
  `resource` varchar(2048) DEFAULT NULL,
  `country` varchar(16) DEFAULT '',
  `dt` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$gz_55 = fixture_dump($tmp, 'v55.sql.gz', $dump_55);
$gz_48 = fixture_dump($tmp, 'v48.sql.gz', $dump_48);

$check = function (string $label, bool $ok, string $detail = '') use (&$failures, &$checks) {
    ++$checks;
    if ($ok) {
        echo "  ok   {$label}\n";

        return;
    }
    $line = $label . ('' !== $detail ? ' — ' . $detail : '');
    echo "  FAIL {$line}\n";
    $failures[] = $line;
};

echo "rehearsal column control\n";

// ── dump_columns: the file's own declaration ────────────────────────────────
$cols_55 = dump_columns($lib, $gz_55, 'wp_slim_stats');
$check(
    'a v5 dump yields its column set, sorted and comma-joined',
    'browser_id,dt,id,ip,notes,resource' === $cols_55,
    'got [' . $cols_55 . ']'
);
$check(
    'index lines are not read as columns',
    false === strpos(',' . $cols_55 . ',', ',dt_idx,') && false === strpos(',' . $cols_55 . ',', ',ip_idx,'),
    'got [' . $cols_55 . ']'
);
$check(
    'a second table in the same dump does not leak in',
    false === strpos(',' . $cols_55 . ',', ',browser,'),
    'got [' . $cols_55 . ']'
);
$cols_48 = dump_columns($lib, $gz_48, 'wp_slim_stats');
$check(
    'a 4.8-shaped dump yields the 4.8 column set',
    'country,dt,id,ip,resource' === $cols_48,
    'got [' . $cols_48 . ']'
);
$check(
    'the other table is readable by name',
    'browser,browser_id' === dump_columns($lib, $gz_55, 'wp_slim_browsers'),
    'got [' . dump_columns($lib, $gz_55, 'wp_slim_browsers') . ']'
);
$check(
    'a table the dump does not declare yields nothing',
    '' === dump_columns($lib, $gz_55, 'wp_slim_outbound'),
    'got [' . dump_columns($lib, $gz_55, 'wp_slim_outbound') . ']'
);

// ── columns_missing_from: the difference, by name ───────────────────────────
$check('identical sets differ in nothing', '' === missing_from($lib, $cols_55, $cols_55));
$check(
    'a column the dump lacks is named',
    'notes' === missing_from($lib, 'dt,id,notes', 'dt,id'),
    'got [' . missing_from($lib, 'dt,id,notes', 'dt,id') . ']'
);
$check(
    'several are named, comma-joined',
    'browser_id,notes' === missing_from($lib, $cols_55, $cols_48),
    'got [' . missing_from($lib, $cols_55, $cols_48) . ']'
);
$check(
    'and the other direction is asked separately',
    'country' === missing_from($lib, $cols_48, $cols_55),
    'got [' . missing_from($lib, $cols_48, $cols_55) . ']'
);
$check(
    'a name that is a substring of another is still missing',
    'id' === missing_from($lib, 'id', 'ua_id,vid_hash'),
    'got [' . missing_from($lib, 'id', 'ua_id,vid_hash') . ']'
);
$check('an empty first set is contained in anything', '' === missing_from($lib, '', 'id,ip'));
$check(
    'an empty second set contains nothing',
    'id,ip' === missing_from($lib, 'id,ip', ''),
    'got [' . missing_from($lib, 'id,ip', '') . ']'
);

// ── table_columns: the schema that exists ───────────────────────────────────
$check(
    'the live column set is sorted and comma-joined',
    'dt,id,ip' === table_columns($lib, ['ip', 'id', 'dt']),
    'got [' . table_columns($lib, ['ip', 'id', 'dt']) . ']'
);
$check(
    'a table that does not exist yields nothing, not a phantom column',
    '' === table_columns($lib, []),
    'got [' . table_columns($lib, []) . ']'
);
$check(
    "MySQL's CR and blank lines are stripped",
    'dt,id' === table_columns($lib, ["id\r", '', "dt\r"]),
    'got [' . table_columns($lib, ["id\r", '', "dt\r"]) . ']'
);

// ── the case the control exists for ─────────────────────────────────────────
// A 4.8.1 arm built the table; the corpus offered is the 5.5 dump. Both directions are non-empty
// and both name real columns, which is the difference between a cell that stops and a cell that
// reports PASS about code it never ran.
$arm_48   = table_columns($lib, explode(',', $cols_48));
$only_arm = missing_from($lib, $arm_48, $cols_55);
$only_dmp = missing_from($lib, $cols_55, $arm_48);
$check(
    'a 5.5 corpus under a 4.8 arm is caught in both directions',
    'country' === $only_arm && 'browser_id,notes' === $only_dmp,
    'arm-only [' . $only_arm . '] dump-only [' . $only_dmp . ']'
);
$check(
    'and the arm matched with its own corpus is silent',
    '' === missing_from($lib, $arm_48, $cols_48) && '' === missing_from($lib, $cols_48, $arm_48)
);

// ── wiring: the cell asks the question, and asks it in time ─────────────────
// These are wiring checks, not proofs: the assertion itself needs a booted stack. What they can
// do is refuse to let the control be deleted, or slid one line past the import that makes it
// vacuous. Named for what they are.
$src = is_file($rehearse) ? (string) file_get_contents($rehearse) : '';
$check('rehearse-upgrade.sh reads the live column set', false !== strpos($src, 'table_columns wp_slim_stats'));
$check('and the dump\'s own', false !== strpos($src, 'dump_columns "$DUMP" wp_slim_stats'));
$check(
    'and reports the difference by name, both ways',
    2 === preg_match_all('/columns_missing_from "\$(ARM_COLS|DUMP_COLS)"/', $src)
);
$check(
    'the verdict line exists',
    false !== strpos($src, 'check "the corpus is the arm\'s own vintage"')
        && false !== strpos($src, '"arm-only: ${ONLY_ARM:-none}; dump-only: ${ONLY_DUMP:-none}"' . "\n  exit 1")
);

$pos_control = strpos($src, 'DUMP_COLS=$(dump_columns');
$pos_import  = strpos($src, 'gzip -dc "$DUMP" | dc exec');
$check(
    'the control runs BEFORE the hydration that would make it vacuous',
    false !== $pos_control && false !== $pos_import && $pos_control < $pos_import,
    'control at ' . var_export($pos_control, true) . ', import at ' . var_export($pos_import, true)
);

// H2: the include is vintage-aware, and the cell asserts the installer actually ran.
//
// The definition MOVED to lib.sh when downgrade-corpus.sh (H4) needed the identical helper —
// two scripts that build the vintage's tables differently would produce a corpus that does not
// fit the cell it was built for. So these three read $libsrc, and the fourth still reads the
// cell: the cell is where the RESULT has to be asserted rather than assumed.
//
// Asserted as a file_exists() CANDIDATE, not as a string present in the file: the first draft
// looked for 'admin/wp-slimstat-admin.php' anywhere, and the comment three lines above the code
// satisfied it — a mutation that deleted the 4.8 branch outright SURVIVED the gate.
$libsrc = is_file($lib) ? (string) file_get_contents($lib) : '';
$check(
    'the installer include tries the modern path',
    false !== strpos($libsrc, 'file_exists($dir . "admin/index.php")')
);
$check(
    'and the 4.8 path, which is the only one that vintage ships',
    false !== strpos($libsrc, 'file_exists($dir . "admin/wp-slimstat-admin.php")')
);
$check(
    'and a missing file fails the cell instead of warning into a log',
    false !== strpos($libsrc, 'NOFILE') && false !== strpos($libsrc, 'NOMETHOD')
);
$check(
    'the file that ran is reported, not assumed',
    false !== strpos($src, 'INSTALLER=$(run_vintage_installer')
        && false !== strpos($src, 'check "the arm\'s own installer ran"')
);
// Both callers run the SAME helper. A private copy in either one is the drift this move exists
// to prevent, and it would be invisible until a corpus silently did not fit its cell.
$check(
    'lib.sh is the only definition — both callers run the same installer',
    1 === preg_match_all('/^run_vintage_installer\(\)/m', $libsrc)
        && 0 === preg_match_all('/^run_vintage_installer\(\)/m', $src)
        && false !== strpos((string) @file_get_contents($plugin_root . '/tests/docker/downgrade-corpus.sh'), 'run_vintage_installer')
);

array_map('unlink', [$gz_55, $gz_48]);
@rmdir($tmp);

echo "\nSLIMSTAT-REHEARSAL-COLUMN-CONTROL checks=" . $checks . ' failures=' . count($failures) . "\n";
if ([] !== $failures) {
    fwrite(STDERR, "FAIL: rehearsal column control\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo 'PASS: rehearsal column control — ' . $checks . " checks\n";
exit(0);

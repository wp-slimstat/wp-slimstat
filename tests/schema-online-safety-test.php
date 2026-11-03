<?php
/** Exercise the real DDL policy, including session state and the migration caller. */
require dirname(__DIR__) . '/vendor/autoload.php';
class wpdb {
    public $last_error = '';
    public $queries = [];
    public $timeout = '600';
    public $failSet = false;
    public $failRestore = false;
    public $throwAlter = false;
    public $outcomes = [[0, '']];
    public function get_var($sql) { $this->queries[] = $sql; return $this->timeout; }
    public function query($sql) {
        $this->queries[] = $sql; $this->last_error = '';
        if (strpos($sql, 'SET SESSION') === 0) {
            if (($sql === 'SET SESSION lock_wait_timeout = 5' && $this->failSet)
                || ($sql === 'SET SESSION lock_wait_timeout = 600' && $this->failRestore)) {
                $this->last_error = 'session setting failed'; return false;
            }
            return 0;
        }
        if ($this->throwAlter) { throw new RuntimeException('connection failed'); }
        [$result, $this->last_error] = array_shift($this->outcomes) ?? [false, 'online unsupported'];
        return $result;
    }
}
class OnlineSafetyMigration extends \SlimStat\Migration\AbstractMigration {
    public function getId(): string { return 'fixture'; }
    public function getName(): string { return 'fixture'; }
    public function getDescription(): string { return 'fixture'; }
    public function shouldRun(): bool { return true; }
    public function run(): bool { return false !== $this->alterOnline('ALTER TABLE wp_slim_stats ADD COLUMN fixture INT'); }
}
function ddl_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function ddl_alters($db) { return array_values(array_filter($db->queries, static function ($sql) { return strpos($sql, 'ALTER ') === 0; })); }
$sql = 'ALTER TABLE wp_slim_stats ADD COLUMN fixture INT';
$db = new wpdb();
ddl_check(0 === \SlimStat\Schema\Schema::alterOnline($db, $sql, false), 'instant success');
ddl_check($db->queries === ['SELECT @@SESSION.lock_wait_timeout', 'SET SESSION lock_wait_timeout = 5', $sql . ', ALGORITHM=INSTANT', 'SET SESSION lock_wait_timeout = 600'], 'set timeout before DDL and restore after success');
foreach (['0', '1'] as $stricter) {
    $db = new wpdb(); $db->timeout = $stricter;
    ddl_check(0 === \SlimStat\Schema\Schema::alterOnline($db, $sql, false), 'stricter host timeout succeeds');
    ddl_check($db->queries[1] === 'SET SESSION lock_wait_timeout = ' . $stricter, 'never relax a stricter host timeout');
}
$db = new wpdb(); $db->outcomes = [[false, 'instant unsupported'], [0, '']];
ddl_check(0 === \SlimStat\Schema\Schema::alterOnline($db, $sql, false), 'online fallback succeeds');
ddl_check(ddl_alters($db) === [$sql . ', ALGORITHM=INSTANT', $sql . ', ALGORITHM=INPLACE, LOCK=NONE'], 'instant then online ordering');
$db = new wpdb(); $db->outcomes = [[false, 'instant unsupported'], [false, 'online unsupported'], [0, '']];
ddl_check(!(new OnlineSafetyMigration($db))->run(), 'migration must refuse copying fallback');
ddl_check(count(ddl_alters($db)) === 2, 'migration must never issue bare ALTER');
ddl_check(strpos($db->last_error, 'online unsupported') !== false, 'restore must preserve DDL error');
ddl_check(end($db->queries) === 'SET SESSION lock_wait_timeout = 600', 'restore after refusal');
$db = new wpdb(); $db->outcomes = [[false, 'instant unsupported'], [false, 'online unsupported'], [0, '']];
ddl_check(0 === \SlimStat\Schema\Schema::alterOnline($db, $sql), 'explicit compatibility caller retains fallback');
ddl_check(ddl_alters($db) === [$sql . ', ALGORITHM=INSTANT', $sql . ', ALGORITHM=INPLACE, LOCK=NONE', $sql], 'compatibility fallback order');
foreach (['Lock wait timeout exceeded; try restarting transaction', 'Deadlock found when trying to get lock'] as $error) {
    $db = new wpdb(); $db->outcomes = [[false, $error], [0, '']];
    ddl_check(false === \SlimStat\Schema\Schema::alterOnline($db, $sql), 'lock failure must stop retrying');
    ddl_check(count(ddl_alters($db)) === 1 && $db->last_error === $error, 'lock failure preserved without a second lock wait');
}
foreach (['unreadable', 'set-failed'] as $case) {
    $db = new wpdb(); if ($case === 'unreadable') { $db->timeout = null; } else { $db->failSet = true; }
    ddl_check(false === \SlimStat\Schema\Schema::alterOnline($db, $sql), 'unsafe timeout must refuse DDL');
    ddl_check(ddl_alters($db) === [], 'no DDL when timeout cannot be established');
}
$db = new wpdb(); $db->throwAlter = true;
try { \SlimStat\Schema\Schema::alterOnline($db, $sql); throw new LogicException('exception swallowed'); }
catch (RuntimeException $e) { ddl_check($e->getMessage() === 'connection failed', 'original exception retained'); }
ddl_check(end($db->queries) === 'SET SESSION lock_wait_timeout = 600', 'restore after exception');
$db = new wpdb(); $db->failRestore = true;
ddl_check(false === \SlimStat\Schema\Schema::alterOnline($db, $sql), 'restore failure is not successful completion');
ddl_check(strpos($db->last_error, 'restore') !== false, 'restore failure remains visible');
echo "PASS: bounded DDL, no migration copy, compatibility, lock refusal, failures and session restoration\n";

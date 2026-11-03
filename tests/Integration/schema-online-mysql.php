<?php
/** Real metadata-lock timeout and retry on owned tables in a disposable database. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('A disposable WordPress test database is required.');
}
$table = 'slimstat_ddl_' . bin2hex(random_bytes(6));
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$holder = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$db->suppress_errors(true);
$run = static function ($sql) use ($db) { if (false === $db->query($sql)) { throw new RuntimeException($db->last_error); } };
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
try {
    $run("CREATE TABLE {$table} (id INT PRIMARY KEY, payload VARCHAR(100)) ENGINE=InnoDB");
    $run("INSERT INTO {$table} VALUES (1, 'must survive')");
    $run('SET SESSION lock_wait_timeout = 37');
    $holder->query('START TRANSACTION');
    $check('must survive' === $holder->get_var("SELECT payload FROM {$table} WHERE id = 1"), 'holder must acquire real metadata lock');
    $started = microtime(true);
    $result = \SlimStat\Schema\Schema::alterOnline($db, "ALTER TABLE {$table} ADD COLUMN fixture INT NULL", false);
    $elapsed = microtime(true) - $started;
    $error = $db->last_error;
    $check(false === $result && stripos($error, 'lock wait timeout') !== false, 'blocked DDL must fail with the original database error');
    $check($elapsed >= 4.5 && $elapsed < 8, 'one five-second lock wait, not a second fallback wait: ' . $elapsed);
    $check('37' === $db->get_var('SELECT @@SESSION.lock_wait_timeout'), 'restore original session timeout after refusal');
    $check(!$db->get_var("SHOW COLUMNS FROM {$table} LIKE 'fixture'"), 'timeout must not partially add the column');
    $check('must survive' === $db->get_var("SELECT payload FROM {$table} WHERE id = 1"), 'timeout preserves stored values');
    $holder->query('ROLLBACK');
    $check(false !== \SlimStat\Schema\Schema::alterOnline($db, "ALTER TABLE {$table} ADD COLUMN fixture INT NULL", false), 'retry after lock release succeeds');
    $check('37' === $db->get_var('SELECT @@SESSION.lock_wait_timeout'), 'restore session timeout after successful retry');
    $check('must survive' === $db->get_var("SELECT payload FROM {$table} WHERE id = 1"), 'online retry preserves stored values');
    $run("DROP TABLE {$table}");
    $run("CREATE TABLE {$table} (id INT PRIMARY KEY, payload VARCHAR(100)) ENGINE=MyISAM");
    $run("INSERT INTO {$table} VALUES (1, 'must survive')");
    $check(false === \SlimStat\Schema\Schema::alterOnline($db, "ALTER TABLE {$table} ADD COLUMN fixture INT NULL", false), 'legacy MyISAM migration refuses a blocking copy');
    $check(!$db->get_var("SHOW COLUMNS FROM {$table} LIKE 'fixture'"), 'refused MyISAM migration leaves schema unchanged');
    $check('must survive' === $db->get_var("SELECT payload FROM {$table} WHERE id = 1"), 'refused MyISAM migration preserves stored values');
    $check(false !== \SlimStat\Schema\Schema::alterOnline($db, "ALTER TABLE {$table} ADD COLUMN fixture INT NULL"), 'non-migration compatibility caller can explicitly retain legacy behavior');
    $check('must survive' === $db->get_var("SELECT payload FROM {$table} WHERE id = 1"), 'compatibility path preserves values');
    echo 'PASS: metadata lock refused in ' . round($elapsed, 3) . "s; retry, session restoration, InnoDB/MyISAM values and compatibility verified\n";
} finally {
    $holder->query('ROLLBACK');
    $db->query("DROP TABLE IF EXISTS {$table}");
    $holder->close(); $db->close();
}

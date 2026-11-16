<?php
/** Retention selections must not scan retained history for each small batch. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) { throw new RuntimeException('Disposable database required'); }
$rows = (int) (getenv('SLIMSTAT_PURGE_SCALE') ?: 100000);
if (!in_array($rows, [100000, 1000000], true)) { throw new RuntimeException('Scale must be 100000 or 1000000'); }
$oldStart = $rows - 1000;
$prefix = 'slimstat_scale_' . bin2hex(random_bytes(5)) . '_';
$run = static function ($sql) use ($wpdb) { if (false === $wpdb->query($sql)) { throw new RuntimeException($wpdb->last_error); } };
$counter = static function () use ($wpdb) { $total=0; foreach ($wpdb->get_results("SHOW SESSION STATUS LIKE 'Handler_read_%'", ARRAY_A) as $r) { $total += (int) $r['Value']; } return $total; };
try {
    $run("CREATE TABLE {$prefix}digits (n INT PRIMARY KEY)");
    $run("INSERT INTO {$prefix}digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)");
    $run("CREATE TABLE {$prefix}slim_stats (id BIGINT PRIMARY KEY, resource VARCHAR(30), dt BIGINT, KEY dt(dt)) ENGINE=InnoDB");
    $run("CREATE TABLE {$prefix}slim_events (event_id BIGINT PRIMARY KEY, id BIGINT, dt BIGINT, KEY dt(dt), KEY id(id)) ENGINE=InnoDB");
    $run("INSERT INTO {$prefix}slim_stats SELECT n+1, CONCAT('/fixture/', n), IF(n >= {$oldStart},100,2000000000) FROM (SELECT a.n + 10*b.n + 100*c.n + 1000*d.n + 10000*e.n + 100000*f.n AS n FROM {$prefix}digits a CROSS JOIN {$prefix}digits b CROSS JOIN {$prefix}digits c CROSS JOIN {$prefix}digits d CROSS JOIN {$prefix}digits e CROSS JOIN {$prefix}digits f) numbers WHERE n < {$rows}");
    $run("INSERT INTO {$prefix}slim_events SELECT id,id,dt FROM {$prefix}slim_stats");
    $wpdb->query("ANALYZE TABLE {$prefix}slim_stats, {$prefix}slim_events");
    $survivorSql = "SELECT COUNT(*) n, SUM(id) ids, SUM(CRC32(CONCAT(id, '/', resource, '/', dt))) payload FROM {$prefix}slim_stats WHERE dt=2000000000";
    $survivors = $wpdb->get_row($survivorSql, ARRAY_A);
    $before=$counter(); $start=microtime(true);
    $result=\SlimStat\Utils\PurgeArchive::purgeBatch($wpdb,$prefix,0,200,false,[],[]);
    $elapsed=microtime(true)-$start; $reads=$counter()-$before;
    echo json_encode(['rows'=>$rows,'result'=>$result,'seconds'=>$elapsed,'handler_reads'=>$reads,'survivors'=>$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats WHERE dt=2000000000")])."\n";
    if ($result !== ['stats'=>500,'events'=>500,'error'=>''] || $wpdb->get_row($survivorSql, ARRAY_A) !== $survivors) { throw new RuntimeException('Exact batch and retained row fingerprint must survive'); }
    if ($reads > 20000) { throw new RuntimeException('Small retention batch must not scan retained history: ' . $reads); }
    echo "PASS: bounded selection work and unchanged retained rows\n";
} finally { foreach (['slim_events','slim_stats','digits'] as $suffix) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$suffix}"); } }

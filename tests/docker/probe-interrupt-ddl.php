<?php
// Real migration worker; the host kills this process during its server-observed ALTER.
if (!defined('ABSPATH')) { exit(2); }
$a = SlimStat\Migration\MigrationService::analyticsConnection();
$m = new SlimStat\Migration\MigrationManager();
$m->register(new SlimStat\Migration\Migrations\AddVisitIdentity($a, $GLOBALS['wpdb']));
$m->forgetProbe();
if (($args[0] ?? '') === 'claim') {
    $held = (int) get_option('slimstat_migration_run_claim');
    $ttl = (new ReflectionClass($m))->getConstant('RUN_CLAIM_STALE_AFTER');
    if ($held <= 0 || !is_int($ttl) || $ttl <= 0) { throw new RuntimeException('Missing interrupted run claim'); }
    echo json_encode(['held' => $held, 'ttl' => $ttl, 'now' => time()]);
    return;
}
if (($args[0] ?? '') === 'refused') {
    if ($m->runOne('add-visit-identity') !== null) { throw new RuntimeException('Unexpired interrupted claim was not respected'); }
    echo "DDL-CLAIM-REFUSED\n";
    return;
}
file_put_contents('/tmp/ddl-worker.json', json_encode(['pid' => getmypid(), 'connection' => (int) $a->get_var('SELECT CONNECTION_ID()')]));
$result = $m->runOne('add-visit-identity');
echo 'DDL-WORKER:' . json_encode($result) . "\n";
exit($result === true ? 0 : 1);

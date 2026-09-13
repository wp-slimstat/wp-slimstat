<?php
// Real migration worker; the host kills this process during its server-observed ALTER.
if (!defined('ABSPATH')) { exit(2); }
$a = SlimStat\Migration\MigrationService::analyticsConnection();
$m = new SlimStat\Migration\MigrationManager();
$m->register(new SlimStat\Migration\Migrations\AddVisitIdentity($a, $GLOBALS['wpdb']));
$m->forgetProbe();
if (($args[0] ?? '') === 'lock') {
    $name = 'wpss_migrate_' . md5($a->dbname . '|' . $GLOBALS['wpdb']->prefix);
    echo json_encode([
        'name' => $name,
        'owner' => (int) $a->get_var($a->prepare('SELECT IS_USED_LOCK(%s)', $name)),
        'hint' => (string) get_option('slimstat_migration_run_claim'),
    ]);
    return;
}
if (($args[0] ?? '') === 'refused') {
    if ($m->runOne('add-visit-identity') !== null || !$m->isRunContended()) { throw new RuntimeException('Live migration owner did not cause a contention refusal'); }
    echo "DDL-CLAIM-REFUSED\n";
    return;
}
if (($args[0] ?? '') === 'status') {
    if (array_key_exists('add-visit-identity', $m->getStatus())) { throw new RuntimeException('Interrupted DDL wrote completion status'); }
    echo "DDL-NO-STATUS\n";
    return;
}
file_put_contents('/tmp/ddl-worker.json', json_encode(['pid' => getmypid(), 'connection' => (int) $a->get_var('SELECT CONNECTION_ID()')]));
$result = $m->runOne('add-visit-identity');
echo 'DDL-WORKER:' . json_encode($result) . "\n";
exit($result === true ? 0 : 1);

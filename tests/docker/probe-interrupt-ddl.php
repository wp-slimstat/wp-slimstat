<?php
// Real migration worker; the host interrupts a server-observed, executing ALTER TABLE.
if (!defined('ABSPATH')) { exit(2); }
$a = SlimStat\Migration\MigrationService::analyticsConnection();
$m = new SlimStat\Migration\MigrationManager();
foreach (glob(WP_PLUGIN_DIR . '/wp-slimstat/src/Migration/Migrations/*.php') as $file) {
    $class = 'SlimStat\\Migration\\Migrations\\' . basename($file, '.php');
    if (class_exists($class)) { $m->register(new $class($a, $GLOBALS['wpdb'])); }
}
$m->forgetProbe();
$result = $m->runAll();
echo 'DDL-WORKER:' . json_encode($result) . "\n";
// An interrupted migration must report failure, not be silently marked complete.
exit(in_array(false, $result, true) ? 1 : 0);

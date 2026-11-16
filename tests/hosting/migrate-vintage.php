<?php
/** Apply the registered required migrations, then prove the second pass issues no DDL. */
if (!defined('WP_CLI') || !WP_CLI || 'tests-v550' !== DB_NAME || 'db' !== DB_HOST || '1' !== getenv('SLIMSTAT_HOSTING_DISPOSABLE')) {
    throw new RuntimeException('Requires the armed disposable vintage database.');
}
$manager = SlimStat\Migration\MigrationService::manager();
// WP-CLI selects its --user after plugins load. Execute only the service's newly
// registered callback, without replaying WordPress init or copying its registry.
if (!$manager && is_admin() && current_user_can('manage_options')) {
    $beforeCallbacks = $GLOBALS['wp_filter']['init']->callbacks[70] ?? [];
    SlimStat\Migration\MigrationService::init();
    foreach (array_diff_key($GLOBALS['wp_filter']['init']->callbacks[70], $beforeCallbacks) as $callback) {
        call_user_func($callback['function']);
    }
    $manager = SlimStat\Migration\MigrationService::manager();
}
if (!$manager) { throw new RuntimeException('Run with WP_ADMIN defined and --user=admin so the normal registry boots.'); }
$before = array_map(static function ($migration) { return $migration->getId(); }, $manager->getRequiredMigrations());
$ddl = [];
add_filter('query', static function ($query) use (&$ddl) {
    if (preg_match('/^\s*(ALTER|CREATE|DROP|TRUNCATE)\b/i', $query)) { $ddl[] = $query; }
    return $query;
});
$start = microtime(true);
$first = $manager->runAll();
$elapsed = microtime(true) - $start;
$firstDdl = $ddl;
$ddl = [];
$second = $manager->runAll();
$remaining = array_map(static function ($migration) { return $migration->getId(); }, $manager->getRequiredMigrations());
$checks = ['first_pass_success' => [] !== $first && !in_array(false, $first, true),
    'second_pass_success' => [] !== $second && !in_array(false, $second, true),
    'second_pass_no_ddl' => [] === $ddl, 'nothing_owed' => [] === $remaining,
    'claim_released' => false === get_option('slimstat_migration_run_claim', false)];
echo wp_json_encode(['before' => array_values($before), 'first' => $first, 'first_seconds' => $elapsed,
    'first_ddl' => $firstDdl, 'second' => $second, 'second_ddl' => $ddl, 'checks' => $checks], JSON_PRETTY_PRINT) . "\n";
if (in_array(false, $checks, true)) { WP_CLI::error('Migration qualification failed.'); }

<?php
/** Run after ecommerce-data.php seed, on disposable wp-env only. */
use SlimStat\Ecommerce\Integration;
use SlimStat\Ecommerce\Report;

if ('tests-wordpress' !== DB_NAME) { throw new RuntimeException('Disposable database required.'); }
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/index.php';
$fixture = get_option('slimstat_ecommerce_test_fixture');
if (!$fixture) { throw new RuntimeException('Seed the known commerce fixture first.'); }
$core = $GLOBALS['wpdb']; $original = wp_slimstat::$wpdb; $prefix = $core->prefix;
$state = get_option(Integration::STATE); $options = wp_slimstat::$settings;
$external = new wpdb('root', 'ecommerce-test-only', 'ecommerce_analytics', 'slimstat-ec-audit-db');
$external->hide_errors();
if (!$external->ready) { throw new RuntimeException('Start the isolated Ecommerce analytics server.'); }
$checks = [];
$same = static function ($expected, $actual, $label) use (&$checks) {
    if ($expected !== $actual) { throw new RuntimeException($label . ': ' . wp_json_encode(compact('expected', 'actual'))); }
    $checks[$label] = ['expected' => $expected, 'actual' => $actual];
};
try {
    foreach (['slim_stats', 'slim_events', 'slim_ecommerce'] as $name) {
        $create = $original->get_row('SHOW CREATE TABLE ' . $prefix . $name, ARRAY_N)[1];
        $external->query('DROP TABLE IF EXISTS ' . $prefix . $name);
        if (false === $external->query($create)) { throw new RuntimeException('External schema setup failed.'); }
    }
    // Exercise the installed Pro connection resolver, not just a manually assigned handle.
    wp_slimstat::$settings = array_merge($options, ['addon_custom_db_enable' => 'on',
        'addon_custom_db_dbhost' => 'slimstat-ec-audit-db', 'addon_custom_db_dbname' => 'ecommerce_analytics',
        'addon_custom_db_dbuser' => 'root', 'addon_custom_db_dbpass' => 'ecommerce-test-only']);
    $routed = apply_filters('slimstat_custom_wpdb', $core);
    $same(false, $routed === $core, 'Pro resolves a distinct analytics connection');
    $same('ecommerce_analytics', $routed->get_var('SELECT DATABASE()'), 'Pro selects the external analytics database');
    $same(true, $GLOBALS['wpdb'] === $core, 'WooCommerce retains the original WordPress connection');
    $external->close(); $external = $routed;
    wp_slimstat_db::init();
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $fixture['start'], 'end' => $fixture['end']];
    wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
    // A pre-upgrade state has no connection fingerprint. Caches must still isolate it.
    $legacy = $state; unset($legacy['database']); update_option(Integration::STATE, $legacy, false);
    Integration::invalidate();
    $same('115.000000', (new Report())->data()['current']['net'], 'Local fixture total');
    wp_slimstat::$wpdb = $external;
    $externalReadyKey = \SlimStat\Tracker\Acquisition::readinessKey();
    $externalReady = get_option($externalReadyKey, null);
    // Same schema availability on both servers: only the connection identity differs.
    \SlimStat\Tracker\Acquisition::checkSchema();
    $same(0.0, (float) (new Report())->data()['current']['net'], 'External connection cannot reuse local totals');
    Integration::setup();
    foreach ($fixture['orders'] as $id) { Integration::sync($id); }
    $externalState = get_option(Integration::STATE); $externalState['complete'] = true;
    update_option(Integration::STATE, $externalState, false);
    $same('115.000000', (new Report())->data()['current']['net'], 'WC CRUD reads core orders and projects externally');
    $same(0, (new Report())->data()['journey']['visits'], 'No local traffic leaks into external report');
    $same(null, $external->get_var("SHOW TABLES LIKE '{$prefix}wc_orders'"), 'No WooCommerce table required on analytics server');
    wp_slimstat::$wpdb = $original;
    $same(false, Integration::ready(), 'Setup state belongs to its database');
    wp_slimstat::$wpdb = $external;
    foreach (['slim_stats', 'slim_events', 'slim_ecommerce'] as $name) {
        $external->query('DROP TABLE IF EXISTS ec_audit_' . $name);
        $external->query('CREATE TABLE ec_audit_' . $name . ' LIKE ' . $prefix . $name);
        $external->query('INSERT INTO ec_audit_' . $name . ' SELECT * FROM ' . $prefix . $name);
    }
    // Report table prefix is the core blog prefix; external handle need not guess it.
    unset($externalState['database']); update_option(Integration::STATE, $externalState, false);
    $core->prefix = 'ec_audit_';
    $same('115.000000', (new Report())->data()['current']['net'], 'Non-default analytics table prefix');
    $core->prefix = $prefix;
    wp_slimstat::$wpdb = $original; update_option(Integration::STATE, $state, false);
    wp_set_current_user(1);
    $raw = Report::raw();
    $values = array_column($raw, 'value', 'metric');
    $same(true, strpos($values['Net sales'], '115.00') !== false, 'No-JS report net sales');
    $same('4', $values['Orders'], 'No-JS report orders');
    $same(true, isset($values['Reporting period'], $values['Data quality']), 'No-JS scope and quality');
    wp_set_current_user(0);
    $same([], Report::raw(), 'Unprivileged raw invocation withheld');
    $same(true, count(Report::raw(['email' => true])) >= 8, 'Trusted email callback works during cron');
    wp_slimstat::$settings['auto_purge'] = 5;
    Integration::invalidate();
    $same(0, (new Report())->data()['journey']['visits'], 'Pending purge cannot reintroduce expired journey observations');
    echo wp_json_encode(['result' => 'pass', 'checks' => $checks]);
} finally {
    $core->prefix = $prefix; wp_slimstat::$wpdb = $original; wp_slimstat::$settings = $options;
    update_option(Integration::STATE, $state, false); Integration::invalidate();
    foreach ([$prefix, 'ec_audit_'] as $p) {
        foreach (['slim_stats', 'slim_events', 'slim_ecommerce'] as $name) { $external->query('DROP TABLE IF EXISTS ' . $p . $name); }
    }
    $external->close();
    if (isset($externalReadyKey)) {
        if (null === $externalReady) { delete_option($externalReadyKey); }
        else { update_option($externalReadyKey, $externalReady); }
    }
}

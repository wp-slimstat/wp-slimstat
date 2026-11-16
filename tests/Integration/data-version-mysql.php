<?php
/** Real callback invalidation, including the AJAX callback's terminating exit. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('Disposable database required');
}
require_once SLIMSTAT_DIR . '/admin/index.php';
if (($args[0] ?? '') === 'delete') {
    $prefix = $args[1] ?? '';
    if (!preg_match('/^slimstat_version_[a-f0-9]{12}_$/', $prefix)) { throw new RuntimeException('Invalid fixture prefix'); }
    $wpdb->prefix = $prefix;
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    wp_slimstat::$settings['capability_can_admin'] = 'manage_options';
    $_POST = ['pageview_id' => '2', 'security' => wp_create_nonce('meta-box-order')];
    register_shutdown_function(static function () { echo "DELETE_CALLBACK_FINISHED\n"; });
    wp_slimstat_admin::delete_pageview();
    throw new RuntimeException('Valid deletion must reach the terminating callback');
}
$prefix = 'slimstat_version_' . bin2hex(random_bytes(6)) . '_';
$originalPrefix = $wpdb->prefix;
$originalDb = wp_slimstat::$wpdb;
$settings = wp_slimstat::$settings;
$saved = [];
foreach (['slimstat_data_ver', 'slimstat_goals_cache_ver', 'slimstat_degradations', 'slimstat_ecommerce_state', 'slimstat_ecommerce_generation'] as $key) { $saved[$key] = get_option($key, null); }
$run = static function ($sql) use ($wpdb) { if (false === $wpdb->query($sql)) { throw new RuntimeException($wpdb->last_error); } };
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$today = intdiv(wp_slimstat::now(), DAY_IN_SECONDS) * DAY_IN_SECONDS;
$old = $today - 40 * DAY_IN_SECONDS;
$count = static function ($suffix = 'slim_stats') use ($prefix, $today, $old) {
    return (int) \SlimStat\Utils\Query::select('COUNT(*)')->from($prefix . $suffix)
        ->whereDate('dt', ['from' => $old - 1, 'to' => $today - 1])->getVar();
};
$bumped = static function ($version, $message) use ($check) {
    $check(get_option('slimstat_data_ver', '0') !== $version, $message);
};
try {
    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
        $run("CREATE TABLE {$prefix}{$suffix} LIKE {$originalPrefix}slim_stats");
    }
    $run("CREATE TABLE {$prefix}slim_ecommerce (order_id BIGINT, item_id BIGINT, stat_id BIGINT NULL, erased INT DEFAULT 0, dt BIGINT, PRIMARY KEY(order_id,item_id)) ENGINE=InnoDB");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($originalPrefix . 'wc_orders')))) {
        $run("CREATE TABLE {$prefix}wc_orders LIKE {$originalPrefix}wc_orders");
    }
    $wpdb->prefix = $prefix;
    wp_slimstat::$wpdb = $wpdb;
    \SlimStat\Utils\Query::setProcessingTimestamp($today + 3600);
    $run("INSERT INTO {$prefix}slim_stats (id,ip,username,dt) VALUES (1,'192.0.2.1','visitor',{$old}),(2,'192.0.2.2','visitor',{$old}),(3,'192.0.2.3','canary',{$old})");
    $check($count() === 3 && $count() === 3, 'Warm historical pageview count');
    $version = get_option('slimstat_data_ver', '0');
    wp_slimstat_admin::remove_spam('spam', 'approved', (object) ['comment_author_IP' => '192.0.2.1']);
    $bumped($version, 'Spam deletion must invalidate history');
    $check($count() === 2, 'Spam callback refreshes the warmed count');
    $version = get_option('slimstat_data_ver');
    wp_slimstat_admin::remove_spam('spam', 'approved', (object) ['comment_author_IP' => '192.0.2.1']);
    $check(get_option('slimstat_data_ver') === $version, 'No-op spam callback must not invalidate');

    $child = WP_CLI::runcommand('eval-file ' . escapeshellarg(__FILE__) . ' delete ' . $prefix, ['return' => 'all', 'launch' => true, 'exit_error' => false]);
    $check($child->return_code === 0 && strpos($child->stdout, 'DELETE_CALLBACK_FINISHED') !== false, 'Real pageview callback must run in child process: ' . $child->stderr);
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('slimstat_data_ver', 'options');
    $bumped($version, 'Pageview deletion must invalidate history');
    $check($count() === 1 && '192.0.2.3' === $wpdb->get_var("SELECT ip FROM {$prefix}slim_stats WHERE id=3"), 'Pageview callback refreshes count and preserves canary');
    echo "PASS: spam and actual terminating pageview callback invalidate warm history\n";

    update_option(\SlimStat\Ecommerce\Integration::STATE, ['version' => 1]);
    wp_slimstat::$settings['auto_purge'] = 30;
    $run("INSERT INTO {$prefix}slim_ecommerce VALUES (2147483646,0,3,0,{$old})");
    $check($count('slim_ecommerce') === 1, 'Warm ecommerce history');
    $version = get_option('slimstat_data_ver');
    \SlimStat\Ecommerce\Integration::maintenance();
    $bumped($version, 'Ecommerce retention must invalidate history');
    $check($count('slim_ecommerce') === 0, 'Ecommerce retention refreshes warm count');
    $check(\SlimStat\Ecommerce\Integration::available(), 'WooCommerce is required to execute order sync');
    $run("INSERT INTO {$prefix}slim_ecommerce VALUES (2147483646,0,3,0,{$old})");
    wp_slimstat::bump_data_version();
    $check($count('slim_ecommerce') === 1, 'Warm missing-order association');
    $version = get_option('slimstat_data_ver');
    \SlimStat\Ecommerce\Integration::sync(2147483646);
    $bumped($version, 'Historical order cleanup must invalidate history');
    $check($count('slim_ecommerce') === 0, 'Order cleanup refreshes warmed count');
    echo "PASS: ecommerce retention and deleted-order sync invalidate warm history\n";

    $before = $wpdb->get_results("SELECT * FROM {$prefix}slim_stats", ARRAY_A);
    $version = get_option('slimstat_data_ver');
    $check((new \SlimStat\Migration\Migrations\AddVisitIdentity($wpdb))->run(), 'Visit identity migration completes');
    $bumped($version, 'Visit identity migration invalidates report history');
    $check($before === $wpdb->get_results("SELECT * FROM {$prefix}slim_stats", ARRAY_A), 'Identity migration preserves every existing value');
    echo "PASS: idempotent identity migration invalidates reports and preserves rows\n";
} finally {
    \SlimStat\Utils\Query::setProcessingTimestamp(null);
    $wpdb->prefix = $originalPrefix; wp_slimstat::$wpdb = $originalDb; wp_slimstat::$settings = $settings;
    foreach (['wc_orders', 'slim_ecommerce', 'slim_stats_archive', 'slim_stats'] as $suffix) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$suffix}"); }
    foreach ($saved as $key => $value) { if ($value === null) { delete_option($key); } else { update_option($key, $value); } }
}

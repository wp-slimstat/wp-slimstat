<?php
/**
 * Run with wp eval-file on a disposable two-site network with Free and Pro active.
 * Requires DB_NAME beginning test_acquisition_network_; never uses a real site's DB.
 */

use SlimStat\Reports\AcquisitionReport;
use SlimStat\Tracker\Storage;
use SlimStat\Utils\NetworkMerge;

if (!defined('WP_CLI') || !WP_CLI || !is_multisite() || 0 !== strpos(DB_NAME, 'test_acquisition_network_')
    || wp_slimstat::$wpdb->dbname !== DB_NAME) {
    fwrite(STDERR, "Requires an isolated test_acquisition_network_ database, multisite and SlimStat Pro.\n");
    exit(1);
}
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once dirname(__DIR__) . '/admin/index.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';

$check = static function ($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$sites = get_sites(['fields' => 'ids', 'number' => 2, 'archived' => 0, 'deleted' => 0, 'spam' => 0]);
$check(2 === count($sites), 'Two sites are required');
wp_set_current_user(get_user_by('login', get_super_admins()[0])->ID);
set_current_screen('dashboard-network');
$check(NetworkMerge::isMerging(), 'Pro enables the real authorized network merge');
$marker = '/acquisition-network-' . bin2hex(random_bytes(4));
$tables = [];
$now = wp_slimstat::now();
try {
    foreach ($sites as $index => $blog) {
        switch_to_blog($blog);
        try {
            wp_slimstat_admin::init_tables(wp_slimstat::$wpdb);
            $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
            $tables[] = $table;
            for ($i = 0; $i < $index + 2; ++$i) {
                $check(Storage::insertRow([
                    'dt' => $now, 'resource' => $marker, 'author' => 'alice',
                    'traffic_channel' => 'email', 'traffic_source' => 'newsletter',
                    'utm_source' => 'Newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'Summer + %20',
                ], $table)->isStored(), 'Store shared campaign on each site');
            }
            $extra = 0 === $index
                ? [['ai_fetcher', 'Bot', 'alice'], ['ai_assistant', null, 'alice']]
                : [['paid_search', 'Search', 'bob'], [null, null, 'alice']];
            foreach ($extra as [$channel, $campaign, $author]) {
                $check(false !== wp_slimstat::$wpdb->insert($table, [
                    'dt' => $now, 'resource' => $marker, 'author' => $author,
                    'traffic_channel' => $channel, 'utm_campaign' => $campaign,
                ]), 'Store distinct channels and legacy data');
            }
        } finally {
            restore_current_blog();
        }
    }
    wp_slimstat_db::init();
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $now - 3600, 'end' => $now + 1];
    wp_slimstat_db::$filters_normalized['columns'] = ['resource' => ['equals', $marker]];
    wp_slimstat::$settings['limit_results'] = 100;
    wp_slimstat::$settings['rows_to_show'] = 20;
    wp_slimstat::$settings['async_load'] = 'off';
    $utm = AcquisitionReport::rows(['mode' => 'utm']);
    $check(3 === count($utm) && 6 === array_sum(array_column($utm, 'counthits')), 'Network UTM groups preserve site identity and exclude bots');
    $check(9 === array_sum(array_column(AcquisitionReport::rows(), 'counthits')), 'Channels partition both sites');
    $check(9 === (int) wp_slimstat_db::count_records('id'), 'Network denominator uses the same scope');
    wp_slimstat::$settings['limit_results'] = 1;
    $utm = AcquisitionReport::rows(['mode' => 'utm']);
    $check(1 === count($utm) && 3 === (int) $utm[0]['counthits'], 'Network result cap applies after the union');
    ob_start();
    AcquisitionReport::render(['mode' => 'utm']);
    $html = ob_get_clean();
    $check(false !== strpos($html, '50.0%') && false !== strpos($html, '6 pageviews'), 'Network shares use all six tagged views');
    wp_slimstat_db::$filters_normalized['columns']['author'] = ['equals', 'alice'];
    wp_slimstat::$settings['limit_results'] = 100;
    $check(5 === array_sum(array_column(AcquisitionReport::rows(['mode' => 'utm']), 'counthits')), 'Author filtering applies to both sites');
    wp_slimstat_db::$filters_normalized['columns']['utm_source'] = ['equals', 'newsletter'];
    $check([] === AcquisitionReport::rows(['mode' => 'utm']), 'Network filters retain exact tag case');
    echo "PASS: two-site UTM/channel totals, site grouping, cap, shares and exact author/dimension filters.\n";
} finally {
    foreach ($tables as $table) {
        wp_slimstat::$wpdb->delete($table, ['resource' => $marker]);
    }
}

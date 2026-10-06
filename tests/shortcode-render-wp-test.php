<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file; disposable analytics table, no existing rows changed.
 */
use SlimStat\Shortcodes\Shortcode;
use SlimStat\Controllers\Rest\ShortcodeRestController;
if (!defined('ABSPATH')) { exit(1); }
if (!defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Run with wp eval-file.\n"); exit(1); }
require_once dirname(__DIR__) . '/admin/index.php';
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';
wp_slimstat_reports::init();
$core = $GLOBALS['wpdb'];
$prefix = $core->prefix;
$analytics = wp_slimstat::$wpdb;
$settings = wp_slimstat::$settings;
$user = get_current_user_id();
$testPrefix = 'test_shortcode_' . bin2hex(random_bytes(4)) . '_';
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$free = static function ($plugins) { return array_values(array_diff($plugins, ['wp-slimstat-pro/wp-slimstat-pro.php'])); };
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$table = $testPrefix . 'slim_stats';
$written = [];
$watch = static function ($sql) use (&$written, $core) { if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE).*' . preg_quote($core->options, '/') . '/is', $sql)) { $written[] = $sql; } return $sql; };
try {
    $check(false !== $core->query("CREATE TABLE `$table` LIKE `{$prefix}slim_stats`"), 'Create isolated analytics table');
    $core->prefix = $testPrefix;
    wp_slimstat::$wpdb = $core;
    wp_slimstat::$settings['restrict_authors_view'] = 'off';
    $check(false !== $core->insert($table, ['dt' => wp_slimstat::now(), 'resource' => '/shortcode-test', 'ip' => '192.0.2.17', 'username' => $admin->user_login, 'utm_source' => 'test', 'utm_campaign' => '<script>alert(1)</script>', 'traffic_channel' => 'email', 'visit_id' => 17]), 'Seed shortcode rows');
    wp_set_current_user($admin->ID);
    add_filter('query', $watch);
    $html = do_shortcode('[slimstat f="recent" w="utm_campaign" o="x"]interval equals -30[/slimstat]');
    $check(strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false, 'Campaign XSS is escaped');
    $html = Shortcode::render(['f' => 'recent', 'w' => 'post_link,dt', 's' => '<img src=x onerror=alert(1)>'], 'interval equals -30');
    $check(strpos($html, 'col-post_link') !== false && strpos($html, 'col-dt') !== false, 'Both fields render');
    $check(strpos($html, 'onerror') === false, 'Separator is filtered');
    $check('1' === Shortcode::render(['f' => 'count', 'w' => 'utm_source', 'o' => 'x'], 'interval equals -30'), 'UTM count and nonnumeric offset');
    $check('1' === Shortcode::render(['f' => 'count', 'w' => 'traffic_channel'], 'interval equals -30'), 'Channel count');
    // A user object cache entry exercises a hostile display_name without writing users.
    $userData = clone $admin->data;
    $userData->display_name = '<script>name</script>';
    wp_cache_set($admin->ID, $userData, 'users');
    $html = Shortcode::render(['f' => 'recent', 'w' => 'display_name'], 'interval equals -30');
    $check(strpos($html, '&lt;script&gt;name') !== false && strpos($html, '<script>') === false, 'Display name XSS is escaped');
    clean_user_cache($admin->ID);
    $check(strpos(Shortcode::render(['f' => 'recent', 'w' => 'ip']), '192.0.2.17') !== false, 'Staff can see IPs');
    // Widget reports declare no where clause; raw_results_to_html() read it unguarded.
    $warnings = [];
    set_error_handler(static function ($no, $message) use (&$warnings) { $warnings[] = $message; return true; }, E_WARNING | E_NOTICE);
    try { Shortcode::render(['f' => 'widget', 'w' => 'slim_p2_02']); } finally { restore_error_handler(); }
    $check(!$warnings, 'Widget renders without warnings: ' . implode('; ', $warnings));
    wp_set_current_user(0);
    $check('1' === Shortcode::render(['f' => 'count', 'w' => 'ip']), 'Legacy public unique-IP counter remains public');
    $check('1' === Shortcode::render(['f' => 'count-all', 'w' => 'username']), 'Aggregate counts do not reveal personal values');
    $requestState = [$_GET, $_POST, $_REQUEST];
    try {
        $_GET['type'] = 'yesterday';
        $_POST['f'] = 'resource'; $_POST['o'] = 'equals'; $_POST['v'] = '/missing';
        $_REQUEST['fs'] = ['ip' => 'equals 192.0.2.99', 'limit_results' => 'equals 999999'];
        $check('1' === Shortcode::render(['f' => 'count', 'w' => 'ip']), 'Public request fields cannot change published counters');
    } finally { [$_GET, $_POST, $_REQUEST] = $requestState; }
    foreach (['ip', 'other_ip', 'hostname', 'username', 'display_name', 'email', 'fingerprint', 'user_agent'] as $column) {
        $check('' === Shortcode::render(['f' => 'recent', 'w' => $column]), 'Visitor privacy: ' . $column);
        $check('' === Shortcode::render(['f' => 'recent', 'w' => 'country,' . $column]), 'Mixed-column privacy: ' . $column);
    }
    $check('' === Shortcode::render(['f' => 'widget', 'w' => 'slim_p7_02']), 'Access log is private');
    $check('' === Shortcode::render(['f' => 'widget', 'w' => 'slim_p1_04']), 'Online IP report is private');
    $check('' === Shortcode::render(['f' => 'x', 'w' => 'id']), 'Invalid shortcode is silent for visitors');
    add_filter('option_active_plugins', $free);
    $check('' === Shortcode::render(['f' => 'widget', 'w' => 'slim_heatmap_01']), 'Pro notice is private');
    wp_set_current_user($admin->ID);
    $check(strpos(Shortcode::render(['f' => 'widget', 'w' => 'slim_heatmap_01']), 'utm_content=frontend') !== false, 'Staff Pro notice attribution');
    $controller = new ShortcodeRestController();
    foreach (['[gallery]', '<script>x</script>', '[[slimstat f="live" w="users"]]', '[slimstat f="live" w="users"]junk', str_repeat('a', 2049), '[slimstat f="recent" w="country,SLEEP(1)"]'] as $code) {
        $request = new WP_REST_Request('POST'); $request['shortcode'] = $code;
        $result = $controller->preview($request);
        $check(is_wp_error($result) && 400 === $result->get_error_data()['status'], 'Reject invalid preview: ' . substr($code, 0, 50));
    }
    $request = new WP_REST_Request('POST'); $request['shortcode'] = '[slimstat f="widget" w="slim_heatmap_01"]';
    $check($controller->preview($request)->get_data()['sample'], 'Free preview labels sample');
    $request['shortcode'] = '[slimstat f="recent" w="ip"]';
    $check($controller->preview($request)->get_data()['staff_only'], 'Preview carries privacy');
    $request['shortcode'] = '[slimstat f="count" w="ip"]';
    $check(!$controller->preview($request)->get_data()['staff_only'], 'Preview labels aggregate counts as public');
    // Exercise dispatch, not only direct callbacks, for the permission/status contract.
    $server = rest_get_server();
    $request = new WP_REST_Request('POST', '/slimstat/v1/shortcode/preview');
    $request['shortcode'] = '[slimstat f="recent" w="ip"]';
    $deny = static function () { return []; };
    add_filter('user_has_cap', $deny);
    $check(403 === $server->dispatch($request)->get_status(), 'REST rejects a logged-in user without can_view');
    remove_filter('user_has_cap', $deny);
    $check(200 === $server->dispatch($request)->get_status(), 'REST accepts analytics staff');
    remove_filter('option_active_plugins', $free);
    $pro = static function ($plugins) { $plugins[] = 'wp-slimstat-pro/wp-slimstat-pro.php'; return $plugins; };
    add_filter('option_active_plugins', $pro);
    $reports = wp_slimstat_reports::$reports;
    unset(wp_slimstat_reports::$reports['slim_heatmap_01']);
    $check(strpos(Shortcode::render(['f' => 'widget', 'w' => 'slim_heatmap_01']), 'Update SlimStat Pro') !== false, 'Outdated Pro gets update guidance');
    wp_slimstat_reports::$reports = $reports;
    wp_slimstat_reports::$reports['slim_heatmap_01'] = ['title' => 'Heatmap insights', 'callback' => '', 'callback_args' => ['raw' => static function ($args) use ($check) {
        $check(!empty($args['is_widget']), 'Raw reports receive widget context');
        return [['resource' => '/heatmap-shortcode-fixture', 'addon_hm_clicks' => 1234]];
    }]];
    $heatmap = Shortcode::render(['f' => 'widget', 'w' => 'slim_heatmap_01']);
    $check(strpos($heatmap, '<table') !== false && strpos($heatmap, '/heatmap-shortcode-fixture') !== false && strpos($heatmap, number_format_i18n(1234)) !== false, 'Empty display callback uses escaped raw table');
    wp_slimstat_reports::$reports = $reports;
    remove_filter('option_active_plugins', $pro);
    $core->prefix = $prefix;
    $check(is_string(Shortcode::render(['f' => 'widget', 'w' => 'slim_heatmap_01'])), 'Raw heatmap has no fatal');
    $core->prefix = $testPrefix;
    // Rendering, including the first catalog load, must leave the next report intact.
    $beforeFilters = wp_slimstat_db::$filters_normalized;
    $beforeWhere = wp_slimstat_db::$sql_where;
    wp_slimstat_db::$pageviews = 987654;
    $reports = wp_slimstat_reports::$reports;
    wp_slimstat_reports::$reports = [];
    Shortcode::render(['f' => 'count', 'w' => 'id'], 'resource equals /missing');
    $check(987654 === wp_slimstat_db::$pageviews, 'Shortcode restores the pageview denominator');
    $check($beforeFilters === wp_slimstat_db::$filters_normalized && $beforeWhere === wp_slimstat_db::$sql_where, 'Cold catalog preserves filters');
    wp_slimstat_reports::$reports = $reports;
    $permalinks = static function () { return '/%postname%/'; };
    add_filter('pre_option_permalink_structure', $permalinks);
    foreach (['/grouped?utm_source=one', '/grouped?utm_source=two'] as $resource) {
        $check(false !== $core->insert($table, ['dt' => wp_slimstat::now(), 'resource' => $resource, 'country' => 'us']), 'Seed query-string variants');
    }
    foreach (['post_link_no_qs', 'post_link_no_qs,count', 'post_link_no_qs,country,count'] as $columns) {
        $html = Shortcode::render(['f' => 'top', 'w' => $columns], 'resource starts_with /grouped');
        $check(1 === substr_count($html, '<li>') && false !== strpos($html, 'href="/grouped"'), 'Query-string variants group before rendering: ' . $columns);
        if (false !== strpos($columns, 'count')) { $check(false !== strpos($html, 'col-count">2</span>'), 'Grouped hit count is summed'); }
    }
    $html = Shortcode::render(['f' => 'top', 'w' => 'dt'], 'resource starts_with /grouped');
    $check(false !== strpos($html, esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), wp_slimstat::now()))), 'Top dates use the stored date');
    remove_filter('pre_option_permalink_structure', $permalinks);
    remove_filter('query', $watch);
    $unexpected = array_filter($written, static function ($sql) { return strpos($sql, '_transient_') === false; });
    $check(!$unexpected, 'Rendering does not write options');
    $key = 'slimstat_shortcode_live_' . get_current_blog_id() . '_' . wp_slimstat::report_scope()['cache'];
    delete_transient($key);
    Shortcode::render(['f' => 'live', 'w' => 'users']);
    $check(false !== get_transient($key), 'Live result cached');
    set_transient($key, ['users' => 1234, 'pages' => 8, 'countries' => 2], 60);
    $check(number_format_i18n(1234) === Shortcode::render(['f' => 'live', 'w' => 'users']), 'Second live call uses transient');
    delete_transient($key);
    echo "PASS: shortcode rendering, SQL boundary, privacy, REST validation, Pro sample, escaping and live cache\n";
} finally {
    remove_filter('query', $watch);
    remove_filter('option_active_plugins', $free);
    clean_user_cache($admin->ID);
    $core->prefix = $prefix;
    wp_slimstat::$wpdb = $analytics;
    wp_slimstat::$settings = $settings;
    wp_set_current_user($user);
    $core->query("DROP TABLE IF EXISTS `$table`");
}

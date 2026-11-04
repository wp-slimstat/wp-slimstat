<?php
// Prepare only the disposable benchmark's core users and report definitions.
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'wordpress' || getenv('SLIMSTAT_REPORT_FIXTURE') !== '1') {
    throw new RuntimeException('Explicit disposable benchmark fixture required');
}
$db = wp_slimstat::$wpdb;
$usernames = $db->get_col("SELECT DISTINCT username FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE username IS NOT NULL AND username <> '' LIMIT 101");
if ($db->last_error !== '' || count($usernames) > 100) {
    throw new RuntimeException('Cannot prepare bounded report users');
}
foreach ($usernames as $username) {
    if (!username_exists($username)) {
        $id = wp_insert_user(['user_login'=>$username, 'user_pass'=>wp_generate_password(), 'role'=>'subscriber', 'user_email'=>$username . '@benchmark.invalid']);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    }
}
$goal = ['id'=>901, 'name'=>'Benchmark pageview goal', 'active'=>true, 'dimension'=>'resource', 'operator'=>'contains', 'value'=>'/'];
$funnel = ['id'=>902, 'name'=>'Benchmark two-page funnel', 'steps'=>[
    ['id'=>903, 'name'=>'First page', 'active'=>true, 'dimension'=>'resource', 'operator'=>'contains', 'value'=>'/'],
    ['id'=>904, 'name'=>'Next page', 'active'=>true, 'dimension'=>'resource', 'operator'=>'contains', 'value'=>'/'],
]];
if (get_option('slimstat_goals') || get_option('slimstat_funnels')) {
    throw new RuntimeException('Report fixture would overwrite existing definitions');
}
update_option('slimstat_goals', [$goal]);
update_option('slimstat_funnels', [$funnel]);
if (get_option('slimstat_goals') !== [$goal] || get_option('slimstat_funnels') !== [$funnel]) {
    throw new RuntimeException('Report definitions were not persisted');
}
echo wp_json_encode(['usernames'=>$usernames, 'goals'=>[$goal], 'funnels'=>[$funnel]], JSON_PRETTY_PRINT) . "\n";

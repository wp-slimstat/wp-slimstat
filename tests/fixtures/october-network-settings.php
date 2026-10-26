<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
/** Run only on the disposable octnet_ multisite; seed, then verify in a separate request. */
if (!is_multisite() || 'octnet_' !== $GLOBALS['wpdb']->base_prefix || 'tests-wordpress' !== DB_NAME) {
    throw new RuntimeException('Requires the isolated October multisite fixture.');
}
$mode=$args[0] ?? 'verify';
if ('seed' === $mode) {
    $settings=wp_slimstat::init_options();
    $settings=array_replace($settings, ['version'=>'5.4.0','_migration_5460'=>'0','gdpr_enabled'=>'off','javascript_mode'=>'off','set_tracker_cookie'=>'off','anonymize_ip'=>'on','hash_ip'=>'on','heatmap_capture'=>'off','heatmap_rate'=>0,'auto_purge'=>0,'use_separate_menu'=>'no','delete_data_on_uninstall'=>'no','addon_email_report_email'=>'qa@example.invalid','addon_email_report_frequency'=>'monthly','slimstat_pro_license_status'=>false,'custom_nested'=>['zero'=>0,'empty'=>'','false'=>false],'addon_network_settings_heatmap_capture'=>'on','addon_network_settings_heatmap_rate'=>'on']);
    update_site_option('slimstat_options',$settings);
    update_site_option('october_network_expected',$settings);
    echo "Network settings seeded.\n"; return;
}
$expected=get_site_option('october_network_expected');
if (!$expected) throw new RuntimeException('Missing expected settings');
require_once WP_PLUGIN_DIR.'/wp-slimstat/admin/index.php';
wp_slimstat_admin::update_tables_and_options();
$actual=get_site_option('slimstat_options'); $diff=[];
foreach ($expected as $key=>$value) {
    if (in_array($key,['version','_migration_5460','browscap_last_modified'],true)) continue;
    if (!array_key_exists($key,$actual) || $actual[$key]!==$value) $diff[]=$key;
}
$result=['multisite'=>true,'scope'=>is_network_admin()?'network admin':'site','checked_keys'=>count($expected)-3,'changed_user_keys'=>$diff,'effective_capture'=>wp_slimstat::$settings['heatmap_capture'],'effective_rate'=>wp_slimstat::$settings['heatmap_rate']];
echo wp_json_encode($result)."\n";
if ($diff || 'off' !== $result['effective_capture'] || 0 !== $result['effective_rate']) throw new RuntimeException('Network settings changed');

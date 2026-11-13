<?php
// Run with wp eval-file against each checkout on the disposable comparison database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (DB_NAME !== 'test_privacy_20261113') { throw new RuntimeException('Disposable database required'); }
$saved = wp_slimstat::$settings;
$facts = [];
$facts['fresh_defaults'] = array_intersect_key(wp_slimstat::get_fresh_defaults(), array_flip(['gdpr_enabled','anonymize_ip','hash_ip','set_tracker_cookie','auto_purge_delete','do_not_track']));
foreach (['missing' => ['', 'off'], 'banner' => ['slimstat_banner','on'], 'rcb' => ['real_cookie_banner','off']] as $name => [$integration, $banner]) {
    wp_slimstat::$settings = array_merge($saved, ['gdpr_enabled'=>'on','consent_integration'=>$integration,'use_slimstat_banner'=>$banner,'set_tracker_cookie'=>'on','anonymize_ip'=>'off','hash_ip'=>'off']);
    $_COOKIE = [];
    foreach (['off','on'] as $anonymous) {
        wp_slimstat::$settings['anonymous_tracking']=$anonymous;
        $facts['consent'][$name][$anonymous] = ['track'=>SlimStat\Utils\Consent::canTrack(),'pii'=>SlimStat\Utils\Consent::piiAllowed(),'forged_upgrade'=>SlimStat\Utils\Consent::piiAllowed(true)];
    }
}
wp_slimstat::$settings = array_merge($saved, ['gdpr_enabled'=>'on','consent_integration'=>'slimstat_banner','use_slimstat_banner'=>'on','anonymous_tracking'=>'on','anonymize_ip'=>'off','hash_ip'=>'off']);
$facts['forged_ip'] = SlimStat\Providers\IPHashProvider::processIp(['ip'=>'198.51.100.123'], true)['ip'];
$db=wp_slimstat::$wpdb; $p=$GLOBALS['wpdb']->prefix;
if ($db->get_var('SELECT DATABASE()') !== DB_NAME) { throw new RuntimeException('Analytics handle must use the disposable database'); }
$db->insert($p.'slim_stats', ['email'=>'audit@example.test','username'=>'privacy-audit','resource'=>'/private?email=jane%40example.test&token=secret&utm_source=news','other_ip'=>'198.51.100.99','language'=>'de','searchterms'=>'private query','notes'=>'private note','dt'=>time(),'visit_id'=>9913]);
$id=$db->insert_id;
$export=SlimStat\Services\Privacy\DataExporter::exportPageviews('audit@example.test');
$facts['export_names'] = array_column($export['data'][0]['data'], 'name');
$_COOKIE['slimstat_tracking_code']=SlimStat\Tracker\Utils::getValueWithChecksum('9913');
$pro='WpSlimstatPro\\Addon\\Addons\\UserOverviewAddon';
if (class_exists($pro)) {
    $pro::addLoginNoteToPageview('privacy-audit');
    $facts['pro_unconsented_note']=$db->get_var($db->prepare("SELECT notes FROM {$p}slim_stats WHERE id=%d",$id));
}
$facts['privacy_shortcode']=shortcode_exists('slimstat_privacy_settings');
$facts['banner_implied_consent']=strpos((new SlimStat\Services\GDPRService(wp_slimstat::$settings))->getBannerHtml(),'By continuing')!==false;
$facts['fresh_report_masking'] = wp_slimstat::get_fresh_defaults()['mask_personal_data'] ?? 'unavailable';
wp_slimstat::$settings['do_not_track']='on';
wp_slimstat::$settings['gdpr_enabled']='off';
$_SERVER['HTTP_SEC_GPC']='1';
$facts['gpc_blocks_tracking'] = !SlimStat\Utils\Consent::canTrack();
unset($_SERVER['HTTP_SEC_GPC']);
$detail=get_option('slimstat_tracker_error_detail','');
SlimStat\Tracker\Utils::recordErrorDetail("Duplicate entry 'fixture@example.test' for key 'email'");
$facts['stored_database_diagnostic'] = get_option('slimstat_tracker_error_detail');
update_option('slimstat_tracker_error_detail',$detail,false);
wp_slimstat::$settings=$saved;
$db->delete($p.'slim_stats',['id'=>$id]);
echo wp_json_encode($facts, JSON_PRETTY_PRINT);

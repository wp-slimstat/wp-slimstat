<?php
/** Real checkout fixture; no payment or mail leaves this disposable site. @license GPL-2.0-or-later */
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !preg_match('/^test_form_analytics_/', DB_NAME)) { throw new RuntimeException('Use a disposable Form Analytics site.'); }
$mode = $args[0] ?? '';
if (!in_array($mode, ['seed','confirm','cleanup'],true)) { throw new RuntimeException('Invalid purchase fixture mode.'); }
define('SLIMSTAT_E2E_TESTING', true);
$fixture_mode = $mode;
$fixture_run = 'forms-purchase-qa';
if ('seed' === $mode) {
    update_option('slimstat_forms_qa_previous_settings', wp_slimstat::$settings, false);
    wp_slimstat::$settings['set_tracker_cookie']='on';
    wp_slimstat::update_option('set_tracker_cookie','on');
    SlimStat\Ecommerce\Integration::setup();
    SlimStat\Ecommerce\Integration::import();
}
if ('confirm' !== $mode) {
    ob_start(); require dirname(__DIR__).'/e2e/helpers/woocommerce-store.php'; $json=ob_get_clean();
    if ('cleanup' === $mode) {
        $previous=get_option('slimstat_forms_qa_previous_settings',[]);
        if (isset($previous['set_tracker_cookie'])) wp_slimstat::update_option('set_tracker_cookie',$previous['set_tracker_cookie']);
        delete_option('slimstat_forms_qa_previous_settings');
    }
    echo 'FORMS_PURCHASE_JSON:'.$json."\n";
    return;
}
$store=get_option('slimstat_e2e_woo_store',[]);
$orders=wc_get_orders(['billing_email'=>$store['email'],'limit'=>2]);
if (count($orders)!==1 || $orders[0]->get_status()!=='on-hold') throw new RuntimeException('A real unpaid checkout must precede confirmation.');
$order=$orders[0];
SlimStat\Ecommerce\Integration::sync($order->get_id());
if (!class_exists('wp_slimstat_admin',false)) require SLIMSTAT_DIR.'/admin/index.php';
if (!class_exists('wp_slimstat_db',false)) require SLIMSTAT_DIR.'/admin/view/wp-slimstat-db.php';
wp_set_current_user(1); wp_slimstat_db::init('',false,false);
$id=(int)($args[1]??0); $form=(int)($args[2]??0); $db=SlimStat\Forms\Store::db();
$stats=SlimStat\Forms\Store::table('slim_stats');
$visit=(int)$db->get_var($db->prepare("SELECT visit_id FROM {$stats} WHERE id=%d",$id));
if (!$visit || !$form) throw new RuntimeException('Missing consented form visit.');
$day=(int)floor(wp_slimstat::now()/DAY_IN_SECONDS)*DAY_IN_SECONDS;
wp_slimstat_db::$filters_normalized=['utime'=>['start'=>$day,'end'=>$day+DAY_IN_SECONDS-1],'columns'=>['addon_form'=>['equals','cf7:'.$form],'visit_id'=>['equals',(string)$visit]]];
$unpaid=WpSlimstatPro\Forms\Report::revenue();
$order->update_status('completed');
SlimStat\Ecommerce\Integration::sync($order->get_id()); wp_slimstat::bump_data_version();
$paid=WpSlimstatPro\Forms\Report::revenue();
echo 'FORMS_PURCHASE_JSON:'.wp_json_encode(['unpaid'=>$unpaid,'paid'=>$paid,'total'=>$order->get_total()])."\n";

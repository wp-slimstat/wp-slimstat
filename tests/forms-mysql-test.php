<?php
/** Real MySQL checks; only disposable test databases are allowed. @license GPL-2.0-or-later */
use SlimStat\Forms\Store;
use SlimStat\Forms\Integration;
use SlimStat\Forms\Report;
use SlimStat\Schema\Schema;
if ( ! defined('WP_CLI') || ! WP_CLI || ! preg_match('/^test_form_analytics_/', DB_NAME) ) { throw new RuntimeException('Use an isolated test_form_analytics_* database.'); }
function forms_check($condition,$message) { if (!$condition) { throw new RuntimeException($message); } }
global $wpdb;
$prefix=$wpdb->prefix;
$settings=wp_slimstat::$settings;
$state=get_option(Store::STATE);
$wpdb->prefix='formsqa_'.getmypid().'_';
$created=[];
try {
 foreach (['slim_stats','slim_events'] as $suffix) {
  $created[]=Schema::tableName($suffix,$wpdb->prefix);
  forms_check(false!==$wpdb->query(Schema::createTableSql($suffix,$wpdb->prefix,Schema::targetCollation($wpdb))), 'Create fixture: '.$suffix);
 }
 $created[]=Store::table();$created[]=Store::table('slim_form_daily');
 forms_check(Store::setup(),'Fresh setup');
 forms_check(Store::setup(),'Idempotent setup');
 forms_check(Store::ready(),'Schema is ready');
 wp_set_current_user(0);
 wp_slimstat::$settings['is_tracking']='on'; wp_slimstat::$settings['gdpr_enabled']='off';
 wp_slimstat::$settings['anonymous_tracking']='off'; wp_slimstat::$settings['ignore_wp_users']='off';
 wp_slimstat::$settings['ignore_ips']=''; wp_slimstat::$settings['set_tracker_cookie']='off';
 $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['HTTP_REFERER']=home_url('/forms-contract/');
 $stats=Store::table('slim_stats');$sessions=Store::table();$daily=Store::table('slim_form_daily');$events=Store::table('slim_events');
 $wpdb->insert($stats,['id'=>9001,'visit_id'=>9001,'dt'=>wp_slimstat::now(),'resource'=>'/forms-contract/','browser'=>'Chrome','browser_type'=>0,'platform'=>'MacOSX','country'=>'de','email'=>'forms-qa@example.test']);
 forms_check(!$wpdb->last_error,'Fixture page exists');
 $key='cf7:5';
 $payload=static function($flags,$token=null) use ($key) {return wp_json_encode(['v'=>1,'forms'=>[['token'=>$token??Integration::token($key),'flags'=>$flags]]]);};
 forms_check(1===Store::ingest(9001,$payload(7)),'Consent-allowed behavior accepted');
 forms_check(1===Store::ingest(9001,$payload(7)),'Duplicate delivery acknowledged');
 forms_check(1===$wpdb->get_var("SELECT COUNT(*) FROM $sessions")*1,'One bounded cohort');
 forms_check(1===$wpdb->get_var("SELECT SUM(seen) FROM $daily")*1,'No duplicate views');
 forms_check(0===Store::ingest(9001,$payload(64)),'Client success rejected');
 forms_check(0===Store::ingest(9001,$payload(7,'cf7:5.forged')),'Forged form marker rejected');
 forms_check(0===Store::ingest(9001,str_repeat('a',Store::MAX_BYTES+1)),'Oversized payload rejected');
 $_SERVER['HTTP_REFERER']=home_url('/other-page/');
 forms_check(0===Store::ingest(9001,$payload(15)),'Different source page rejected');
 $_SERVER['HTTP_REFERER']=home_url('/forms-contract/');
 wp_slimstat::$settings['is_tracking']='off';
 forms_check(0===Store::ingest(9001,$payload(15)),'Global tracking disable is respected');
 wp_slimstat::$settings['is_tracking']='on';
 Store::confirm($key,'submitted',0);
 forms_check(0===$wpdb->get_var("SELECT SUM(submitted) FROM $daily")*1,'Missing signed page never guesses from a visitor cookie');
 $wpdb->insert($stats,['id'=>9003,'visit_id'=>9003,'dt'=>wp_slimstat::now(),'resource'=>'/?page_id=123','browser'=>'Chrome','browser_type'=>0]);
 $_SERVER['HTTP_REFERER']=home_url('/?page_id=456');
 forms_check(0===Store::ingest(9003,$payload(3)),'Different plain permalink page rejected');
 $_SERVER['HTTP_REFERER']=home_url('/forms-contract/');
 Store::confirm($key,'submitted',9001);Store::confirm($key,'submitted',9001);
 forms_check(1===$wpdb->get_var("SELECT SUM(submitted) FROM $daily")*1,'Duplicate outcome counted once');
 forms_check(1===$wpdb->get_var("SELECT COUNT(*) FROM $events WHERE notes LIKE '%[type:form_complete]%'")*1,'One confirmed Goal event');
 forms_check(0===$wpdb->get_var("SELECT COUNT(*) FROM $events WHERE notes LIKE '%[type:submit]%'")*1,'Existing attempt Goal vocabulary unchanged');
 // An analytics call must never commit someone else's transaction.
 $wpdb->query('START TRANSACTION');
 $wpdb->insert($stats,['id'=>9002,'visit_id'=>9002,'dt'=>wp_slimstat::now(),'resource'=>'/forms-contract/','browser'=>'Chrome','browser_type'=>0]);
 try { Store::ingest(9002,$payload(3)); } catch (Throwable $error) { /* Expected fail-closed transaction guard. */ }
 $wpdb->query('ROLLBACK');
 forms_check(0===$wpdb->get_var("SELECT COUNT(*) FROM $stats WHERE id=9002")*1,'Builder transaction not committed by analytics');
 if (!class_exists('wp_slimstat_admin',false)) { require SLIMSTAT_DIR . '/admin/index.php'; }
 if (!class_exists('wp_slimstat_db',false)) { require SLIMSTAT_DIR . '/admin/view/wp-slimstat-db.php'; }
 wp_set_current_user(1);
 wp_slimstat_db::init('', false, false);
 $goal=wp_slimstat_db::get_goal_results(['id'=>'forms-qa-'.getmypid(),'dimension'=>'event_notes','operator'=>'contains','value'=>'[type:form_complete][form:cf7:5]']);
 forms_check(1===(int)$goal['total'] && 1===(int)$goal['uniques'],'Existing Goals counts a confirmed form once');
 $day=(int)floor(wp_slimstat::now()/DAY_IN_SECONDS)*DAY_IN_SECONDS;
 wp_slimstat_db::$filters_normalized=['utime'=>['start'=>$day,'end'=>$day+DAY_IN_SECONDS-1],'columns'=>[Report::FORM_FILTER=>['equals',$key]]];
 $report=Report::load();
 forms_check(1===(int)$report['forms'][0]['submitted'],'Native date/form selection reconciles with storage');
 forms_check(1===Report::raw()[0]['submitted'],'Export/AI reader uses the same confirmed count');
 wp_slimstat_db::$filters_normalized['columns']['browser_type']=['equals','2'];
 forms_check([]===Report::load()['forms'],'A mobile segment cannot contain the desktop fixture');
 wp_slimstat_db::$filters_normalized['columns']['country']=['equals','de'];
 if (Report::advanced()) {
  unset(wp_slimstat_db::$filters_normalized['columns']['browser_type']);
  forms_check(1===(int)Report::load()['forms'][0]['submitted'],'Pro country segment uses the same confirmed cohort');
 } else {
  try { Report::load(); throw new RuntimeException('Unsupported filters must not silently disappear'); }
  catch (InvalidArgumentException $expected) { /* Honest unavailable selection, not unfiltered totals. */ }
 }
 wp_slimstat_db::$filters_normalized['columns']=[Report::FORM_FILTER=>['equals',$key]];
 wp_slimstat_db::$filters_normalized['utime']=['start'=>$day-DAY_IN_SECONDS,'end'=>$day-1];
 forms_check([]===Report::load()['forms'],'Yesterday excludes today even after a cached current report');
 wp_slimstat::$settings['auto_purge']=1;
 $wpdb->insert($daily,['day'=>$day-2*DAY_IN_SECONDS,'form'=>$key,'device'=>0,'channel'=>'direct','seen'=>999,'submitted'=>999,'converted'=>999]);
 wp_slimstat_db::$filters_normalized['utime']=['start'=>$day-3*DAY_IN_SECONDS,'end'=>$day+DAY_IN_SECONDS-1];
 wp_slimstat::bump_data_version();
 $retained=Report::load();
 forms_check($retained['retention_limited'] && 1===(int)$retained['forms'][0]['submitted'],'Expired daily rows are excluded even before the purge runs');
 Store::purge();
 forms_check(0===(int)$wpdb->get_var("SELECT COUNT(*) FROM $daily WHERE day<".Store::retentionStart()),'Bounded aggregate purge removes expired rows');
 wp_set_current_user(0);
 try { Report::raw(); throw new LogicException('Anonymous export must be refused'); }
 catch (RuntimeException $expected) { /* Authorization precedes cached data reads. */ }
 $export=Store::export('forms-qa@example.test');
 forms_check(1===count($export['data']),'Form activity exported using existing visitor association');
 $erase=Store::erase('forms-qa@example.test');
 forms_check($erase['items_removed'] && $erase['done'],'Privacy erasure completes');
 forms_check(0===$wpdb->get_var("SELECT COUNT(*) FROM $sessions")*1,'Attributable form state erased');
 forms_check(1===$wpdb->get_var("SELECT SUM(submitted) FROM $daily")*1,'Coarse totals retain no visitor identifier');
 $wpdb->prefix=$prefix;
 if (!class_exists('wp_slimstat_admin',false)) { require SLIMSTAT_DIR . '/admin/index.php'; }
 wp_set_current_user(1);
 forms_check(Report::canView(),'Owner can view form totals');
 wp_set_current_user(0);
 forms_check(!Report::canView(),'Public cannot read reports');
 echo "PASS: live schema, retry, deduplication, signed identity, payload bounds, source context, tracking disable, Goals, transaction isolation, privacy and permissions\n";
} finally {
 foreach(array_reverse($created) as $table) {$wpdb->query('DROP TABLE IF EXISTS `'. $table .'`');}
 $wpdb->prefix=$prefix;wp_slimstat::$settings=$settings;
 update_option(Store::STATE,$state,false);
}

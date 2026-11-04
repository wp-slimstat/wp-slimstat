<?php
/** Reproducible local scale benchmark. Never use a production database. @license GPL-2.0-or-later */
use SlimStat\Forms\Store;
use SlimStat\Forms\Report;
use SlimStat\Forms\Integration;
use SlimStat\Schema\Schema;
if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !preg_match('/^test_form_analytics_/', DB_NAME)) { throw new RuntimeException('Use a disposable Form Analytics database.'); }
function forms_bench_check($value,$label) { if (!$value) throw new RuntimeException($label); }
global $wpdb;
$prefix=$wpdb->prefix; $oldState=get_option(Store::STATE); $settings=wp_slimstat::$settings;
$wpdb->prefix='formsbench_'.getmypid().'_'; $created=[]; $result=['boot_php_bytes'=>memory_get_usage(true)];
try {
    foreach(['slim_stats','slim_events'] as $suffix) {
        $created[]=Store::table($suffix);
        forms_bench_check(false!==$wpdb->query(Schema::createTableSql($suffix,$wpdb->prefix,Schema::targetCollation($wpdb))), 'Create '.$suffix);
    }
    $created[]=Store::table(); $created[]=Store::table('slim_form_daily');
    forms_bench_check(Store::setup(),'Prepare benchmark forms');
    $stats=Store::table('slim_stats');$forms=Store::table();$daily=Store::table('slim_form_daily');
    $sequence=$wpdb->prefix.'sequence';$created[]=$sequence;
    $wpdb->query("CREATE TABLE {$sequence} (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    $digits='(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $wpdb->query("INSERT INTO {$sequence} SELECT 1+a.n+10*b.n+100*c.n+1000*d.n FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c CROSS JOIN {$digits} d");
    $now=wp_slimstat::now();$day=(int)floor($now/DAY_IN_SECONDS)*DAY_IN_SECONDS;
    $start=microtime(true);
    for($batch=0;$batch<100;$batch++) {
        $offset=$batch*10000;
        forms_bench_check(false!==$wpdb->query("INSERT INTO {$stats} (id,visit_id,dt,resource,browser,browser_type,country) SELECT id+{$offset},id+{$offset},{$day}-MOD(FLOOR((id+{$offset}-1)/20),30)*86400,'/forms-performance/','Chrome',0,'de' FROM {$sequence}"),'Populate real-schema pageviews');
        if(0===($batch+1)%20) echo 'Populated '.(($batch+1)*10000)." historical visits\n";
    }
    $result['seed_seconds']=round(microtime(true)-$start,2);
    forms_bench_check(false!==$wpdb->query("INSERT INTO {$forms} (form,cohort,stat_id,visit_id,dt,day,updated,flags,started_at,submitted_at,duration,device,channel) SELECT CONCAT('cf7:',1+MOD(id,20)),CONCAT('v',id),id,visit_id,dt,dt,dt,IF(MOD(id,4)=0,71,7),dt,IF(MOD(id,4)=0,dt+30,0),IF(MOD(id,4)=0,30,0),MOD(FLOOR(id/600),2),ELT(1+MOD(FLOOR(id/1200),5),'direct','organic_search','referral','email','paid_search') FROM {$stats} WHERE id<=100000"),'Populate cohort summaries');
    forms_bench_check(false!==$wpdb->query("INSERT INTO {$daily} (day,form,device,channel,loaded,seen,started,submitted,converted,completed_starts,timed,duration) SELECT day,form,device,channel,COUNT(*),COUNT(*),COUNT(*),SUM((flags & 64)<>0),SUM((flags & 64)<>0),SUM((flags & 64)<>0),SUM((flags & 64)<>0),SUM(duration) FROM {$forms} GROUP BY day,form,device,channel"),'Aggregate benchmark data');
    foreach([$stats,$forms,$daily] as $table) $wpdb->query("ANALYZE TABLE {$table}");
    $result['rows']=['visits'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$stats}"),'cohorts'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$forms}"),'daily'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$daily}")];
    $result['storage']=$wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',$forms,$daily),ARRAY_A);
    if(!class_exists('wp_slimstat_admin',false))require SLIMSTAT_DIR.'/admin/index.php';
    if(!class_exists('wp_slimstat_db',false))require SLIMSTAT_DIR.'/admin/view/wp-slimstat-db.php';
    wp_set_current_user(1);wp_slimstat_db::init('',false,false);
    wp_slimstat_db::$filters_normalized=['utime'=>['start'=>$day-29*DAY_IN_SECONDS,'end'=>$day+DAY_IN_SECONDS-1],'columns'=>[]];
    $queries=[];
    $capture=static function($sql)use(&$queries,$daily){ if(strpos($sql,$daily)!==false && preg_match('/^SELECT /i',$sql))$queries[]=$sql;return $sql;};
    add_filter('query',$capture);
    foreach(['overview'=>'','detail'=>'cf7:1'] as $kind=>$form) {
        wp_slimstat_db::$filters_normalized['columns']=$form?['addon_form'=>['equals',$form]]:[];
        $times=[];$counts=[];$memory=memory_get_usage(true);
        for($i=0;$i<20;$i++) {
            wp_slimstat::bump_data_version();$queries=[];$begin=microtime(true);$data=Report::load();
            $times[]=(microtime(true)-$begin)*1000;$counts[]=count($queries);
        }
        sort($times);$result[$kind]=['median_ms'=>round($times[9],2),'p95_ms'=>round($times[18],2),'analytics_queries'=>max($counts),'allocated_delta_bytes'=>memory_get_usage(true)-$memory,'explain'=>[]];
        forms_bench_check(max($counts)===('overview'===$kind?1:4),'Fixed report query budget');
        foreach($queries as $sql)$result[$kind]['explain'][]=$wpdb->get_results('EXPLAIN '.$sql,ARRAY_A);
        $queries=[];$begin=microtime(true);Report::load();$result[$kind]['cached_ms']=round((microtime(true)-$begin)*1000,2);$result[$kind]['cached_analytics_queries']=count($queries);
        forms_bench_check(!$queries,'Warm reports must not query analytics');
    }
    remove_filter('query',$capture);
    if (class_exists('WpSlimstatPro\\Forms\\Report') && Report::advanced()) {
        $field=\WpSlimstatPro\Forms\Fields::merge('', ['173',0,1,[[0,10,1],[1,3,1]]],1);
        $wpdb->query($wpdb->prepare("UPDATE {$forms} SET fields=%s",$field));
        wp_slimstat::bump_data_version();
        $begin=microtime(true);\WpSlimstatPro\Forms\Setup::run();$result['journey_index_seconds']=round(microtime(true)-$begin,3);
        foreach(['fields','journeys'] as $method) {
            $times=[];$before=memory_get_usage(true);
            for($i=0;$i<10;$i++) {
                wp_slimstat::bump_data_version();$begin=microtime(true);$read=\WpSlimstatPro\Forms\Report::$method();
                forms_bench_check(null!==$read,'Premium benchmark must produce a complete report');
                $times[]=(microtime(true)-$begin)*1000;
            }
            sort($times);$result['pro_'.$method]=['median_ms'=>round($times[4],2),'p95_ms'=>round($times[9],2),'allocated_delta_bytes'=>memory_get_usage(true)-$before];
        }
    }

    wp_set_current_user(0);
    foreach(['is_tracking'=>'on','gdpr_enabled'=>'off','anonymous_tracking'=>'off','ignore_wp_users'=>'off','ignore_ips'=>'','set_tracker_cookie'=>'off'] as $k=>$v)wp_slimstat::$settings[$k]=$v;
    $_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_REFERER']=home_url('/forms-performance/');
    $payload=static function($flags){return wp_json_encode(['v'=>1,'forms'=>[['token'=>Integration::token('cf7:5'),'flags'=>$flags]]]);};
    $writeQueries=[];$duplicateQueries=[];$start=microtime(true);
    for($i=1000001;$i<=1000100;$i++) {
        $wpdb->insert($stats,['id'=>$i,'visit_id'=>$i,'dt'=>$now,'resource'=>'/forms-performance/','browser'=>'Chrome','browser_type'=>0]);
        $q=$wpdb->num_queries;forms_bench_check(1===Store::ingest($i,$payload(3)),'Seen accepted');$writeQueries['seen']=$wpdb->num_queries-$q;
        $q=$wpdb->num_queries;Store::ingest($i,$payload(7));$writeQueries['started']=$wpdb->num_queries-$q;
        $q=$wpdb->num_queries;Store::confirm('cf7:5','submitted',$i);$writeQueries['confirmed']=$wpdb->num_queries-$q;
        $q=$wpdb->num_queries;Store::ingest($i,$payload(7));$duplicateQueries[]=$wpdb->num_queries-$q;
    }
    $result['write_flow']=['visits'=>100,'seconds'=>round(microtime(true)-$start,3),'statements_per_transition'=>$writeQueries,'retry_statements'=>max($duplicateQueries)];
    $result['peak_php_bytes']=memory_get_peak_usage(true);
    $result['php']=PHP_VERSION;$result['mysql']=$wpdb->db_version();
    echo 'FORMS_BENCHMARK_JSON:'.wp_json_encode($result)."\n";
} finally {
    foreach(array_reverse($created) as $table)$wpdb->query('DROP TABLE IF EXISTS `'.$table.'`');
    $wpdb->prefix=$prefix;wp_slimstat::$settings=$settings;
    false===$oldState?delete_option(Store::STATE):update_option(Store::STATE,$oldState,false);
}

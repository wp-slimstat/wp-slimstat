<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
/** Real legacy/full writes against the disposable database; never the user's Local site. */
use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;
if ('tests-wordpress' !== DB_NAME || is_multisite()) throw new RuntimeException('Disposable single-site DB required');
Store::setup(); $db=Query::db(); $prefix=$GLOBALS['wpdb']->prefix; $path='/october-freshness-fixture/';
$start=wp_slimstat::now()-10; $end=$start+120;
$db->insert($prefix.'slim_stats',['resource'=>$path,'dt'=>$start+1,'resolution'=>'1280x900']); $id=(int)$db->insert_id;
$fullId=0;
$count=static function ($data) use($path) {foreach($data['rows'] as $row) if($row['page']===$path) return (int)$row['clicks']; return 0;};
$assert=static function($want,$got) {if($want!==$got) throw new RuntimeException("Expected $want, received $got");};
try {
 Store::invalidate(); $before=Query::cachedPages($start,$end); $assert(0,$count($before));
 $db->insert($prefix.'slim_events',['id'=>$id,'dt'=>$start+2,'position'=>'12,34','notes'=>'{"id":"fixture-link"}']);
 $assert(0,$count(Query::cachedPages($start,$end))); // Legacy ingestion has no generation bump.
 $began=microtime(true); $legacy=Query::cachedPages($start,$end,'',true); $ms=round(1000*(microtime(true)-$began),2);
 $assert(1,$count($legacy));
 $db->insert($prefix.'slim_stats',['resource'=>$path,'dt'=>$start+3,'resolution'=>'1280x900']); $fullId=(int)$db->insert_id;
 $db->insert($prefix.'slim_heatmap',['id'=>$fullId,'page'=>hex2bin(substr(md5($path),0,16)),'dt'=>$start+3,'device'=>1,'kind'=>0]);
 Store::invalidate(); $full=Query::cachedPages($start,$end); $assert(2,$count($full));
 if ($full['updated']<$before['updated']) throw new RuntimeException('Timestamp moved backwards');
 echo wp_json_encode(['legacy_before_refresh'=>0,'legacy_after_refresh'=>1,'after_full_capture_write'=>2,'forced_query_ms'=>$ms,'timestamp'=>'computation time'])."\n";
} finally {
 $db->delete($prefix.'slim_heatmap',['id'=>$fullId]); $db->delete($prefix.'slim_stats',['id'=>$fullId]); $db->delete($prefix.'slim_events',['id'=>$id]); $db->delete($prefix.'slim_stats',['id'=>$id]); Store::invalidate();
}

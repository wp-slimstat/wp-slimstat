<?php
/** Backdated full capture must replace the legacy layer, including the history note. */
if (PHP_SAPI !== 'cli' || 'tests-wordpress' !== DB_NAME) { throw new RuntimeException('Disposable CLI database required.'); }
use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;
Store::setup();
$state = get_option(Store::STATE);
$db = Query::db(); $prefix = $GLOBALS['wpdb']->prefix;
$path = '/heatmap-overlap-regression/'; $ids = [];
$now = wp_slimstat::now();
$assert = static function ($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException("Expected $expected, got $actual"); } };
try {
 update_option(Store::STATE, array_merge($state, ['since'=>$now-10]));
 foreach ([$now-20, $now-20, $now] as $date) {
  $db->insert($prefix.'slim_stats', ['resource'=>$path, 'dt'=>$date, 'resolution'=>'1280x900']);
  $ids[] = (int) $db->insert_id;
  $db->insert($prefix.'slim_events', ['id'=>end($ids), 'dt'=>$date, 'position'=>'12,34', 'notes'=>'{"id":"link"}']);
 }
 $db->insert(Store::table(), ['id'=>$ids[0], 'page'=>hex2bin(\SlimStat\Schema\SurrogateKey::hex($path)), 'dt'=>$now-20, 'device'=>1, 'kind'=>0]);
 if ($db->last_error) { throw new RuntimeException($db->last_error); }
 Store::invalidate();
 $assert(2, array_sum(array_column(Query::legacyPoints($path, 'desktop'), 'n')));
 $assert(1, array_sum(array_column(Query::clickBins($path, 'desktop', $now-30, $now+1), 'n')));
 $views = Query::deviceViews($path, $now-30, $now+1);
 $assert(3, $views['views']); $assert(1, $views['older']);
 $rows = array_values(array_filter(Query::pages($now-30, $now+1), static fn($row)=>$row['page']===$path));
 $assert(3, (int)$rows[0]['clicks']);
 $assert(0, count(Query::legacyPoints($path, 'mobile')));
 $assert(0, Query::deviceViews($path, $now-30, $now+1, 't1.id = '.$ids[0])['older']);
 echo wp_json_encode(['result'=>'pass','clicks'=>3,'pageviews'=>3,'older'=>1,'covered_old_view_note'=>0]);
} finally {
 foreach ($ids as $id) {
  $db->delete(Store::table(), ['id'=>$id]); $db->delete($prefix.'slim_events', ['id'=>$id]); $db->delete($prefix.'slim_stats', ['id'=>$id]);
 }
 update_option(Store::STATE, $state); Store::invalidate();
}

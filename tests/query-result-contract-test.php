<?php
/** Cached executors keep wpdb's result shape and cannot consume one another's entries. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
define('ABSPATH', '/'); define('ARRAY_A', 'ARRAY_A'); define('MINUTE_IN_SECONDS', 60);
function get_option($key, $default=false) { return $default; }
function apply_filters($tag, $value) { return $value; }
function get_transient($key) { return $GLOBALS['result_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl=0) { $GLOBALS['result_cache'][$key]=$value; return true; }
function delete_transient($key) { unset($GLOBALS['result_cache'][$key]); return true; }
function wp_json_encode($value, $flags=0) { return json_encode($value,$flags); }
class ResultContractDb {
    public $prefix='wp_', $last_error='', $queries=0, $empty=false;
    public function prepare($sql,...$args) { if(count($args)===1&&is_array($args[0])){$args=$args[0];} return vsprintf(str_replace('%s',"'%s'",$sql),$args); }
    public function get_var($sql) { ++$this->queries; return $this->empty?null:'7'; }
    public function get_row($sql) { ++$this->queries; return $this->empty?null:(object)['id'=>'7','resource'=>'/cached']; }
    public function get_col($sql) { ++$this->queries; return $this->empty?[]:['7']; }
    public function get_results($sql,$format) { ++$this->queries; return $this->empty?[]:[['id'=>'7','resource'=>'/cached']]; }
}
class wp_slimstat { public static $wpdb; public static $settings=['show_sql_debug'=>'no']; public static function now(){return 2000000000;} }
require dirname(__DIR__).'/src/Utils/NetworkMerge.php';
require dirname(__DIR__).'/src/Utils/Query.php';
require dirname(__DIR__).'/admin/view/wp-slimstat-db.php';
$db=new ResultContractDb(); wp_slimstat::$wpdb=$GLOBALS['wpdb']=$db;
$today=intdiv(wp_slimstat::now(),86400)*86400;
$make=static function()use($today){return SlimStat\Utils\Query::select('id, resource')->from('wp_slim_stats')->where('dt','BETWEEN',[$today-86400,$today-1])->allowCaching(true);};
$expected=['getVar'=>'7','getRow'=>(object)['id'=>'7','resource'=>'/cached'],'getCol'=>['7'],'getAll'=>[['id'=>'7','resource'=>'/cached']]];
$check=static function($actual,$want,$label){if(gettype($actual)!==gettype($want)||json_encode($actual)!==json_encode($want)){throw new RuntimeException($label.': result shape or values changed');}};
// Include the same builder being reused: the executor is a call property, not a constructor choice.
foreach (array_keys($expected) as $first) {
    $GLOBALS['result_cache']=[]; $db->queries=0; $q=$make();
    $check($q->$first(),$expected[$first],$first.' cold');
    $check($make()->$first(),$expected[$first],$first.' warm');
    if($db->queries!==1){throw new RuntimeException('Warm executor did not reuse its successful answer');}
    foreach($expected as $method=>$want){$check($q->$method(),$want,$first.' then '.$method);}
    foreach($expected as $method=>$want){$check($make()->$method(),$want,$method.' independent warm builder');}
    if($db->queries!==4){throw new RuntimeException('Executors must own four independent cache entries');}
}
$db->empty=true; $GLOBALS['result_cache']=[]; $db->queries=0;
foreach(['getVar'=>null,'getRow'=>null,'getCol'=>[],'getAll'=>[]] as $method=>$want){
    $check($make()->$method(),$want,$method.' empty cold');
    $check($make()->$method(),$want,$method.' empty warm');
}
if($db->queries!==4){throw new RuntimeException('Empty successful answers should still cache');}
echo "PASS: scalar, object, column and row-list cache contracts remain distinct; warm rows are objects and empty rows remain null\n";

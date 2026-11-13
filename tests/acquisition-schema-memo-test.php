<?php
/** Reports reuse one schema probe; migrations, connection switches and failures stay fresh. */
function update_option($key, $value, $autoload = null) { return true; }
class wp_slimstat { public static $wpdb; }
class MemoDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $columns = [];
    public $queries = 0;
    public $fail = false;
    public function suppress_errors($value) { return false; }
    public function get_col($sql) { ++$this->queries; $this->last_error = $this->fail ? 'unavailable' : ''; return $this->columns; }
}
require dirname(__DIR__) . '/src/Tracker/Acquisition.php';
use SlimStat\Tracker\Acquisition;
function memo_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$db = wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new MemoDb();
memo_check(!Acquisition::checkSchema(true), 'missing columns must refuse readiness');
$db->columns = Acquisition::COLUMNS;
memo_check(Acquisition::checkSchema(), 'the migration probe must refresh readiness');
memo_check(Acquisition::checkSchema(true) && 2 === $db->queries, 'reports must reuse the completed probe');
$db->prefix = 'wp_2_';
memo_check(Acquisition::checkSchema(true) && 3 === $db->queries, 'blog switch must use its own schema');
$other = wp_slimstat::$wpdb = new MemoDb();
memo_check(!Acquisition::checkSchema(true) && 1 === $other->queries, 'different analytics connection must not borrow readiness');
$other->fail = true;
memo_check(!Acquisition::checkSchema(), 'failed probe must refuse readiness');
$other->fail = false; $other->columns = Acquisition::COLUMNS;
memo_check(Acquisition::checkSchema(true) && 3 === $other->queries, 'failed probe must be retried, not memoized');
echo "PASS: schema memo reuses reports and preserves migration, blog, connection and failure boundaries\n";

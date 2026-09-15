<?php
require __DIR__ . '/lib/source-scan.php';
function apply_filters($hook, $value) { return $value; }
function get_userdata($id) { return $id === 7 ? (object) ['user_login' => 'verified-login'] : false; }
class wp_slimstat { public static $wpdb; }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_', $sql, $params, $writes = 0;
    public function prepare($sql, $params) { $this->sql = $sql; $this->params = $params; return $sql; }
    public function query($sql) { ++$this->writes; return 1; }
};
wp_slimstat::$wpdb = $GLOBALS['wpdb'];
$body = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/index.php'), 'remove_spam');
eval('class SpamCleanup { public static function run($_new_status, $_old_status, $_comment) {' . $body . '} }');
$comment = (object) ['comment_author' => 'administrator', 'comment_author_IP' => '192.0.2.1', 'user_id' => 0];
SpamCleanup::run('spam', 'unapproved', $comment);
if ($GLOBALS['wpdb']->params !== ['192.0.2.1', '3221225985'] || strpos($GLOBALS['wpdb']->sql, 'username') !== false
    || substr_count($GLOBALS['wpdb']->sql, 'CAST(ip AS CHAR) = %s') !== 2) { throw new RuntimeException('Guest name can delete another login, or IP-era comparison coerces values'); }
$comment->user_id = 7;
SpamCleanup::run('spam', 'unapproved', $comment);
if ($GLOBALS['wpdb']->params !== ['192.0.2.1', '3221225985', 'verified-login']) { throw new RuntimeException('Verified WordPress account was not resolved'); }
$comment->user_id = 0; $comment->comment_author_IP = '2001:db8::1';
SpamCleanup::run('spam', 'unapproved', $comment);
if ($GLOBALS['wpdb']->params !== ['2001:db8::1']) { throw new RuntimeException('IPv6 cleanup fabricated an IPv4 value'); }
$before = $GLOBALS['wpdb']->writes;
$comment->comment_author_IP = 'not-an-ip'; SpamCleanup::run('spam', 'unapproved', $comment);
$comment->comment_author_IP = '192.0.2.1'; SpamCleanup::run('approved', 'unapproved', $comment);
if ($GLOBALS['wpdb']->writes !== $before) { throw new RuntimeException('Non-spam or invalid identity caused deletion'); }
echo "PASS: spam cleanup binds IPv4/IPv6 and integer-era addresses; forged guest names cannot delete another login\n";

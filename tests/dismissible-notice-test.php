<?php
/** Execute the notice renderer and endpoint with isolated users and clocks. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require __DIR__ . '/lib/source-scan.php';
$source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
$methods = [
    'notice_is_dismissed' => '$key, $stamp',
    'show_dismissible_notice' => '$key, $stamp, $message, $buttons = [], $tone = "warning", $snooze = 0',
    'dismiss_notice' => '',
];
$code = '';
foreach ($methods as $method => $args) {
    $body = slimstat_function_body($source, $method);
    if (!$body) { fwrite(STDERR, "FAIL: missing $method\n"); exit(1); }
    $code .= 'public static function ' . $method . '(' . $args . ') {' . $body . '}';
}
eval('class NoticeProbe { ' . $code . ' }');
function get_current_user_id() { return $GLOBALS['user']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_user_meta($user, $key, $single) { return $GLOBALS['meta'][$user][$key] ?? []; }
function update_user_meta($user, $key, $value) { $GLOBALS['meta'][$user][$key] = $value; return true; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function wp_create_nonce($action) { return hash('sha256', $action . ':secret'); }
function check_ajax_referer($action, $key) { if (($_POST[$key] ?? '') !== wp_create_nonce($action)) throw new RuntimeException('nonce'); }
function wp_unslash($value) { return $value; }
function wp_send_json_error($value = null, $status = null) { throw new RuntimeException('denied'); }
function wp_send_json_success() { throw new RuntimeException('saved'); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html($v) { return esc_attr($v); }
function esc_url($v) { return esc_attr($v); }
function esc_html__($v, $domain) { return esc_html($v); }
function __($v, $domain) { return $v; }
function wp_kses_post($v) { return $v; }
function wpautop($v) { return "<p>" . $v . "</p>"; }
function admin_url($v) { return '/wp-admin/' . $v; }
$GLOBALS['user'] = $GLOBALS['blog'] = 1;
$GLOBALS['allowed'] = true;
$GLOBALS['meta'] = [];
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function render($stamp, $snooze = 0) {
    ob_start(); NoticeProbe::show_dismissible_notice('tracking', $stamp, 'New visits are not being recorded.', [['Fix', '/fix', false]], 'error', $snooze); return ob_get_clean();
}
function dismiss($html, $expected) {
    $_POST = ['action' => 'slimstat_dismiss_notice'];
    foreach (['key', 'stamp', 'snooze', 'nonce'] as $key) {
        preg_match('/data-notice-' . $key . '="([^"]*)"/', $html, $m);
        $_POST[$key] = html_entity_decode($m[1] ?? '');
    }
    try { NoticeProbe::dismiss_notice(); } catch (RuntimeException $e) { check($e->getMessage() === $expected, $e->getMessage()); return; }
    throw new RuntimeException('Endpoint did not answer');
}
$stamp = hash('sha256', 'occurrence-one');
$html = render($stamp, 86400);
check(strpos($html, 'is-dismissible') !== false && strpos($html, 'slimstat-custom-db-notice') !== false && strpos($html, 'button-primary') !== false, 'Shared accessible banner missing');
dismiss($html, 'saved');
check(render($stamp, 86400) === '', 'Dismissal not persisted');
$GLOBALS['user'] = 2;
check(render($stamp) !== '', 'Dismissal leaked to another administrator');
$GLOBALS['user'] = 1; $GLOBALS['blog'] = 2;
check(render($stamp) !== '', 'Dismissal leaked to another site');
$GLOBALS['blog'] = 1;
check(render(hash('sha256', 'occurrence-two')) !== '', 'New occurrence hidden');
$GLOBALS['meta'][1]['slimstat_notice_dismissals_1']['tracking']['until'] = time() - 1;
check(render($stamp, 86400) !== '', '24 hour snooze did not expire');
$GLOBALS['allowed'] = false;
dismiss($html, 'denied');
$GLOBALS['allowed'] = true;
try { $_POST['nonce'] = 'invalid'; NoticeProbe::dismiss_notice(); throw new RuntimeException('Invalid nonce accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'nonce', $e->getMessage()); }
// A valid token cannot be reused to turn a snooze into permanent dismissal.
preg_match('/data-notice-nonce="([^"]*)"/', $html, $m);
$_POST['nonce'] = $m[1]; $_POST['snooze'] = '0';
try { NoticeProbe::dismiss_notice(); throw new RuntimeException('Changed snooze accepted'); } catch (RuntimeException $e) { check($e->getMessage() === 'nonce', $e->getMessage()); }
echo "PASS: dismissal is per administrator/site/occurrence, expires, and requires capability and bound nonce\n";

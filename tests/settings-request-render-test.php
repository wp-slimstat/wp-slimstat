<?php
/** Execute the settings action boundary and textarea render with hostile inputs. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr_e($text, $domain = '') { echo esc_attr($text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_url($text) { return esc_attr($text); }
function wp_kses_post($text) { return strip_tags($text, '<li><a><strong><p>'); }
function sanitize_text_field($text) { return strip_tags($text); }
function sanitize_key($text) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($text)); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function wp_die($message) { throw new LogicException($message); }
function wp_nonce_field(...$args) {}
function is_network_admin() { return false; }
class wp_slimstat {
    public static $wpdb;
    public static $settings = [];
    public static function pro_is_installed() { return false; }
    public static function update_option(...$args) { $GLOBALS['writes']++; }
    public static function get_fresh_defaults() { return []; }
}
class wp_slimstat_admin {
    public static $config_url = '/wp-admin/admin.php?page=slimconfig&tab=';
    public static function show_message(...$args) {}
    public static function get_template(...$args) {}
}
wp_slimstat::$wpdb = new class { public $prefix = 'wp_'; public function query($sql) { $GLOBALS['writes']++; } };
$GLOBALS['wpdb'] = wp_slimstat::$wpdb;
$source = file_get_contents(__DIR__ . '/../admin/config/index.php');
$start = strpos($source, '// Save options');
$end = strpos($source, '    // Some of them require extra processing', $start);
check($start !== false && $end !== false, 'settings boundary disappeared');
$boundary = substr($source, $start, $end - $start) . "\n}";
foreach (['reset-settings', 'truncate-table'] as $action) {
    $settings = [1 => ['rows' => []]];
    $_REQUEST = ['slimstat_update_settings' => 'valid'];
    $_GET = ['action' => $action];
    $_POST = [];
    $GLOBALS['allowed'] = false;
    $GLOBALS['writes'] = 0;
    try { eval($boundary); throw new RuntimeException('unauthorized action accepted'); }
    catch (LogicException $error) { check($error->getMessage() === 'Insufficient permissions.', 'wrong denial'); }
    check($GLOBALS['writes'] === 0, 'destructive write before authorization');
    $GLOBALS['allowed'] = true;
    eval($boundary);
    check($GLOBALS['writes'] > 0, 'authorized action did not execute');
}
foreach (['bad', ['valid'], ''] as $nonce) {
    $_REQUEST = ['slimstat_update_settings' => $nonce];
    $GLOBALS['writes'] = 0;
    eval($boundary);
    check($GLOBALS['writes'] === 0, 'invalid nonce caused writes');
}
$_REQUEST = ['slimstat_update_settings' => 'valid'];
foreach (['not-an-array', ['option' => ['nested']]] as $options) {
    $_POST = ['options' => $options];
    $GLOBALS['writes'] = 0;
    try { eval($boundary); throw new RuntimeException('invalid input accepted'); }
    catch (LogicException $error) { check($error->getMessage() === 'Invalid settings data.', 'wrong shape denial'); }
    check($GLOBALS['writes'] === 0, 'malformed settings caused writes');
}
$current_tab = 1;
$GLOBALS['wp_locale'] = (object) ['text_direction' => 'ltr'];
$settings = [1 => ['title' => 'Settings', 'rows' => ['hostile' => [
    'type' => 'textarea', 'title' => 'Text', 'description' => '',
    'after_input_field' => '<input type="hidden" name="trusted-extension" value="1">',
]]]];
wp_slimstat::$settings['hostile'] = '</textarea><script>alert(1)</script>\\literal';
$start = strpos($source, '$tabs_html =');
$end = strpos($source, "<?php\n// Detect companion");
check($start !== false && $end !== false, 'settings render boundary disappeared');
ob_start();
eval(substr($source, $start, $end - $start));
$html = ob_get_clean();
check(strpos($html, '&lt;/textarea&gt;&lt;script&gt;') !== false, 'textarea breakout not escaped');
check(strpos($html, '\\literal') !== false, 'literal backslash lost on render');
check(strpos($html, 'name="trusted-extension"') !== false, 'extension field markup removed');
echo "PASS: settings capabilities precede destructive actions; malformed nonce/options refuse writes; textarea escaped without stripping extension fields\n";

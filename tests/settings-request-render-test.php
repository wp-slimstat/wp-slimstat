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
function current_user_can($cap) { return $cap === 'manage_network_options' ? ($GLOBALS['network_allowed'] ?? false) : $GLOBALS['allowed']; }
function wp_die($message) { throw new LogicException($message); }
function wp_nonce_field(...$args) {}
function is_network_admin() { return $GLOBALS['network'] ?? false; }
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
    $current_tab = 1;
    $settings = [1 => ['rows' => ['option' => ['type' => 'text']]]];
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
$_POST = ['options' => ['extension_payload' => ['nested' => 'preserved']]];
$_GET = [];
eval($boundary);
check($posted_options['extension_payload']['nested'] === 'preserved', 'unknown extension payload rejected');
$GLOBALS['network'] = true;
$GLOBALS['network_allowed'] = false;
$_GET = ['action' => 'reset-settings'];
$GLOBALS['writes'] = 0;
try { eval($boundary); throw new RuntimeException('site admin changed network settings'); }
catch (LogicException $error) { check($GLOBALS['writes'] === 0, 'network authorization follows writes'); }
$GLOBALS['network_allowed'] = true;
eval($boundary);
check($GLOBALS['writes'] > 0, 'network administrator action refused');
$GLOBALS['network'] = false;
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

// Execute shared admin sinks, preserving CSS child combinators and valid text.
require_once __DIR__ . '/lib/source-scan.php';
function wp_strip_all_tags($text) { return strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $text)); }
function wp_kses($text, $allowed) { return strip_tags($text, '<' . implode('><', array_keys($allowed)) . '>'); }
function wpautop($text) { return '<p>' . $text . '</p>'; }
function get_option($name, $default = []) { return $GLOBALS['saved_filters_fixture'] ?? $default; }
class wp_slimstat_reports { public static function fs_url($filters) { return '/admin?filter="onmouseover="attack'; } }
$admin = file_get_contents(__DIR__ . '/../admin/index.php');
$methods = '';
foreach (['wp_slimstat_userdefined_stylesheet' => '', 'add_column_header' => '$_columns = []', 'add_post_column' => '$_column_name, $_post_id', 'show_message' => '$_message = "", $_type = "info", $_dismiss_handle = ""'] as $name => $args) {
    $methods .= 'public static function ' . $name . '(' . $args . ') {' . slimstat_function_body($admin, $name) . '}';
}
eval('class SlimstatAdminRenderProbe { public static $data_for_column = []; ' . $methods . '}');
wp_slimstat::$settings['custom_css'] = 'body > p { color: red; }</style><script>attack()</script>';
ob_start(); SlimstatAdminRenderProbe::wp_slimstat_userdefined_stylesheet(); $css = ob_get_clean();
check(strpos($css, '<script') === false && substr_count($css, '</style>') === 1, 'custom CSS breaks out of style element');
check(strpos($css, 'body > p') !== false && strpos($css, '&gt;') === false, 'CSS child combinator encoded');
wp_slimstat::$settings['posts_column_pageviews'] = 'on';
wp_slimstat::$settings['posts_column_day_interval'] = '" onmouseover="attack';
$header = SlimstatAdminRenderProbe::add_column_header();
check(strpos($header['wp-slimstat'], '&quot; onmouseover=&quot;') !== false, 'column header attribute unescaped');
SlimstatAdminRenderProbe::$data_for_column = ['url' => [7 => '/page'], 'count' => [7 => '<script>attack</script>']];
ob_start(); SlimstatAdminRenderProbe::add_post_column('wp-slimstat', 7); $column = ob_get_clean();
check(strpos($column, '<script>') === false && strpos($column, '&lt;script&gt;') !== false, 'column count unescaped');
check(strpos($column, 'filter=&quot;onmouseover=&quot;') !== false, 'column URL attribute unescaped');
ob_start(); SlimstatAdminRenderProbe::show_message('<strong>Keep</strong><script>attack</script>'); $notice = ob_get_clean();
check(strpos($notice, '<strong>Keep</strong>') !== false && strpos($notice, '<script>') === false, 'notice markup not filtered at output');

$handler = slimstat_function_body($admin, 'manage_filters');
$start = strpos($handler, '$new_filter =');
$end = strpos($handler, '// Check if this filter is already saved', $start);
check($start !== false && $end !== false, 'saved filter validation missing');
$validation = substr($handler, $start, $end - $start);
foreach (['null', 'false', '"scalar"', '{broken', '[]', '{"resource":["equals",[]]}'] as $bad) {
    $_POST = ['filter_array' => $bad];
    try { eval($validation); throw new RuntimeException('malformed filter accepted: ' . $bad); }
    catch (LogicException $error) { check($error->getMessage() === 'Invalid filter data.', 'wrong filter rejection'); }
}
$filter = ['resource' => ['contains', '<tag> & "literal"'], 'content_id' => ['equals', 123]];
$_POST = ['filter_array' => addslashes(json_encode($filter))];
eval($validation);
check($new_filter === $filter, 'valid saved filter values changed during JSON parsing');
echo "PASS: admin CSS, headers, links and notices escape by context; malformed saved filters refused; valid filter JSON preserved\n";

function is_admin() { return true; }
class wp_slimstat_db { public static $debug_message = ''; }
$reports = file_get_contents(__DIR__ . '/../admin/view/wp-slimstat-reports.php');
eval('class SlimstatSummaryProbe { public static function raw_results_to_html($_args = []) {' . slimstat_function_body($reports, 'raw_results_to_html') . '} }');
wp_slimstat::$settings['async_load'] = 'no';
ob_start();
SlimstatSummaryProbe::raw_results_to_html(['columns' => '', 'raw' => static function () {
    return [['metric' => '<strong>Visits</strong><script>attack()</script>', 'value' => '<script>attack()</script>42', 'details' => '<script>attack()</script>detail']];
}]);
$summary = ob_get_clean();
check(strpos($summary, '<script>') === false && strpos($summary, '<strong>Visits</strong>') !== false, 'summary branches must filter markup without removing formatting');
echo "PASS: summary metric, value and details use contextual HTML filtering (real WordPress KSES control in reports-output-escaping-test.php)\n";

// The legacy add-on list is a separate remote-data and license-write boundary.
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function sanitize_title($text) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $text)); }
function get_transient($key) { return $GLOBALS['addon_response']; }
function wp_remote_retrieve_body($response) { return is_array($response) && isset($response['body']) ? $response['body'] : ''; }
function is_plugin_active($plugin) { return false; }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$addons = file_get_contents(__DIR__ . '/../admin/view/addons.php');
$start = strpos($addons, '// Update license keys');
$end = strpos($addons, '$response      =', $start);
$license_boundary = substr($addons, $start, $end - $start);
$_POST = ['licenses' => ['wp-slimstat-addon' => 'key'], 'slimstat_update_licenses' => 'valid'];
$GLOBALS['allowed'] = false;
$GLOBALS['writes'] = 0;
try { eval($license_boundary); throw new RuntimeException('unauthorized license write accepted'); }
catch (LogicException $error) { check($GLOBALS['writes'] === 0, 'unauthorized license write executed'); }
$GLOBALS['allowed'] = true;
foreach ([['../outside' => 'key'], ['wp-addon' => ['nested']]] as $bad) {
    $_POST['licenses'] = $bad;
    try { eval($license_boundary); throw new RuntimeException('malformed license data accepted'); }
    catch (LogicException $error) { check($GLOBALS['writes'] === 0, 'invalid license write executed'); }
}
$_POST = [];
$_GET = [];
$_SERVER['REQUEST_URI'] = '/admin?page=slimaddons';
$GLOBALS['addon_response'] = ['body' => json_encode([['slug' => 'wp-addon', 'name' => '<script>name</script>', 'download_url' => '/addon', 'description' => '<strong>Keep</strong>', 'price' => '<script>price</script>', 'version' => '<script>version</script>']])];
ob_start(); include __DIR__ . '/../admin/view/addons.php'; $addon_html = ob_get_clean();
check(strpos($addon_html, '<script>') === false && strpos($addon_html, '&lt;script&gt;price') !== false, 'remote add-on metadata not escaped');
check(strpos($addon_html, '<strong>Keep</strong>') !== false, 'remote add-on description formatting lost');
foreach ([(object) ['error' => 'network failure'], ['body' => '{bad'], ['body' => '[{"slug":"../outside"}]']] as $bad) {
    $GLOBALS['addon_response'] = $bad;
    ob_start(); include __DIR__ . '/../admin/view/addons.php'; $addon_html = ob_get_clean();
    check(strpos($addon_html, 'slimstat-addons') === false, 'malformed remote response rendered as valid list');
}
echo "PASS: legacy license writes authorized and validated; remote add-on metadata escaped; malformed remote responses fail closed\n";

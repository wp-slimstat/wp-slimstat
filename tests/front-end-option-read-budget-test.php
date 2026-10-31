<?php
/** Anonymous boot must not read absent optional-feature options. */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
require_once __DIR__ . '/lib/source-scan.php';
require_once dirname(__DIR__) . '/src/Ecommerce/Integration.php';
require_once dirname(__DIR__) . '/src/Providers/RestApiManager.php';

$reads = [];
$options = [];
$hooks = [];
$failures = [];
function get_option($name, $default = false) { global $reads, $options; $reads[] = $name; return $options[$name] ?? $default; }
function add_filter($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name] = $callback; }
function add_action($name, $callback, $priority = 10) { add_filter($name, $callback, $priority); }
function __($text, $domain = '') { return $text; }
class wp_slimstat { public static $settings = []; }
function budget_check($ok, $label) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $GLOBALS['failures'][] = $label; }
}

\SlimStat\Ecommerce\Integration::boot();
\SlimStat\Providers\RestApiManager::rewriteRuleRequest();
budget_check([] === $reads, 'non-WooCommerce boot and init perform zero option reads');

$input = ['another-plugin' => ['callback' => 'other']];
foreach (['erasers', 'exporters'] as $method) {
    $hook = 'wp_privacy_personal_data_' . $method;
    budget_check(isset($hooks[$hook]), 'privacy hook remains registered without WooCommerce: ' . $method);
    budget_check($input === \SlimStat\Ecommerce\Integration::$method($input), 'unconfigured privacy filter preserves input: ' . $method);
}
budget_check(isset($hooks['wp_slimstat_purge'], $hooks['slimstat_ecommerce_maintenance']), 'retention hooks remain registered after WooCommerce deactivation');
$options['slimstat_ecommerce_state'] = ['version' => 1];
foreach (['erasers', 'exporters'] as $method) {
    $result = \SlimStat\Ecommerce\Integration::$method($input);
    budget_check(isset($result['slimstat-ecommerce'], $result['another-plugin']), 'configured privacy filter works without WooCommerce: ' . $method);
}

$source = slimstat_blank_comments(file_get_contents(dirname(__DIR__) . '/src/Ecommerce/Integration.php'));
preg_match_all('/update_option\(self::STATE,\s*\$state,\s*([^)]*)\)/', $source, $writes);
budget_check(count($writes[1]) >= 3 && [] === array_diff($writes[1], ['true']), 'every Ecommerce state write autoloads the small state');
$source = slimstat_blank_comments(file_get_contents(dirname(__DIR__) . '/admin/config/index.php'));
budget_check(false === strpos($source, "update_option('slimstat_permalink_structure_updated'"), 'settings no longer writes a per-request rewrite flag');
budget_check(false !== strpos($source, "delete_option('rewrite_rules')"), 'tracking-method changes invalidate rewrite rules for the next init');
$bootstrap = slimstat_blank_comments(file_get_contents(dirname(__DIR__) . '/wp-slimstat.php'));
budget_check(false === strpos($bootstrap, 'Integration::ready()'), 'plugin entry point defers Ecommerce readiness to callbacks');
exit($failures ? 1 : 0);

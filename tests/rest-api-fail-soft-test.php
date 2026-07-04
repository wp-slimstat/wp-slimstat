<?php
/**
 * Regression (issue #325): one REST controller that throws during
 * register_routes() must not stop the other controllers from registering.
 *
 * Standalone (own process) so its global wp_slimstat / WP-function stubs can't
 * pollute a shared PHPUnit process — an earlier PHPUnit version of this test
 * shadowed wp_slimstat and broke unrelated integration tests. Uses a self-
 * registered PSR-4 autoloader for SlimStat\ so it is independent of the built
 * vendor/ (which CI can leave in a cached/sparse state).
 *
 * 7.4-safe: plain PHP, no PHPUnit.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

// Load SlimStat\ classes straight from src/ (no dependency on vendor/).
spl_autoload_register(function ($class) use ($plugin_root) {
    if (strpos($class, 'SlimStat\\') !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen('SlimStat\\')));
    $file = $plugin_root . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// RestApiManager and the controllers guard on ABSPATH.
if (!defined('ABSPATH')) {
    define('ABSPATH', $plugin_root . '/');
}

$GLOBALS['__slim_registered'] = [];
// A controller whose register_routes() throws — injected at the FRONT so that,
// without the per-controller guard, it aborts the loop before the real five.
$GLOBALS['__slim_throwing'] = new class {
    public function register_routes(): void
    {
        throw new \Error('simulated class-load failure');
    }
};

function add_action($hook, $callback = null, $priority = 10, $args = 1)
{
    return true;
}
function register_rest_route($namespace, $route, $args = [])
{
    $GLOBALS['__slim_registered'][] = $namespace . $route;
    return true;
}
function apply_filters($hook, $value = null)
{
    if ($hook === 'slimstat_rest_controllers' && is_array($value)) {
        return array_merge([$GLOBALS['__slim_throwing']], $value);
    }
    return $value;
}
// RestApiManager's fail-soft catch calls \wp_slimstat::log().
class wp_slimstat
{
    public static function log($message, $level = 'info')
    {
    }
}

\SlimStat\Providers\RestApiManager::run(); // instantiates the 5 core controllers + the injected one

try {
    // Must NOT rethrow after the per-controller guard; a rethrow is the regression.
    \SlimStat\Providers\RestApiManager::register_routes();
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: register_routes() rethrew instead of failing soft: " . $e->getMessage() . "\n");
    exit(1);
}

$count = count($GLOBALS['__slim_registered']);
if ($count >= 5) {
    fwrite(STDOUT, "OK: {$count} core REST routes registered despite a throwing controller (issue #325)\n");
    exit(0);
}
fwrite(STDERR, "FAIL: only {$count} route(s) registered — a throwing controller aborted the loop\n");
exit(1);

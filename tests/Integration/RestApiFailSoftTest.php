<?php
declare(strict_types=1);

namespace {
    // RestApiManager's fail-soft catch calls \wp_slimstat::log(). The plugin main
    // class isn't loaded in the isolated integration suite, so provide a stub.
    if (!class_exists('wp_slimstat')) {
        class wp_slimstat
        {
            public static function log($message, $level = 'info')
            {
            }
        }
    }
}

namespace WpSlimstat\Tests\Integration {

    use Brain\Monkey\Functions;
    use SlimStat\Providers\RestApiManager;
    use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

    /**
     * Regression (issue #325): one REST controller that throws during
     * register_routes() must not prevent the other controllers from registering.
     *
     * Before the per-controller try/catch, a throwing controller placed at the
     * front of the list aborted the whole loop, so no routes registered.
     */
    final class RestApiFailSoftTest extends WpSlimstatTestCase
    {
        protected function tearDown(): void
        {
            // Reset the private static so controller state can't leak across tests.
            $prop = new \ReflectionProperty(RestApiManager::class, 'controllers');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
            parent::tearDown();
        }

        public function test_one_throwing_controller_does_not_drop_the_others(): void
        {
            $registered = [];

            Functions\when('add_action')->justReturn(true);
            Functions\when('register_rest_route')->alias(
                static function ($namespace, $route) use (&$registered) {
                    $registered[] = $namespace . $route;
                    return true;
                }
            );

            // A controller whose register_routes() throws — injected at the FRONT
            // so that, without the fix, it aborts the loop before the real five.
            $throwing = new class {
                public function register_routes(): void
                {
                    throw new \Error('simulated class-load failure');
                }
            };
            Functions\when('apply_filters')->alias(
                static function ($hook, $value = null) use ($throwing) {
                    if ($hook === 'slimstat_rest_controllers' && is_array($value)) {
                        return array_merge([$throwing], $value);
                    }
                    return $value;
                }
            );

            RestApiManager::run();            // instantiates the 5 core controllers + the injected one
            RestApiManager::register_routes(); // must NOT rethrow after the per-controller try/catch

            $this->assertGreaterThanOrEqual(
                5,
                count($registered),
                'the five core controllers still register despite one throwing controller'
            );
        }
    }
}

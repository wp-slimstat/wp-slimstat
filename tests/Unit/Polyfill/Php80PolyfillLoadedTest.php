<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Polyfill;

use PHPUnit\Framework\TestCase;

/**
 * Integration check: the bundled Symfony/Polyfill/Php80 bootstrap, loaded
 * from wp-slimstat.php, exposes all 7 PHP 8.0 stdlib functions globally.
 *
 * On PHP 8.0+ these are native and the test is a no-op confirmation. On
 * PHP 7.4 (CI Tier 1 fast lane) the polyfill's `function_exists` guards
 * fire and the functions become available — exercising the same code path
 * a production PHP 7.4 visitor would.
 */
class Php80PolyfillLoadedTest extends TestCase
{
    /**
     * @dataProvider polyfilledFunctionProvider
     */
    public function test_polyfilled_function_is_callable(string $fn): void
    {
        // Bootstrap is required by tests/bootstrap.php (indirectly via vendor/autoload),
        // but the production load lives in wp-slimstat.php. Belt and suspenders:
        // require the bootstrap directly so the test is independent of the autoloader
        // wiring.
        require_once dirname(__DIR__, 3) . '/src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php';

        $this->assertTrue(
            function_exists($fn),
            "Function `{$fn}` must be available after loading Symfony/Polyfill/Php80 bootstrap"
        );
    }

    /** @return array<string,array{0:string}> */
    public static function polyfilledFunctionProvider(): array
    {
        // These 7 are defined in src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php
        // behind `if (!function_exists(...))` guards.
        return [
            'fdiv'                => ['fdiv'],
            'preg_last_error_msg' => ['preg_last_error_msg'],
            'str_contains'        => ['str_contains'],
            'str_starts_with'     => ['str_starts_with'],
            'str_ends_with'       => ['str_ends_with'],
            'get_debug_type'      => ['get_debug_type'],
            'get_resource_id'     => ['get_resource_id'],
        ];
    }

    public function test_str_contains_semantics_match_native(): void
    {
        require_once dirname(__DIR__, 3) . '/src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php';

        $this->assertTrue(str_contains('hello world', 'world'));
        $this->assertFalse(str_contains('hello world', 'xyz'));
        $this->assertTrue(str_contains('hello', '')); // PHP 8.0 contract: empty needle is always found
    }
}

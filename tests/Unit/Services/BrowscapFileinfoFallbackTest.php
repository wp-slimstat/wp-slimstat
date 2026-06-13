<?php
declare(strict_types=1);

namespace SlimStat\Services;

/**
 * Namespace-local shadow of PHP's `extension_loaded()`.
 *
 * PHP function-name resolution looks in the current namespace before falling
 * back to the global scope, so the unqualified `extension_loaded('fileinfo')`
 * call inside `SlimStat\Services\Browscap` resolves to THIS function during
 * the test run. The shadow defers to the real built-in unless the test has
 * registered an override via FileinfoStub.
 */
if (!function_exists(__NAMESPACE__ . '\\extension_loaded')) {
    function extension_loaded(string $extension): bool
    {
        return \WpSlimstat\Tests\Unit\Services\FileinfoStub::resolve($extension);
    }
}

namespace WpSlimstat\Tests\Unit\Services;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class FileinfoStub
{
    private static ?bool $fileinfoOverride = null;

    public static function disableFileinfo(): void
    {
        self::$fileinfoOverride = false;
    }

    public static function reset(): void
    {
        self::$fileinfoOverride = null;
    }

    public static function resolve(string $extension): bool
    {
        if ('fileinfo' === $extension && null !== self::$fileinfoOverride) {
            return self::$fileinfoOverride;
        }
        return \extension_loaded($extension);
    }
}

/**
 * Integration tests for the Browscap fileinfo preflight (#303).
 *
 * @see SlimStat\Services\Browscap::get_browser_from_browscap()
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/303
 */
class BrowscapFileinfoFallbackTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FileinfoStub::reset();
    }

    protected function tearDown(): void
    {
        FileinfoStub::reset();
        parent::tearDown();
    }

    /** @test */
    public function returns_input_unchanged_when_fileinfo_missing(): void
    {
        FileinfoStub::disableFileinfo();

        $input = [
            'browser'         => 'Default Browser',
            'browser_version' => '',
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => 'Mozilla/5.0',
        ];

        $result = \SlimStat\Services\Browscap::get_browser_from_browscap(
            $input,
            sys_get_temp_dir() . '/browscap-cache-fileinfo-test/'
        );

        $this->assertSame(
            $input,
            $result,
            'When ext-fileinfo is missing, get_browser_from_browscap() must early-return $_browser unchanged — no Flysystem construction, no fatal.'
        );
    }

    /** @test */
    public function does_not_fatal_when_cache_path_invalid_with_fileinfo_present(): void
    {
        if (!\extension_loaded('fileinfo')) {
            $this->markTestSkipped('Host PHP lacks fileinfo; cannot exercise the catch path with real Flysystem.');
        }

        $input = [
            'browser'         => 'Default Browser',
            'browser_version' => '',
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => 'Mozilla/5.0',
        ];

        // Suppress the wp_slimstat::log() noise the catch emits via WP_DEBUG.
        $prev_log = ini_set('error_log', '/dev/null');

        try {
            $result = \SlimStat\Services\Browscap::get_browser_from_browscap(
                $input,
                "\0invalid-path-that-cannot-be-created"
            );
        } catch (\Throwable $e) {
            $this->fail('get_browser_from_browscap() must catch \Throwable, but threw: ' . $e->getMessage());
        } finally {
            if (false !== $prev_log) {
                ini_set('error_log', $prev_log);
            }
        }

        $this->assertIsArray($result, 'Result must be an array even when the underlying Flysystem call fails.');
        $this->assertArrayHasKey('browser', $result, 'Result shape must remain compatible with the caller in get_browser().');
    }
}

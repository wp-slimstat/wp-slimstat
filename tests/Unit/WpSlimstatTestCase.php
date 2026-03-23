<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

abstract class WpSlimstatTestCase extends TestCase
{
    // MockeryPHPUnitIntegration provides assertion-count verification;
    // Brain Monkey owns Mockery::close() in tearDown — the double-close is harmless (no-op).
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown(); // resets WP stubs, closes Mockery, restores Patchwork
        parent::tearDown();
    }

    /**
     * Stubs the common WP sanitization/escape functions used throughout Tracker tests.
     * Call from a test's setUp() or at the start of tests that exercise code calling these.
     */
    protected function stubCommonWpFunctions(): void
    {
        Monkey\Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
        ]);
    }
}

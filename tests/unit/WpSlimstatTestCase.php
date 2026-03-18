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
}

<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Exception;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** Implicit-nullable signature guard for LogException::__construct. */
class LogExceptionCompatTest extends WpSlimstatTestCase
{
    public function test_signature_declares_nullable_previous(): void
    {
        $reflect = new \ReflectionMethod(\SlimStat\Exception\LogException::class, '__construct');
        $params  = $reflect->getParameters();
        $this->assertCount(3, $params);

        $previous = $params[2];
        $type = $previous->getType();
        $this->assertNotNull($type, '$previous must have an explicit type');
        $this->assertTrue($type->allowsNull(), '$previous must be nullable (?Exception)');
    }
}

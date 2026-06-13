<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Implicit-nullable signature guard for Session::setTrackingCookie, plus
 * a negative pin on $value remaining untyped (see test_value_param_stays...).
 */
class SessionCookieCompatTest extends WpSlimstatTestCase
{
    public function test_signature_declares_nullable_expires(): void
    {
        $reflect = new \ReflectionMethod(\SlimStat\Tracker\Session::class, 'setTrackingCookie');
        $params  = $reflect->getParameters();
        $this->assertCount(4, $params, 'setTrackingCookie must take 4 params');

        $expires = $params[2];
        $type = $expires->getType();
        $this->assertNotNull($type, '$expires must have an explicit type');
        $this->assertTrue($type->allowsNull(), '$expires must be nullable int');
        $this->assertSame('int', $type->getName(), '$expires must be typed int (?int)');
    }

    public function test_value_param_stays_untyped_for_caller_compat(): void
    {
        // Backstop: tightening $value to `string` would TypeError when
        // ConsentChangeRestController (strict_types=1) passes the int return
        // of Session::getVisitId(): int.
        $reflect = new \ReflectionMethod(\SlimStat\Tracker\Session::class, 'setTrackingCookie');
        $params  = $reflect->getParameters();
        $value = $params[0];
        $this->assertNull($value->getType(), '$value must stay untyped to preserve strict_types caller compat');
    }
}

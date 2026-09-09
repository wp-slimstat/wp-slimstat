<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Components;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** Implicit-nullable signature guard for DateRangeHelper::format_date_range. */
class DateRangeHelperCompatTest extends WpSlimstatTestCase
{
    public function test_signature_declares_nullable_preset(): void
    {
        $reflect = new \ReflectionMethod(\SlimStat\Components\DateRangeHelper::class, 'format_date_range');
        $params  = $reflect->getParameters();
        $this->assertCount(3, $params, 'format_date_range must take 3 params');

        $preset = $params[2];
        $type = $preset->getType();
        $this->assertNotNull($type, '$preset must have an explicit type');
        $this->assertTrue($type->allowsNull(), '$preset must be nullable string');
        $this->assertSame('string', $type->getName(), '$preset must be typed string (?string)');
    }

    public function test_filter_interval_is_not_shortened_by_server_dst(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $filters = \SlimStat\Components\DateRangeHelper::convert_to_slimstat_filters(
                strtotime('2026-03-29 12:00:00 UTC'),
                strtotime('2026-03-30 12:00:00 UTC')
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        $this->assertSame(-2, $filters['interval']);
        $this->assertSame('2026-03-30', $filters['strtotime']);
    }
}

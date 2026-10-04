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
    public function test_calendar_uses_wordpress_locale_names(): void
    {
        $old = $GLOBALS['wp_locale'] ?? null;
        $GLOBALS['wp_locale'] = (object) ['weekday_abbrev' => ['Sunday' => 'So', 'Monday' => 'Mo'], 'month' => ['01' => 'Januar', '02' => 'Februar'], 'month_abbrev' => ['Januar' => 'Jan', 'Februar' => 'Feb']];
        \Brain\Monkey\Functions\when('__')->returnArg();
        try {
            $strings = \SlimStat\Components\DateRangeHelper::get_localized_strings();
            $this->assertSame(['So', 'Mo'], $strings['weekdays']);
            $this->assertSame(['Januar', 'Februar'], $strings['months']);
            $this->assertSame(['Jan', 'Feb'], $strings['months_short']);
        } finally {
            $GLOBALS['wp_locale'] = $old;
        }
    }

    /** The picker shows dates the way Settings > General says, not a fixed DD/MM/YYYY. */
    public function test_date_format_follows_the_wordpress_setting(): void
    {
        $cases = [
            'F j, Y' => 'MMMM D, YYYY',
            'm/d/Y' => 'MM/DD/YYYY',
            'd/m/Y' => 'DD/MM/YYYY',
            'Y-m-d' => 'YYYY-MM-DD',
            'jS M y' => 'Do MMM YY',
            'j. F Y' => 'D. MMMM YYYY',
            'Y年n月j日' => 'YYYY年M月D日',
            'l, \d\e F' => 'dddd, [d][e] MMMM',
            'g:i a' => 'YYYY-MM-DD',
        ];
        // get_option() is a real stub (tests/Unit/Tracker/stubs.php) seeded through this store.
        try {
            foreach ($cases as $php => $moment) {
                $GLOBALS['slimstat_test_options']['date_format'] = $php;
                $this->assertSame($moment, \SlimStat\Components\DateRangeHelper::get_date_format(), $php);
            }
        } finally {
            unset($GLOBALS['slimstat_test_options']['date_format']);
        }
    }
}

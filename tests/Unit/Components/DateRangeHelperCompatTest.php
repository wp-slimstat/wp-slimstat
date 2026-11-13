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

    /** Pages without a range in the URL open on the reports' default, Last 28 days (Heatmaps once showed 30). */
    public function test_default_range_matches_the_report_default(): void
    {
        $range = \SlimStat\Components\DateRangeHelper::get_current_date_range();
        $this->assertSame('last_28_days', $range['preset']);
        $this->assertSame(\SlimStat\Components\DateRangeHelper::get_range_by_preset('last_28_days')['start'], $range['start']);
    }

    /** Reports → Heatmaps carries fs[…] date filters, never type or from/to: the picker shows the window the reports query. */
    public function test_range_follows_the_reports_date_filter(): void
    {
        if (!class_exists('wp_slimstat_db', false)) {
            require_once dirname(__DIR__, 3) . '/admin/view/wp-slimstat-db.php';
        }
        \Brain\Monkey\Functions\when('wp_date')->alias(static fn($format, $ts = null) => gmdate($format, $ts ?? time()));
        $six = \SlimStat\Components\DateRangeHelper::get_range_by_preset('last_6_months');
        try {
            \wp_slimstat_db::$filters_normalized['utime'] = ['start' => $six['start'], 'end' => $six['end']];
            $range = \SlimStat\Components\DateRangeHelper::get_current_date_range();
            $this->assertSame(['last_6_months', $six['start'], $six['end']], [$range['preset'], $range['start'], $range['end']]);

            \wp_slimstat_db::$filters_normalized['utime'] = ['start' => strtotime('2026-01-05'), 'end' => strtotime('2026-02-10 23:59:59')];
            $range = \SlimStat\Components\DateRangeHelper::get_current_date_range();
            $this->assertSame(['custom', strtotime('2026-01-05'), strtotime('2026-02-10 23:59:59')], [$range['preset'], $range['start'], $range['end']]);
        } finally {
            unset(\wp_slimstat_db::$filters_normalized['utime']);
        }
    }
}

<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use SlimStat\Services\BlackFriday2026;

/** Nov 23 00:00 to Dec 1 00:00 (exclusive) in whatever zone the site uses, named or a fixed offset. */
class BlackFriday2026WindowTest extends TestCase
{
    public static function zones(): array
    {
        return [['UTC'], ['America/Los_Angeles'], ['Asia/Tehran'], ['Pacific/Kiritimati'], ['Etc/GMT+12'], ['+05:45']];
    }

    /** @dataProvider zones */
    public function test_window_boundaries_hold_in_every_zone(string $zone): void
    {
        $tz = new \DateTimeZone($zone);
        $at = static fn(string $time) => BlackFriday2026::in_window(new \DateTimeImmutable($time, $tz));
        $this->assertFalse($at('2026-11-22 23:59:59'), "$zone: the evening before");
        $this->assertTrue($at('2026-11-23 00:00:00'), "$zone: first second");
        $this->assertTrue($at('2026-11-27 12:00:00'), "$zone: Black Friday itself");
        $this->assertTrue($at('2026-11-30 23:59:59'), "$zone: last second");
        $this->assertFalse($at('2026-12-01 00:00:00'), "$zone: December 1");
        $this->assertFalse($at('2027-11-25 12:00:00'), "$zone: next year");
    }

    public function test_the_window_follows_the_site_zone_not_utc(): void
    {
        // 2026-11-23 05:00 UTC is still Nov 22 in Los Angeles and already Nov 23 in Tehran.
        $instant = new \DateTimeImmutable('2026-11-23 05:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse(BlackFriday2026::in_window($instant->setTimezone(new \DateTimeZone('America/Los_Angeles'))));
        $this->assertTrue(BlackFriday2026::in_window($instant->setTimezone(new \DateTimeZone('Asia/Tehran'))));
    }
}

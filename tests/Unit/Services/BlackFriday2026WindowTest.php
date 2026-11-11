<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use SlimStat\Services\BlackFriday2026;

/**
 * Nov 23 00:00 to Dec 1 00:00 (exclusive) in whatever zone the site uses, named or a fixed offset,
 * and never before BFCM2026 opens at 2026-11-22 18:00 UTC, which is still Nov 22 up to UTC+6.
 */
class BlackFriday2026WindowTest extends TestCase
{
    /** @return array<array{0: string, 1: string}> zone, and its first second on Nov 23 */
    public static function zones(): array
    {
        return [
            ['UTC', '00:00:00'], ['America/Los_Angeles', '00:00:00'], ['Asia/Tehran', '00:00:00'], ['Etc/GMT+12', '00:00:00'], ['+05:45', '00:00:00'],
            ['Asia/Dhaka', '00:00:00'], ['Asia/Shanghai', '02:00:00'], ['Asia/Tokyo', '03:00:00'], ['Pacific/Auckland', '07:00:00'], ['Pacific/Kiritimati', '08:00:00'],
        ];
    }

    /** @dataProvider zones */
    public function test_window_boundaries_hold_in_every_zone(string $zone, string $first): void
    {
        $tz = new \DateTimeZone($zone);
        $at = static fn(string $time) => BlackFriday2026::in_window(new \DateTimeImmutable($time, $tz));
        $this->assertFalse($at('2026-11-22 23:59:59'), "$zone: the evening before");
        $this->assertFalse(BlackFriday2026::in_window((new \DateTimeImmutable("2026-11-23 $first", $tz))->modify('-1 second')), "$zone: the second before");
        $this->assertTrue($at("2026-11-23 $first"), "$zone: first second");
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

    public function test_every_shown_second_is_inside_the_coupon(): void
    {
        $opens = new \DateTimeImmutable('2026-11-22 18:00:00', new \DateTimeZone('UTC'));
        $closes = new \DateTimeImmutable('2026-12-01 12:00:00', new \DateTimeZone('UTC'));
        // Every offset WordPress offers, UTC-12 to UTC+14 in quarter hours, probed every 15 minutes across both edges.
        for ($minutes = -720; $minutes <= 840; $minutes += 15) {
            $tz = new \DateTimeZone(sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60));
            foreach ([$opens, $closes] as $edge) {
                for ($step = -1440; $step <= 1440; $step += 15) {
                    $now = $edge->modify("$step minutes")->setTimezone($tz);
                    if (BlackFriday2026::in_window($now)) {
                        $this->assertTrue($opens <= $now && $now < $closes, $tz->getName() . ' shows the strip at ' . $now->format(DATE_ATOM) . ', outside the coupon');
                    }
                }
            }
        }
    }
}

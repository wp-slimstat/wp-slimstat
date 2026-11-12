<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use SlimStat\Services\BlackFriday2026;

class BlackFriday2026WindowTest extends TestCase
{
    public function test_offer_boundaries_are_the_same_in_every_site_timezone(): void
    {
        $cases = [
            '2026-11-19T23:59:59Z' => false,
            '2026-11-20T00:00:00Z' => true,
            '2026-12-01T23:58:59Z' => true,
            '2026-12-01T23:59:00Z' => false,
            '2027-11-20T00:00:00Z' => false,
        ];
        // Every quarter-hour offset WordPress permits, including both date-line extremes.
        for ($offset = -720; $offset <= 840; $offset += 15) {
            $zone = new \DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60));
            foreach ($cases as $date => $expected) {
                $now = (new \DateTimeImmutable($date))->setTimezone($zone);
                $this->assertSame($expected, BlackFriday2026::in_window($now), $zone->getName() . ': ' . $date);
            }
        }
    }
}

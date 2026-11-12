<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use SlimStat\Services\Admin\FeedWindow;
use SlimStat\Decorators\NotificationDecorator;

class HorizonWindowTest extends TestCase
{
    public function test_dates_use_explicit_zones_and_exact_boundaries(): void
    {
        $window = ['starts_at' => '2026-11-20T00:00:00Z', 'ends_at' => '2026-12-01T23:59:00Z'];
        $this->assertFalse(FeedWindow::contains($window, strtotime('2026-11-19T23:59:59Z')));
        $this->assertTrue(FeedWindow::contains($window, strtotime('2026-11-20T00:00:00Z')));
        $this->assertTrue(FeedWindow::contains($window, strtotime('2026-12-01T23:58:59Z')));
        $this->assertFalse(FeedWindow::contains($window, strtotime('2026-12-01T23:59:00Z')));
        $this->assertSame(FeedWindow::timestamp('2026-11-20T00:00:00Z'), FeedWindow::timestamp('2026-11-20T03:30:00+03:30'));
        $this->assertSame(FeedWindow::timestamp('2026-11-20T00:00:00Z'), FeedWindow::timestamp('2026-11-20T00:00:00.000Z'));
    }

    public function test_legacy_fields_do_not_control_delivery_and_bad_dates_fail_closed(): void
    {
        $this->assertTrue(FeedWindow::contains(['activated_at' => '2099-01-01', 'expires_at' => '2020-01-01']));
        $this->assertTrue(FeedWindow::contains(['starts_at' => null, 'ends_at' => '']));
        foreach (['tomorrow', '2026-11-20T00:00:00', '2026-02-30T00:00:00Z', [], new \stdClass(), 1, false] as $invalid) {
            $this->assertFalse(FeedWindow::contains(['starts_at' => $invalid]));
            $this->assertFalse(FeedWindow::contains(['ends_at' => $invalid]));
        }
        $this->assertFalse(FeedWindow::contains(['starts_at' => '2099-01-01T00:00:00Z', 'ends_at' => '2020-01-01T00:00:00Z']));
    }

    public function test_new_optional_fields_are_safe_with_old_and_malformed_payloads(): void
    {
        foreach ([null, 'oops', 1, [], ['badge' => [], 'code' => new \stdClass()]] as $extra) {
            $notice = new NotificationDecorator((object) ['extra_data' => $extra, 'ends_at' => []]);
            $this->assertSame('', $notice->getBadge());
            $this->assertSame('', $notice->getCode());
            $this->assertSame('', $notice->getEndsAt());
            $this->assertFalse($notice->isPromo());
        }
        foreach ([['badge' => '🔥 -30%', 'code' => 'BFCM2026'], (object) ['badge' => '🔥 -30%', 'code' => 'BFCM2026']] as $extra) {
            $notice = new NotificationDecorator((object) ['background_color' => 'promo', 'extra_data' => $extra, 'ends_at' => '2026-12-02T03:29:00+03:30']);
            $this->assertSame('🔥 -30%', $notice->getBadge());
            $this->assertSame('BFCM2026', $notice->getCode());
            $this->assertSame('2026-12-01T23:59:00Z', $notice->getEndsAt());
            $this->assertSame('slimstat-notification-sidebar__promo', $notice->backgroundColor());
            $this->assertTrue($notice->isPromo());
        }
    }
}

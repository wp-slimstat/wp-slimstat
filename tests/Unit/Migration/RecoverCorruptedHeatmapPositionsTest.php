<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use SlimStat\Migration\Migrations\RecoverCorruptedHeatmapPositions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class RecoverCorruptedHeatmapPositionsTest extends WpSlimstatTestCase
{
    private RecoverCorruptedHeatmapPositions $migration;
    private \ReflectionMethod $recoverPosition;

    protected function setUp(): void
    {
        parent::setUp();

        $wpdb = \Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';

        $this->migration = new RecoverCorruptedHeatmapPositions($wpdb);

        $this->recoverPosition = new \ReflectionMethod(RecoverCorruptedHeatmapPositions::class, 'recoverPosition');
    }

    private function recover(string $position, int $screenWidth): ?string
    {
        return $this->recoverPosition->invoke($this->migration, $position, $screenWidth);
    }

    // ── Unambiguous recovery (exactly 1 valid split) ─────────────

    public function test_standard_desktop_position_recovered(): void
    {
        // "320480" + SW=1920 → only "320,480" valid (Y=20480 exceeds SW*10=19200)
        $this->assertSame('320,480', $this->recover('320480', 1920));
    }

    public function test_mobile_position_recovered(): void
    {
        // "320480" + SW=400 → only "320,480" valid (Y=20480 exceeds SW*10=4000)
        $this->assertSame('320,480', $this->recover('320480', 400));
    }

    public function test_clean_split_with_leading_zero_filter(): void
    {
        // "100200" → "100,200" is the only split without leading zeros
        $this->assertSame('100,200', $this->recover('100200', 1920));
    }

    public function test_origin_position_recovered(): void
    {
        // "00" → "0,0" is the only valid split
        $this->assertSame('0,0', $this->recover('00', 1920));
    }

    public function test_mobile_e2e_position_recovered(): void
    {
        // "50100" + SW=320 → only "50,100" valid (leading-zero filter removes "5","0100")
        $this->assertSame('50,100', $this->recover('50100', 320));
    }

    public function test_eight_digit_position_recovered(): void
    {
        // "12345678" + SW=1920 → "1234,5678" (Y=45678 exceeds SW*10, Y=5678 does not)
        $this->assertSame('1234,5678', $this->recover('12345678', 1920));
    }

    // ── Ambiguous (multiple valid splits → null) ─────────────────

    public function test_short_string_ambiguous(): void
    {
        // "1234" + SW=1920 → 3 candidates: "1,234", "12,34", "123,4"
        $this->assertNull($this->recover('1234', 1920));
    }

    public function test_e2e_position_ambiguous(): void
    {
        // "42135" + SW=1024 → 3 candidates: "4,2135", "42,135", "421,35"
        $this->assertNull($this->recover('42135', 1024));
    }

    public function test_max_screen_width_ambiguous(): void
    {
        // "999991" + SW=99999 → 5 candidates
        $this->assertNull($this->recover('999991', 99999));
    }

    // ── Rejected input (null) ────────────────────────────────────

    public function test_single_digit_rejected(): void
    {
        // Too short — strlen < 2
        $this->assertNull($this->recover('5', 1920));
    }

    public function test_empty_string_rejected(): void
    {
        $this->assertNull($this->recover('', 1920));
    }

    public function test_non_digit_string_rejected(): void
    {
        $this->assertNull($this->recover('abc', 1920));
    }

    public function test_zero_screen_width_rejected(): void
    {
        $this->assertNull($this->recover('320480', 0));
    }

    public function test_negative_screen_width_rejected(): void
    {
        $this->assertNull($this->recover('320480', -1));
    }

    public function test_leading_zero_in_position(): void
    {
        // "01" → "0,1" is valid (x="0" is allowed as special case)
        $this->assertSame('0,1', $this->recover('01', 1920));
    }

    // ── Y constraint boundary ────────────────────────────────────

    public function test_wide_screen_does_not_recover_when_y_passes_constraint(): void
    {
        // "320480" + SW=2560 → Y=20480 ≤ SW*10=25600, so "3,20480" passes → 2 candidates → null
        $this->assertNull($this->recover('320480', 2560));
    }

    public function test_y_at_exact_boundary_passes(): void
    {
        // "319200" + SW=1920 → split "3,19200": Y=19200 = SW*10=19200 → passes
        // split "31,9200": X=31 ≤ 1920 → passes, Y=9200 ≤ 19200 → passes
        // split "319,200": X=319 ≤ 1920 → passes
        // split "3192,00": leading zero on Y → rejected
        // 3 candidates → null (ambiguous)
        $this->assertNull($this->recover('319200', 1920));
    }
}

<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the funnel "step unreachable" copy fix (#9).
 *
 * A funnel step that legitimately has zero conversions in the selected window
 * is a valid outcome, not an error. The old copy ("…this step's event hasn't
 * fired yet", with a ⚠ glyph) framed it as a problem. The fix uses a neutral
 * message in BOTH render paths — the SSR partial (funnel-bars.php) and the
 * AJAX-rendered sibling tabs (goals-funnels.js) — which must stay identical or
 * the first funnel tab and lazily-loaded tabs would diverge.
 */
class GoalsFunnelsUnreachableCopyTest extends TestCase
{
    private const NEUTRAL = 'No visitors reached this step in the selected date range';

    private string $bars;
    private string $js;

    protected function setUp(): void
    {
        parent::setUp();
        $root       = dirname(__DIR__, 2);
        $this->bars = (string) file_get_contents($root . '/admin/view/partials/goals-funnels/funnel-bars.php');
        $this->js   = (string) file_get_contents($root . '/admin/assets/js/goals-funnels.js');
        if ($this->bars === '' || $this->js === '') {
            $this->fail('Could not read funnel-bars.php / goals-funnels.js');
        }
    }

    public function test_ssr_uses_the_neutral_message(): void
    {
        $this->assertStringContainsString(self::NEUTRAL, $this->bars);
    }

    public function test_ajax_render_uses_the_same_neutral_message(): void
    {
        // Identical string in both paths so SSR'd and lazily-loaded tabs agree.
        $this->assertStringContainsString(self::NEUTRAL, $this->js);
    }

    public function test_old_alarming_copy_is_gone_from_both_paths(): void
    {
        foreach (['hasn\'t fired yet', 'No data in this range'] as $stale) {
            $this->assertStringNotContainsString($stale, $this->bars, "Stale copy '{$stale}' must be removed from SSR");
            $this->assertStringNotContainsString($stale, $this->js, "Stale copy '{$stale}' must be removed from AJAX render");
        }
    }

    public function test_warning_glyph_removed_from_unreachable_message(): void
    {
        // The ⚠ glyph was the error signal #9 objects to. The per-step
        // unreachable block must no longer carry it. (The summary success line
        // keeps its own ✓; we only assert the ⚠ warning glyph is gone.)
        $this->assertStringNotContainsString('⚠', $this->bars, '⚠ must be removed from the SSR unreachable message');
        $this->assertStringNotContainsString('⚠', $this->js, '⚠ must be removed from the AJAX unreachable message');
    }
}

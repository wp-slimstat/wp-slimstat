<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Batch E funnel data-viz fixes (impeccable review FN-1 / FN-2):
 *
 *   FN-1 — funnel bars encode magnitude with ONE neutral fill; status (zero /
 *          unreachable) is a muted variant + ⚠ text, never the brand-red ramp.
 *   FN-2 — when a step has no data yet the summary says "Conversion rate pending"
 *          instead of a misleading "0.0% conversion rate".
 *
 * Structure/source guards (deterministic, no DB, no live site) — the runtime
 * branch behaviour is exercised separately; these lock the implementation in so
 * a future edit can't silently reintroduce the alarm-red ramp or the misleading
 * 0.0% line. Mirrors the file-content approach of GoalsFunnelsTokensTest.
 */
class GoalsFunnelsVizTest extends TestCase
{
    // ── FN-1: CSS uses a single neutral fill, not the per-step brand ramp ──

    public function test_funnel_tokens_declared(): void
    {
        $tokens = file_get_contents($this->tokensPath());
        $this->assertStringContainsString('--ss-funnel-bar:', $tokens, 'Neutral magnitude token missing');
        $this->assertStringContainsString('--ss-funnel-bar-zero:', $tokens, 'Muted zero/unreachable token missing');
    }

    public function test_step_fill_uses_neutral_token_not_brand_ramp(): void
    {
        $css = file_get_contents($this->cssPath());

        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-step__fill\s*\{[^}]*background:\s*var\(--ss-funnel-bar\)/s',
            $css,
            'Step fill must use the neutral --ss-funnel-bar magnitude token'
        );

        // The per-step brand ramp must be gone — a healthy 100% step must not
        // render as alarm-red (real bars and the locked mock both).
        $this->assertDoesNotMatchRegularExpression(
            '/\.slimstat-gf-step__fill\[data-step="\d"\]/',
            $css,
            'Per-step brand ramp on funnel bars must be removed (FN-1)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.slimstat-gf-funnel-bar\[data-step="\d"\]/',
            $css,
            'Per-step brand ramp on mock bars must be removed (FN-1)'
        );
    }

    public function test_zero_and_unreachable_fill_use_muted_token(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\[data-zero\][^{]*\{[^}]*var\(--ss-funnel-bar-zero\)/s',
            $css,
            'Zero/unreachable bars must use the muted --ss-funnel-bar-zero token'
        );
    }

    public function test_tiny_bars_keep_a_visible_min_width(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-step__fill\s*\{[^}]*min-width:\s*6px/s',
            $css,
            'Step fill min-width must be raised so a ~2% bar stays perceptible'
        );
    }

    // ── FN-2: "Conversion rate pending" replaces a misleading 0.0% ──

    public function test_summary_partial_has_pending_branch(): void
    {
        $php = file_get_contents($this->partialPath('funnel-summary.php'));
        $this->assertStringContainsString('Conversion rate pending', $php, 'FN-2 pending copy missing');
        $this->assertStringContainsString('has no data yet', $php, 'Warn chip must use plain-language "no data yet"');
        $this->assertStringNotContainsString('step unreachable', $php, 'Old "step unreachable" jargon must be gone');
    }

    public function test_summary_js_mirror_has_pending_branch(): void
    {
        $js = file_get_contents($this->jsPath());
        $this->assertStringContainsString('Conversion rate pending', $js, 'JS mirror missing FN-2 pending copy');
        $this->assertStringContainsString('has no data yet', $js, 'JS mirror warn chip copy out of sync');
    }

    // ── FN-1: funnel bars expose magnitude vs status without color-only ──

    public function test_bars_partial_flags_zero_and_exposes_valuetext(): void
    {
        $php = file_get_contents($this->partialPath('funnel-bars.php'));
        $this->assertStringContainsString('data-zero', $php, 'Empty bars must be flagged for the muted fill (not red)');
        $this->assertStringContainsString('aria-valuetext', $php, 'Exact percentage must be exposed to AT via aria-valuetext');
        $this->assertStringContainsString('fired yet', $php, 'Unreachable copy must be plain-language');
        $this->assertStringNotContainsString('Step unreachable · event not seen in range', $php, 'Old unreachable jargon must be gone');
    }

    public function test_bars_js_mirror_in_sync(): void
    {
        $js = file_get_contents($this->jsPath());
        $this->assertStringContainsString('data-zero', $js, 'JS bar mirror missing data-zero');
        $this->assertStringContainsString('aria-valuetext', $js, 'JS bar mirror missing aria-valuetext');
    }

    // ── paths ──

    private function partialPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/' . $file;
    }

    private function cssPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/goals-funnels.css';
    }

    private function jsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/js/goals-funnels.js';
    }

    private function tokensPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/tokens.css';
    }
}

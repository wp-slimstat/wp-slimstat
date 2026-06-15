<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Batch H upsell-honesty & metric-trust fixes (impeccable FN-5 / FN-9
 * / FN-11). Deterministic structure/source guards (cf. GoalsFunnelsTokensTest).
 */
class GoalsFunnelsUpsellTest extends TestCase
{
    // ── FN-5: no banned colored side-stripe borders ──

    public function test_no_side_stripe_borders(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertStringNotContainsString('border-inline-start', $css, 'Side-stripe borders are banned (FN-5)');
    }

    public function test_error_block_uses_danger_surface(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertStringContainsString('--ss-danger-bg-soft', file_get_contents($this->tokensPath()), 'Soft danger tint token missing');
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-drawer__error,\s*\.slimstat-gf-builder__error\s*\{[^}]*background:\s*var\(--ss-danger-bg-soft\)/s',
            $css,
            'Error block must use a danger-tinted surface, not warning-yellow (FN-5)'
        );
    }

    public function test_upsell_uses_full_border(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-upsell\s*\{[^}]*border:\s*1px solid/s',
            $css,
            'Upsell must use a full 1px border, not a side-stripe (FN-5)'
        );
    }

    // ── FN-9: goal CR is localized + defined ──

    public function test_goal_cr_is_localized_with_tooltip(): void
    {
        $php = file_get_contents($this->partialPath('goals-card.php'));
        $this->assertMatchesRegularExpression(
            '/slimstat-gf-metric__value">.*number_format_i18n\(\(float\) \$cr/',
            $php,
            'Goal CR must render through number_format_i18n (FN-9)'
        );
        $this->assertStringNotContainsString('echo esc_html((string) $cr)', $php, 'Raw float CR cast must be gone (FN-9)');
        $this->assertMatchesRegularExpression(
            '/slimstat-gf-metric__label" title="[^"]*[Cc]onversion rate/',
            $php,
            'CR label must carry a defining tooltip (FN-9)'
        );
    }

    // ── FN-11: terminology + de-duplication ──

    public function test_funnels_subtitle_says_steps_not_goals(): void
    {
        $php = file_get_contents($this->indexPath());
        $this->assertMatchesRegularExpression('/String 2 to 5 steps into a journey/', $php, 'Funnels subtitle must say "steps" (FN-11)');
        $this->assertStringNotContainsString('String 2–5 goals', $php, 'Old "goals" + en-dash subtitle must be gone (FN-11)');
    }

    public function test_upsell_headline_drops_redundant_count(): void
    {
        $php = file_get_contents($this->partialPath('goals-card.php'));
        // The pill ("1 of 1 used") owns the count; the upsell headline must not restate it.
        $this->assertStringNotContainsString('1 of 1 goals used', $php, 'Upsell headline must not restate the pill count (FN-11)');
        // And no em dash.
        $start = strpos($php, 'slimstat-gf-upsell');
        $this->assertNotFalse($start);
        $this->assertStringNotContainsString('—', substr($php, $start, 400), 'Upsell copy must not use em dashes');
    }

    // ── paths ──

    private function cssPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/goals-funnels.css';
    }

    private function tokensPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/tokens.css';
    }

    private function indexPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/index.php';
    }

    private function partialPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/' . $file;
    }
}

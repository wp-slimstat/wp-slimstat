<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Batch G responsive + a11y sweep (impeccable FN-4 / FN-6 / FN-8 /
 * FN-12). Deterministic structure/source guards, matching GoalsFunnelsTokensTest.
 */
class GoalsFunnelsA11yResponsiveTest extends TestCase
{
    // ── FN-6: WP-native focus token, not brand magenta ──

    public function test_focus_token_declared(): void
    {
        $this->assertStringContainsString('--ss-focus', file_get_contents($this->tokensPath()));
    }

    public function test_form_focus_uses_focus_token_not_brand(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-field input\[type="text"\]:focus[\s\S]{0,200}outline:\s*2px solid var\(--ss-focus\)/',
            $css,
            'Form field focus ring must use --ss-focus (FN-6)'
        );
        // The field focus block must no longer use the brand magenta.
        $this->assertDoesNotMatchRegularExpression(
            '/select:focus \{[\s\S]{0,160}var\(--ss-brand-500\)/',
            $css,
            'Field focus must not use brand-500 (reads as an error)'
        );
    }

    // ── FN-4: responsive breakpoint (only viewport media query in the file) ──

    public function test_responsive_breakpoint_stacks_grids(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertSame(1, preg_match('/@media \(max-width:\s*782px\)/', $css, $m, PREG_OFFSET_CAPTURE), 'Missing 782px breakpoint (FN-4)');
        // Within the breakpoint the goal row and step row collapse to one column.
        // The overrides MUST be container-scoped (.slimstat-gf-goals / .slimstat-gf-builder)
        // so they beat the base rules by specificity (0,2,0 > 0,1,0) rather than by
        // source order — otherwise a CSS reorder silently re-breaks the reflow
        // (the cascade bug this guard exists to catch).
        $block = substr($css, $m[0][1], 700);
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-goals\s+\.slimstat-gf-goal\s*\{[^}]*grid-template-columns:\s*1fr/',
            $block,
            'Goal-row reflow must be container-scoped to win by specificity, not source order (FN-4)'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-builder\s+\.slimstat-gf-step-row\s*\{[^}]*grid-template-columns:\s*1fr/',
            $block,
            'Step-row reflow must be container-scoped to win by specificity, not source order (FN-4)'
        );
    }

    // ── FN-8: comfortable touch targets + aria-hidden glyphs ──

    public function test_action_buttons_have_min_height(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-goal-edit[\s\S]{0,300}min-height:\s*32px/',
            $css,
            'Edit/Delete buttons need a min-height target (FN-8)'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-drawer__close[\s\S]{0,500}min-height:\s*44px/',
            $css,
            'Modal close button needs a 44px target (FN-8)'
        );
    }

    public function test_glyph_buttons_are_aria_hidden(): void
    {
        $drawer  = file_get_contents($this->partialPath('goal-drawer.php'));
        $builder = file_get_contents($this->partialPath('funnel-builder.php'));
        $this->assertStringContainsString('<span aria-hidden="true">×</span>', $drawer, 'Drawer close glyph must be aria-hidden (FN-8)');
        $this->assertStringContainsString('<span aria-hidden="true">⋮⋮</span>', $builder, 'Drag handle glyph must be aria-hidden (FN-8)');
        $this->assertStringContainsString('<span aria-hidden="true">×</span>', $builder, 'Remove/close glyphs must be aria-hidden (FN-8)');
    }

    // ── FN-12: required affordance + scoped live region ──

    public function test_required_fields_have_marker_and_aria(): void
    {
        foreach (['goal-drawer.php', 'funnel-builder.php'] as $file) {
            $php = file_get_contents($this->partialPath($file));
            $this->assertStringContainsString('aria-required="true"', $php, "$file required input needs aria-required (FN-12)");
            $this->assertStringContainsString('slimstat-gf-required', $php, "$file needs a visible required marker (FN-12)");
        }
        $this->assertStringContainsString('.slimstat-gf-required', file_get_contents($this->cssPath()), 'Required marker needs a style');
    }

    public function test_steps_container_not_aria_live_and_scoped_region_exists(): void
    {
        $builder = file_get_contents($this->partialPath('funnel-builder.php'));
        $this->assertDoesNotMatchRegularExpression(
            '/data-role="steps-container"[^>]*aria-live/',
            $builder,
            'Steps container must not be a container-level live region (FN-12)'
        );
        $this->assertStringContainsString('data-role="builder-live"', $builder, 'Scoped builder live region missing (FN-12)');
        $this->assertStringContainsString('announceBuilder(', file_get_contents($this->jsPath()), 'JS must announce step changes via the scoped region (FN-12)');
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

    private function jsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/js/goals-funnels.js';
    }

    private function partialPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/' . $file;
    }
}

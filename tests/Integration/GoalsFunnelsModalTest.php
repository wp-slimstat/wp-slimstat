<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the goal-drawer / funnel-builder modal layout fixes:
 *   - Cancel/Save footer is a sticky bar (was pushed below the fold via
 *     margin-top:auto, so Save wasn't visible).
 *   - Panel top clears the fixed WP admin bar (title was jammed/clipped).
 *   - Native selects get a consistent chevron and the builder has room for its
 *     step grid (inputs were cramped / "Order received" truncated).
 *
 * Deterministic structure/source guards (cf. GoalsFunnelsTokensTest).
 */
class GoalsFunnelsModalTest extends TestCase
{
    public function test_modal_footer_is_sticky(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-drawer__foot,\s*\.slimstat-gf-builder__foot\s*\{[^}]*position:\s*sticky/s',
            $css,
            'Modal footer must be sticky so Save/Cancel stay visible'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-drawer__foot,\s*\.slimstat-gf-builder__foot\s*\{[^}]*bottom:\s*0/s',
            $css,
            'Sticky footer must pin to the bottom'
        );
    }

    public function test_panels_clear_the_admin_bar(): void
    {
        $css = file_get_contents($this->cssPath());
        foreach (['slimstat-gf-drawer__panel', 'slimstat-gf-builder__panel'] as $panel) {
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($panel, '/') . '\s*\{[^}]*padding:[^;]*var\(--wp-admin--admin-bar--height/s',
                $css,
                "$panel top padding must clear the WP admin bar so the title isn't clipped"
            );
        }
    }

    public function test_selects_have_a_chevron(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-field select,\s*\.slimstat-gf-step-row select\s*\{[^}]*appearance:\s*none[^}]*background-image:/s',
            $css,
            'Native selects must get a consistent chevron affordance'
        );
    }

    public function test_step_row_inputs_fill_their_grid_column(): void
    {
        $css = file_get_contents($this->cssPath());
        // Step-row controls drop the 420px cap so they fill the grid column.
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-step-row input\[type="text"\],\s*\.slimstat-gf-step-row select\s*\{[^}]*max-width:\s*none/s',
            $css,
            'Step-row controls must fill their grid column (no 420px cap)'
        );
        // The builder panel is wide enough for the step grid.
        $this->assertMatchesRegularExpression('/--ss-builder-width:\s*880px/', file_get_contents($this->tokensPath()), 'Builder panel must be widened for the step grid');
    }

    private function cssPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/goals-funnels.css';
    }

    private function tokensPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/tokens.css';
    }
}

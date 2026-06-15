<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the goals/funnels UI polish pass (impeccable): conversion-rate emphasis,
 * the "See templates" reveal styling + top placement, the Value required-asterisk,
 * and the funnel-step Test reporting total matches. Source-shape guards (no DB);
 * rendered behaviour is covered by tests/e2e/goals-funnels.spec.ts.
 */
class GoalsFunnelsUiPolishTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function css(): string
    {
        return (string) file_get_contents($this->root() . '/admin/assets/css/goals-funnels.css');
    }

    private function js(): string
    {
        return (string) file_get_contents($this->root() . '/admin/assets/js/goals-funnels.js');
    }

    private function partial(string $f): string
    {
        return (string) file_get_contents($this->root() . '/admin/view/partials/goals-funnels/' . $f);
    }

    public function test_goal_cr_is_emphasized(): void
    {
        $this->assertStringContainsString(
            'slimstat-gf-metric--cr',
            $this->partial('goals-card.php'),
            'The CR metric must carry the --cr modifier'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-metric--cr\s+\.slimstat-gf-metric__value\s*\{[^}]*font-weight:\s*700[^}]*var\(--ss-brand-700\)/s',
            $this->css(),
            'CR value must be bolded and brand-colored'
        );
    }

    public function test_see_templates_reveal_is_styled(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-templates-reveal\s+\.slimstat-gf-see-templates\s*\{[^}]*border:\s*1px solid/s',
            $css,
            'See-templates toggle must be a bordered secondary control (wins over .wp-core-ui .button-link)'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-templates-reveal__panel\s*\{[^}]*background:\s*var\(--ss-surface-tint\)/s',
            $css,
            'Reveal panel must be a tinted, contained region'
        );
        $this->assertStringContainsString(
            'justify-content: flex-end',
            $css,
            'Toggle bar must right-align the See-templates button (beside the header CTA)'
        );
    }

    public function test_see_templates_reveal_sits_above_the_funnel_panels(): void
    {
        // Placement: the reveal renders before the funnel panels (top of the card,
        // beside "+ Add Funnel"), not buried at the bottom.
        $php       = $this->partial('funnels-card.php');
        $revealPos = strpos($php, 'data-role="funnels-templates"');
        $panelPos  = strpos($php, 'slimstat-gf-funnel-panel');
        $this->assertNotFalse($revealPos, 'reveal markup present');
        $this->assertNotFalse($panelPos, 'funnel panels present');
        $this->assertLessThan($panelPos, $revealPos, 'See-templates reveal must render before the funnel panels');
    }

    public function test_value_field_has_toggleable_required_marker(): void
    {
        $this->assertStringContainsString(
            'data-role="value-required"',
            $this->partial('goal-drawer.php'),
            'Value label must carry a required marker'
        );
        $this->assertStringContainsString(
            "find('[data-role=\"value-required\"]')",
            $this->js(),
            'syncValueDisabledByOperator must toggle the required marker with the operator'
        );
    }

    public function test_funnel_step_test_reports_total_matches(): void
    {
        $js = $this->js();
        $this->assertStringContainsString(
            'response.data.total',
            $js,
            'Funnel-step Test must report TOTAL matches'
        );
        $this->assertStringNotContainsString(
            'Number(response.data.visitors)',
            $js,
            'Funnel-step Test must no longer report the deduplicated visitor count'
        );
    }
}

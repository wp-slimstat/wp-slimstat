<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the goals/funnels UI polish pass (impeccable): conversion-rate emphasis,
 * the "See templates" reveal styling + top placement, the Value required-asterisk,
 * and the funnel-step Test reporting unique visitors + metric-unit tooltips (#1, #3).
 * Source-shape guards (no DB);
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

    public function test_goal_cr_is_emphasized_by_weight_not_red(): void
    {
        $this->assertStringContainsString(
            'slimstat-gf-metric--cr',
            $this->partial('goals-card.php'),
            'The CR metric must carry the --cr modifier'
        );
        // CR keeps the heaviest weight so it still reads first among the metrics…
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-metric--cr\s+\.slimstat-gf-metric__value\s*\{[^}]*font-weight:\s*700[^}]*\}/s',
            $this->css(),
            'CR value must stay bold'
        );
        // …but NOT in brand red — it read as an error/warning on a low rate. (#13)
        $this->assertDoesNotMatchRegularExpression(
            '/\.slimstat-gf-metric--cr\s+\.slimstat-gf-metric__value\s*\{[^}]*var\(--ss-brand-700\)/s',
            $this->css(),
            'CR value must no longer use brand red'
        );
    }

    public function test_goal_card_shows_cr_denominator(): void
    {
        $card = $this->partial('goals-card.php');
        // The card surfaces the denominator so "0.1%" is legible. (#13)
        $this->assertStringContainsString(
            'total_visitors',
            $card,
            'goals-card must read the CR denominator'
        );
        $this->assertStringContainsString(
            'of %2$s uniques',
            $card,
            'goals-card must render the "N of M uniques" denominator line'
        );
        $this->assertStringContainsString(
            'slimstat-gf-metric__sub',
            $card,
            'denominator must use the metric sub-line element'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-metric__sub\s*\{/s',
            $this->css(),
            'metric sub-line must be styled'
        );
    }

    public function test_see_templates_toggle_lives_in_the_header_beside_the_cta(): void
    {
        $reports = (string) file_get_contents($this->root() . '/admin/view/wp-slimstat-reports.php');
        // The toggle is built into the funnels postbox-header actions, beside the
        // "+ Add funnel" CTA (render_funnels_card_actions' secondary slot), and
        // controls the in-card panel by id.
        $this->assertMatchesRegularExpression(
            '/render_funnels_card_actions.*?slimstat-gf-see-templates.*?data-action="toggle-funnel-templates".*?aria-controls="slimstat-gf-templates-panel"/s',
            $reports,
            'See-templates toggle must render in the funnels header actions, controlling the panel by id'
        );
        // The JS resolves the panel by aria-controls id (button is now outside the
        // card subtree, so the old .closest() ancestor lookup would miss it).
        $this->assertStringContainsString(
            "var panelId = \$btn.attr('aria-controls')",
            $this->js(),
            'Toggle must resolve its panel by aria-controls id, not a DOM-ancestor lookup'
        );
    }

    public function test_see_templates_panel_is_styled_and_wired(): void
    {
        $css = $this->css();
        // Header toggle: neutral secondary; brand accent reserved for hover/focus
        // so it never competes with the primary red CTA.
        $this->assertMatchesRegularExpression(
            '/#slim_p9_02 \.slimstat-gf-see-templates:hover[^{]*:focus-visible\s*\{[^}]*var\(--ss-brand-500\)/s',
            $css,
            'See-templates toggle must reveal the brand accent only on hover/focus'
        );
        // The revealed panel stays a tinted, contained region in the card body.
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-templates-reveal__panel\s*\{[^}]*background:\s*var\(--ss-surface-tint\)/s',
            $css,
            'Reveal panel must be a tinted, contained region'
        );
        $this->assertStringContainsString(
            'id="slimstat-gf-templates-panel"',
            $this->partial('funnels-card.php'),
            'Panel must carry the aria-controls target id'
        );
        // The obsolete in-card right-aligned toggle bar must be gone.
        $this->assertStringNotContainsString(
            'slimstat-gf-templates-reveal__bar',
            $css,
            'Obsolete in-card toggle bar must be removed'
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

    public function test_funnel_step_test_reports_unique_visitors(): void
    {
        // The Test must preview UNIQUE VISITORS — the same unit the funnel step
        // counts — so "Test: 995" matches the funnel instead of showing raw
        // pageviews (5,556). The server returns both; the UI reads `visitors`. (#1, #3)
        $js = $this->js();
        $this->assertStringContainsString(
            'Number(response.data.visitors)',
            $js,
            'Funnel-step Test must display the unique-visitor count'
        );
        $this->assertStringNotContainsString(
            'Number(response.data.total)',
            $js,
            'Funnel-step Test must no longer display raw pageviews'
        );
        $this->assertMatchesRegularExpression(
            "/_n\(\s*'%s unique visitor',\s*'%s unique visitors'/",
            $js,
            'Test result copy must say "unique visitor(s)"'
        );
    }

    public function test_goal_metric_units_are_labeled(): void
    {
        // The pageviews-vs-unique-visitors distinction is explained inline so a Total
        // that climbs faster than Uniques reads as expected, not a bug. (#3)
        $card = $this->partial('goals-card.php');
        $this->assertStringContainsString('Unique visitors who matched this goal', $card, 'Uniques label needs a clarifying tooltip');
        $this->assertStringContainsString('Matching pageviews.', $card, 'Total label needs a clarifying tooltip');
        $this->assertStringContainsString('Unique visitors who reached this step', $this->partial('funnel-bars.php'), 'Funnel step count needs a unique-visitors tooltip');
    }
}

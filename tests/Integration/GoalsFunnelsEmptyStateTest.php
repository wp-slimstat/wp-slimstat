<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Batch F empty-state & CTA-hygiene fixes (impeccable FN-3 / FN-10 /
 * FN-17 + copy). Deterministic structure/source guards (no DB, no live site),
 * matching GoalsFunnelsTokensTest's approach. Rendered behaviour is covered by
 * tests/e2e/goals-funnels.spec.ts.
 */
class GoalsFunnelsEmptyStateTest extends TestCase
{
    // ── FN-3: the empty state owns the single primary CTA ──

    public function test_goals_header_cta_gated_on_existing_goals(): void
    {
        $php = file_get_contents($this->reportsPath());
        // Load-bearing fact: the goals header CTA depends on there being a goal,
        // so the header and the centered empty-state CTA never both render (FN-3).
        $this->assertMatchesRegularExpression(
            "/'show_add_cta'[\s\S]{0,80}!empty\(\\\$goals\)/",
            $php,
            'Goals header CTA must be gated on !empty($goals) (FN-3)'
        );
    }

    public function test_funnels_header_cta_gated_on_existing_funnels(): void
    {
        $php = file_get_contents($this->reportsPath());
        $this->assertMatchesRegularExpression(
            "/'show_add_cta'[\s\S]{0,80}\\\$funnel_count\s*>\s*0/",
            $php,
            'Funnels header CTA must be gated on $funnel_count > 0 (FN-3)'
        );
    }

    // ── FN-17 / FN-7: "Export" link gated by a report-level `exportable` flag ──

    public function test_reports_declare_exportable_presence_probe(): void
    {
        $php = file_get_contents($this->reportsPath());
        // Both Goals & Funnels reports declare an `exportable` probe so the Free
        // export link is suppressed when there's nothing to export.
        $this->assertMatchesRegularExpression(
            "/'exportable'\s*=>[\s\S]{0,80}get_goals_card_state\(\)\['goals'\]/",
            $php,
            'Goals report must declare an exportable probe on its goals (FN-17)'
        );
        $this->assertMatchesRegularExpression(
            "/'exportable'\s*=>[\s\S]{0,80}get_funnels_card_state\(\)\['funnels'\]/",
            $php,
            'Funnels report must declare an exportable probe on its funnels (FN-7)'
        );
    }

    public function test_export_button_honors_exportable_flag(): void
    {
        $php = file_get_contents($this->indexPath());
        // The export-button renderer honors the generic `exportable` flag rather
        // than hardcoding report ids.
        $this->assertStringContainsString("array_key_exists('exportable', \$callback_args)", $php, 'Export button must honor the exportable flag');
        $this->assertStringContainsString('call_user_func($callback_args[\'exportable\'])', $php, 'Export button must invoke a callable exportable probe');
    }

    // ── FN-10: the admin.css postbox h3/p bleed is neutralised on modern cards ──

    public function test_legacy_postbox_bleed_is_reset(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/#slim_p9_01 \.slimstat-gf-card (h3|p)[\s\S]{0,160}border-bottom:\s*0/',
            $css,
            'Modern cards must reset the postbox border-bottom bleed (FN-10)'
        );
        $this->assertMatchesRegularExpression(
            '/#slim_p9_01 \.slimstat-gf-empty__title[\s\S]{0,80}margin:/',
            $css,
            'Empty-state title margin must be re-asserted at id specificity (FN-10)'
        );
    }

    // ── Copy (FN-14 subset): no em dashes; verb+noun save button ──

    public function test_empty_state_copy_has_no_em_dash(): void
    {
        $php = file_get_contents($this->partialPath('goals-card.php'));
        // Pull the empty-state block and assert it carries no em/en dash.
        $start = strpos($php, "data-role=\"goals-empty\"");
        $this->assertNotFalse($start);
        $block = substr($php, $start, 700);
        $this->assertStringNotContainsString('—', $block, 'Empty-state copy must not use em dashes');
        $this->assertStringNotContainsString('–', $block, 'Empty-state copy must not use en dashes');
    }

    public function test_drawer_save_button_names_the_object(): void
    {
        $php = file_get_contents($this->partialPath('goal-drawer.php'));
        $this->assertMatchesRegularExpression(
            "/data-role=\"save-create\">.*esc_html_e\('Add goal'/",
            $php,
            'Drawer create button must say "Add goal", not bare "Add"'
        );
    }

    // ── paths ──

    private function reportsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
    }

    private function indexPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/index.php';
    }

    private function cssPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/goals-funnels.css';
    }

    private function partialPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/' . $file;
    }
}

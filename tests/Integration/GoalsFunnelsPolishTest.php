<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Batch I polish fixes (impeccable FN-13 / FN-15 / FN-16 / FN-18 /
 * FN-20 + copy). Deterministic structure/source guards (cf. GoalsFunnelsTokensTest).
 */
class GoalsFunnelsPolishTest extends TestCase
{
    // ── FN-13: page-level framing above the postboxes, only on slimview6 ──

    public function test_page_intro_driven_by_screen_registry(): void
    {
        // The slimview6 screen declares a 'lead' in the $screens_info registry...
        $admin = file_get_contents($this->adminIndexPath());
        $this->assertMatchesRegularExpression(
            "/'slimview6'\s*=>\s*\[[\s\S]{0,400}'lead'\s*=>/",
            $admin,
            'slimview6 must declare a page-intro lead in its registry entry (FN-13)'
        );
        // ...and the shared view renders the intro generically from the registry
        // (title + lead), not via a hardcoded screen-id branch.
        $php = file_get_contents($this->viewIndexPath());
        $this->assertStringContainsString("\$current_screen_info['lead']", $php, 'Page intro must render from the registry lead (FN-13)');
        $this->assertStringContainsString('<h1 class="slimstat-gf-pageintro__title"', $php, 'Page intro must use an <h1> (FN-13)');
        $this->assertStringContainsString("echo esc_html(\$current_screen_info['title'])", $php, 'H1 text must come from the registry title, not a duplicated literal (FN-13)');
    }

    // ── FN-15: zero-data goal row shows a hint, not 0/0/0% ──

    public function test_zero_data_goal_shows_hint(): void
    {
        $php = file_get_contents($this->partialPath('goals-card.php'));
        $this->assertStringContainsString('0 === $uniques && 0 === $total', $php, 'Zero-data branch missing (FN-15)');
        $this->assertStringContainsString('slimstat-gf-goal__nomatch', $php, 'Zero-data hint element missing (FN-15)');
    }

    // ── FN-16: consent note is readable, not 11px italic ──

    public function test_consent_note_is_readable(): void
    {
        $css = file_get_contents($this->cssPath());
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-consent\s*\{[^}]*font-size:\s*var\(--ss-text-sm\)/s',
            $css,
            'Consent note must use --ss-text-sm (FN-16)'
        );
        $this->assertMatchesRegularExpression(
            '/\.slimstat-gf-consent\s*\{[^}]*font-style:\s*normal/s',
            $css,
            'Consent note must drop the italic (FN-16)'
        );
    }

    // ── FN-18: stray literals tokenized ──

    public function test_scrim_and_rtl_shadow_tokenized(): void
    {
        $tokens = file_get_contents($this->tokensPath());
        $css    = file_get_contents($this->cssPath());
        $this->assertStringContainsString('--ss-overlay-scrim', $tokens, 'Scrim token missing (FN-18)');
        $this->assertStringContainsString('--ss-shadow-drawer-rtl', $tokens, 'RTL shadow token missing (FN-18)');
        // The literals must be gone from the component sheet.
        $this->assertStringNotContainsString('rgba(15, 23, 42, 0.45)', $css, 'Scrim literal must be tokenized (FN-18)');
        $this->assertStringNotContainsString('8px 0 24px rgba(15, 23, 42, 0.08)', $css, 'RTL shadow literal must be tokenized (FN-18)');
    }

    // ── FN-20: locked mock labelled as illustrative ──

    public function test_locked_mock_has_example_caption(): void
    {
        $php = file_get_contents($this->partialPath('funnels-card.php'));
        $this->assertStringContainsString('slimstat-gf-funnel-lock__caption', $php, 'Locked mock needs an "example" caption (FN-20)');
        $this->assertStringContainsString('Example funnel', $php, 'Caption copy missing (FN-20)');
    }

    // ── Copy (FN-14 remainder): sentence-case CTAs, no en dashes, value placeholder ──

    public function test_header_ctas_are_sentence_case(): void
    {
        $php = file_get_contents($this->reportsPath());
        $this->assertStringContainsString("'+ Add goal'", $php, 'Header CTA should be "+ Add goal" (FN-14)');
        $this->assertStringContainsString("'+ Add funnel'", $php, 'Header CTA should be "+ Add funnel" (FN-14)');
        $this->assertStringNotContainsString("'+ Add Goal'", $php, 'Title-case "+ Add Goal" must be gone');
    }

    public function test_funnels_card_copy_has_no_dashes(): void
    {
        $php = file_get_contents($this->partialPath('funnels-card.php'));
        $this->assertStringNotContainsString('2–5', $php, 'En-dash "2–5" range must be reworded (FN-14)');
        // Em/en dashes are banned in *translatable copy* (code comments are fine):
        // no i18n call may contain a dash.
        $this->assertDoesNotMatchRegularExpression(
            '/(esc_html_e|esc_html__|esc_attr_e|esc_attr__|__|_e)\([^)\n]*[—–]/u',
            $php,
            'Em/en dashes must be gone from funnels-card user copy (FN-14)'
        );
    }

    public function test_value_placeholder_leads_with_action(): void
    {
        $js = file_get_contents($this->jsPath());
        $this->assertStringContainsString("__('Type or pick a value')", $js, 'Value placeholder should lead with the action (FN-14)');
    }

    // ── paths ──

    private function viewIndexPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/index.php';
    }

    private function adminIndexPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/index.php';
    }

    private function reportsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
    }

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

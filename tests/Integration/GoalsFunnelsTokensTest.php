<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards that legacy --slimstat-* CSS custom properties are preserved verbatim in tokens.css,
 * and that tokens.css declares no unprefixed property: it also loads on the WordPress Dashboard
 * and Plugins screens, where a generic name such as --gdpr-border would reach other plugins.
 *
 * Rationale: the datepicker (admin/assets/css/daterangepicker/slimstat-datepicker-styles.css)
 * consumes these aliases; the front-end GDPR banner declares its own --gdpr-* in
 * assets/css/gdpr-banner.css. Any value drift → visible regression on those surfaces.
 *
 * See the 5.5.0 redesign notes for the legacy-alias preservation contract.
 */
class GoalsFunnelsTokensTest extends TestCase
{
    private const EXPECTED_LEGACY = [
        '--slimstat-primary'       => '#dc3232',
        '--slimstat-primary-hover' => '#b32d2e',
        '--slimstat-border'        => '#ddd',
        '--slimstat-background'    => '#fff',
        '--slimstat-text'          => '#333',
        '--slimstat-light-bg'      => '#f8f8f8',
    ];

    public function test_tokens_css_exists(): void
    {
        $this->assertFileExists($this->tokensPath());
    }

    public function test_tokens_css_preserves_legacy_aliases_verbatim(): void
    {
        $css = file_get_contents($this->tokensPath());

        foreach (self::EXPECTED_LEGACY as $var => $value) {
            $pattern = '/' . preg_quote($var, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*;/';
            $this->assertMatchesRegularExpression(
                $pattern,
                $css,
                "Legacy alias {$var} must be declared with value {$value} in tokens.css"
            );
        }
    }

    public function test_tokens_css_declares_only_slimstat_prefixed_properties(): void
    {
        preg_match_all('/(--[\w-]+)\s*:/', (string) file_get_contents($this->tokensPath()), $declared);
        $this->assertNotEmpty($declared[1]);
        $this->assertSame([], array_values(array_unique(preg_grep('/^--(ss|slimstat)-/', $declared[1], PREG_GREP_INVERT))), 'tokens.css loads outside SlimStat screens; prefix every custom property');
    }

    public function test_gdpr_banner_keeps_its_own_border_value(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/gdpr-banner.css');
        $this->assertMatchesRegularExpression('/--gdpr-border\s*:\s*#dee2e6\s*;/', $css);
    }

    public function test_tokens_css_declares_brand_ramp(): void
    {
        $css = file_get_contents($this->tokensPath());
        foreach (['--ss-brand-900', '--ss-brand-500', '--ss-brand-300', '--ss-brand-100'] as $var) {
            $this->assertStringContainsString($var, $css, "Brand ramp token {$var} missing");
        }
    }

    public function test_tokens_css_honors_reduced_motion(): void
    {
        $css = file_get_contents($this->tokensPath());
        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/',
            $css,
            'prefers-reduced-motion override block must exist'
        );
    }

    private function tokensPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/assets/css/tokens.css';
    }
}

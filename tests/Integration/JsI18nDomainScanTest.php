<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards WordPress JS i18n extractability across the plugin's own scripts.
 *
 * `wp i18n make-pot` only extracts a JS string when its gettext call carries a
 * literal text-domain argument: `__('text', 'wp-slimstat')`. A single-arg call
 * (`__('text')`) — even one that adds the domain inside a wrapper — is invisible
 * to the static extractor, so the string never reaches the .pot and is never
 * translated. This used to be the case for all of goals-funnels.js.
 *
 * This test fails if any plugin-authored JS gettext call (`__`, `_e`, `_x`, `_n`,
 * `_nx`) with a string-literal first argument is missing the 'wp-slimstat' domain,
 * so the regression cannot silently return. Vendored libraries (*.min.js and the
 * chartjs/daterangepicker bundles) are out of scope.
 */
class JsI18nDomainScanTest extends TestCase
{
    private const DOMAIN = 'wp-slimstat';

    /** Plugin-authored JS files that may contain translatable strings. */
    private function pluginJsFiles(): array
    {
        $root  = dirname(__DIR__, 2) . '/admin/assets/js';
        $files = glob($root . '/*.js') ?: [];
        // Top-level only; *.min.js and vendor subdirs (chartjs/, daterangepicker/)
        // are third-party and not ours to translate.
        return array_values(array_filter($files, static fn ($f) => !str_ends_with($f, '.min.js')));
    }

    public function test_plugin_js_files_are_discovered(): void
    {
        $names = array_map('basename', $this->pluginJsFiles());
        $this->assertContains('goals-funnels.js', $names, 'goals-funnels.js must be scanned');
        $this->assertContains('admin.js', $names, 'admin.js must be scanned');
    }

    public function test_every_js_gettext_call_carries_the_text_domain(): void
    {
        $offenders = [];

        foreach ($this->pluginJsFiles() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $i => $line) {
                $trimmed = ltrim($line);
                // Skip comment lines so documented examples don't trip the scan.
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }
                // A gettext call with a string-literal first argument: __('x' / _n("y" ...
                if (!preg_match('/\b(__|_e|_x|_n|_nx)\(\s*[\'"]/', $line)) {
                    continue;
                }
                // The call (and its domain) live on one line in this codebase; the
                // domain must appear as an argument on the same line.
                if (strpos($line, "'" . self::DOMAIN . "'") === false
                    && strpos($line, '"' . self::DOMAIN . '"') === false) {
                    $offenders[] = basename($file) . ':' . ($i + 1) . ' → ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "JS gettext calls missing the '" . self::DOMAIN . "' text domain (not extractable by make-pot):\n"
            . implode("\n", $offenders)
        );
    }

    public function test_scripts_share_one_i18n_accessor(): void
    {
        $root = dirname(__DIR__, 2);

        // The shared module defines the accessor once...
        $this->assertStringContainsString(
            'window.wpSlimstatI18n',
            (string) file_get_contents($root . '/admin/assets/js/i18n.js'),
            'i18n.js must define the shared window.wpSlimstatI18n accessor'
        );

        // ...and both consumer scripts read it instead of re-rolling their own
        // wp.i18n binding + fallback.
        foreach (['admin.js', 'goals-funnels.js'] as $script) {
            $this->assertStringContainsString(
                'window.wpSlimstatI18n',
                (string) file_get_contents($root . '/admin/assets/js/' . $script),
                $script . ' must read the shared accessor, not re-roll its own wp.i18n binding'
            );
        }

        // Enqueue wiring: slimstat-i18n is registered and is a dependency of both
        // consumer scripts (handle once + two deps = at least three references).
        $idx = (string) file_get_contents($root . '/admin/index.php');
        $this->assertStringContainsString("wp_enqueue_script('slimstat-i18n'", $idx, 'slimstat-i18n must be enqueued');
        $this->assertGreaterThanOrEqual(
            3,
            substr_count($idx, "'slimstat-i18n'"),
            'slimstat-i18n must be the handle plus a dependency of slimstat_admin and slimstat-goals-funnels'
        );
    }
}

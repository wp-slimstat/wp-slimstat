<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Shortcodes;
use Brain\Monkey\Functions;
use SlimStat\Shortcodes\Shortcode;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;
if (!defined('ABSPATH')) { exit; }
require_once dirname(__DIR__) . '/Heatmap/rest-stubs.php';
class ShortcodeTest extends WpSlimstatTestCase
{
    public function test_normalization_and_sql_boundary(): void
    {
        Functions\stubTranslationFunctions();
        Functions\when('shortcode_atts')->alias(static function ($defaults, $atts, $tag) {
            self::assertSame('slimstat', $tag);
            return array_merge($defaults, $atts);
        });
        Functions\when('wp_kses_post')->alias('strip_tags');
        $value = Shortcode::attributes(['f' => 'recent', 'w' => 'post_link, dt', 'o' => 'x', 's' => '<script>x</script>']);
        self::assertSame(0, $value['o']);
        self::assertSame(['post_link', 'dt'], $value['columns']);
        self::assertSame('x', $value['s']);
        foreach ([['f' => 'x', 'w' => 'id'], ['f' => 'top', 'w' => 'country, SLEEP(1)'], ['f' => 'widget', 'w' => 'country'], ['f' => 'top', 'w' => 'slim_p7_02']] as $atts) {
            self::assertInstanceOf(\WP_Error::class, Shortcode::attributes($atts));
        }
    }
    public function test_table_escapes_cells_and_headers(): void
    {
        Functions\stubTranslationFunctions();
        Functions\when('esc_html')->alias(static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES));
        Functions\when('number_format_i18n')->alias('number_format');
        $html = Shortcode::table([['<b>key</b>' => '<script>alert(1)</script>', 'count' => 1234]]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;b&gt;key&lt;/b&gt;', $html);
        self::assertStringContainsString('1,234', $html);
    }
}

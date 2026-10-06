<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Shortcodes;
use Brain\Monkey\Functions;
use SlimStat\Controllers\Rest\ShortcodeRestController;
use SlimStat\Shortcodes\Shortcode;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;
if (!defined('ABSPATH')) { exit; }
require_once dirname(__DIR__) . '/Heatmap/rest-stubs.php';
class ShortcodeRestControllerTest extends WpSlimstatTestCase
{
    public function test_route_requires_analytics_permission_and_a_bounded_string(): void
    {
        $routes = [];
        Functions\when('register_rest_route')->alias(static function ($namespace, $route, $args) use (&$routes) { $routes[$namespace . $route] = $args; });
        (new ShortcodeRestController())->register_routes();
        $route = $routes['slimstat/v1/shortcode/preview'];
        self::assertSame('POST', $route['methods']);
        self::assertSame([Shortcode::class, 'canView'], $route['permission_callback']);
        self::assertSame(['type' => 'string', 'required' => true, 'maxLength' => 2048], $route['args']['shortcode']);
        if (!class_exists('wp_slimstat_admin', false)) { require_once dirname(__DIR__, 3) . '/admin/index.php'; }
        $settings = \wp_slimstat::$settings;
        $GLOBALS['current_user'] = (object) ['user_login' => 'bob'];
        \wp_slimstat::$settings['can_view'] = 'alice';
        \wp_slimstat::$settings['capability_can_view'] = 'manage_options';
        Functions\when('current_user_can')->justReturn(false);
        self::assertFalse(Shortcode::canView());
        $GLOBALS['current_user']->user_login = '';
        $asked = null;
        Functions\when('current_user_can')->alias(static function ($cap) use (&$asked) { $asked = $cap; return false; });
        self::assertFalse(Shortcode::canView(), 'An anonymous user never matches the whitelist, including PHP 7.4');
        self::assertSame('manage_options', $asked);
        $GLOBALS['current_user']->user_login = 'bob';
        Functions\when('current_user_can')->justReturn(true);
        self::assertTrue(Shortcode::canView());
        \wp_slimstat::$settings = $settings;
        unset($GLOBALS['current_user']);
    }
    public function test_oversize_input_is_rejected_before_parsing(): void
    {
        Functions\stubTranslationFunctions();
        Functions\expect('get_shortcode_regex')->never();
        $result = (new ShortcodeRestController())->preview(new \WP_REST_Request(['shortcode' => str_repeat('x', 2049)]));
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
    }
}

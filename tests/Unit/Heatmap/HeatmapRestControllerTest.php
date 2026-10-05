<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Heatmap;

use Brain\Monkey\Functions;
use SlimStat\Controllers\Rest\HeatmapRestController;
use SlimStat\Heatmap\Store;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

require_once __DIR__ . '/rest-stubs.php';

class HeatmapRestControllerTest extends WpSlimstatTestCase
{
	private array $settings = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists('wp_slimstat_admin', false)) {
			require_once dirname(__DIR__, 3) . '/admin/index.php';
		}
		$this->settings = \wp_slimstat::$settings;
		$GLOBALS['slimstat_test_options'] = [];
		Functions\stubTranslationFunctions();
	}

	protected function tearDown(): void
	{
		\wp_slimstat::$settings = $this->settings;
		$GLOBALS['slimstat_test_options'] = [];
		parent::tearDown();
	}

	/** The same gate as the report screens: the configured view capability. */
	public function test_only_users_who_may_view_reports_can_read_the_list(): void
	{
		$GLOBALS['current_user'] = (object) ['user_login' => 'bob'];
		\wp_slimstat::$settings['can_view']            = 'alice';
		\wp_slimstat::$settings['capability_can_view'] = 'edit_posts';
		$caps = [];
		Functions\when('current_user_can')->alias(static function ($cap) use (&$caps) {
			$caps[] = $cap;
			return 'edit_posts' === $cap;
		});
		self::assertTrue(HeatmapRestController::canView());

		\wp_slimstat::$settings['capability_can_view'] = 'manage_options';
		self::assertFalse(HeatmapRestController::canView());
		self::assertSame(['edit_posts', 'manage_options'], $caps);
		unset($GLOBALS['current_user']);
	}

	public function test_a_reversed_range_is_a_400_and_reads_nothing(): void
	{
		Functions\expect('get_transient')->never();
		$result = (new HeatmapRestController())->pages(new \WP_REST_Request(['from' => '2026-10-03', 'to' => '2026-10-01', 'days' => 30, 'device' => '', 'refresh' => false]));

		self::assertInstanceOf(\WP_Error::class, $result);
		self::assertSame(400, $result->data['status']);
	}

	public function test_rows_carry_the_viewer_url_only_where_a_viewer_exists(): void
	{
		$row = ['page' => '/about', 'clicks' => 8, 'desktop' => 3, 'tablet' => 1, 'mobile' => 4, 'dead' => 1, 'rage' => 1, 'scroll' => 62, 'last' => 1759449600, 'full' => true, 'pageviews' => 20, 'content_id' => 5];
		$keys = [];
		Functions\when('get_current_blog_id')->justReturn(1);
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('get_transient')->alias(static function ($key) use (&$keys, $row) {
			$keys[] = $key;
			return ['signature' => ['', \wp_slimstat::$settings['auto_purge'] ?? 0, false], 'data' => ['rows' => [$row], 'updated' => 77]];
		});
		Functions\when('_prime_post_caches')->justReturn(null);
		Functions\when('get_the_title')->justReturn('<em>About</em> us');
		Functions\when('esc_url_raw')->returnArg();
		Functions\when('add_query_arg')->alias(static fn($args, $url) => $url . '&' . http_build_query($args));
		Functions\when('rest_ensure_response')->returnArg();
		$GLOBALS['slimstat_test_options']['date_format'] = 'Y-m-d';
		$request = new \WP_REST_Request(['to' => '2026-10-03', 'days' => 7, 'device' => '', 'refresh' => false]);

		Functions\when('apply_filters')->returnArg(2);
		$free = (new HeatmapRestController())->pages($request);
		self::assertSame(['from' => '2026-09-27', 'to' => '2026-10-03', 'updated' => 77], array_slice($free, 0, 3), 'seven days ending on to');
		self::assertSame('', $free['rows'][0]['url'], 'Free: no viewer, the row opens the Pro dialog');
		self::assertSame('About us', $free['rows'][0]['title']);
		self::assertSame([3, 1, 4], $free['rows'][0]['devices']);
		self::assertTrue($free['ever'], 'rows in range: the site has clicks, no extra query (no wpdb here)');

		Functions\when('apply_filters')->alias(static fn($hook, $url, $page) => 'slimstat_heatmap_row_url' === $hook ? 'https://example.test/view?page=' . rawurlencode($page) : $url);
		$pro = (new HeatmapRestController())->pages($request);
		self::assertSame('https://example.test/view?page=%2Fabout&from=2026-09-27&to=2026-10-03', $pro['rows'][0]['url'], 'the viewer opens on the list\'s range');
		self::assertCount(1, array_unique($keys), 'one cache entry per range and device');
	}
}

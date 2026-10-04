<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Heatmap;

use Brain\Monkey\Functions;
use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** The Heatmaps page list: both layers merged by page key, each pageview counted once. */
class PagesTest extends WpSlimstatTestCase
{
	/** @var string[] */
	private array $queries = [];

	private array $canned = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->queries = [];
		$this->canned  = [
			'slim_events te'      => [
				['page' => '/about', 'clicks' => '5', 'desktop' => '3', 'tablet' => '1', 'mobile' => '1', 'last' => '200'],
				['page' => '/', 'clicks' => '2', 'desktop' => '2', 'tablet' => '0', 'mobile' => '0', 'last' => '150'],
			],
			'kind = 0'            => [
				['page' => 'AAAA', 'id' => '7', 'clicks' => '3', 'desktop' => '0', 'tablet' => '0', 'mobile' => '3', 'dead' => '1', 'rage' => '1', 'last' => '300'],
				['page' => 'BBBB', 'id' => '9', 'clicks' => '4', 'desktop' => '4', 'tablet' => '0', 'mobile' => '0', 'dead' => '0', 'rage' => '0', 'last' => '120'],
			],
			'kind = 1'            => [['page' => 'AAAA', 'depth' => '62'], ['page' => 'CCCC', 'depth' => '10']],
			'SELECT id, resource' => [['id' => '7', 'resource' => '/about?ref=nav'], ['id' => '9', 'resource' => '/pricing#plans']],
			'COUNT(*) n'          => [['page' => '/about', 'n' => '20', 'content_id' => '5'], ['page' => '/elsewhere', 'n' => '1', 'content_id' => '0']],
		];
		$GLOBALS['slimstat_test_options'] = [];

		$db             = \Mockery::mock(\wpdb::class);
		$db->prefix     = 'wp_';
		$db->last_error = '';
		$db->shouldReceive('suppress_errors')->andReturn(false);
		$db->shouldReceive('esc_like')->andReturnUsing(static fn($text) => addcslashes($text, '_%\\'));
		$db->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), is_array($args[0] ?? null) ? $args[0] : $args));
		$db->shouldReceive('get_results')->andReturnUsing(function ($sql) {
			$this->queries[] = $sql;
			foreach ($this->canned as $needle => $rows) {
				if (false !== strpos($sql, $needle)) {
					return $rows;
				}
			}
			return [];
		});
		$GLOBALS['wpdb']    = $db;
		\wp_slimstat::$wpdb = $db;
	}

	protected function tearDown(): void
	{
		\wp_slimstat::$wpdb = null;
		$GLOBALS['slimstat_test_options'] = [];
		parent::tearDown();
	}

	private function ready(int $since = 100): void
	{
		$GLOBALS['slimstat_test_options'][Store::STATE] = ['schema' => Store::SCHEMA, 'since' => $since];
	}

	private function query(string $needle): string
	{
		return implode("\n", array_filter($this->queries, static fn($q) => false !== strpos($q, $needle)));
	}

	public function test_before_capture_only_legacy_clicks_are_read(): void
	{
		$rows = Query::pages(1, 999);

		self::assertSame(['/about', '/'], array_column($rows, 'page'));
		self::assertStringNotContainsString('slim_heatmap', implode("\n", $this->queries), 'no table, no probe');
		self::assertSame([null, null], array_column($rows, 'dead'), 'legacy clicks have no dead/rage signal');
		self::assertSame([false, false], array_column($rows, 'full'));
		self::assertSame(20, $rows[0]['pageviews']);
		self::assertSame(5, $rows[0]['content_id']);
		self::assertSame(0, $rows[1]['pageviews'], 'a page with no pageview row stays 0');
	}

	public function test_the_login_screen_is_not_a_heatmap_page(): void
	{
		$this->canned['slim_events te'][] = ['page' => '/wp-login.php', 'clicks' => '9', 'desktop' => '9', 'tablet' => '0', 'mobile' => '0', 'last' => '250'];
		$this->canned['slim_events te'][] = ['page' => '/blog/wp-login.php', 'clicks' => '9', 'desktop' => '9', 'tablet' => '0', 'mobile' => '0', 'last' => '250'];

		self::assertSame(['/about', '/'], array_column(Query::pages(1, 999), 'page'), 'WordPress in a subdirectory too');
	}

	public function test_any_clicks_tells_a_never_tracked_site_from_an_empty_range(): void
	{
		$this->canned = ['slim_events te' => [['1' => '1']]];
		self::assertTrue(Query::anyClicks(), 'an old link click counts');

		$this->canned = [];
		self::assertFalse(Query::anyClicks());
		self::assertStringNotContainsString('slim_heatmap', implode("\n", $this->queries), 'no table yet, not read');

		$this->ready();
		$this->canned = ['kind = 0' => [['1' => '1']]];
		self::assertTrue(Query::anyClicks(), 'a full-tracking click counts');
	}

	public function test_both_layers_merge_by_page_key_and_a_pageview_counts_once(): void
	{
		$this->ready(100);
		$rows = Query::pages(1, 999);

		self::assertSame(['/about', '/pricing', '/'], array_column($rows, 'page'), 'sorted by merged clicks');
		$about = $rows[0];
		self::assertSame(8, $about['clicks']);
		self::assertSame([3, 1, 4], [$about['desktop'], $about['tablet'], $about['mobile']]);
		self::assertSame([1, 1], [$about['dead'], $about['rage']]);
		self::assertSame(62, $about['scroll']);
		self::assertTrue($about['full'], 'a scroll row means everything was recorded');
		self::assertSame(300, $about['last']);
		self::assertSame([0, 0, null, false], [$rows[1]['dead'], $rows[1]['rage'], $rows[1]['scroll'], $rows[1]['full']]);
		self::assertNull($rows[2]['dead']);

		// After capture began, legacy rows of a pageview that has heatmap rows are skipped.
		self::assertStringContainsString('te.dt < 100 OR NOT EXISTS (SELECT 1 FROM wp_slim_heatmap h WHERE h.id = te.id)', $this->query('slim_events te'));
		self::assertStringContainsString('WHERE id IN (7,9)', $this->query('SELECT id, resource'));
	}

	public function test_device_filter_reaches_every_layer(): void
	{
		$this->ready();
		Query::pages(1, 999, 'mobile');

		self::assertStringContainsString('BETWEEN 1 AND 767', $this->query('slim_events te'));
		self::assertStringContainsString('device = 3', $this->query('kind = 0'));
		self::assertStringContainsString('device = 3', $this->query('kind = 1'));
		self::assertStringContainsString('BETWEEN 1 AND 767', $this->query('COUNT(*) n'));
	}

	public function test_no_clicks_skips_the_pageview_query(): void
	{
		$this->canned = [];
		self::assertSame([], Query::pages(1, 999));
		self::assertSame('', $this->query('COUNT(*) n'));
	}

	public function test_a_database_error_is_an_error_not_an_empty_list(): void
	{
		Functions\stubTranslationFunctions();
		$GLOBALS['wpdb']->last_error = 'Table is marked as crashed';
		$this->expectException(\RuntimeException::class);
		Query::pages(1, 999);
	}

	public function test_cached_list_is_reused_until_the_data_changes(): void
	{
		$store = [];
		Functions\when('get_current_blog_id')->justReturn(1);
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('get_transient')->alias(static function ($key) use (&$store) {
			return $store[$key] ?? false;
		});
		Functions\when('set_transient')->alias(static function ($key, $value) use (&$store) {
			$store[$key] = $value;
			return true;
		});

		$first = Query::cachedPages(1, 999);
		$count = count($this->queries);
		self::assertSame($first, Query::cachedPages(1, 999), 'hit');
		self::assertCount($count, $this->queries, 'a hit runs no query');

		Query::cachedPages(1, 999, '', true);
		self::assertGreaterThan($count, count($this->queries), 'refresh recomputes');

		$count = count($this->queries);
		$GLOBALS['slimstat_test_options'][Store::GENERATION] = 'bumped';
		Query::cachedPages(1, 999);
		self::assertGreaterThan($count, count($this->queries), 'a write (generation bump) misses');

		$count = count($this->queries);
		Query::cachedPages(1, 999, 'mobile');
		self::assertGreaterThan($count, count($this->queries), 'device is part of the key');
	}
}

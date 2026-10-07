<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Heatmap;

use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;
use SlimStat\Schema\SurrogateKey;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/** Viewer reads: one page, one device, one range; each pageview counted by one layer. */
class ViewerQueryTest extends WpSlimstatTestCase
{
	/** @var string[] */
	private array $queries = [];

	private array $canned = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->queries = [];
		$this->canned  = [];
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

	public function test_click_bins_read_one_page_device_and_range_from_the_new_layer(): void
	{
		$this->ready();
		$this->canned['kind = 0'] = [
			['selector' => '#buy', 'label' => 'Buy now', 'bx' => '9', 'by' => '10', 'x' => '700', 'y' => '410', 'vw' => '1440', 'n' => '12', 'dead' => '0', 'rage' => '3', 'first' => '5'],
			['selector' => null, 'label' => null, 'bx' => '0', 'by' => '0', 'x' => '20', 'y' => '30', 'vw' => '1400', 'n' => '2', 'dead' => '2', 'rage' => '0', 'first' => '0'],
		];

		$bins = Query::clickBins('/pricing', 'mobile', 10, 20);

		$sql = implode("\n", $this->queries);
		self::assertStringContainsString("UNHEX('" . SurrogateKey::hex('/pricing') . "')", $sql, 'the page hash, never a LIKE');
		self::assertStringContainsString('h.device = 3', $sql);
		self::assertStringContainsString('h.dt BETWEEN 10 AND 20', $sql);
		self::assertStringNotContainsString('slim_stats', $sql, 'no filters, no join');
		self::assertSame(['s' => '#buy', 'l' => 'Buy now', 'rx' => 4750, 'ry' => 5250, 'x' => 700, 'y' => 410, 'vw' => 1440, 'n' => 12, 'd' => 0, 'r' => 3, 'f' => 5], $bins[0]);
		self::assertSame('', $bins[1]['s'], 'a click on an element with no usable selector keeps its position');
	}

	public function test_click_bins_join_pageviews_only_for_report_filters(): void
	{
		$this->ready();
		Query::clickBins('/pricing', '', 10, 20, "t1.country = 'de'");

		$sql = implode("\n", $this->queries);
		self::assertStringContainsString('INNER JOIN wp_slim_stats t1 ON t1.id = h.id', $sql);
		self::assertStringContainsString("(t1.country = 'de')", $sql);
		self::assertStringNotContainsString('h.device =', $sql, 'all devices');
	}

	public function test_click_bins_are_empty_before_the_tables_exist(): void
	{
		self::assertSame([], Query::clickBins('/pricing', 'mobile', 10, 20));
		self::assertSame([], $this->queries);
	}

	/** Each pageview is read by exactly one layer: a pageview with heatmap rows is not legacy. */
	public function test_legacy_points_skip_pageviews_the_new_layer_covers(): void
	{
		$this->ready(150);
		Query::legacyPoints('/pricing', 'desktop', 't1.dt BETWEEN 10 AND 20');
		self::assertStringContainsString('te.dt < 150 OR NOT EXISTS (SELECT 1 FROM wp_slim_heatmap h WHERE h.id = te.id)', implode("\n", $this->queries));

		$this->queries = [];
		$GLOBALS['slimstat_test_options'] = [];
		Query::legacyPoints('/pricing', 'desktop');
		self::assertStringNotContainsString('slim_heatmap', implode("\n", $this->queries), 'no table, no probe');
	}

	public function test_scroll_reach_is_the_share_of_pageviews_reaching_each_percent(): void
	{
		$this->ready();
		// Four pageviews: one stopped at 20%, two at 50%, one read to the end.
		$this->canned['kind = 1'] = [
			['band' => '20', 'n' => '1', 'fold' => '25'],
			['band' => '50', 'n' => '2', 'fold' => '60'],
			['band' => '100', 'n' => '1', 'fold' => '15'],
		];

		$scroll = Query::scrollReach('/pricing', 'desktop', 10, 20);

		self::assertSame(4, $scroll['views']);
		self::assertCount(101, $scroll['reach']);
		self::assertSame([100, 100, 75, 75, 25, 25], [$scroll['reach'][0], $scroll['reach'][20], $scroll['reach'][21], $scroll['reach'][50], $scroll['reach'][51], $scroll['reach'][100]]);
		self::assertSame(50, $scroll['half'], 'the deepest point at least half of visitors reach');
		self::assertSame(40, $scroll['fold'], 'share of the page visible without scrolling, averaged over pageviews');
		self::assertStringContainsString('h.device = 1', implode("\n", $this->queries));
	}

	public function test_scroll_reach_without_scroll_rows_is_empty(): void
	{
		$this->ready();
		self::assertSame(['views' => 0, 'reach' => [], 'half' => 0, 'fold' => 0], Query::scrollReach('/pricing', '', 10, 20));
	}

	public function test_device_views_count_pageviews_per_device_and_before_full_tracking(): void
	{
		$this->ready(150);
		$this->canned['COUNT(*) views'] = [['views' => '9', 'desktop' => '5', 'tablet' => '1', 'mobile' => '3', 'older' => '4', 'content_id' => '12']];

		$views = Query::deviceViews('/pricing', 10, 200);

		self::assertSame(['views' => 9, 'desktop' => 5, 'tablet' => 1, 'mobile' => 3, 'older' => 4, 'content_id' => 12], $views);
		$sql = implode("\n", $this->queries);
		self::assertStringContainsString("t1.resource = '/pricing'", $sql, 'exact page');
		self::assertStringContainsString('SUM(t1.dt < 150) older', $sql);
	}

	public function test_scroll_depth_by_device_feeds_the_device_gap(): void
	{
		$this->ready();
		$this->canned['GROUP BY h.device'] = [['device' => '1', 'depth' => '70'], ['device' => '3', 'depth' => '40'], ['device' => '0', 'depth' => '10']];

		self::assertSame(['desktop' => 70, 'mobile' => 40], Query::scrollByDevice('/pricing', 10, 20));
	}
}

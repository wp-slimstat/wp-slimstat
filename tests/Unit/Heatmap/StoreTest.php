<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit\Heatmap;

use Brain\Monkey\Functions;
use SlimStat\Heatmap\Store;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class StoreTest extends WpSlimstatTestCase
{
	/** @var string[] */
	private array $queries = [];

	/** @var mixed What the next write reports. */
	private $result = 1;

	private array $settings = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->queries  = [];
		$this->result   = 1;
		$this->settings = \wp_slimstat::$settings;
		\wp_slimstat::$degradations = [];
		Functions\stubTranslationFunctions();
		Functions\when('update_option')->alias(static function ($name, $value) {
			$GLOBALS['slimstat_test_options'][$name] = $value;
			return true;
		});
		Functions\when('wp_cache_delete')->justReturn(true);

		$db          = \Mockery::mock(\wpdb::class);
		$db->prefix  = 'wp_';
		$db->options = 'wp_options';
		$db->last_error = '';
		$db->users   = 'wp_users';
		$db->shouldReceive('suppress_errors')->andReturn(false);
		$db->shouldReceive('get_var')->andReturn('utf8mb4_unicode_ci');
		$db->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), $args));
		$db->shouldReceive('query')->andReturnUsing(function ($sql) {
			$this->queries[] = $sql;
			return 0 === strpos($sql, 'INSERT INTO `wp_options`') || 0 === strpos($sql, 'DELETE FROM `wp_options`') ? 1 : $this->result;
		});
		$GLOBALS['wpdb']  = $db;
		\wp_slimstat::$wpdb = $db;
	}

	protected function tearDown(): void
	{
		\wp_slimstat::$settings = $this->settings;
		\wp_slimstat::$wpdb     = null;
		$GLOBALS['slimstat_test_options'] = [];
		parent::tearDown();
	}

	private function heatmapQueries(): array
	{
		return array_values(array_filter($this->queries, static fn($q) => false !== strpos($q, 'slim_heatmap')));
	}

	public function test_unknown_level_reads_as_off_and_capture_needs_a_viewer(): void
	{
		\wp_slimstat::$settings['heatmap_capture'] = 'everything';
		self::assertSame('off', Store::level());

		\wp_slimstat::$settings['heatmap_capture'] = 'main';
		Functions\when('has_filter')->justReturn(false);
		self::assertFalse(Store::capturing(), 'Free-only sites collect nothing: no viewer');
		Functions\when('has_filter')->justReturn(true);
		self::assertTrue(Store::capturing());
	}

	/** DDL only for an admin, on a page load, with capture on and no recorded failure. */
	public function test_setup_runs_only_from_an_admin_page_load(): void
	{
		\wp_slimstat::$settings['heatmap_capture'] = 'full';
		Functions\when('has_filter')->justReturn(true);
		Functions\when('wp_doing_cron')->justReturn(false);

		$cases = [
			'ajax'           => [true, true, []],
			'non-admin'      => [false, false, []],
			'failed before'  => [false, true, ['error' => 'Could not create wp_slim_heatmap']],
			'already ready'  => [false, true, ['schema' => Store::SCHEMA, 'error' => '']],
		];
		foreach ($cases as $label => [$ajax, $admin, $state]) {
			$this->queries = [];
			$GLOBALS['slimstat_test_options'] = [Store::STATE => $state];
			Functions\when('wp_doing_ajax')->justReturn($ajax);
			Functions\when('current_user_can')->justReturn($admin);
			Store::maybeSetup();
			self::assertSame([], $this->heatmapQueries(), $label);
		}
	}

	/** A failed CREATE is recorded for the Retry card, capture stays off, the claim is released. */
	public function test_failed_create_is_recorded_and_releases_the_claim(): void
	{
		$this->result = false;
		$GLOBALS['wpdb']->last_error = 'CREATE command denied';
		$GLOBALS['slimstat_test_options'] = [];

		try {
			Store::setup();
			self::fail('setup() must throw when CREATE fails');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('CREATE command denied', $e->getMessage());
		}

		self::assertFalse(Store::ready());
		self::assertStringContainsString('CREATE command denied', $GLOBALS['slimstat_test_options'][Store::STATE]['error']);
		self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `wp_slim_heatmap_elements`', $this->heatmapQueries()[0]);
		self::assertStringStartsWith('DELETE FROM `wp_options`', end($this->queries), 'claim released');
	}

	public function test_purge_uses_the_retention_cutoff_and_reports_failure(): void
	{
		\wp_slimstat::$settings['auto_purge'] = 30;
		$GLOBALS['slimstat_test_options'] = [Store::STATE => ['schema' => Store::SCHEMA]];

		Store::purge();
		self::assertCount(1, $this->heatmapQueries());
		self::assertMatchesRegularExpression('/^DELETE FROM wp_slim_heatmap WHERE kind IN \(0, 1\) AND dt < \d+ LIMIT 50000$/', $this->heatmapQueries()[0]);

		$this->result = false;
		Store::purge();
		self::assertArrayHasKey('purge (heatmap rows)', \wp_slimstat::$degradations);

		$this->queries = [];
		\wp_slimstat::$settings['auto_purge'] = 0;
		Store::purge();
		self::assertSame([], $this->heatmapQueries(), 'retention off purges nothing');
	}

	/** Heatmap rows reach a person only through the pageview, so they are erased first. */
	public function test_eraser_runs_before_pageviews_and_only_once_tables_exist(): void
	{
		$existing = ['slimstat-events' => [], 'slimstat-pageviews' => []];
		self::assertSame($existing, Store::erasers($existing));

		$GLOBALS['slimstat_test_options'] = [Store::STATE => ['schema' => Store::SCHEMA]];
		self::assertSame(['slimstat-heatmap', 'slimstat-events', 'slimstat-pageviews'], array_keys(Store::erasers($existing)));

		$result = Store::erase("o'neil@example.com");
		self::assertSame("DELETE h FROM wp_slim_heatmap h INNER JOIN wp_slim_stats s ON s.id = h.id WHERE s.email = 'o'neil@example.com'", $this->heatmapQueries()[0]);
		self::assertTrue($result['items_removed']);
		self::assertTrue($result['done']);
	}
}

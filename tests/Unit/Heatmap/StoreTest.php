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

	/** @var array|null The pageview row ingest looks up. */
	private $view = ['resource' => '/about?ref=nav', 'dt' => 1700000000];

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
		$db->shouldReceive('get_row')->andReturnUsing(fn() => $this->view);
		$db->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), is_array($args[0] ?? null) ? $args[0] : $args));
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
		unset($_SERVER['HTTP_DNT']);
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

	private function readyToIngest(string $level = 'full'): void
	{
		$this->stubCommonWpFunctions();
		Functions\when('has_filter')->justReturn(true);
		\wp_slimstat::$settings = array_merge(\wp_slimstat::$settings, [
			'heatmap_capture' => $level,
			'heatmap_rate'    => 10000,
			'heatmap_pages'   => '',
			'gdpr_enabled'    => 'off',
			'do_not_track'    => 'on',
		]);
		$GLOBALS['slimstat_test_options'] = [Store::STATE => ['schema' => Store::SCHEMA]];
	}

	private function batch(array $rows, array $selectors = [], ?array $scroll = null): string
	{
		return json_encode(['v' => 1, 'vw' => 1280, 'vh' => 720, 's' => $selectors, 'r' => $rows] + (null === $scroll ? [] : ['sc' => $scroll]));
	}

	/** Each gate the tracker applies is applied again here: a cached page can carry a stale hm. */
	public function test_ingest_refuses_batches_the_site_would_not_record(): void
	{
		$good  = $this->batch([[0, 1, 0, 5000, 5000, 100, 200]], [['#buy', 'Buy']]);
		$cases = [
			'viewer removed'  => [static fn() => Functions\when('has_filter')->justReturn(false), 42, $good],
			'capture off'     => [static fn() => \wp_slimstat::$settings['heatmap_capture'] = 'off', 42, $good],
			'tables missing'  => [static fn() => $GLOBALS['slimstat_test_options'] = [], 42, $good],
			'no pageview id'  => [static fn() => null, 0, $good],
			'oversized'       => [static fn() => null, 42, json_encode(['r' => [], 'pad' => str_repeat('x', Store::MAX_BYTES)])],
			'not json'        => [static fn() => null, 42, '{"r":'],
			'too deep'        => [static fn() => null, 42, '{"r":[[[1]]]}'],
			'sampled out'     => [static fn() => \wp_slimstat::$settings['heatmap_rate'] = 42, 42, $good],
			'do not track'    => [static fn() => $_SERVER['HTTP_DNT'] = '1', 42, $good],
			'page not listed' => [static fn() => \wp_slimstat::$settings['heatmap_pages'] = '/pricing*, /shop', 42, $good],
			'pageview gone'   => [fn() => $this->view = null, 42, $good],
		];
		foreach ($cases as $label => [$arrange, $id, $raw]) {
			$this->readyToIngest();
			$this->view    = ['resource' => '/about?ref=nav', 'dt' => 1700000000];
			$this->queries = [];
			unset($_SERVER['HTTP_DNT']);
			$arrange();
			self::assertSame(0, Store::ingest($id, $raw), $label);
			self::assertSame([], $this->heatmapQueries(), $label);
		}

		// The same batch with every gate open is stored, so each refusal above is the gate's doing.
		$this->readyToIngest();
		$this->view = ['resource' => '/about?ref=nav', 'dt' => 1700000000];
		\wp_slimstat::$settings['heatmap_pages'] = '/pricing*, /about';
		self::assertSame(1, Store::ingest(42, $good));
	}

	/** Page, date and device are the server's; the client's numbers are clamped; bad selectors drop. */
	public function test_ingest_writes_server_facts_and_clamps_client_numbers(): void
	{
		$this->readyToIngest();
		$raw = $this->batch(
			[
				[0, Store::FLAG_INTERACTIVE, 0, 2500, 9999999, 100, 200],
				[999, 255, 1, -4, 5000, 'x', 99999999],
				[2, 0, 7, 1, 1, 1, 1],
				['bad row'],
			],
			[['div.cta > a#buy', 'Buy now'], ['a[href="x"]', 'Bad']],
			[3000, 99999999]
		);

		self::assertSame(4, Store::ingest(42, $raw));
		[$insert, $elements] = $this->heatmapQueries();

		$page = bin2hex(substr(md5('/about', true), 0, 8));
		$sel  = bin2hex(substr(md5('div.cta > a#buy', true), 0, 8));
		$head = "(42, %d, %d, UNHEX('$page'), 1700000000, 1, 1280, 720, ";
		$rows = [
			sprintf($head, 0, 0) . "0, 100, 200, UNHEX('$sel'), 2500, 10000, 1)",
			sprintf($head, 0, 199) . '0, 0, 16777215, NULL, 0, 5000, 7)',
			sprintf($head, 0, 2) . '0, 1, 1, NULL, 1, 1, 0)',
			sprintf($head, 1, 0) . '16777215, 0, 3000, NULL, 0, 0, 0)',
		];
		self::assertSame(
			'INSERT INTO wp_slim_heatmap (id, kind, seq, page, dt, device, vw, vh, dh, x, y, sel, rx, ry, flags) VALUES '
			. implode(', ', $rows)
			. ' ON DUPLICATE KEY UPDATE y = GREATEST(y, VALUES(y)), dh = GREATEST(dh, VALUES(dh))',
			$insert
		);
		self::assertSame("INSERT IGNORE INTO wp_slim_heatmap_elements (sel, selector, label) VALUES (UNHEX('$sel'), 'div.cta > a#buy', 'Buy now')", $elements);
	}

	/** Main level keeps clicks on interactive elements only, no scroll, no labels from plain text. */
	public function test_main_level_keeps_interactive_clicks_only(): void
	{
		$this->readyToIngest('main');
		$raw = $this->batch([[0, Store::FLAG_INTERACTIVE, 0, 1, 1, 1, 1], [1, Store::FLAG_DEAD, 1, 1, 1, 1, 1]], [['#buy', 'Buy'], ['p', 'Private text']], [100, 900]);

		self::assertSame(1, Store::ingest(42, $raw));
		[$insert, $elements] = $this->heatmapQueries();
		self::assertSame(1, substr_count($insert, '(42, '), 'one click row, no scroll row');
		self::assertStringNotContainsString('Private text', $elements);

		// Full level stores the dead click, but its element keeps no label.
		$this->readyToIngest('full');
		$this->queries = [];
		self::assertSame(3, Store::ingest(42, $raw));
		self::assertStringContainsString("'p', '')", $this->heatmapQueries()[1]);
	}

	/** Form posts arrive slashed by WordPress; the batch still decodes. A write failure throws. */
	public function test_slashed_batch_decodes_and_write_failure_throws(): void
	{
		$this->readyToIngest();
		self::assertSame(1, Store::ingest(42, addslashes($this->batch([[0, 1, 0, 1, 1, 1, 1]], [['#buy', 'Say "hi"']]))));
		self::assertStringContainsString("'Say \"hi\"'", $this->heatmapQueries()[1]);

		$this->result = false;
		$GLOBALS['wpdb']->last_error = 'Cannot add or update a child row';
		$this->expectExceptionMessage('Cannot add or update a child row');
		Store::ingest(42, $this->batch([[0, 1, 0, 1, 1, 1, 1]]));
	}

	/** The tracker gets hm only where capture would be stored; a preview frame gets nothing. */
	public function test_tracker_params_only_where_capture_is_stored(): void
	{
		$this->readyToIngest('main');
		Functions\when('has_filter')->justReturn(true);
		\wp_slimstat::$settings['heatmap_rate']  = 2500;
		\wp_slimstat::$settings['heatmap_pages'] = '/pricing*, /about';
		self::assertSame(['l' => 'main', 'r' => 2500], Store::params('/about?ref=nav#team'));

		$cases = [
			'page not listed' => static fn() => \wp_slimstat::$settings['heatmap_pages'] = '/pricing*',
			'rate zero'       => static fn() => \wp_slimstat::$settings['heatmap_rate'] = 0,
			'no viewer'       => static fn() => Functions\when('has_filter')->justReturn(false),
			'tables missing'  => static fn() => $GLOBALS['slimstat_test_options'] = [],
			'preview frame'   => static fn() => $_GET['slimstat_heatmap'] = 'nonce',
		];
		foreach ($cases as $label => $arrange) {
			$this->readyToIngest('main');
			Functions\when('has_filter')->justReturn(true);
			$arrange();
			self::assertNull(Store::params('/about'), $label);
			unset($_GET['slimstat_heatmap']);
		}
		self::assertFalse(Store::isPreview());
	}

	/** Delete and Retry need the nonce first, then manage_options; a refusal changes nothing. */
	public function test_admin_actions_refuse_without_nonce_or_capability(): void
	{
		$GLOBALS['slimstat_test_options'][Store::STATE] = ['schema' => Store::SCHEMA, 'error' => 'x'];
		$nonces = [];
		$caps   = [];
		$valid  = false;
		Functions\when('wp_die')->alias(static function ($message, $code) {
			throw new \RuntimeException('die ' . $code);
		});
		Functions\when('check_admin_referer')->alias(static function ($action) use (&$nonces, &$valid) {
			$nonces[] = $action;
			if (!$valid) {
				throw new \RuntimeException('nonce');
			}
			return 1;
		});
		Functions\when('current_user_can')->alias(static function ($cap) use (&$caps) {
			$caps[] = $cap;
			return false;
		});
		foreach (['handleDelete' => ['slimstat_heatmap_delete', 'nonce'], 'handleRetry' => ['slimstat_heatmap_retry', 'nonce'], 'handleDelete ' => ['slimstat_heatmap_delete', 'die 403'], 'handleRetry ' => ['slimstat_heatmap_retry', 'die 403']] as $handler => [$action, $refusal]) {
			$valid = 'die 403' === $refusal;
			try {
				Store::{trim($handler)}();
				self::fail("{$handler} ran: {$refusal}");
			} catch (\RuntimeException $e) {
				self::assertSame($refusal, $e->getMessage());
			}
			self::assertSame($action, end($nonces));
		}
		self::assertSame(['manage_options', 'manage_options'], $caps, 'capability is checked only after a valid nonce');
		self::assertSame([], $this->heatmapQueries());
		self::assertSame('x', $GLOBALS['slimstat_test_options'][Store::STATE]['error'], 'the failure is still on record');
	}

	public function test_delete_all_empties_both_tables_or_reports_failure(): void
	{
		self::assertTrue(Store::deleteAll());
		self::assertSame(['TRUNCATE TABLE wp_slim_heatmap', 'TRUNCATE TABLE wp_slim_heatmap_elements'], $this->heatmapQueries());
		self::assertArrayHasKey(Store::GENERATION, $GLOBALS['slimstat_test_options'], 'cached lists are invalidated');

		$this->queries = [];
		unset($GLOBALS['slimstat_test_options'][Store::GENERATION]);
		$this->result = false;
		self::assertFalse(Store::deleteAll());
		self::assertCount(1, $this->heatmapQueries(), 'stops at the first failure');
		self::assertArrayHasKey('heatmap delete', \wp_slimstat::$degradations);
		self::assertArrayNotHasKey(Store::GENERATION, $GLOBALS['slimstat_test_options']);
	}
}

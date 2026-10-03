<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Heatmap;

use SlimStat\Migration\MigrationService;
use SlimStat\Schema\Schema;
use SlimStat\Utils\OptionClaim;

/**
 * Heatmap capture storage: on-demand tables, their state, retention and privacy.
 *
 * Tables are created only when heatmap tracking is on and a heatmap viewer (Pro) is
 * active, so Free-only sites never get them. Rows follow their pageview: FK cascade,
 * the retention purge and the personal-data eraser. They are never archived.
 *
 * @since 6.1.0
 */
final class Store
{
	public const SCHEMA = 1;
	public const STATE = 'slimstat_heatmap_state';
	public const GENERATION = 'slimstat_heatmap_generation';
	public const CLAIM = 'slimstat_heatmap_setup';
	public const LEVELS = ['off', 'main', 'full'];

	/** Children-free order: the dictionary has no FK, slim_heatmap references slim_stats. */
	private const TABLES = ['slim_heatmap_elements', 'slim_heatmap'];

	/** Hook registration only; no option or schema reads on a storefront request. */
	public static function boot(): void
	{
		add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers'], 5);
		add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
		add_action('wp_slimstat_purge', [self::class, 'purge'], 20);
		if (is_admin()) {
			add_action('admin_init', [self::class, 'maybeSetup']);
		}
	}

	public static function table(string $suffix = 'slim_heatmap'): string
	{
		return Schema::tableName($suffix, $GLOBALS['wpdb']->prefix);
	}

	/** Configured capture level; anything unknown reads as off. */
	public static function level(): string
	{
		$level = \wp_slimstat::$settings['heatmap_capture'] ?? 'off';
		return in_array($level, self::LEVELS, true) ? $level : 'off';
	}

	/** Capture runs only with a level set and a viewer to show it (data minimisation). */
	public static function capturing(): bool
	{
		return 'off' !== self::level() && has_filter('slimstat_heatmap_row_url');
	}

	/** Both tables were created and verified at the current schema version. */
	public static function ready(): bool
	{
		$state = get_option(self::STATE, []);
		return self::SCHEMA === (int) ($state['schema'] ?? 0);
	}

	/**
	 * Create on first admin load after capture is enabled. Admins only, never from ajax,
	 * cron or REST; a recorded failure waits for the Retry action instead of re-running.
	 */
	public static function maybeSetup(): void
	{
		if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		if (!current_user_can('manage_options') || !self::capturing() || self::ready()) {
			return;
		}
		if ('' !== (string) (get_option(self::STATE, [])['error'] ?? '')) {
			return;
		}
		try {
			self::setup();
		} catch (\Throwable $error) {
			// Recorded in the state by setup(); the Heatmaps page shows it with Retry.
		}
	}

	/** Idempotent, single-flight CREATE plus verification. Capture waits for both tables. */
	public static function setup(): void
	{
		if (MigrationService::migrationsDisabled()) {
			self::failure('SLIMSTAT_DISABLE_MIGRATIONS is set, so heatmap tables were not created.');
			throw new \RuntimeException('Heatmap setup is disabled.');
		}
		$token = (string) (time() + 300);
		$old   = get_option(self::CLAIM, null);
		$won   = null === $old ? OptionClaim::insert(self::CLAIM, $token) : ((int) $old < time() && OptionClaim::compareAndSwap(self::CLAIM, (string) $old, $token));
		if (!$won) {
			throw new \RuntimeException('Heatmap setup is already running.');
		}
		try {
			$db     = Query::db();
			$prefix    = $GLOBALS['wpdb']->prefix;
			$collation = Schema::targetCollation($GLOBALS['wpdb']);
			foreach (self::TABLES as $suffix) {
				if (false === $db->query(Schema::createTableSql($suffix, $prefix, $collation))) {
					throw new \RuntimeException(sprintf('Could not create %s: %s', $prefix . $suffix, $db->last_error));
				}
				$columns = Schema::columnState($db, $suffix, $prefix);
				if (!$columns['present'] || $columns['missing'] || $columns['narrow']) {
					throw new \RuntimeException(sprintf('%s does not match the expected columns.', $prefix . $suffix));
				}
				if (!Schema::indexes($suffix)) {
					continue;
				}
				$indexes = Schema::indexState($db, $suffix, $prefix);
				if ($indexes['malformed'] || (!$indexes['present'] && !$indexes['missing'])) {
					throw new \RuntimeException(sprintf('%s has malformed indexes.', $prefix . $suffix));
				}
				foreach ($indexes['missing'] as $name) {
					if (false === $db->query(Schema::createIndexSql($suffix, $name, $prefix))) {
						throw new \RuntimeException(sprintf('Could not add index %s: %s', $name, $db->last_error));
					}
				}
			}
			$state = get_option(self::STATE, []);
			update_option(self::STATE, ['schema' => self::SCHEMA, 'since' => (int) ($state['since'] ?? \wp_slimstat::now()), 'error' => ''], false);
			self::invalidate();
		} catch (\Throwable $error) {
			self::failure($error->getMessage());
			throw $error;
		} finally {
			OptionClaim::delete(self::CLAIM, $token);
		}
	}

	/**
	 * Retention. FK cascade removes rows with their pageview; this covers installs without
	 * FKs. Rows carry their pageview's dt, so the cutoff matches the pageview purge exactly.
	 */
	public static function purge(): void
	{
		$days = (int) (\wp_slimstat::$settings['auto_purge'] ?? 0);
		if ($days <= 0 || !self::ready()) {
			return;
		}
		$db    = Query::db();
		$table = self::table();
		// ponytail: one bounded batch per purge tick; loop or reschedule if a no-FK site falls behind.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table name from the manifest; cutoff is prepared.
		$deleted = $db->query($db->prepare("DELETE FROM {$table} WHERE kind IN (0, 1) AND dt < %d LIMIT 50000", \wp_slimstat::now() - $days * DAY_IN_SECONDS));
		if (false === $deleted) {
			\wp_slimstat::record_degradation('purge (heatmap rows)', $db->last_error, \wp_slimstat::DEGRADATION_OPERATIONAL);
		} elseif ($deleted) {
			self::invalidate();
		}
	}

	/** Before SlimStat erases the parent pageviews, which are the only link to a person. */
	public static function erasers(array $erasers): array
	{
		if (!self::ready()) {
			return $erasers;
		}
		return ['slimstat-heatmap' => [
			'eraser_friendly_name' => __('SlimStat Heatmap clicks', 'wp-slimstat'),
			'callback'             => [self::class, 'erase'],
		]] + $erasers;
	}

	public static function erase($email, $page = 1): array
	{
		$db    = Query::db();
		$table = self::table();
		$stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names from the prefix; the email is prepared.
		$deleted = $db->query($db->prepare("DELETE h FROM {$table} h INNER JOIN {$stats} s ON s.id = h.id WHERE s.email = %s", $email));
		if (false === $deleted) {
			throw new \RuntimeException('Heatmap privacy erasure failed.');
		}
		if ($deleted) {
			self::invalidate();
		}
		return ['items_removed' => $deleted > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
	}

	public static function exporters(array $exporters): array
	{
		if (!self::ready()) {
			return $exporters;
		}
		$exporters['slimstat-heatmap'] = [
			'exporter_friendly_name' => __('SlimStat Heatmap clicks', 'wp-slimstat'),
			'callback'               => [self::class, 'exportPersonalData'],
		];
		return $exporters;
	}

	/** Page, date, click or scroll, and the element label. No coordinates leave as personal data. */
	public static function exportPersonalData($email, $page = 1): array
	{
		$db       = Query::db();
		$table    = self::table();
		$elements = self::table('slim_heatmap_elements');
		$stats    = $GLOBALS['wpdb']->prefix . 'slim_stats';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names from the prefix; values are prepared.
		$rows = $db->get_results($db->prepare("SELECT h.id, h.kind, h.seq, h.dt, h.y, h.dh, s.resource, e.label FROM {$table} h INNER JOIN {$stats} s ON s.id = h.id LEFT JOIN {$elements} e ON e.sel = h.sel WHERE s.email = %s ORDER BY h.id, h.kind, h.seq LIMIT 500 OFFSET %d", $email, max(0, (int) $page - 1) * 500), ARRAY_A);
		if ('' !== (string) $db->last_error || !is_array($rows)) {
			throw new \RuntimeException('Heatmap privacy export failed.');
		}
		$data = [];
		foreach ($rows as $row) {
			$scroll = 1 === (int) $row['kind'];
			$item   = [
				['name' => __('Date', 'wp-slimstat'), 'value' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['dt'])],
				['name' => __('Page', 'wp-slimstat'), 'value' => (string) $row['resource']],
				['name' => __('Type', 'wp-slimstat'), 'value' => $scroll ? __('Scroll depth', 'wp-slimstat') : __('Click', 'wp-slimstat')],
			];
			if ($scroll && (int) $row['dh'] > 0) {
				$item[] = ['name' => __('Scroll depth', 'wp-slimstat'), 'value' => min(100, (int) round(100 * (int) $row['y'] / (int) $row['dh'])) . '%'];
			} elseif (!$scroll && '' !== (string) $row['label']) {
				$item[] = ['name' => __('Element', 'wp-slimstat'), 'value' => (string) $row['label']];
			}
			$data[] = [
				'group_id'    => 'slimstat-heatmap',
				'group_label' => __('SlimStat Heatmap clicks', 'wp-slimstat'),
				'item_id'     => sprintf('heatmap-%d-%d-%d', $row['id'], $row['kind'], $row['seq']),
				'data'        => $item,
			];
		}
		return ['data' => $data, 'done' => count($rows) < 500];
	}

	/** Cache key for viewer reads; bumped on every write path that changes what they see. */
	public static function invalidate(): void
	{
		update_option(self::GENERATION, sprintf('%.6F', microtime(true)), false);
	}

	private static function failure(string $message): void
	{
		$state          = get_option(self::STATE, []);
		$state['error'] = '' !== $message ? $message : 'Heatmap setup failed.';
		update_option(self::STATE, $state, false);
	}
}

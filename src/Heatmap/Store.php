<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Heatmap;

use SlimStat\Migration\MigrationService;
use SlimStat\Schema\Schema;
use SlimStat\Schema\SurrogateKey;
use SlimStat\Tracker\Utils;
use SlimStat\Utils\Consent;
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

	/** Row flags, as the tracker sends them. */
	public const FLAG_INTERACTIVE = 1;
	public const FLAG_DEAD = 2;
	public const FLAG_RAGE = 4;

	/** Per-batch limits; the tracker stays well inside them. */
	public const MAX_BYTES = 16384;
	public const MAX_ROWS = 200;

	/** Selectors the tracker builds: #id, tag.class, :nth-of-type(n), joined by " > ". */
	private const SELECTOR = '/\A[A-Za-z0-9_\-#.:>() ]{1,255}\z/';

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
			add_action('admin_post_slimstat_heatmap_retry', [self::class, 'handleRetry']);
			add_action('admin_post_slimstat_heatmap_delete', [self::class, 'handleDelete']);
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

	/** A heatmap viewer's preview frame: never tracked, no tracker enqueued. */
	public static function isPreview(): bool
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; the viewer verifies the nonce.
		return isset($_GET['slimstat_heatmap']);
	}

	/**
	 * The tracker's hm param for this request, or null when no capture code should run.
	 * Cached HTML is per URL, so the page-list check here is safe behind page caches.
	 *
	 * @return array{l: string, r: int}|null
	 */
	public static function params(string $requestUri): ?array
	{
		$rate = max(0, min(10000, (int) (\wp_slimstat::$settings['heatmap_rate'] ?? 10000)));
		if (0 === $rate || self::isPreview() || !self::capturing() || !self::ready()) {
			return null;
		}
		$pages = trim((string) (\wp_slimstat::$settings['heatmap_pages'] ?? ''));
		if ('' !== $pages && !Utils::isBlacklisted(Query::pageKeyFromUrl($requestUri), $pages)) {
			return null;
		}
		return ['l' => self::level(), 'r' => $rate];
	}

	/**
	 * Store one capture batch for a verified pageview id. Every gate is re-checked here,
	 * because a cached page can carry a stale hm param. Page, device and date come from the
	 * server; the client only says where on the page it clicked.
	 *
	 * Batch: {vw, vh, s: [[selector, label]], r: [[seq, flags, selIdx, rx, ry, x, y]], sc: [y, dh]}.
	 *
	 * @return int Rows written (0 when the batch was refused).
	 * @throws \RuntimeException On a database error; the caller records it and the hit goes on.
	 */
	public static function ingest(int $id, string $raw): int
	{
		$level = self::level();
		$rate  = (int) (\wp_slimstat::$settings['heatmap_rate'] ?? 10000);
		if ($id <= 0 || !self::capturing() || strlen($raw) > self::MAX_BYTES || $id % 10000 >= $rate || !self::ready()) {
			return 0;
		}
		if (!Consent::canTrack() || !Consent::piiAllowed()) {
			return 0;
		}
		// Form posts arrive slashed, REST bodies do not; valid JSON is never both.
		$batch = json_decode($raw, true, 4);
		if (!is_array($batch)) {
			$batch = json_decode(wp_unslash($raw), true, 4);
		}
		if (!is_array($batch)) {
			return 0;
		}

		$db   = Query::db();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table from the manifest; id prepared.
		$view = $db->get_row($db->prepare('SELECT resource, dt FROM ' . self::table('slim_stats') . ' WHERE id = %d', $id), ARRAY_A);
		if ('' !== (string) $db->last_error) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; the caller records them as a degradation, which escapes on display.
			throw new \RuntimeException($db->last_error);
		}
		$page  = Query::pageKey((string) ($view['resource'] ?? ''));
		$pages = trim((string) (\wp_slimstat::$settings['heatmap_pages'] ?? ''));
		if ('' === $page || ('' !== $pages && !Utils::isBlacklisted($page, $pages))) {
			return 0;
		}

		$selectors = [];
		foreach (array_slice(is_array($batch['s'] ?? null) ? $batch['s'] : [], 0, self::MAX_ROWS, true) as $i => $pair) {
			$selector = is_array($pair) ? ($pair[0] ?? null) : null;
			if (is_string($selector) && preg_match(self::SELECTOR, $selector)) {
				$label         = is_string($pair[1] ?? null) ? mb_substr(sanitize_text_field($pair[1]), 0, 40) : '';
				$selectors[$i] = [SurrogateKey::hex($selector), $selector, $label];
			}
		}

		$rows     = [];
		$elements = [];
		foreach (array_slice(is_array($batch['r'] ?? null) ? $batch['r'] : [], 0, self::MAX_ROWS) as $row) {
			if (!is_array($row) || 7 !== count($row)) {
				continue;
			}
			[$seq, $flags, $index, $rx, $ry, $x, $y] = array_values($row);
			$flags = self::clamp($flags, 7);
			if ('main' === $level && !($flags & self::FLAG_INTERACTIVE)) {
				continue;
			}
			$element = is_int($index) ? ($selectors[$index] ?? null) : null;
			if ($element) {
				// Labels only from interactive elements; never text the visitor merely clicked near.
				$known = $elements[$element[0]] ?? [$element[1], ''];
				$elements[$element[0]] = [$element[1], ($flags & self::FLAG_INTERACTIVE) ? $element[2] : $known[1]];
			}
			$rows[] = [0, self::clamp($seq, self::MAX_ROWS - 1), 0, self::clamp($x, 65535), self::clamp($y, 16777215), $element[0] ?? null, self::clamp($rx, 10000), self::clamp($ry, 10000), $flags];
		}
		if ('full' === $level && is_array($batch['sc'] ?? null) && 2 === count($batch['sc'])) {
			[$y, $dh] = array_values($batch['sc']);
			$rows[]   = [1, 0, self::clamp($dh, 16777215), 0, self::clamp($y, 16777215), null, 0, 0, 0];
		}
		if (!$rows) {
			return 0;
		}

		$vw     = self::clamp($batch['vw'] ?? 0, 65535);
		$common = [$id, SurrogateKey::hex($page), (int) ($view['dt'] ?? 0), Query::DEVICE_CODES[Query::device($vw)] ?? 0, $vw, self::clamp($batch['vh'] ?? 0, 65535)];
		$values = [];
		$args   = [];
		foreach ($rows as [$kind, $seq, $dh, $x, $y, $sel, $rx, $ry, $flags]) {
			$values[] = '(%d, %d, %d, UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, ' . (null === $sel ? 'NULL' : 'UNHEX(%s)') . ', %d, %d, %d)';
			array_push($args, $common[0], $kind, $seq, ...array_slice($common, 1));
			array_push($args, $dh, $x, $y, ...(null === $sel ? [] : [$sel]));
			array_push($args, $rx, $ry, $flags);
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table from the manifest; placeholders built above, values prepared.
		if (false === $db->query($db->prepare("INSERT INTO {$table} (id, kind, seq, page, dt, device, vw, vh, dh, x, y, sel, rx, ry, flags) VALUES " . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE y = GREATEST(y, VALUES(y)), dh = GREATEST(dh, VALUES(dh))', $args))) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; the caller records them as a degradation, which escapes on display.
			throw new \RuntimeException($db->last_error);
		}

		if ($elements) {
			$args = [];
			foreach ($elements as $hex => [$selector, $label]) {
				array_push($args, $hex, $selector, $label);
			}
			$elementsTable = self::table('slim_heatmap_elements');
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Table from the manifest; values prepared.
			if (false === $db->query($db->prepare("INSERT IGNORE INTO {$elementsTable} (sel, selector, label) VALUES " . implode(', ', array_fill(0, count($elements), '(UNHEX(%s), %s, %s)')), $args))) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; the caller records them as a degradation, which escapes on display.
				throw new \RuntimeException($db->last_error);
			}
		}
		return count($rows);
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
		$stats = self::table('slim_stats');
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
		$stats    = self::table('slim_stats');
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

	/** Retry on the Heatmaps status card: forget the recorded failure and set up again. */
	public static function handleRetry(): void
	{
		check_admin_referer('slimstat_heatmap_retry');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to change heatmap tracking.', 'wp-slimstat'), 403);
		}
		$state = get_option(self::STATE, []);
		unset($state['error']);
		update_option(self::STATE, $state, false);
		try {
			self::setup();
		} catch (\Throwable $error) {
			// Recorded in the state; the status card shows it again.
		}
		self::backToList();
	}

	/** "Delete heatmap data": every full-tracking row. Link and button clicks in slim_events stay. */
	public static function handleDelete(): void
	{
		check_admin_referer('slimstat_heatmap_delete');
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to delete heatmap data.', 'wp-slimstat'), 403);
		}
		$deleted = self::ready() ? self::deleteAll() : true;
		self::backToList(['deleted' => $deleted ? '1' : '0']);
	}

	public static function deleteAll(): bool
	{
		$db = Query::db();
		foreach (array_reverse(self::TABLES) as $suffix) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name from the manifest; TRUNCATE of SlimStat's own on-demand table.
			if (false === $db->query('TRUNCATE TABLE ' . self::table($suffix))) {
				\wp_slimstat::record_degradation('heatmap delete', $db->last_error, \wp_slimstat::DEGRADATION_OPERATIONAL);
				return false;
			}
		}
		self::invalidate();
		return true;
	}

	private static function backToList(array $args = []): void
	{
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=slimheatmap')));
		exit;
	}

	/** Cache key for viewer reads; bumped on every write path that changes what they see. */
	public static function invalidate(): void
	{
		update_option(self::GENERATION, sprintf('%.6F', microtime(true)), false);
	}

	/** Untrusted number to an integer in 0..max; anything else is 0. */
	private static function clamp($value, int $max): int
	{
		return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? max(0, min($max, (int) $value)) : 0;
	}

	private static function failure(string $message): void
	{
		$state          = get_option(self::STATE, []);
		$state['error'] = '' !== $message ? $message : 'Heatmap setup failed.';
		update_option(self::STATE, $state, false);
	}
}

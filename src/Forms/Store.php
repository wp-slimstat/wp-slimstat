<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;

use SlimStat\Migration\MigrationService;
use SlimStat\Schema\Schema;
use SlimStat\Tracker\Processor;
use SlimStat\Tracker\Storage;
use SlimStat\Tracker\Acquisition;
use SlimStat\Tracker\Utils;
use SlimStat\Utils\Consent;
use SlimStat\Utils\OptionClaim;

/** Bounded form-session summaries and atomic daily counter changes. */
final class Store {
	public const STATE     = 'slimstat_forms_state';
	public const VERSION   = 1;
	public const MAX_BYTES = 8192;
	public const MAX_FORMS = 20;

	/** Register lifecycle hooks without reading options on ordinary pages. */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'maybeSetup' ) );
		add_action( 'admin_post_slimstat_forms_toggle', array( self::class, 'toggle' ) );
		add_action( 'admin_post_slimstat_forms_retry', array( self::class, 'retry' ) );
		add_action( 'wp_slimstat_purge', array( self::class, 'purge' ), 20 );
		add_action( 'slimstat_forms_purge', array( self::class, 'purge' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ), 4 );
	}

	/** Use the same optional external analytics database as the tracker. */
	public static function db(): \wpdb {
		return apply_filters( 'slimstat_custom_wpdb', $GLOBALS['wpdb'] );
	}

	/** Stable per-site names on the configured analytics database. */
	public static function table( string $suffix = 'slim_form_sessions' ): string {
		return Schema::tableName( $suffix, $GLOBALS['wpdb']->prefix );
	}

	/** No setup required. Pause affects future collection, not retained reports. */
	public static function enabled(): bool {
		return 'off' !== get_option( 'slimstat_forms_enabled', 'on' );
	}

	/** One explicit setting; reuse SlimStat privacy and retention configuration. */
	public static function toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure Form Analytics.', 'wp-slimstat' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'slimstat_forms_toggle' );
		$enabled = isset( $_POST['enabled'] ) && 'on' === $_POST['enabled'];
		update_option( 'slimstat_forms_enabled', $enabled ? 'on' : 'off', false );
		wp_safe_redirect( admin_url( 'admin.php?page=slimforms&tab=setup&saved=1' ) );
		exit;
	}

	/** Called only on eligible forms, form payloads or admin/report requests. */
	public static function ready(): bool {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) && self::VERSION === ( $state['version'] ?? 0 )
			&& ( ! isset( $state['database'] ) || Acquisition::readinessKey() === $state['database'] );
	}

	/** Do not create tables on storefront, tracking, cron or background form requests. */
	public static function maybeSetup(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) || ! Registry::active() || ! self::enabled() || self::ready() ) {
			return;
		}
		$state = get_option( self::STATE, array() );
		if ( ! empty( $state['error'] ) ) {
			return;
		}
		self::setup();
	}

	/** Idempotent on-demand CREATE, never ALTER or scan historical visits. */
	public static function setup(): bool {
		if ( MigrationService::migrationsDisabled() ) {
			return false;
		}
		$claim = OptionClaim::acquire( 'slimstat_forms_setup', 300 );
		if ( null === $claim ) {
			return false;
		}
		$state = get_option( self::STATE, array() );
		$since = (int) ( $state['since'] ?? \wp_slimstat::now() );
		try {
			$db = Store::db();
			foreach ( array( 'slim_form_sessions', 'slim_form_daily' ) as $suffix ) {
				if ( false === $db->query( Schema::createTableSql( $suffix, $GLOBALS['wpdb']->prefix, Schema::targetCollation( $GLOBALS['wpdb'] ) ) ) ) {
					throw new \RuntimeException( 'Form Analytics table creation failed.' );
				}
				if ( 'innodb' !== strtolower( Schema::tableEngine( $db, self::table( $suffix ) ) ) ) {
					throw new \RuntimeException( 'Form Analytics requires transactional tables.' );
				}
				$columns = Schema::columnState( $db, $suffix, $GLOBALS['wpdb']->prefix );
				$indexes = Schema::indexState( $db, $suffix, $GLOBALS['wpdb']->prefix );
				if ( ! $columns['present'] || $columns['missing'] || $columns['narrow'] || $indexes['missing'] || $indexes['malformed'] ) {
					throw new \RuntimeException( 'Form Analytics schema verification failed.' );
				}
			}
			foreach ( array( 'slim_stats', 'slim_events' ) as $suffix ) {
				if ( 'innodb' !== strtolower( Schema::tableEngine( $db, self::table( $suffix ) ) ) ) {
					throw new \RuntimeException( 'The existing analytics tables must support transactions.' );
				}
			}
			// CREATE IF NOT EXISTS cannot repair an interrupted or manually changed FK.
			$foreign_key = $db->get_row(
				$db->prepare(
					'SELECT r.DELETE_RULE, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS r INNER JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME = %s AND k.COLUMN_NAME = %s',
					self::table(),
					'stat_id'
				),
				ARRAY_A
			);
			if ( ! is_array( $foreign_key ) || 'CASCADE' !== $foreign_key['DELETE_RULE'] || self::table( 'slim_stats' ) !== $foreign_key['REFERENCED_TABLE_NAME'] || 'id' !== $foreign_key['REFERENCED_COLUMN_NAME'] ) {
				throw new \RuntimeException( 'Form Analytics retention constraint is unavailable.' );
			}
			update_option(
				self::STATE,
				array(
					'version'  => self::VERSION,
					'since'    => $since,
					'database' => Acquisition::readinessKey(),
				),
				false
			);
			return true;
		} catch ( \Throwable $error ) {
			update_option(
				self::STATE,
				array(
					'error' => true,
					'since' => $since,
				),
				false
			);
			return false;
		} finally {
			OptionClaim::delete( 'slimstat_forms_setup', $claim );
		}
	}

	/** An explicit retry needs both WordPress authorization and CSRF protection. */
	public static function retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot configure Form Analytics.', 'wp-slimstat' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'slimstat_forms_retry' );
		self::setup();
		wp_safe_redirect( admin_url( 'admin.php?page=slimforms' ) );
		exit;
	}

	/** Identified form analytics never treat anonymous mode as consent. */
	public static function allowed(): bool {
		if ( ! self::enabled() || 'on' !== ( \wp_slimstat::$settings['is_tracking'] ?? 'on' ) || \wp_slimstat::$is_programmatic_tracking
			|| ! Consent::canTrack() || ! Consent::piiAllowed() || Processor::isUserExcluded() ) {
			return false;
		}
		list( $ip, $other_ip ) = Utils::getRemoteIp();
		return ! Processor::isIpExcluded( $ip, $other_ip );
	}

	/** Resolve a retained page and current source context, without accepting posted dimensions. */
	private static function page( int $id ): ?array {
		$db      = Store::db();
		$table   = self::table( 'slim_stats' );
		$now     = \wp_slimstat::now();
		$channel = '1' === get_option( Acquisition::readinessKey(), '0' ) ? 'traffic_channel' : "'' AS traffic_channel";
		$row     = $db->get_row( $db->prepare( "SELECT id, visit_id, dt, resource, referer, browser, browser_type, platform, country, content_type, {$channel} FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) || (int) $row['dt'] < $now - max( 60, (int) ( \wp_slimstat::$settings['session_duration'] ?? 1800 ) ) || (int) $row['dt'] > $now
			|| ! in_array( (int) $row['browser_type'], array( 0, 2 ), true ) ) {
			return null;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! is_string( $ref ) || wp_parse_url( $ref, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST )
			|| wp_parse_url( $ref, PHP_URL_PORT ) !== wp_parse_url( home_url(), PHP_URL_PORT )
			|| wp_parse_url( Processor::sanitizeResource( $ref ), PHP_URL_PATH ) !== wp_parse_url( $row['resource'], PHP_URL_PATH ) ) {
			return null;
		}
		// Plain permalinks share a path. Compare routing IDs without retaining query values.
		$ref_query  = array();
		$page_query = array();
		wp_parse_str( (string) wp_parse_url( $ref, PHP_URL_QUERY ), $ref_query );
		wp_parse_str( (string) wp_parse_url( $row['resource'], PHP_URL_QUERY ), $page_query );
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $route ) {
			if ( ( $ref_query[ $route ] ?? null ) !== ( $page_query[ $route ] ?? null ) ) {
				return null;
			}
		}
		foreach ( array(
			'resource'     => 'ignore_resources',
			'referer'      => 'ignore_referers',
			'browser'      => 'ignore_browsers',
			'platform'     => 'ignore_platforms',
			'country'      => 'ignore_countries',
			'content_type' => 'ignore_content_types',
		) as $column => $setting ) {
			if ( Utils::isBlacklisted( $row[ $column ], \wp_slimstat::$settings[ $setting ] ?? '' ) ) {
				return null;
			}
		}
		return $row;
	}

	/** Browser batches cannot contain a confirmed submission, spam or failure bit. */
	public static function ingest( int $id, string $raw ): int {
		if ( strlen( $raw ) > self::MAX_BYTES || ! self::allowed() || ! self::ready() ) {
			return 0;
		}
		$batch = json_decode( $raw, true, 8 );
		if ( ! is_array( $batch ) ) {
			$batch = json_decode( wp_unslash( $raw ), true, 8 );
		}
		if ( ! is_array( $batch ) || 1 !== ( $batch['v'] ?? null ) || ! is_array( $batch['forms'] ?? null ) || count( $batch['forms'] ) > self::MAX_FORMS ) {
			return 0;
		}
		$page = self::page( $id );
		if ( ! $page ) {
			return 0;
		}
		$count = 0;
		foreach ( $batch['forms'] as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['flags'] ?? null ) || $item['flags'] < 1 || $item['flags'] > 15 ) {
				continue;
			}
			$key = Integration::verify( $item['token'] ?? null );
			if ( '' !== $key ) {
				self::change( $key, $page, $item['flags'], $item['fields'] ?? null );
				++$count;
			}
		}
		return $count;
	}

	/** Server confirmation requires an explicit signed page; never guess from the latest visit. */
	public static function confirm( string $key, string $outcome, int $id ): void {
		$bits = array(
			'submitted' => 64,
			'invalid'   => 8,
			'spam'      => 16,
			'failed'    => 32,
		);
		if ( ! isset( $bits[ $outcome ] ) || ! self::allowed() ) {
			return;
		}
		$page = $id ? self::page( $id ) : null;
		if ( $page ) {
			self::change( $key, $page, $bits[ $outcome ] );
		}
	}

	/** Serialize one cohort and its aggregate delta in the same InnoDB transaction. */
	private static function change( string $key, array $page, int $flags, $fields = null ): void {
		$db      = Store::db();
		$table   = self::table();
		$now     = \wp_slimstat::now();
		$visit   = (int) $page['visit_id'];
		$cohort  = $visit > 0 ? 'v' . $visit : 'p' . (int) $page['id'];
		$device  = 2 === (int) $page['browser_type'] ? 1 : 0;
		$day     = (int) ( floor( (int) $page['dt'] / DAY_IN_SECONDS ) * DAY_IN_SECONDS );
		$channel = isset( Acquisition::labels()[ $page['traffic_channel'] ] ) ? $page['traffic_channel'] : 'other';
		// This statement fails inside an existing transaction, without committing it.
		// Never let optional analytics commit a form builder's unfinished work.
		$previous  = $db->suppress_errors( true );
		$can_start = $db->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		$db->suppress_errors( $previous );
		if ( false === $can_start || false === $db->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Form Analytics transaction unavailable.' );
		}
		try {
			self::checked( $db->query( $db->prepare( "INSERT IGNORE INTO {$table} (form, cohort, stat_id, visit_id, dt, updated, day, device, channel, fields) VALUES (%s,%s,%d,%d,%d,%d,%d,%d,%s,'')", $key, $cohort, $page['id'], $visit, $page['dt'], $now, $day, $device, $channel ) ) );
			$old = $db->get_row( $db->prepare( "SELECT form, cohort, stat_id, day, device, channel, flags, started_at, submitted_at, duration, fields FROM {$table} WHERE form = %s AND cohort = %s FOR UPDATE", $key, $cohort ), ARRAY_A );
			if ( ! is_array( $old ) ) {
				throw new \RuntimeException( 'Form Analytics cohort unavailable.' );
			}
			$new           = $old;
			$new['flags']  = (int) $old['flags'] | $flags;
			$new['fields'] = apply_filters( 'slimstat_forms_field_summary', $old['fields'], $fields, (int) $page['id'] );
			if ( ! is_string( $new['fields'] ) || strlen( $new['fields'] ) > 4096 ) {
				$new['fields'] = $old['fields'];
			}
			if ( $new['flags'] === (int) $old['flags'] && $new['fields'] === $old['fields'] ) {
				self::checked( $db->query( 'COMMIT' ) );
				return;
			}
			if ( ( $flags & Metrics::STARTED ) && ! (int) $old['started_at'] ) {
				$new['started_at'] = $now;
			}
			if ( ( $flags & Metrics::SUBMITTED ) && ! (int) $old['submitted_at'] ) {
				$new['submitted_at'] = $now;
			}
			$new['duration'] = $new['started_at'] && $new['submitted_at'] ? max( 0, min( DAY_IN_SECONDS, $new['submitted_at'] - $new['started_at'] ) ) : 0;
			self::checked( $db->query( $db->prepare( "UPDATE {$table} SET flags = %d, started_at = %d, submitted_at = %d, duration = %d, updated = %d, fields = %s WHERE form = %s AND cohort = %s", $new['flags'], $new['started_at'], $new['submitted_at'], $new['duration'], $now, $new['fields'], $key, $cohort ) ) );
			if ( $new['flags'] !== (int) $old['flags'] ) {
				self::aggregate( $new, Metrics::counters( $old ), Metrics::counters( $new ) );
			}
			// Keep legacy attempt Goals unchanged. Confirmed forms use a distinct event name.
			if ( ( $new['flags'] & Metrics::SUBMITTED ) && ! ( (int) $old['flags'] & Metrics::SUBMITTED ) ) {
				$event = array(
					'id'    => (int) $page['id'],
					'dt'    => $now,
					'notes' => '[type:form_complete][form:' . $key . ']',
				);
				if ( apply_filters( 'slimstat_track_event_enabled', true, $event ) && ! Storage::insertRow( $event, self::table( 'slim_events' ) )->isStored() ) {
					throw new \RuntimeException( 'Form completion event could not be stored.' );
				}
			}
			/** Fired inside the transaction; premium summaries must be atomic too. */
			do_action( 'slimstat_forms_state_changed', $old, $new, $page );
			self::checked( $db->query( 'COMMIT' ) );
		} catch ( \Throwable $error ) {
			$db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** Apply one bounded aggregate delta; column names come only from Metrics. */
	private static function aggregate( array $row, array $before, array $after ): void {
		$db      = Store::db();
		$table   = self::table( 'slim_form_daily' );
		$columns = array_keys( Metrics::counters( array() ) );
		$delta   = array();
		$updates = array();
		foreach ( $columns as $column ) {
			$delta[]   = $after[ $column ] - $before[ $column ];
			$updates[] = $column . ' = ' . $column . ' + VALUES(' . $column . ')';
		}
		$sql = "INSERT INTO {$table} (day, form, device, channel," . implode( ',', $columns ) . ') VALUES (%d,%s,%d,%s,' . implode( ',', array_fill( 0, count( $columns ), '%d' ) ) . ') ON DUPLICATE KEY UPDATE ' . implode( ',', $updates );
		self::checked( $db->query( $db->prepare( $sql, array_merge( array( $row['day'], $row['form'], $row['device'], $row['channel'] ), $delta ) ) ) );
	}

	/** Never turn a failed write into an acknowledged metric. */
	private static function checked( $result ): void {
		if ( false === $result ) {
			throw new \RuntimeException( 'Form Analytics write failed.' );
		}
	}

	/** Whole-day reporting boundary also protects reads while purge work is pending. */
	public static function retentionStart(): int {
		$days = (int) ( \wp_slimstat::$settings['auto_purge'] ?? 0 );
		return $days > 0 ? (int) floor( ( \wp_slimstat::now() - $days * DAY_IN_SECONDS ) / DAY_IN_SECONDS ) * DAY_IN_SECONDS : 0;
	}

	/** Aggregate retention follows SlimStat; state follows pageviews through its FK. */
	public static function purge(): void {
		if ( ! self::ready() ) {
			return;
		}
		$days = (int) ( \wp_slimstat::$settings['auto_purge'] ?? 0 );
		if ( $days < 1 ) {
			return;
		}
		$db      = Store::db();
		$table   = self::table( 'slim_form_daily' );
		$deleted = $db->query( $db->prepare( "DELETE FROM {$table} WHERE day < %d LIMIT 1000", self::retentionStart() ) );
		if ( false === $deleted ) {
			\wp_slimstat::record_degradation( 'purge (form analytics)', 'Expired form analytics totals could not be deleted.', \wp_slimstat::DEGRADATION_OPERATIONAL );
			return;
		}
		if ( $deleted > 0 ) {
			\wp_slimstat::bump_data_version();
		}
		if ( 1000 === $deleted && ! wp_next_scheduled( 'slimstat_forms_purge' ) ) {
			wp_schedule_single_event( time() + 60, 'slimstat_forms_purge' );
		}
	}

	/** Register export even after the builder is deactivated. */
	public static function exporters( array $exporters ): array {
		if ( self::ready() ) {
			$exporters['slimstat-forms'] = array(
				'exporter_friendly_name' => __( 'SlimStat Form Analytics', 'wp-slimstat' ),
				'callback'               => array( self::class, 'export' ),
			);
		}
		return $exporters;
	}

	/** Register before the core eraser removes the pageview-to-person association. */
	public static function erasers( array $erasers ): array {
		if ( self::ready() ) {
			return array(
				'slimstat-forms' => array(
					'eraser_friendly_name' => __( 'SlimStat Form Analytics', 'wp-slimstat' ),
					'callback'             => array( self::class, 'erase' ),
				),
			) + $erasers;
		}
		return $erasers;
	}

	/** Export only structural activity, never submitted form data. */
	public static function export( $email, $page = 1 ): array {
		$db    = Store::db();
		$table = self::table();
		$stats = self::table( 'slim_stats' );
		$rows  = $db->get_results( $db->prepare( "SELECT f.form, f.cohort, f.dt, f.updated, f.device, f.channel, f.flags, f.started_at, f.submitted_at, f.duration, f.fields FROM {$table} f INNER JOIN {$stats} s ON s.id = f.stat_id WHERE s.email = %s ORDER BY f.form, f.cohort LIMIT 500 OFFSET %d", $email, max( 0, (int) $page - 1 ) * 500 ), ARRAY_A );
		if ( ! is_array( $rows ) || $db->last_error ) {
			throw new \RuntimeException( 'Form Analytics export failed.' );
		}
		$data = array();
		foreach ( $rows as $row ) {
			$details = array(
				array(
					'name'  => __( 'Form', 'wp-slimstat' ),
					'value' => $row['form'],
				),
				array(
					'name'  => __( 'Date', 'wp-slimstat' ),
					'value' => gmdate( 'Y-m-d H:i:s', (int) $row['dt'] ),
				),
				array(
					'name'  => __( 'Last activity', 'wp-slimstat' ),
					'value' => gmdate( 'Y-m-d H:i:s', (int) $row['updated'] ),
				),
				array(
					'name'  => __( 'Device', 'wp-slimstat' ),
					'value' => 1 === (int) $row['device'] ? __( 'Mobile', 'wp-slimstat' ) : __( 'Desktop', 'wp-slimstat' ),
				),
				array(
					'name'  => __( 'Traffic channel', 'wp-slimstat' ),
					'value' => $row['channel'],
				),
				array(
					'name'  => __( 'Completion time (seconds)', 'wp-slimstat' ),
					'value' => (string) $row['duration'],
				),
			);
			foreach ( array(
				Metrics::LOADED    => __( 'Loaded', 'wp-slimstat' ),
				Metrics::SEEN      => __( 'Seen', 'wp-slimstat' ),
				Metrics::STARTED   => __( 'Started', 'wp-slimstat' ),
				Metrics::SUBMITTED => __( 'Completed', 'wp-slimstat' ),
				Metrics::INVALID   => __( 'Validation error observed', 'wp-slimstat' ),
				Metrics::SPAM      => __( 'Marked as spam', 'wp-slimstat' ),
				Metrics::FAILED    => __( 'Processing failure observed', 'wp-slimstat' ),
			) as $bit => $label ) {
				$details[] = array(
					'name'  => $label,
					'value' => ( (int) $row['flags'] & $bit ) ? __( 'Yes', 'wp-slimstat' ) : __( 'No', 'wp-slimstat' ),
				);
			}
			foreach ( array(
				'started_at'   => __( 'Start time', 'wp-slimstat' ),
				'submitted_at' => __( 'Confirmation time', 'wp-slimstat' ),
			) as $column => $label ) {
				if ( (int) $row[ $column ] ) {
					$details[] = array(
						'name'  => $label,
						'value' => gmdate( 'Y-m-d H:i:s', (int) $row[ $column ] ),
					); }
			}
			if ( $row['fields'] ) {
				$details[] = array(
					'name'  => __( 'Field interaction metadata', 'wp-slimstat' ),
					'value' => $row['fields'],
				); }
			$data[] = array(
				'group_id'    => 'slimstat-forms',
				'group_label' => __( 'Form Analytics', 'wp-slimstat' ),
				'item_id'     => $row['form'] . '-' . $row['cohort'],
				'data'        => $details,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 500,
		);
	}

	/** Delete attributable state; coarse daily totals contain no visitor identifiers. */
	public static function erase( $email, $page = 1 ): array {
		$db    = Store::db();
		$table = self::table();
		$stats = self::table( 'slim_stats' );
		$keys  = $db->get_results( $db->prepare( "SELECT f.form, f.cohort FROM {$table} f INNER JOIN {$stats} s ON s.id = f.stat_id WHERE s.email = %s LIMIT 500", $email ), ARRAY_A );
		if ( ! is_array( $keys ) || $db->last_error ) {
			throw new \RuntimeException( 'Form Analytics erasure read failed.' );
		}
		if ( $keys ) {
			$conditions = array();
			foreach ( $keys as $key ) {
				$conditions[] = $db->prepare( '(form = %s AND cohort = %s)', $key['form'], $key['cohort'] );
			}
			self::checked( $db->query( "DELETE FROM {$table} WHERE " . implode( ' OR ', $conditions ) ) );
			\wp_slimstat::bump_data_version();
		}
		return array(
			'items_removed'  => ! empty( $keys ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $keys ) < 500,
		);
	}
}

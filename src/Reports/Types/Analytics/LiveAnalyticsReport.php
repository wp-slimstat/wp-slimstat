<?php
/**
 * Live Analytics Report
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Types\Analytics;

use SlimStat\Reports\Abstracts\AbstractReport;
use SlimStat\Reports\Contracts\ReportInterface;
use SlimStat\Reports\Contracts\RenderableInterface;
use SlimStat\Reports\Traits\HasTooltip;
use SlimStat\Utils\Query;

/**
 * Class LiveAnalyticsReport
 *
 * Displays live analytics with real-time updates every minute.
 *
 * This report is fully self-contained:
 * - Handles its own AJAX endpoints
 * - Enqueues its own assets (CSS/JS)
 * - Manages all WordPress hooks
 *
 * Use this as a template for creating new reports.
 */
class LiveAnalyticsReport extends AbstractReport implements ReportInterface, RenderableInterface {
	use HasTooltip;

	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		$this->id        = 'slim_live_analytics';
		$this->title     = __( 'Live Analytics', 'wp-slimstat' );
		$this->locations = [ 'slimview1' ];
		$this->classes   = [ 'full-width', 'live-analytics', 'realtime-report' ];
		$this->tooltip   = $this->build_tooltip(
			__( 'Live Analytics', 'wp-slimstat' ),
			__( 'Real-time analytics with second-level accuracy showing current user activity and trends.', 'wp-slimstat' ),
			[
				__( '• Users Live: Unique sessions active within the last 30 minutes', 'wp-slimstat' ),
				__( '• Counters use the latest dt/dt_out data so long reads remain “online” until they go idle', 'wp-slimstat' ),
				__( '• Deferred dt_out updates backfill past minutes so long reads remain visible on the chart', 'wp-slimstat' ),
				__( '• Chart shows exact user count for each minute of the last 30 minutes', 'wp-slimstat' ),
				__( '• Pages Live: Unique pages viewed in the last 30 minutes', 'wp-slimstat' ),
				__( '• Countries Live: Number of countries with active users in the last 30 minutes', 'wp-slimstat' ),
				__( '• Data refreshes every 10 seconds with a short-lived cache for stability', 'wp-slimstat' ),
				__( '• Red bars highlight peak activity periods', 'wp-slimstat' ),
			]
		);

		$this->postbox_config = [
			'hide_header'   => true,
			'hide_padding'  => true,
			'custom_height' => '321px',
			'full_width'    => true,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_data(): array {
		// Get the selected metric from request or default to 'users'
		$selected_metric = sanitize_text_field( $_GET['metric'] ?? $_POST['metric'] ?? 'users' );

		// Validate metric
		if ( ! in_array( $selected_metric, [ 'users', 'pages', 'countries' ], true ) ) {
			$selected_metric = 'users';
		}

		$chart_data = $this->get_chart_data_for_metric( $selected_metric );
		$live_counts = $this->get_all_live_counts();

		return [
			'users_live'              => $live_counts['users'] ?? 0,
			'pages_live'              => $live_counts['pages'] ?? 0,
			'countries_live'          => $live_counts['countries'] ?? 0,
			'active_users_per_minute' => $chart_data,
			'selected_metric'         => $selected_metric,
			'last_updated'            => current_time( 'timestamp' ),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_renderer(): string {
		return 'live-analytics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_callback_args(): array {
		return [
			'id'               => $this->get_id(),
			'data'             => $this->get_data(),
			'auto_refresh'     => true,
			'refresh_interval' => 30000, // 30 seconds
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_priority(): int {
		return 1;
	}

	/**
	 * Get the timestamp for 30 minutes ago
	 *
	 * @return int
	 */
	private function get_30min_threshold(): int {
		return current_time( 'timestamp' ) - ( 30 * 60 );
	}

	/**
	 * Get all live counts in a single optimized query
	 * Combines users, pages, and countries counts into one query for better performance
	 *
	 * @return array{users: int, pages: int, countries: int}
	 */
	private function get_all_live_counts(): array {
		global $wpdb;

		if ( ! $this->is_tracking_enabled() ) {
			return [
				'users'     => 0,
				'pages'     => 0,
				'countries' => 0,
			];
		}

		$users_count = $this->get_sessions_count_within_window( 30 * 60 );
		$now         = current_time( 'timestamp' );

		// 2) pages and countries in last 30 minutes (unique)
		$threshold_30 = $now - ( 30 * 60 );
		$row          = Query::select( "COUNT(DISTINCT NULLIF(resource,'')) AS pages_count, COUNT(DISTINCT NULLIF(country,'')) AS countries_count" )
			->from( "{$wpdb->prefix}slim_stats" )
			->where( 'dt', '>=', $threshold_30 )
			->allowCaching( true, 1 )
			->getRow();

		return [
			'users'     => $users_count,
			'pages'     => (int) ( $row->pages_count ?? 0 ),
			'countries' => (int) ( $row->countries_count ?? 0 ),
		];
	}

	/**
	 * Count sessions that had activity within the provided time window.
	 *
	 * @param int $window_seconds Number of seconds to look back.
	 * @return int
	 */
	private function get_sessions_count_within_window( int $window_seconds ): int {
		global $wpdb;

		$window_seconds = max( 60, $window_seconds );
		$table          = "{$wpdb->prefix}slim_stats";

		$current_minute_start = (int) floor( current_time( 'timestamp' ) / 60 ) * 60;
		$window_minutes       = (int) ceil( $window_seconds / 60 );
		if ( $window_minutes < 1 ) {
			$window_minutes = 1;
		}
		$window_start = $current_minute_start - ( ( $window_minutes - 1 ) * 60 );

		$sql = $wpdb->prepare(
			"
			SELECT COUNT(*) FROM (
				SELECT visit_id, MAX(
					CASE
						WHEN dt_out IS NOT NULL AND dt_out > 0 AND dt_out >= dt THEN dt_out
						ELSE dt
					END
				) AS last_activity
				FROM {$table}
				WHERE visit_id > 0
					AND (
						dt >= %d
						OR ( dt_out IS NOT NULL AND dt_out >= %d )
					)
				GROUP BY visit_id
				HAVING (FLOOR(last_activity / 60) * 60 + 59) >= %d
			) live_sessions
			",
			$window_start,
			$window_start,
			$window_start
		);

		$count = (int) $wpdb->get_var( $sql );

		return $count > 0 ? $count : 0;
	}

	/**
	 * Generate chart labels for 30-minute window
	 *
	 * @return array<string>
	 */
	private function generate_chart_labels(): array {
		$labels = [];
		// Labels for the last 30 minutes including the current minute. Index 29 -> -29 Min, index 0 -> Now
		$marked_minutes = [ 29 => '-29 Min', 25 => '-25 Min', 20 => '-20 Min', 15 => '-15 Min', 10 => '-10 Min', 5 => '-5 Min', 0 => 'Now' ];

		for ( $i = 29; $i >= 0; $i-- ) {
			$labels[] = $marked_minutes[ $i ] ?? '';
		}

		return $labels;
	}

	/**
	 * Get chart data for the selected metric (Users, Pages, or Countries)
	 *
	 * @param string $metric The metric to get data for ('users', 'pages', 'countries')
	 * @return array
	 */
	private function get_chart_data_for_metric( string $metric = 'users' ): array {
		global $wpdb;

		if ( ! $this->is_tracking_enabled() ) {
			return $this->get_empty_chart_data();
		}

		// Use Query class cache - no need for separate transient cache
		if ( 'users' === $metric ) {
			$chart_data = $this->get_users_chart_data();
		} else {
			// Align pages/countries metric to the same window as users: last 30 minutes including current minute
			$now = current_time( 'timestamp' );
			$end_minute = (int) floor( $now / 60 ) * 60; // start of current minute
			$start_minute = $end_minute - ( 29 * 60 );

			$field     = 'pages' === $metric ? 'resource' : 'country';
			$condition = 'pages' === $metric ? "resource IS NOT NULL AND resource != ''" : "country IS NOT NULL AND country != ''";

			// Use Query class for secure and cached queries
			$minuteExpr = 'FLOOR(dt / 60) * 60';
			$results = Query::select( "{$minuteExpr} as minute_timestamp, COUNT(DISTINCT {$field}) as count" )
				->from( "{$wpdb->prefix}slim_stats" )
				->where( 'dt', '>=', $start_minute )
					->whereRaw( $condition )
					// Use the select alias in GROUP BY and ORDER BY for MySQL 5.7 compatibility
					->groupBy( 'minute_timestamp' )
					->orderBy( 'minute_timestamp ASC' )
					->allowCaching( true, 1 )
				->getAll();

			$chart_data = $this->format_chart_data( $results );
		}

		return $chart_data;
	}

	/**
	 * Get users chart data
	 * Counts ONLINE sessions - sessions that are still active (not expired) at each minute
	 *
	 * A session is considered online at a specific minute if:
	 * 1. The session has started (first_activity <= minute)
	 * 2. The session has not expired (last_activity + session_duration >= minute)
	 *
	 * Note: This method uses wpdb directly for complex JOINs that Query class doesn't support yet.
	 * Manual caching via transient is appropriate here since we can't use Query class cache.
	 *
	 * @return array Chart data
	 */
	private function get_users_chart_data(): array {
		global $wpdb;

		if ( ! $this->is_tracking_enabled() ) {
			$empty = $this->get_empty_chart_data();
			$empty['cache_time'] = current_time( 'timestamp' );

			return $empty;
		}

		$cache_key = 'slimstat_chart_data_users_' . get_current_blog_id();
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$cache_time = $cached['cache_time'] ?? 0;
			$now        = current_time( 'timestamp' );
			$seconds    = (int) date( 's', $now );

			// Cache is valid only if we're NOT at :00 or :30 of the minute
			// This aligns with the JS update schedule
			if ( 0 !== $seconds && 30 !== $seconds ) {
				// Also check if cache is from the same minute (to avoid stale data)
				$cache_minute = (int) floor( $cache_time / 60 );
				$current_minute = (int) floor( $now / 60 );
				if ( $cache_minute === $current_minute ) {
					return $cached;
				}
			}
		}

		$bucket_count = 30;
		$bucket_size  = 60;
		$now          = current_time( 'timestamp' );
		$end_minute   = (int) floor( $now / $bucket_size ) * $bucket_size;
		$start_minute = $end_minute - ( ( $bucket_count - 1 ) * $bucket_size );
		$window_start = $start_minute;
		$window_end   = $end_minute + ( $bucket_size - 1 );
		$table        = "{$wpdb->prefix}slim_stats";

		$numbers = [];
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$numbers[] = 'SELECT ' . $i . ' AS n';
		}
		$numbers_union = implode( ' UNION ALL ', $numbers );

		$sql = $wpdb->prepare(
			"
			SELECT m.minute_ts AS minute_timestamp, COUNT(DISTINCT s.visit_id) AS cnt
			FROM (
				SELECT %d + (n.n * 60) AS minute_ts
				FROM (
					{$numbers_union}
				) n
			) m
			LEFT JOIN (
				SELECT visit_id,
					FLOOR( MIN(dt) / 60 ) * 60 AS first_minute,
					FLOOR( MAX(
						CASE
							WHEN dt_out IS NOT NULL AND dt_out > 0 AND dt_out >= dt THEN dt_out
							ELSE dt
						END
					) / 60 ) * 60 AS last_minute
				FROM {$table}
				WHERE visit_id > 0
					AND dt <= %d
					AND (
						dt >= %d
						OR ( dt_out IS NOT NULL AND dt_out > 0 AND dt_out >= %d )
					)
				GROUP BY visit_id
				HAVING last_minute >= %d
			) s ON s.first_minute <= ( m.minute_ts + 59 ) AND s.last_minute >= m.minute_ts
			GROUP BY m.minute_ts
			ORDER BY m.minute_ts ASC
			",
			$start_minute,
			$window_end,
			$window_start,
			$window_start,
			$window_start
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );
		$lookup  = [];
		foreach ( (array) $results as $row ) {
			$minute_timestamp = (int) ( $row['minute_timestamp'] ?? 0 );
			$lookup[ $minute_timestamp ] = (int) ( $row['cnt'] ?? 0 );
		}

		$data = [];
		$max  = 0;
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$ts  = $start_minute + ( $i * $bucket_size );
			$cnt = $lookup[ $ts ] ?? 0;
			$data[] = $cnt;
			if ( $cnt > $max ) {
				$max = $cnt;
			}
		}

		$formatted = [
			'labels'     => $this->generate_chart_labels(),
			'data'       => $data,
			'max_value'  => max( 1, $max ),
			'peak_index' => $this->find_peak_index( $data ),
			'cache_time' => $now,
		];

		// Cache TTL: 60 seconds to align with update schedule (:00 and :30 of each minute)
		$ttl = (int) apply_filters( 'slimstat_live_users_cache_ttl', 60 );
		set_transient( $cache_key, $formatted, $ttl );

		return $formatted;
	}


	/**
	 * Format chart data from database results
	 * Optimized to minimize iterations and memory usage
	 *
	 * @param array|object $data_source Database results or pre-grouped array
	 * @param bool         $is_grouped  Whether data is already grouped by minute
	 * @return array
	 */
	private function format_chart_data( $data_source, bool $is_grouped = false ): array {
		$current_time = current_time( 'timestamp' );

		// Build lookup array with optimized memory usage
		$data_lookup = [];
		if ( $is_grouped ) {
			$data_lookup = (array) $data_source;
		} else {
			// Process results more efficiently
			foreach ( (array) $data_source as $row ) {
				if ( empty( $row ) ) {
					continue;
				}

				$minute = (int) ( $row['minute_timestamp'] ?? 0 );
				$count  = (int) ( $row['count'] ?? 0 );

				if ( $minute > 0 ) {
					$data_lookup[ $minute ] = $count;
				}
			}
		}

		// Pre-allocate arrays for better performance
		$data      = [];
		$max_value = 0;

		// Generate data points for last 30 minutes
		for ( $i = 29; $i >= 0; $i-- ) {
			$minute_key = (int) floor( ( $current_time - ( $i * 60 ) ) / 60 ) * 60;
			$count      = isset( $data_lookup[ $minute_key ] ) ? (int) $data_lookup[ $minute_key ] : 0;
			$data[]     = $count;

			if ( $count > $max_value ) {
				$max_value = $count;
			}
		}

		return [
			'labels'     => $this->generate_chart_labels(),
			'data'       => $data,
			'max_value'  => max( 1, $max_value ),
			'peak_index' => $this->find_peak_index( $data ),
		];
	}

	/**
	 * Find the index of the peak value
	 *
	 * @param array $data
	 * @return int|null
	 */
	private function find_peak_index( array $data ): ?int {
		if ( empty( $data ) ) {
			return null;
		}

		$max_value = max( $data );
		$peak_index = array_search( $max_value, $data, true );

		return $peak_index !== false ? $peak_index : null;
	}

	/**
	 * Initialize report hooks and assets
	 * Called automatically when report is registered
	 * Only registers hooks when necessary to minimize overhead
	 */
	public static function init_hooks(): void {
		// Always register AJAX handler - WordPress will only call it during AJAX requests
		add_action( 'wp_ajax_slimstat_get_live_analytics_data', [ __CLASS__, 'ajax_get_live_analytics_data' ] );

		// Enqueue assets only on admin pages
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		}
	}

	/**
	 * Enqueue required assets for Live Analytics
	 */
	public static function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		// Allow loading on:
		// 1. Any SlimStat page (slimview*, slimconfig, slimlayout)
		// 2. WordPress Dashboard (for widgets)
		// 3. Custom pages via filter
		$is_slimstat_page = false !== strpos( $screen->id, 'slimstat' )
			|| false !== strpos( $screen->id, 'slimview' )
			|| false !== strpos( $screen->id, 'slimconfig' )
			|| false !== strpos( $screen->id, 'slimlayout' );

		$is_dashboard = 'dashboard' === $screen->id;

		/**
		 * Allow developers to load Live Analytics assets on custom pages
		 *
		 * @param bool   $should_load Default loading decision
		 * @param object $screen      Current screen object
		 *
		 * @since 5.4.1
		 */
		$should_load = apply_filters( 'slimstat_live_analytics_load_assets', ( $is_slimstat_page || $is_dashboard ), $screen );

		if ( ! $should_load ) {
			return;
		}

		// Enqueue Chart.js if needed
		if ( ! wp_script_is( 'slimstat_chartjs', 'enqueued' ) && ! wp_script_is( 'slimstat_chartjs', 'registered' ) ) {
			wp_enqueue_script(
				'slimstat_chartjs',
				plugins_url( '/admin/assets/js/chartjs/chart.min.js', SLIMSTAT_FILE ),
				[],
				'4.2.1',
				true
			);
		} else {
			wp_enqueue_script( 'slimstat_chartjs' );
		}

		// Enqueue Live Analytics CSS
		wp_enqueue_style(
			'slimstat-live-analytics',
			plugins_url( '/admin/assets/css/live-analytics.css', SLIMSTAT_FILE ),
			[],
			'5.4.0'
		);

		// Enqueue Live Analytics JavaScript
		wp_enqueue_script(
			'slimstat-live-analytics',
			plugins_url( '/admin/assets/js/live-analytics.js', SLIMSTAT_FILE ),
			[ 'slimstat_chartjs', 'jquery' ],
			'5.4.1',
			true
		);

		// Localize script for AJAX
		wp_localize_script(
			'slimstat-live-analytics',
			'wp_slimstat_ajax',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'slimstat_ajax_nonce' ),
			]
		);
	}

	/**
	 * AJAX handler for getting live analytics data
	 */
	public static function ajax_get_live_analytics_data(): void {
		// Rate limiting check
		if ( ! self::check_rate_limit() ) {
			wp_send_json_error( [
				'message' => __( 'Too many requests. Please try again later.', 'wp-slimstat' ),
			] );
			return;
		}

		// Verify nonce
		$nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'slimstat_ajax_nonce' ) ) {
			wp_send_json_error( [
				'message' => __( 'Security check failed', 'wp-slimstat' ),
				'nonce_received' => ! empty( $nonce ),
			] );
			return;
		}

		// Check permissions first
		$report = new self();
		if ( ! $report->can_view() ) {
			wp_send_json_error( [
				'message' => __( 'Insufficient permissions', 'wp-slimstat' ),
				'user_logged_in' => is_user_logged_in(),
			] );
			return;
		}

		// Validate and sanitize all input parameters
		$requested_metric = sanitize_text_field( $_POST['metric'] ?? 'users' );
		$report_id = sanitize_text_field( $_POST['report_id'] ?? '' );

		// Validate metric
		if ( ! in_array( $requested_metric, [ 'users', 'pages', 'countries' ], true ) ) {
			$requested_metric = 'users';
		}

		// Validate report_id format (should be alphanumeric with underscores)
		if ( ! empty( $report_id ) && ! preg_match( '/^[a-zA-Z0-9_]+$/', $report_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Invalid report ID format', 'wp-slimstat' ),
			] );
			return;
		}

		try {
			// Store validated parameters
			$_POST['metric'] = $requested_metric;
			$_POST['report_id'] = $report_id;

			$data = $report->get_data();

			wp_send_json_success( $data );
		} catch ( \Exception $e ) {
			// Log error for debugging
			error_log( 'Live Analytics AJAX Error: ' . $e->getMessage() );

			wp_send_json_error( [
				'message' => __( 'An error occurred while fetching data', 'wp-slimstat' ),
				'trace' => WP_DEBUG ? $e->getTraceAsString() : null,
			] );
		} catch ( \Error $e ) {
			// Log error for debugging
			error_log( 'Live Analytics AJAX Fatal Error: ' . $e->getMessage() );

			wp_send_json_error( [
				'message' => __( 'A fatal error occurred while fetching data', 'wp-slimstat' ),
				'trace' => WP_DEBUG ? $e->getTraceAsString() : null,
			] );
		}
	}

	/**
	 * Check rate limiting for AJAX requests
	 *
	 * @return bool
	 */
	private static function check_rate_limit(): bool {
		$user_id = get_current_user_id();
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
		$cache_key = 'slimstat_rate_limit_' . md5( $user_id . $ip_address );

		// Get current request count
		$request_count = get_transient( $cache_key );
		if ( false === $request_count ) {
			$request_count = 0;
		}

		// Allow 60 requests per minute per user/IP combination
		$max_requests = 60;
		$time_window = 60; // seconds

		if ( $request_count >= $max_requests ) {
			return false;
		}

		// Increment counter
		set_transient( $cache_key, $request_count + 1, $time_window );

		return true;
	}

	/**
	 * Check if tracking is enabled and consent is granted
	 *
	 * @return bool
	 */
	private function is_tracking_enabled(): bool {
		// Check if SlimStat tracking is enabled
		if ( 'on' !== ( \wp_slimstat::$settings['enable_tracking'] ?? 'on' ) ) {
			return false;
		}

		// Use wp_slimstat's own tracking check if available
		if ( class_exists( '\wp_slimstat' ) && method_exists( '\wp_slimstat', 'is_tracking_enabled' ) ) {
			return \wp_slimstat::is_tracking_enabled();
		}

		return true;
	}

	/**
	 * Get empty chart data for when tracking is disabled or no data available
	 *
	 * @return array
	 */
	private function get_empty_chart_data(): array {
		return [
			'labels'     => $this->generate_chart_labels(),
			'data'       => array_fill( 0, 30, 0 ),
			'max_value'  => 1,
			'peak_index' => null,
			'cache_time' => current_time( 'timestamp' ),
		];
	}

	/**
	 * Clear all cached data for Live Analytics
	 * Called when needed to invalidate cache
	 *
	 * Note: Most caching is now handled by Query class automatically.
	 * We only need to clear manual transients for complex queries.
	 *
	 * @return void
	 */
	public static function clear_cache(): void {
		$blog_id = get_current_blog_id();

		// Clear manual transient cache (only used for users chart with complex JOIN)
		delete_transient( 'slimstat_chart_data_users_' . $blog_id );

		// Note: Query class cache is cleared automatically based on query signature
		// No need to manually clear cache for pages/countries metrics
	}

}

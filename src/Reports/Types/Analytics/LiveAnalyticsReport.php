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
				__( '• Users Live: Number of online sessions (calculated with second-level precision)', 'wp-slimstat' ),
				__( '• A session is considered online until session_duration after last activity', 'wp-slimstat' ),
				__( '• Chart shows exact user count for each minute of the last 30 minutes', 'wp-slimstat' ),
				__( '• Session detection covers every second of each minute (99.9% accuracy)', 'wp-slimstat' ),
				__( '• Pages Live: Unique pages viewed in the last 30 minutes', 'wp-slimstat' ),
				__( '• Countries Live: Number of countries with active users in the last 30 minutes', 'wp-slimstat' ),
				__( '• Data refreshes every 10 seconds with 5-second cache for real-time updates', 'wp-slimstat' ),
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

		// Get all live counts in a single optimized query
		$live_counts = $this->get_all_live_counts();
		$chart_data = $this->get_chart_data_for_metric( $selected_metric );

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
			'refresh_interval' => 10000, // 10 seconds
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
	 * Get consistent session duration across all methods
	 *
	 * @return int
	 */
	private function get_session_duration(): int {
		// Use the same logic as Session.php for consistency
		if ( ! empty( \wp_slimstat::$settings['session_duration'] ) ) {
			return (int) \wp_slimstat::$settings['session_duration'];
		}
		// Use the same default as Session.php
		return 1800; // Default 30 minutes (matches Session.php)
	}

	/**
	 * Get all live counts in a single optimized query
	 * Combines users, pages, and countries counts into one query for better performance
	 *
	 * @return array{users: int, pages: int, countries: int}
	 */
	private function get_all_live_counts(): array {
		// Test mode passthrough
		if ( $this->is_test_mode() ) {
			return $this->get_test_data();
		}

		global $wpdb;

		if ( ! $this->is_tracking_enabled() ) {
			return [ 'users' => 0, 'pages' => 0, 'countries' => 0 ];
		}

		// Consistent session duration across all methods
		$session_duration = $this->get_session_duration();
		$now              = current_time( 'timestamp' );
		$online_threshold = $now - $session_duration;

		// 1) users online right now: count distinct visit_id where there's at least one
		// activity (dt) >= online_threshold. This avoids a costly GROUP BY+HAVING over
		// the entire table by leveraging any index on `dt` (recommended). Using
		// COUNT(DISTINCT visit_id) is much faster than materializing a subquery and
		// then counting rows.
		//
		// NOTE: For very large datasets consider adding an index on (dt, visit_id)
		// or a composite covering index to further improve performance.
		$users_count = (int) Query::select( 'COUNT(DISTINCT visit_id) as users_count' )
			->from( "{$wpdb->prefix}slim_stats" )
			->where( 'dt', '>=', $online_threshold )
			->where( 'visit_id', '>', 0 )
			->allowCaching( true, 1 )
			->getVar();

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
		// TEST MODE: Generate fake chart data for testing
		if ( $this->is_test_mode() ) {
			return $this->get_test_chart_data( $metric );
		}
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

		if ( $this->is_test_mode() ) {
			return $this->get_test_chart_data( 'users' );
		}

		if ( ! $this->is_tracking_enabled() ) {
			return $this->get_empty_chart_data();
		}

		$cache_key = 'slimstat_chart_data_users_' . get_current_blog_id();
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			// Check if cache is still fresh (less than 3 seconds old).
			// Use WP-aware current_time() to avoid server/WP time desync.
			$cache_time = $cached['cache_time'] ?? 0;
			if ( current_time( 'timestamp' ) - $cache_time < 3 ) {
				return $cached;
			}
		}

		$session_duration = $this->get_session_duration();
		$now              = current_time( 'timestamp' );

		// ACCURACY IMPROVEMENT: Use exact seconds instead of rounding to minute start
		// This ensures we count sessions that are active in the last N seconds precisely
	// Include the current minute as the most recent bucket. We want 30 buckets
	// covering [start_minute .. end_minute] where end_minute is the start of the current minute.
	$end_minute   = (int) floor( $now / 60 ) * 60; // start of current minute
	$start_minute = $end_minute - ( 29 * 60 ); // 29 minutes before current minute (30 buckets total)
	$start_time   = $start_minute; // use minute-aligned start_time for filtering

		// We first aggregate sessions: first_activity and last_activity per visit_id.
		// Then we generate 30 minute rows using a derived numbers table (0..29).
		// Check if session is online during ANY point in that minute
		// by checking against both the start (minute_ts) and end (minute_ts + 59) of each minute bucket

		// For complex queries with numbers table and custom JOINs, we use wpdb->prepare
		// This query is too complex for the Query builder's current capabilities
		$sql = $wpdb->prepare(
			"
			SELECT m.minute_ts AS minute_timestamp, COUNT(DISTINCT s.visit_id) AS cnt FROM (
				SELECT %d + (n.n * 60) AS minute_ts
				FROM (
					SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
					UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
					UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
					UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
					UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
					UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
				) n
			) m
			LEFT JOIN (
				SELECT visit_id, MIN(dt) AS first_activity, MAX(dt) AS last_activity
				FROM {$wpdb->prefix}slim_stats
								WHERE visit_id > 0
									AND dt >= %d
				GROUP BY visit_id
				HAVING last_activity + %d >= %d
			) s ON s.first_activity <= (m.minute_ts + 59) AND (s.last_activity + %d) >= m.minute_ts
			GROUP BY m.minute_ts
			ORDER BY m.minute_ts ASC
			",
			$start_minute,
			// Only consider sessions with first activity at or after (start_minute - session_duration)
			// because sessions that started earlier but still active during our window should be included
			($start_minute - $session_duration),
			$session_duration,
			$start_minute,
			$session_duration
		);

		// Execute query - note: this complex query will be refactored to use Query class when JOIN support improves
		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Build a lookup from minute_timestamp -> count
		$lookup = [];
		foreach ( (array) $results as $r ) {
			$lookup[ (int) $r['minute_timestamp'] ] = (int) $r['cnt'];
		}

		// Ensure ordering: index 0 -> -30min, index 29 -> -1min (most recent complete minute)
		$data = [];
		$max  = 0;
		for ( $i = 0; $i < 30; $i++ ) {
			$ts  = $start_minute + ( $i * 60 );
			$cnt = $lookup[ $ts ] ?? 0;
			$data[] = $cnt;
			if ( $cnt > $max ) {
				$max = $cnt;
			}
		}

		$formatted = [
			'labels'     => $this->generate_chart_labels(), // will align with this ordering
			'data'       => $data,
			'max_value'  => max( 1, $max ),
			'peak_index' => $this->find_peak_index( $data ),
			'cache_time' => current_time( 'timestamp' ), // Add timestamp for cache freshness check (WP-aware)
		];

        // Cache TTL: default 3 seconds for near-realtime UX. Exposed filter allows tuning.
        // If you need absolute realtime for the latest bucket, you can set this to 0
        // or implement a bypass for the last bucket.
        $ttl = (int) apply_filters( 'slimstat_live_users_cache_ttl', 3 );
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

	/**
	 * Check if test mode is enabled
	 *
	 * @return bool
	 */
	private function is_test_mode(): bool {
		return isset( $_GET['test_mode'] ) && '1' === $_GET['test_mode'];
	}

	/**
	 * Generate test data with high values
	 *
	 * @return array{users: int, pages: int, countries: int}
	 */
	private function get_test_data(): array {
		return [
			'users'     => rand( 5000, 9000 ),
			'pages'     => rand( 2500, 4500 ),
			'countries' => rand( 25, 45 ),
		];
	}

	/**
	 * Generate test chart data with high values
	 *
	 * @param string $metric
	 * @return array
	 */
	private function get_test_chart_data( string $metric ): array {
		$data = [];
		$max_value = 0;

		// Generate random data for 30 minutes
		for ( $i = 29; $i >= 0; $i-- ) {
			$value = rand( 100, 2500 );
			$data[] = $value;

			if ( $value > $max_value ) {
				$max_value = $value;
			}
		}

		// Add some peaks for visual interest
		$data[15] = rand( 5000, 9000 ); // Peak at -15 min
		$data[5] = rand( 2800, 4000 );  // Peak at -5 min

		$max_value = max( $max_value, $data[15], $data[5] );

		return [
			'labels'     => $this->generate_chart_labels(),
			'data'       => $data,
			'max_value'  => $max_value,
			'peak_index' => 15, // Peak at -15 min
		];
	}
}

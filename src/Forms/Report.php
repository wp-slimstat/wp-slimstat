<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Forms;


/** Small, period-indexed aggregate queries. No visitor joins or premium work. */
final class Report {
	public const FORM_FILTER     = 'addon_form';
	private static array $titles = array();
	/** Site-wide form totals must not bypass author-restricted analytics access. */
	public static function canView(): bool {
		return class_exists( 'wp_slimstat_admin', false ) && \wp_slimstat_admin::can_view_stats() && current_user_can( 'manage_options' );
	}

	/** Native navigation, with setup kept separate from report AJAX responses. */
	public static function screen(): void {
		if ( ! self::canView() ) {
			self::render();
			return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( isset( $_GET['tab'] ) && 'setup' === $_GET['tab'] ) {
			$tab = 'setup';
			require SLIMSTAT_DIR . '/admin/view/forms.php';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Backwards-compatible read-only form link.
		$form = isset( $_GET['form'] ) && is_string( $_GET['form'] ) ? sanitize_text_field( wp_unslash( $_GET['form'] ) ) : '';
		if ( preg_match( '/\A[a-z][a-z0-9]{0,15}:[1-9][0-9]{0,18}\z/', $form ) && ! isset( \wp_slimstat_db::$filters_normalized['columns'][ self::FORM_FILTER ] ) ) {
			\wp_slimstat_db::$filters_normalized['columns'][ self::FORM_FILTER ] = array( 'equals', $form );
		}
		\wp_slimstat_admin::wp_slimstat_include_view();
	}

	/** Keep form links in the same date, filter, refresh and export context. */
	public static function url( string $form = '' ): string {
		return \wp_slimstat_reports::fs_url( self::FORM_FILTER . ' equals ' . $form );
	}

	/** Validate a report selection before cache access or any analytics query. */
	public static function context(): array {
		$filters = \wp_slimstat_db::$filters_normalized;
		$range   = $filters['utime'];
		$start   = (int) $range['start'];
		$end     = (int) floor( (int) $range['end'] / DAY_IN_SECONDS ) * DAY_IN_SECONDS;
		if ( $start % DAY_IN_SECONDS || $end < $start || $end - $start >= 366 * DAY_IN_SECONDS ) {
			self::invalid( __( 'Choose a date range of up to 366 whole days for Form Analytics.', 'wp-slimstat' ) );
		}
		if ( \SlimStat\Utils\NetworkMerge::isMerging() ) {
			self::invalid( __( 'Open an individual site dashboard to review its forms.', 'wp-slimstat' ) );
		}
		$form = $filters['columns'][ self::FORM_FILTER ][1] ?? '';
		if ( '' !== $form && ( ! preg_match( '/\A[a-z][a-z0-9]{0,15}:[1-9][0-9]{0,18}\z/', $form ) || 'equals' !== $filters['columns'][ self::FORM_FILTER ][0] ) ) {
			self::invalid( __( 'Select a form from the overview to load its report.', 'wp-slimstat' ) );
		}
		$where    = '';
		$advanced = false;
		$db       = Store::db();
		foreach ( $filters['columns'] as $column => $filter ) {
			if ( self::FORM_FILTER === $column ) {
				continue; }
			if ( ! in_array( $column, array( 'browser_type', 'traffic_channel' ), true ) || ! in_array( $filter[0], array( 'equals', 'is_not_equal_to' ), true ) ) {
				if ( false === strpos( $column, 'addon_' ) && self::advanced() && isset( \wp_slimstat_db::$columns_names[ $column ] ) ) {
					$advanced = true;
					continue; }
				self::invalid( __( 'This selection needs advanced form segmentation. Use an Equals or Is not equal to filter for Browser Type or Traffic Channel, or remove this filter to view the report.', 'wp-slimstat' ) );
			}
			$operator = 'equals' === $filter[0] ? '=' : '<>';
			if ( 'browser_type' === $column ) {
				if ( ! in_array( (string) $filter[1], array( '0', '2' ), true ) ) {
					self::invalid( __( 'Choose regular browsers or mobile devices. Form Analytics excludes automated traffic.', 'wp-slimstat' ) );
				}
				$where .= $db->prepare( " AND device {$operator} %d", '2' === (string) $filter[1] ? 1 : 0 );
			} else {
				$where .= $db->prepare( " AND channel {$operator} %s", $filter[1] );
			}
		}
		return array(
			'start'    => $start,
			'end'      => $end,
			'form'     => $form,
			'where'    => $where,
			'advanced' => $advanced,
		);
	}

	/** Translated diagnostics are escaped by each HTML/JSON presentation layer. */
	private static function invalid( string $message ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain diagnostic; HTML callers escape the caught message.
		throw new \InvalidArgumentException( $message );
	}

	/** Advanced readers exist only while the Pro integration is active. */
	public static function advanced(): bool {
		return \wp_slimstat::pro_is_installed() && (bool) apply_filters( 'slimstat_forms_pro_enabled', false );
	}

	/** The daily indexed path is shared by HTML, refresh, AI and exports. */
	public static function load(): array {
		if ( ! self::canView() || ! Store::ready() ) {
			throw new \RuntimeException( 'Form Analytics report unavailable.' );
		}
		$context = self::context();
		if ( $context['advanced'] ) {
			$data = apply_filters( 'slimstat_forms_filtered_report', null, $context );
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'Advanced Form Analytics unavailable.' ); }
			return $data;
		}
		$start  = $context['start'];
		$end    = $context['end'];
		$form   = $context['form'];
		$key    = 'slimstat_forms_' . md5( wp_json_encode( array( get_current_blog_id(), \SlimStat\Tracker\Acquisition::readinessKey(), $context, Store::retentionStart(), get_option( 'slimstat_data_ver', '0' ) ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached; }
		$db    = Store::db();
		$table = Store::table( 'slim_form_daily' );
		$where = $db->prepare( 'day BETWEEN %d AND %d', $start, $end ) . $context['where'] . $db->prepare( ' AND day >= %d', Store::retentionStart() );
		if ( '' !== $form ) {
			$where .= $db->prepare( ' AND form = %s', $form ); }
		$columns = array_keys( Metrics::counters( array() ) );
		$totals  = implode(
			', ',
			array_map(
				static function ( $column ) {
					return 'SUM(' . $column . ') AS ' . $column;
				},
				$columns
			)
		);
		$result  = array(
			'start'             => $start,
			'end'               => $end,
			'updated'           => \wp_slimstat::now(),
			'retention_limited' => $start < Store::retentionStart(),
			'days'              => (int) ( ( $end - $start ) / DAY_IN_SECONDS ) + 1,
			'form'              => $form,
		);
		// Overview is deliberately one query, independent of the number of displayed forms.
		$result['forms'] = self::query( "SELECT form, {$totals} FROM {$table} WHERE {$where} GROUP BY form ORDER BY submitted DESC, seen DESC, form LIMIT 101" );
		if ( '' !== $form ) {
			$result['devices'] = self::query( "SELECT device, {$totals} FROM {$table} WHERE {$where} GROUP BY device ORDER BY device LIMIT 3" );
			$result['sources'] = self::query( "SELECT channel, {$totals} FROM {$table} WHERE {$where} GROUP BY channel ORDER BY seen DESC, channel LIMIT 5" );
			$result['trend']   = self::query( "SELECT day, {$totals} FROM {$table} WHERE {$where} GROUP BY day ORDER BY day LIMIT 366" );
		}
		set_transient( $key, $result, 30 );
		return $result;
	}

	/** A broken database must never look like a measured zero. */
	private static function query( string $sql ): array {
		$db       = Store::db();
		$previous = $db->suppress_errors( true );
		try {
			$rows = $db->get_results( $sql, ARRAY_A );
			if ( ! is_array( $rows ) || $db->last_error ) {
				throw new \RuntimeException( 'Form Analytics query failed.' );
			}
			return $rows;
		} finally {
			$db->suppress_errors( $previous );
		}
	}

	/** One WordPress query supplies titles for builders using public form post types. */
	public static function primeTitles( array $keys ): void {
		if ( ! self::canView() ) {
			return; }
		$types = array(
			'cf7'        => 'wpcf7_contact_form',
			'wpforms'    => 'wpforms',
			'forminator' => 'forminator_forms',
			'snow'       => 'snow-monkey-forms',
			'mw'         => 'mw-wp-form',
		);
		$ids   = array();
		foreach ( array_slice( $keys, 0, 101 ) as $key ) {
			$parts = explode( ':', $key, 2 );
			if ( isset( $types[ $parts[0] ], $parts[1] ) ) {
				$ids[] = (int) $parts[1]; }
		}
		if ( ! $ids ) {
			return; }
		$posts = get_posts(
			array(
				'post_type'              => array_values( $types ),
				'post_status'            => 'any',
				'include'                => array_unique( $ids ),
				'numberposts'            => 101,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $posts as $post ) {
			$adapter = array_search( $post->post_type, $types, true );
			if ( false !== $adapter && '' !== trim( $post->post_title ) ) {
				self::$titles[ $adapter . ':' . $post->ID ] = $post->post_title; }
		}
	}

	/** Stable, readable identity without querying every builder's private tables. */
	public static function name( string $key ): string {
		if ( isset( self::$titles[ $key ] ) ) {
			return self::$titles[ $key ]; }
		$parts = explode( ':', $key, 2 );
		/* translators: 1: Form builder name, 2: form identifier. */
		return sprintf( __( '%1$s form #%2$s', 'wp-slimstat' ), Registry::labels()[ $parts[0] ] ?? __( 'Unavailable integration', 'wp-slimstat' ), $parts[1] ?? '' );
	}

	/** Always use the intersection of observed and completed cohorts as numerator. */
	public static function rate( array $row ): string {
		$value = Metrics::rate( (int) $row['converted'], (int) $row['seen'] );
		/* translators: %s: Localized completion percentage. */
		return null === $value ? __( 'Not enough data', 'wp-slimstat' ) : sprintf( __( '%s%%', 'wp-slimstat' ), number_format_i18n( $value, 1 ) );
	}

	/** Bounded, authorized rows for the existing export and AI readers. */
	public static function raw(): array {
		$data = self::load();
		self::primeTitles( array_column( $data['forms'], 'form' ) );
		$rows = array();
		foreach ( array_slice( $data['forms'], 0, 100 ) as $row ) {
			$rows[] = array(
				'form'      => self::name( $row['form'] ),
				'seen'      => (int) $row['seen'],
				'started'   => (int) $row['started'],
				'submitted' => (int) $row['submitted'],
				'converted' => (int) $row['converted'],
				'cr'        => Metrics::rate( (int) $row['converted'], (int) $row['seen'] ),
				'invalid'   => (int) $row['invalid'],
				'spam'      => (int) $row['spam'],
				'failed'    => (int) $row['failed'],
			);
		}
		return $rows;
	}

	/** Render only from the registered, capability-checked admin menu. */
	public static function render(): void {
		if ( ! self::canView() ) {
			wp_die( esc_html__( 'Ask your site administrator for access to Form Analytics.', 'wp-slimstat' ), '', array( 'response' => 403 ) );
		}
		$tab = 'overview';
		require SLIMSTAT_DIR . '/admin/view/forms.php';
		if ( wp_doing_ajax() && ! \wp_slimstat_reports::is_batch_rendering() ) {
			wp_die();
		}
	}
}

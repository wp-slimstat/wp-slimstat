<?php
/**
 * Authorized, minimized data for native analysis; all queries remain report-owned.
 *
 * @package SlimStat
 */

namespace SlimStat\Reports;

use SlimStat\Ecommerce\Report as Ecommerce;
use SlimStat\Heatmap\Query as Heatmap;
use SlimStat\Forms\Report as Forms;
use SlimStat\Modules\Chart;
use SlimStat\Shortcodes\Shortcode;
use SlimStat\Utils\NetworkMerge;

/** Reject arbitrary browser data, callbacks, personal reports and unsupported contexts. */
final class AiReport {

	public const MAX_ROWS = 20;

	private const SUMMARY_READERS = array( 'get_overview_summary', 'get_visitors_summary', 'get_traffic_sources_summary', 'get_visits_duration', 'get_your_blog' );
	private const NUMBERS         = array( 'seen', 'started', 'submitted', 'converted', 'invalid', 'spam', 'failed', 'counthits', 'total', 'uniques', 'cr', 'pct', 'visitors', 'dropoff', 'step_order', 'clicks', 'pageviews', 'desktop', 'tablet', 'mobile', 'dead', 'rage', 'scroll', 'addon_hm_clicks', 'addon_hm_pageviews', 'addon_hm_dead', 'addon_hm_rage', 'addon_hm_scroll' );

	/** Initialize the existing registry without running an analytics query. */
	public static function catalog(): array {
		require_once dirname( __DIR__, 2 ) . '/admin/view/wp-slimstat-reports.php';
		\wp_slimstat_reports::init( false, false );
		return \wp_slimstat_reports::$reports + array( 'slimheatmap' => array( 'title' => __( 'Heatmaps', 'wp-slimstat' ) ) );
	}

	/**
	 * Validate context before any report query. This is also the cache access boundary.
	 *
	 * @param array $input JSON context, never a dataset or prompt.
	 * @return array|\WP_Error Normalized private context and its public metadata.
	 */
	public static function context( array $input ) {
		if ( ! Shortcode::canView() || NetworkMerge::isMerging() || is_network_admin() ) {
			return self::error( 'permission', __( 'Open a site dashboard with permission to view this report.', 'wp-slimstat' ), 403 );
		}
		$allowed = array( 'report', 'from', 'to', 'filters', 'granularity', 'page', 'device', 'search', 'fs', 'goal', 'converted', 'selection', 'consent' );
		if ( array_diff( array_keys( $input ), $allowed ) ) {
			return self::invalid();
		}
		$catalog = self::catalog();
		$id      = $input['report'] ?? null;
		if ( ! is_string( $id ) || ! isset( $catalog[ $id ] ) || ( 'slimheatmap' !== $id && ! Shortcode::allowed( $id ) ) ) {
			return self::invalid();
		}
		$report = $catalog[ $id ];
		if ( ( 'slim_p10_01' === $id && ! Ecommerce::canView() ) || ( 'slim_forms_01' === $id && ! Forms::canView() ) ) {
			return self::error( 'permission', __( 'Store reporting permission is required.', 'wp-slimstat' ), 403 );
		}
		if ( in_array( $id, Shortcode::PRO_REPORTS, true ) && ! \wp_slimstat::pro_is_installed() ) {
			return self::invalid();
		}
		$from = self::date( $input['from'] ?? null );
		$to   = self::date( $input['to'] ?? null );
		if ( null === $from || null === $to || $from > $to || $to - $from >= 366 * DAY_IN_SECONDS ) {
			return self::error( 'range', __( 'Choose a valid date range of at most 366 days for AI analysis.', 'wp-slimstat' ) );
		}
		$filters = $input['filters'] ?? array();
		if ( ! is_array( $filters ) || count( $filters ) > 12 ) {
			return self::invalid();
		}
		$fs = $input['fs'] ?? '';
		if ( ! is_string( $fs ) || strlen( $fs ) > 2000 || ( '' !== $fs && ( 'slimheatmap' !== $id || $filters ) ) ) {
			return self::invalid();
		}
		if ( '' !== $fs ) {
			foreach ( explode( '&&&', $fs ) as $filter ) {
				if ( ! preg_match( '/\A([a-z_]+) (.+)\z/s', $filter, $parts ) || isset( $filters[ $parts[1] ] ) ) {
					return self::invalid();
				}
				$filters[ $parts[1] ] = $parts[2];
			}
		}
		$normalized = self::filters( $filters, '' === $fs );
		if ( is_wp_error( $normalized ) || ! AiPromptButton::eligible( $id, $report, $normalized ) ) {
			return self::error( 'privacy', __( 'This report selection cannot be sent to AI. Remove personal or unsupported filters, or copy an eligible aggregate report.', 'wp-slimstat' ), 400 );
		}
		if ( 'slim_p10_01' !== $id && isset( $normalized[ Ecommerce::CURRENCY_FILTER ] ) ) {
			return self::invalid();
		}
		if ( 'slim_forms_01' !== $id && isset( $normalized[ Forms::FORM_FILTER ] ) ) { return self::invalid(); }
		$device    = $input['device'] ?? '';
		$page      = $input['page'] ?? '';
		$search    = $input['search'] ?? '';
		$converted = $input['converted'] ?? '';
		$goal      = $input['goal'] ?? 0;
		$gran      = $input['granularity'] ?? 'daily';
		if ( ! in_array( $device, array( '', 'desktop', 'tablet', 'mobile' ), true )
			|| ! is_string( $page ) || strlen( $page ) > 2048 || ( '' !== $page && ! preg_match( '~\A/[^\x00-\x20]*\z~', $page ) )
			|| ! is_string( $search ) || strlen( $search ) > 200 || ! is_int( $goal ) || $goal < 0
			|| ! in_array( $converted, array( '', 'yes', 'no' ), true )
			|| ! in_array( $gran, array( 'hourly', 'daily', 'weekly', 'monthly', 'yearly' ), true ) ) {
			return self::invalid();
		}
		if ( 'slimheatmap' !== $id && ( $page || $device || $search || $goal || $converted ) ) {
			return self::invalid();
		}
		if ( 'slimheatmap' === $id && ( ( '' === $page && ( $normalized || $goal || $converted ) ) || ( '' !== $page && ( $search || '' === $device ) ) ) ) {
			return self::error( 'context', __( 'This heatmap selection is not supported for native analysis. Use a page and device selection, or an unfiltered page list.', 'wp-slimstat' ) );
		}
		if ( ( $goal > 0 ) !== ( '' !== $converted ) ) {
			return self::invalid();
		}
		if ( 'slim_heatmap_01' === $id && $normalized ) {
			return self::invalid(); }
		$args = $report['callback_args'] ?? array();
		if ( 'slimheatmap' !== $id && ! isset( $args['chart_data'] ) && ! is_callable( $args['raw'] ?? null ) ) {
			return self::error( 'unsupported', __( 'Native analysis is not available for this report. You can still copy its prompt.', 'wp-slimstat' ) );
		}
		if ( 'slim_p4_22' === $id && ( ! current_user_can( 'manage_options' ) || $normalized ) ) {
			return self::error( 'unsupported', __( 'Website inventory requires an administrator and does not support report filters.', 'wp-slimstat' ), 403 );
		}
		$labels = array();
		foreach ( $normalized as $column => $filter ) {
			$labels[] = wp_strip_all_tags( \wp_slimstat_db::$all_columns_names[ $column ][0] ?? $column ) . ': ' . __( 'selected value withheld', 'wp-slimstat' );
		}
		if ( $page ) {
			$labels[] = __( 'Selected page address withheld', 'wp-slimstat' ); }
		if ( $device ) {
			$labels[] = $device; }
		if ( $search ) {
			$labels[] = __( 'Page search applied; text withheld', 'wp-slimstat' ); }
		if ( $goal ) {
			$labels[] = 'yes' === $converted ? __( 'Visitors who converted', 'wp-slimstat' ) : __( 'Visitors who did not convert', 'wp-slimstat' ); }
		$public                   = array(
			'title'    => wp_strip_all_tags( $report['title'] ),
			'from'     => $input['from'],
			'to'       => $input['to'],
			'timezone' => wp_timezone_string(),
			'filters'  => $labels,
		);
		$context                  = compact( 'id', 'from', 'to', 'normalized', 'device', 'page', 'search', 'goal', 'converted', 'gran', 'public' );
		$context['authorization'] = self::fingerprint( array( get_current_blog_id(), get_current_user_id(), \wp_slimstat::report_scope()['cache'] ) );
		$context['fingerprint']   = self::fingerprint( array( $context, $args, AiPrompt::VERSION, array_intersect_key( \wp_slimstat::$settings, array_flip( array( 'hash_ip', 'anonymize_ip', 'geolocation_country' ) ) ) ) );
		return $context;
	}

	/**
	 * Strict calendar dates, using SlimStat's existing wall-clock timestamp convention.
	 *
	 * @param mixed $value Value to validate or encode.
	 */
	private static function date( $value ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value ) ) {
			return null; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $date->getTimestamp() : null;
	}

	/**
	 * Browser filters use existing dimensions/operators, with one bounded scalar value each.
	 *
	 * @param array $filters Submitted report filters.
	 * @param bool  $literal_acquisition Acquisition values came from already-decoded admin fields.
	 */
	private static function filters( array $filters, bool $literal_acquisition = true ) {
		if ( count( $filters ) > 12 ) {
			return self::invalid(); }
		$result = array();
		foreach ( $filters as $column => $expression ) {
			if ( ! is_string( $column ) || ( ! isset( \wp_slimstat_db::$columns_names[ $column ] ) && ! in_array( $column, array( Ecommerce::CURRENCY_FILTER, Forms::FORM_FILTER ), true ) )
				|| ( 0 === strpos( $column, 'addon_' ) && ! in_array( $column, array( Ecommerce::CURRENCY_FILTER, Forms::FORM_FILTER ), true ) ) || 0 === strpos( $column, 'no_filter' )
				|| ! is_string( $expression ) || strlen( $expression ) > 256
				|| ! preg_match( '/\A([a-z_]+)(?: (.*))?\z/s', ( $literal_acquisition && ( 0 === strpos( $column, 'utm_' ) || 'traffic_source' === $column ) ? $expression : urldecode( $expression ) ), $parts )
				|| ! isset( \wp_slimstat_db::$operator_names[ $parts[1] ] )
				|| in_array( $parts[1], array( 'matches', 'does_not_match', 'sounds_like' ), true ) ) {
				return self::invalid();
			}
			$value = $parts[2] ?? '';
			if ( wp_check_invalid_utf8( $value ) !== $value || preg_match( '/[\x00-\x1F]/', $value ) || ( '' === $value && ! in_array( $parts[1], array( 'is_empty', 'is_not_empty' ), true ) ) ) {
				return self::invalid();
			}
			$result[ $column ] = array( $parts[1], $value );
		}
		ksort( $result );
		return $result;
	}

	/**
	 * No raw filter values or personal report labels enter saved metadata.
	 *
	 * @param array $value Value to validate or encode.
	 */
	public static function fingerprint( array $value ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( $value ), wp_salt( 'auth' ) );
	}

	/**
	 * Execute only the registered reader once, preserving all surrounding report state.
	 *
	 * @param array $context Previously authorized context, never supplied by the browser.
	 * @return array|\WP_Error Shared prompt and safe display metadata.
	 */
	public static function build( array $context ) {
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 && $memory_limit - memory_get_usage( true ) < 16 * MB_IN_BYTES ) {
			return self::error( 'resources', __( 'There is not enough available server memory to prepare this report. Copy its prompt instead.', 'wp-slimstat' ), 503 );
		}
		$old       = array( \wp_slimstat_db::$filters_normalized, \wp_slimstat_db::$sql_where, \wp_slimstat_db::$pageviews, \wp_slimstat::$settings['limit_results'] );
		$databases = array( $GLOBALS['wpdb'] );
		if ( \wp_slimstat::$wpdb !== $GLOBALS['wpdb'] ) {
			$databases[] = \wp_slimstat::$wpdb; }
		$database_state = array();
		foreach ( $databases as $database ) {
			$database_state[]     = array( $database->suppress_errors( true ), $database->last_error );
			$database->last_error = '';
		}
		$check_database = static function ( $sql ) use ( $databases ) {
			foreach ( $databases as $database ) {
				if ( '' !== $database->last_error ) {
					throw new \RuntimeException( 'Report query failed.' ); }
			}
			return $sql;
		};
		// Some legacy readers coerce failed aggregates to zero. Do not send partial data as fact.
		add_filter( 'query', $check_database, PHP_INT_MAX );
		try {
			$filters = array();
			foreach ( $context['normalized'] as $column => $filter ) {
				$filters[] = $column . ' ' . $filter[0] . ' ' . rawurlencode( $filter[1] );
			}
			$fs                                      = implode( '&&&', $filters );
			\wp_slimstat::$settings['limit_results'] = self::MAX_ROWS;
			\wp_slimstat_db::init( $fs, false, false );
			\wp_slimstat_db::$filters_normalized['utime']                 = array(
				'start' => $context['from'],
				'end'   => $context['to'] + DAY_IN_SECONDS - 1,
			);
			\wp_slimstat_db::$filters_normalized['misc']['limit_results'] = self::MAX_ROWS;
			\wp_slimstat_db::$filters_normalized['misc']['start_from']    = 0;
			\wp_slimstat_db::$pageviews                                   = 0;
			$report = self::catalog()[ $context['id'] ];
			if ( ! AiPromptButton::eligible( $context['id'], $report, \wp_slimstat_db::$filters_normalized['columns'] ) ) {
				return self::invalid(); }
			$args    = $report['callback_args'] ?? array();
			$rows    = array();
			$summary = false;
			if ( 'slimheatmap' === $context['id'] ) {
				if ( $context['page'] ) {
					$rows = apply_filters( 'slimstat_native_ai_heatmap', null, $context, $fs );
					if ( ! is_array( $rows ) ) {
						return self::invalid(); }
					$summary = true;
				} else {
					$data = Heatmap::cachedPages( $context['from'], $context['to'] + DAY_IN_SECONDS - 1, $context['device'] );
					$rows = array_values(
						array_filter(
							$data['rows'],
							static function ( $row ) use ( $context ) {
								return '' === $context['search'] || false !== stripos( $row['page'], $context['search'] );
							}
						)
					);
				}
			} elseif ( isset( $args['chart_data'] ) ) {
				$args['granularity'] = $context['gran'];
				$chart               = ( new Chart() )->reportData( $args );
				$rows                = self::chartRows( $chart );
				$summary             = true;
			} else {
				$reader  = is_array( $args['raw'] ) ? $args['raw'][1] : '';
				$summary = in_array( $reader, self::SUMMARY_READERS, true ) || 'slim_p10_01' === $context['id'];
				if ( in_array( $reader, self::SUMMARY_READERS, true ) && 'get_your_blog' !== $reader ) {
					\wp_slimstat_db::$pageviews = \wp_slimstat_db::count_records();
					if ( ! \wp_slimstat_db::$pageviews ) {
						return self::emptyReport(); }
				}
				$rows = ReportData::rawData( $args );
				if ( 'slim_p10_01' === $context['id'] && count( $rows ) < 2 ) {
					return self::emptyReport(); }
			}
			$check_database( '' );
			if ( $summary && ! isset( $args['chart_data'] ) && 'slimheatmap' !== $context['id'] ) {
				foreach ( $rows as &$row ) {
					if ( isset( $row['metric'], $row['value'] ) ) {
						$row['value'] = ReportData::summaryValue( $row );
					}
				}
				unset( $row );
			}
			$rows = self::minimize( $rows, $summary );
			if ( ! $rows ) {
				return self::emptyReport(); }
			$public = $context['public'];
			if ( isset( $args['chart_data'] ) ) {
				$length                    = $context['to'] - $context['from'] + DAY_IN_SECONDS;
				$public['previous_period'] = gmdate( 'Y-m-d', $context['from'] - $length ) . ' – ' . gmdate( 'Y-m-d', $context['from'] - 1 );
			}
			$public['limitations'] = __( 'At most 20 representative rows; this is not the complete dataset. Free-form labels, page addresses and personal identifiers are withheld. Small samples are directional. Some summary metrics explicitly cover today or another period. Saved analyses are snapshots and are not refreshed automatically.', 'wp-slimstat' );
			if ( 'slim_p4_22' === $context['id'] ) {
				$public['limitations'] .= ' ' . __( 'Website inventory is site-wide and independent of the selected date range.', 'wp-slimstat' ); }
			return array(
				'prompt'  => AiPrompt::build( $public, $rows ),
				'context' => $context['public'],
			);
		} catch ( \Throwable $exception ) {
			return self::error( 'data', __( 'This report could not be prepared safely within the analysis limits. Try a shorter range or copy the prompt.', 'wp-slimstat' ), 400 );
		} finally {
			remove_filter( 'query', $check_database, PHP_INT_MAX );
			foreach ( $databases as $index => $database ) {
				$database->suppress_errors( $database_state[ $index ][0] );
				$database->last_error = $database_state[ $index ][1];
			}
			list( \wp_slimstat_db::$filters_normalized, \wp_slimstat_db::$sql_where, \wp_slimstat_db::$pageviews, \wp_slimstat::$settings['limit_results'] ) = $old;
		}
	}

	/**
	 * An allowlist, not PII pattern matching: visitor-controlled strings never leave.
	 * Summary strings are permitted only for the audited constant-metric readers above.
	 *
	 * @param array $rows Bounded report rows.
	 * @param bool  $summary Whether the reader supplies audited constant metrics.
	 */
	public static function minimize( array $rows, bool $summary = false ): array {
		$result = array();
		$groups = array();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue; }
			$clean = array();
			if ( $summary ) {
				foreach ( array( 'metric', 'value', 'tooltip' ) as $field ) {
					if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
						$clean[ $field ] = mb_substr( wp_strip_all_tags( (string) $row[ $field ] ), 0, 600 );
					}
				}
			} else {
				/* translators: %d: ordinal row number; the original label is withheld for privacy. */
				$clean['label'] = sprintf( __( 'Row %d', 'wp-slimstat' ), $index + 1 );
				if ( isset( $row['funnel_name'] ) ) {
					$key             = (string) $row['funnel_name'];
					$groups[ $key ]  = $groups[ $key ] ?? count( $groups ) + 1;
					$clean['funnel'] = $groups[ $key ];
				}
			}
			foreach ( self::NUMBERS as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && preg_match( '/\A-?[0-9]+(?:\.[0-9]+)?%?\z/', (string) $row[ $key ] ) ) {
					$clean[ $key ] = $row[ $key ];
				}
			}
			if ( $summary ? isset( $clean['value'] ) : count( $clean ) > ( isset( $clean['funnel'] ) ? 2 : 1 ) ) {
				$result[] = $clean;
			}
		}
		return $result;
	}

	/**
	 * Existing chart totals plus bounded points; never sum unique counts across buckets.
	 *
	 * @param array $chart Existing chart pipeline output.
	 */
	private static function chartRows( array $chart ): array {
		$data   = $chart['data'];
		$rows   = array();
		$totals = Chart::summarizeTotals( $data['totals'] ?? array() );
		if ( ! array_sum( $totals['current'] ) ) {
			return array(); }
		foreach ( array( 'v1', 'v2' ) as $index => $key ) {
			$label = $chart['labels'][ $index ] ?? $key;
			foreach ( $totals as $period => $values ) {
				$rows[] = array(
					'metric' => $label . ' · ' . ( 'current' === $period ? __( 'Current period total', 'wp-slimstat' ) : __( 'Previous period total', 'wp-slimstat' ) ),
					'value'  => $values[ $key ],
				);
			}
			$points    = $data['datasets'][ $key ] ?? array();
			$positions = array_unique(
				array_map(
					static function ( $index ) use ( $points ) {
						return (int) round( $index * ( count( $points ) - 1 ) / 7 );
					},
					range( 0, 7 )
				)
			);
			foreach ( $positions as $position ) {
				$value = $points[ $position ] ?? null;
				if ( null !== $value && is_numeric( $value ) ) {
					$rows[] = array(
						'metric' => $label . ' · ' . trim( $data['labels'][ $position ] ?? '', "'" ),
						'value'  => $value,
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * Explain that no usable data can be sent.
	 */
	private static function emptyReport(): \WP_Error {
		return self::error( 'empty', __( 'There is no usable aggregate data to analyze for this selection.', 'wp-slimstat' ) );
	}

	/**
	 * Reject an unsupported or malformed report context.
	 */
	private static function invalid(): \WP_Error {
		return self::error( 'context', __( 'This report selection is invalid or unavailable. Reload the report and try again.', 'wp-slimstat' ) );
	}

	/**
	 * Return a safe report preparation error.
	 *
	 * @param string $code Safe internal error category.
	 * @param string $message Translated safe error message.
	 * @param int    $status HTTP error status.
	 */
	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'slimstat_ai_' . $code, $message, array( 'status' => $status ) );
	}
}

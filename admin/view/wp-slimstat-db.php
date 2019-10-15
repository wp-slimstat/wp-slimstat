<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// Filters
	public static $columns_names = array();
	public static $operator_names = array();
	public static $filters_normalized = array();

	// Structure that maps filters to SQL information (table names, clauses, lookup tables, etc)
	public static $sql_where = array( 'columns' => '', 'time_range' => '' );

	// Filters that are not visible in the dropdown
	public static $all_columns_names = array();

	// Debug message
	public static $debug_message = '';

	// Useful data for the reports
	public static $pageviews = 0;

	/*
	 * Sets the filters and other structures needed to store the data retrieved from the DB
	 */
	public static function init( $_filters = '' ) {
		// List of supported filters and their user-friendly names
		self::$columns_names = array(
			'browser' => array( __( 'Browser', 'wp-slimstat' ), 'varchar' ),
			'country' => array( __( 'Country Code', 'wp-slimstat' ), 'varchar' ),
			'ip' => array( __( 'IP Address', 'wp-slimstat' ), 'varchar' ),
			'searchterms' => array( __( 'Search Terms', 'wp-slimstat' ), 'varchar' ),
			'language' => array( __( 'Language', 'wp-slimstat' ), 'varchar' ),
			'platform' => array( __( 'Operating System', 'wp-slimstat' ), 'varchar' ),
			'resource' => array( __( 'Permalink', 'wp-slimstat' ), 'varchar' ),
			'referer' => array( __( 'Referer', 'wp-slimstat' ), 'varchar' ),
			'username' => array( __( 'Visitor\'s Username', 'wp-slimstat' ), 'varchar' ),
			'email' => array( __( 'Visitor\'s Email', 'wp-slimstat' ), 'varchar' ),
			'outbound_resource' => array( __( 'Outbound Link', 'wp-slimstat' ), 'varchar' ),
			'tz_offset' => array( __( 'Timezone Offset', 'wp-slimstat' ), 'int' ),
			'fingerprint' => array( __( 'Fingerprint', 'wp-slimstat' ), 'varchar' ),
			'page_performance' => array( __( 'Page Speed', 'wp-slimstat' ), 'int' ),
			'no_filter_selected_2' => array( '', 'none' ),
			'no_filter_selected_3' => array( __( '-- Advanced filters --', 'wp-slimstat' ), 'none' ),
			'browser_version' => array( __( 'Browser Version', 'wp-slimstat' ), 'varchar' ),
			'browser_type' => array( __( 'Browser Type', 'wp-slimstat' ), 'int' ),
			'user_agent' => array( __( 'User Agent', 'wp-slimstat' ), 'varchar' ),
			'city' => array( __( 'City', 'wp-slimstat' ), 'varchar' ),
			'location' => array( __( 'Coordinates', 'wp-slimstat' ), 'varchar' ),
			'notes' => array( __( 'Annotations', 'wp-slimstat' ), 'varchar' ),
			'server_latency' => array( __( 'Server Latency', 'wp-slimstat' ), 'int' ),
			'author' => array( __( 'Post Author', 'wp-slimstat' ), 'varchar' ),
			'category' => array( __( 'Post Category ID', 'wp-slimstat' ), 'varchar' ),
			'other_ip' => array( __( 'Originating IP', 'wp-slimstat' ), 'varchar' ),
			'content_type' => array( __( 'Resource Content Type', 'wp-slimstat' ), 'varchar' ),
			'content_id' => array( __( 'Resource ID', 'wp-slimstat' ), 'int' ),
			'screen_width' => array( __( 'Screen Width', 'wp-slimstat' ), 'int' ),
			'screen_height' => array( __( 'Screen Height', 'wp-slimstat' ), 'int' ),
			'resolution' => array( __( 'Viewport Size', 'wp-slimstat' ), 'varchar' ),
			'visit_id' => array( __( 'Visit ID', 'wp-slimstat' ), 'int' )
		);

		if ( wp_slimstat::$settings[ 'geolocation_country' ] == 'on' ) {
			unset( self::$columns_names[ 'city' ] );
			unset( self::$columns_names[ 'location' ] );
		}

		// List of supported filters and their friendly names
		self::$operator_names = array(
			'equals' => __( 'equals', 'wp-slimstat' ),
			'is_not_equal_to' => __( 'is not equal to', 'wp-slimstat' ),
			'contains' => __( 'contains', 'wp-slimstat' ),
			'includes_in_set' => __( 'is included in', 'wp-slimstat' ),
			'does_not_contain' => __( 'does not contain', 'wp-slimstat' ),
			'starts_with' => __( 'starts with', 'wp-slimstat' ),
			'ends_with' => __( 'ends with', 'wp-slimstat' ),
			'sounds_like' => __( 'sounds like', 'wp-slimstat' ),
			'is_greater_than' => __( 'is greater than', 'wp-slimstat' ),
			'is_less_than' => __( 'is less than', 'wp-slimstat' ),
			'between' => __( 'is between (x,y)', 'wp-slimstat' ),
			'matches' => __( 'matches', 'wp-slimstat' ),
			'does_not_match' => __( 'does not match', 'wp-slimstat' ),
			'is_empty' => __( 'is empty', 'wp-slimstat' ),
			'is_not_empty' => __( 'is not empty', 'wp-slimstat' ),
		);

		// The following filters will not be displayed in the dropdown
		self::$all_columns_names = array_merge( array(
			// Date and Time
			'minute' => array( __( 'Minute', 'wp-slimstat' ), 'int' ),
			'hour' => array( __( 'Hour', 'wp-slimstat' ), 'int' ),
			'day' => array( __( 'Day', 'wp-slimstat' ), 'int' ),
			'month' => array( __( 'Month', 'wp-slimstat' ), 'int' ),
			'year' => array( __( 'Year', 'wp-slimstat' ), 'int' ),
			'interval' => array( __( 'days', 'wp-slimstat' ), 'int' ),
			'interval_hours' => array( __( 'hours', 'wp-slimstat' ), 'int' ),
			'interval_minutes' => array( __( 'minutes', 'wp-slimstat' ), 'int' ),
			'dt' => array( __( 'Timestamp', 'wp-slimstat' ), 'int' ),
			'dt_out' => array( __( 'Exit Timestamp', 'wp-slimstat' ), 'int' ),

			// Other columns
			'metric' => array( __( 'Metric', 'wp-slimstat' ), 'varchar' ),
			'value' => array( __( 'Value', 'wp-slimstat' ), 'varchar' ),
			'counthits' => array( __( 'Hits', 'wp-slimstat' ), 'int' ),
			'column_group' => array( __( 'Grouped Value', 'wp-slimstat' ), 'varchar' ),
			'percentage' => array( __( 'Percentage', 'wp-slimstat' ), 'int' ),
			'tooltip' => array( __( 'Notes', 'wp-slimstat' ), 'varchar' ),
			'details' => array( __( 'Notes', 'wp-slimstat' ), 'varchar' ),

			// Events
			'event_id' => array( __( 'Event ID', 'wp-slimstat' ), 'int' ),
			'type' => array( __( 'Type', 'wp-slimstat' ), 'int' ),
			'event_description' => array( __( 'Event Description', 'wp-slimstat' ), 'varchar' ),
			'position' => array( __( 'Event Coordinates', 'wp-slimstat' ), 'int' ),

			'limit_results' => array( __( 'Max Results', 'wp-slimstat' ), 'int' ),
			'start_from' => array( __( 'Offset', 'wp-slimstat' ), 'int' ),

			// Misc Filters
			'strtotime' => array( 0, 'int' )
		), self::$columns_names );

		// Allow third party plugins to add even more column names to the array
		self::$all_columns_names = apply_filters( 'slimstat_column_names', self::$all_columns_names );

		// Filters use the following format: browser equals Firefox&&&country contains gb
		$filters_array = array();

		// Filters are set via javascript as hidden fields and submitted as a POST request. They override anything passed through the regular input fields
		if ( !empty( $_REQUEST[ 'fs' ] ) && is_array( $_REQUEST[ 'fs' ] ) ) {
			foreach( $_REQUEST[ 'fs' ] as $a_request_filter_name => $a_request_filter_value ) {
				$filters_array[ htmlspecialchars( $a_request_filter_name ) ] = "$a_request_filter_name $a_request_filter_value";
			}
		}

		// Date filters (input fields) - Please note: interval_minutes is not exposed via the web interface, that's why it's not listed here below
		foreach ( array( 'hour', 'day', 'month', 'year', 'interval', 'interval_hours' ) as $a_date_time_filter_name ) {
			if ( isset( $_POST[ $a_date_time_filter_name ] ) && strlen( $_POST[ $a_date_time_filter_name ] ) > 0 ) { // here we use isset instead of !empty to handle ZERO as a valid input value
				$filters_array[ $a_date_time_filter_name ] = "$a_date_time_filter_name equals " . intval( $_POST[ $a_date_time_filter_name ] );
			}
		}

		// Fields and drop downs
		if ( !empty( $_POST[ 'f' ] ) && !empty( $_POST[ 'o' ] ) ) {
			$filters_array[ htmlspecialchars( $_POST[ 'f' ] ) ] = "{$_POST[ 'f' ]} {$_POST[ 'o' ]} " . ( isset( $_POST[ 'v' ] ) ? $_POST[ 'v' ] : '' );
		}

		// Filters set via the plugin options
		if ( wp_slimstat::$settings[ 'restrict_authors_view' ] == 'on' && !current_user_can( 'manage_options' ) && !empty( $GLOBALS[ 'current_user' ]->user_login ) ) {
			$filters_array[ 'author' ] = 'author equals ' . $GLOBALS[ 'current_user' ]->user_login;
		}

		if ( !empty( $filters_array ) ) {
			$filters_raw = implode( '&&&', $filters_array );
		}

		// Filters are defined as: browser equals Chrome&&&country starts_with en
		if ( !isset( $filters_raw ) || !is_string( $filters_raw ) ) {
			$filters_raw = '';
		}

		if ( !empty( $_filters ) && is_string( $_filters ) ) {
			if ( !empty( $filters_raw ) ) {
				$filters_raw = empty( $filters_raw ) ? $_filters : $_filters . '&&&' . $filters_raw;
			}
			else {
				$filters_raw = $_filters;
			}
		}

		// Hook for the... filters
		$filters_raw = apply_filters( 'slimstat_db_pre_filters', $filters_raw );

		// Normalize the filters
		self::$filters_normalized = self::init_filters( $filters_raw );

		// Retrieve data that will be used by multiple reports
		if ( empty( $_REQUEST[ 'page' ] ) || strpos( $_REQUEST[ 'page' ], 'slimview' ) !== false ) {
			self::$pageviews = wp_slimstat_db::count_records();
		}
	}
	// end init

	/**
	 * Builds the array of WHERE clauses to be used later in our SQL queries
	 */
	protected static function _get_sql_where( $_filters_normalized = array(), $_slim_stats_table_alias = '' ) {
		$sql_array = array();

		foreach ( $_filters_normalized as $a_filter_column => $a_filter_data ) {
			// Add-ons can set their own custom filters, which are ignored here
			if ( strpos( $a_filter_column, 'addon_' ) !== false ) {
				continue;
			}

			$sql_array[] = self::get_single_where_clause( $a_filter_column, $a_filter_data[ 0 ], $a_filter_data[ 1 ], $_slim_stats_table_alias );
		}

		// Flatten array
		if ( !empty( $sql_array ) ) {
			return implode( ' AND ', $sql_array );
		}

		return '';
	}

	public static function get_combined_where( $_where = '', $_column = '*', $_use_date_filters = true, $_slim_stats_table_alias = '' ) {
		$dt_with_alias = 'dt';
		if ( !empty( $_slim_stats_table_alias ) ) {
			$dt_with_alias = $_slim_stats_table_alias . '.' . $dt_with_alias;
		}

		$time_range_condition = '';
		if ( empty( $_where ) ) {
			if ( !empty( self::$filters_normalized[ 'columns' ] ) ) {
				$_where = self::_get_sql_where( self::$filters_normalized[ 'columns' ], $_slim_stats_table_alias );

				if ($_use_date_filters) {
					$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
				}
			}
			elseif ( $_use_date_filters ) {
				$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
			}

			// This could happen if we have custom filters (add-ons, third party tools)
			if ( empty( $_where ) ) {
				$_where = '1=1';
			}
		}
		else {
			if ( $_where != '1=1' && !empty( self::$filters_normalized[ 'columns' ] ) ) {
				$new_clause = self::_get_sql_where( self::$filters_normalized[ 'columns' ], $_slim_stats_table_alias );

				// This condition could be empty if it's related to a custom column
				if ( !empty( $new_clause ) ) {
					$_where .= ' AND ' . $new_clause;
				}
			}
			if ( $_use_date_filters ) {
				$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
			}
		}

		if ( !empty( $_where ) && !empty( $time_range_condition ) ) {
			$_where = "$_where AND $time_range_condition";
		}
		else {
			$_where = trim( "$_where $time_range_condition" );
		}

		if ( !empty( $_column ) && !empty( self::$columns_names[ $_column ] ) ) {
			$column_with_alias = $_column;
			if ( !empty( $_slim_stats_table_alias ) ) {
				$column_with_alias = $_slim_stats_table_alias . '.' . $column_with_alias;
			}

			$filter_empty = "$column_with_alias " . ( ( self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NULL' : '= 0' );
			$filter_not_empty = "$column_with_alias " . ( ( self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NOT NULL' : '<> 0' );

			if ( strpos( $_where, $filter_empty ) === false && strpos( $_where, $filter_not_empty) === false) {
				$_where = "$filter_not_empty AND $_where";
			}
		}

		return $_where;
	}

	/**
	 * Translates user-friendly operators into SQL conditions
	 */
	public static function get_single_where_clause( $_dimension = 'id', $_operator = 'equals', $_value = '', $_slim_stats_table_alias = '' ) {
		$filter_empty = ( !empty( self::$columns_names[ $_dimension ] ) && self::$columns_names[ $_dimension ] [ 1 ] == 'varchar' ) ? 'IS NULL' : '= 0';
		$filter_not_empty = ( !empty( self::$columns_names[ $_dimension ] ) && self::$columns_names[ $_dimension ] [ 1 ] == 'varchar' ) ? 'IS NOT NULL' : '<> 0';

		$column_with_alias = $_dimension;
		if ( !empty( $_slim_stats_table_alias ) ) {
			$column_with_alias = $_slim_stats_table_alias . '.' . $_dimension;
		}

		switch( $_dimension ) {
			case 'ip':
			case 'other_ip':
				$filter_empty = '= "0.0.0.0"';
				break;
			default:
				break;
		}

		$where = array( '', htmlentities( $_value, ENT_QUOTES, 'UTF-8' ) );

		switch ( $_operator ) {
			case 'is_not_equal_to':
				$where[ 0 ] = "$column_with_alias <> %s";
				break;

			case 'contains':
				$where = array( "$column_with_alias LIKE %s", '%' . $_value . '%' );
				break;

			case 'includes_in_set':
			case 'included_in_set':
				$where[ 0 ] = "FIND_IN_SET( $column_with_alias, %s ) > 0";
				break;

			case 'does_not_contain':
				$where = array( "$column_with_alias NOT LIKE %s", '%' . $_value . '%' );
				break;

			case 'starts_with':
				$where = array( "$column_with_alias LIKE %s", $_value . '%' );
				break;

			case 'ends_with':
				$where = array( "$column_with_alias LIKE %s", '%' . $_value );
				break;

			case 'sounds_like':
				$where[ 0 ] = "SOUNDEX( $column_with_alias ) = SOUNDEX( %s )";
				break;

			case 'is_empty':
				$where = array( "$column_with_alias $filter_empty", '' );
				break;

			case 'is_not_empty':
				$where = array( "$column_with_alias $filter_not_empty", '' );
				break;

			case 'is_greater_than':
				$where[ 0 ] = "$column_with_alias > %d";
				break;

			case 'is_less_than':
				$where[ 0 ] = "$column_with_alias < %d";
				break;

			case 'between':
				$range = explode( ',', $_value );
				$where = array( "$column_with_alias BETWEEN %d AND %d", array( $range[ 0 ], $range[ 1 ] ) );
				break;

			case 'matches':
				$where[ 0 ] = "$column_with_alias REGEXP %s";
				break;

			case 'does_not_match':
				$where[ 0 ] = "$column_with_alias NOT REGEXP %s";
				break;

			default:
				$where[ 0 ] = "$column_with_alias = %s";
				break;
		}

		if ( isset( $where[ 1 ] ) && $where[ 1 ] != '' ) {
			return $GLOBALS[ 'wpdb' ]->prepare( $where[ 0 ], $where[ 1 ] );
		}
		else {
			return $where[ 0 ];
		}
	}

	public static function get_results( $_sql = '', $_select_no_aggregate_values = '', $_order_by = '', $_group_by = '', $_aggregate_values_add = '' ) {
		$_sql = apply_filters( 'slimstat_get_results_sql', $_sql, $_select_no_aggregate_values, $_order_by, $_group_by, $_aggregate_values_add );

		if ( wp_slimstat::$settings[ 'show_sql_debug' ] == 'on' ) {
			self::$debug_message .= "<p class='debug'>$_sql</p>";
		}

		$cached_results = wp_cache_get( md5( $_sql ), 'wp-slimstat' );

		// Save the results of this query in our object cache
		if ( empty( $cached_results ) ) {
			$cached_results = wp_slimstat::$wpdb->get_results( $_sql, ARRAY_A );
			wp_cache_add( md5( $_sql ), $cached_results, 'wp-slimstat' );
		}

		return $cached_results;
	}

	public static function get_var( $_sql = '', $_aggregate_value = '' ) {
		$_sql = apply_filters( 'slimstat_get_var_sql', $_sql, $_aggregate_value );

		if ( wp_slimstat::$settings[ 'show_sql_debug' ] == 'on' ) {
			self::$debug_message .= "<p class='debug'>$_sql</p>";
		}

		// Save the results of this query in our object cache
		if ( empty( wp_cache_get( md5( $_sql ), 'wp-slimstat' ) ) ) {
			wp_cache_add( md5( $_sql ), wp_slimstat::$wpdb->get_var( $_sql ), 'wp-slimstat' );
		}

		return wp_cache_get( md5( $_sql ), 'wp-slimstat' );
	}

	public static function parse_filters( $_filters_raw ) {
		$filters_parsed = array(
			'columns' => array(),
			'date' => array()
		);

		if ( !empty( $_filters_raw ) ) {
			$matches = explode( '&&&', $_filters_raw );

			foreach( $matches as $idx => $a_match ) {
				preg_match( '/([^\s]+)\s([^\s]+)\s(.+)?/', urldecode( $a_match ), $a_filter );

				if ( empty( $a_filter ) || ( ( !array_key_exists( $a_filter[ 1 ], self::$all_columns_names ) || strpos( $a_filter[ 1 ], 'no_filter' ) !== false ) && strpos( $a_filter[ 1 ], 'addon_' ) === false ) ) {
					continue;
				}

				switch( $a_filter[ 1 ] ) {
					case 'strtotime':
						$custom_date = strtotime( $a_filter[ 3 ], wp_slimstat::date_i18n( 'U' ) );

						$filters_parsed[ 'date' ][ 'minute' ] = intval( date( 'i', $custom_date ) );
						$filters_parsed[ 'date' ][ 'hour' ] = intval( date( 'H', $custom_date ) );
						$filters_parsed[ 'date' ][ 'day' ] = intval( date( 'j', $custom_date ) );
						$filters_parsed[ 'date' ][ 'month' ] = intval( date( 'n', $custom_date ) );
						$filters_parsed[ 'date' ][ 'year' ] = intval( date( 'Y', $custom_date ) );
						break;

					case 'minute':
					case 'hour':
					case 'day':
					case 'month':
					case 'year':
						if ( is_numeric( $a_filter[ 3 ] ) ) {
							$filters_parsed[ 'date' ][ $a_filter[ 1 ] ] = intval( $a_filter[ 3 ] );
						}
						else{
							// Try to apply strtotime to value
							self::toggle_date_i18n_filters( false );
							switch( $a_filter[ 1 ] ) {
								case 'minute':
									$filters_parsed[ 'date' ][ 'minute' ] = intval( date( 'i', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'hour':
									$filters_parsed[ 'date' ][ 'hour' ] = intval( date( 'H', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'day':
									$filters_parsed[ 'date' ][ 'day' ] = intval( date( 'j', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'month':
									$filters_parsed[ 'date' ][ 'month' ] = intval( date( 'n', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'year':
									$filters_parsed[ 'date' ][ 'year' ] = intval( date( 'Y', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								default:
									break;
							}
							self::toggle_date_i18n_filters( true );

							if ( $filters_parsed[ 'date' ][ $a_filter[ 1 ] ] === false ) {
								unset( $filters_parsed[ 'date' ][ $a_filter[ 1 ] ] );
							}
						}
						break;

					case 'interval':
					case 'interval_hours':
					case 'interval_minutes':
						$filters_parsed[ 'date' ][ $a_filter[ 1 ] ] = intval( $a_filter[ 3 ] );
						break;

					case 'limit_results':
					case 'start_from':
						$filters_parsed[ 'misc' ][ $a_filter[ 1 ] ] = str_replace( '\\', '', htmlspecialchars_decode( $a_filter[ 3 ] ) );
						break;

					case 'content_id':
						if ( !empty( $a_filter[ 3 ] ) ) {
							$content_id = ( $a_filter[ 3 ] == 'current' && !empty( $GLOBALS[ 'post' ]->ID ) ) ? $GLOBALS[ 'post' ]->ID : $a_filter[ 3 ];
							$filters_parsed[ 'columns' ][ $a_filter[ 1 ] ] = array( $a_filter[ 2 ], $content_id );
							break;
						}
						// no break here: if value IS numeric, go to the default parser here below

					default:
						$filters_parsed[ 'columns' ][ $a_filter[ 1 ] ] = array( $a_filter[ 2 ], isset( $a_filter[ 3 ] ) ? str_replace( '\\', '', htmlspecialchars_decode( $a_filter[ 3 ] ) ) : '' );
						break;
				}
			}
		}

		return $filters_parsed;
	}

	public static function init_filters( $_filters_raw = '' ) {
		$fn = self::parse_filters( $_filters_raw );

		// Initialize default values
		if ( empty( $fn[ 'misc' ][ 'limit_results' ] ) ) {
			$fn[ 'misc' ][ 'limit_results' ] = wp_slimstat::$settings[ 'limit_results' ];
		}
		if ( empty( $fn[ 'misc' ][ 'start_from' ] ) ) {
			$fn[ 'misc' ][ 'start_from' ] = 0;
		}

		$fn[ 'utime' ] = array(
			'start' => 0,
			'end' => 0
		);

		// Normalize the various date values
		wp_slimstat::toggle_date_i18n_filters( false );

		// Intervals
		// If neither an interval nor interval_hours were specified...
		if ( !isset( $fn[ 'date' ][ 'interval_minutes' ] ) && !isset( $fn[ 'date' ][ 'interval_hours' ] ) && !isset( $fn[ 'date' ][ 'interval' ] ) ) {
			$fn[ 'date' ][ 'interval_minutes' ] = 0;
			$fn[ 'date' ][ 'interval_hours' ] = 0;

			// If a day has been specified, then interval = 1 (show only that day)
			if ( !empty( $fn[ 'date' ][ 'day' ] ) ) {
				$fn[ 'date' ][ 'interval' ] = -1;
			}
			// Show last X days, if the corresponding setting is enabled
			else if ( empty( wp_slimstat::$settings[ 'use_current_month_timespan' ] ) || wp_slimstat::$settings[ 'use_current_month_timespan' ] != 'on' ) {
				$fn[ 'date' ][ 'interval' ] = - abs( wp_slimstat::$settings[ 'posts_column_day_interval' ] );
			}
			// Otherwise, the interval is the number of days from the beginning of the month (current month view)
			else {
				$fn[ 'date' ][ 'interval' ] = - intval( date_i18n( 'j' ) );
			}
		}
		else {
			if ( empty( $fn[ 'date' ][ 'interval_minutes' ] ) ) {
				// interval was set, but not interval_hours
				$fn[ 'date' ][ 'interval_minutes' ] = 0;
			}
			if ( empty( $fn[ 'date' ][ 'interval_hours' ] ) ) {
				// interval_hours was set, but not interval
				$fn[ 'date' ][ 'interval_hours' ] = 0;
			}
			if ( empty( $fn[ 'date' ][ 'interval' ] ) ) {
				// interval_hours was set, but not interval
				$fn[ 'date' ][ 'interval' ] = 0;
			}
		}

		$fn[ 'utime' ][ 'range' ] = $fn[ 'date' ][ 'interval' ] * 86400 + $fn[ 'date' ][ 'interval_hours' ] * 3600 + $fn[ 'date' ][ 'interval_minutes' ] * 60;

		// Day
		if ( empty( $fn[ 'date' ][ 'day' ] ) ) {
			$fn[ 'date' ][ 'day' ] = intval( date_i18n( 'j' ) );
		}

		// Month
		if ( empty( $fn[ 'date' ][ 'month' ] ) ) {
			$fn[ 'date' ][ 'month' ] = intval( date_i18n( 'n' ) );
		}

		// Year
		if ( empty( $fn[ 'date' ][ 'year' ] ) ) {
			$fn[ 'date' ][ 'year' ] = intval( date_i18n( 'Y' ) );
		}

		if ( $fn[ 'utime' ][ 'range' ] < 0 ) {
			$fn[ 'utime' ][ 'end' ] = mktime(
				!empty( $fn[ 'date' ][ 'hour' ] ) ? $fn[ 'date' ][ 'hour' ] : 23,
				!empty( $fn[ 'date' ][ 'minute' ] ) ? $fn[ 'date' ][ 'minute' ] : 59,
				59,
				$fn[ 'date' ][ 'month' ],
				$fn[ 'date' ][ 'day' ],
				$fn[ 'date' ][ 'year' ]
			);

			// If end is in the future and the level of granularity is hours, set it to now
			if ( !empty( $fn[ 'date' ][ 'interval_hours' ] ) && $fn[ 'utime' ][ 'end' ] > date_i18n( 'U' ) ) {
				$fn[ 'utime' ][ 'end' ] = intval( date_i18n( 'U' ) );
			}

			$fn[ 'utime' ][ 'range' ] = $fn[ 'utime' ][ 'range' ] + 1;
			$fn[ 'utime' ][ 'start' ] = $fn[ 'utime' ][ 'end' ] + $fn[ 'utime' ][ 'range' ];

			// Store the absolute value for later (chart)
			$fn[ 'utime' ][ 'range' ] = - $fn[ 'utime' ][ 'range' ];
		}
		else {
			$fn[ 'utime' ][ 'start' ] = mktime(
				!empty( $fn[ 'date' ][ 'hour' ] ) ? $fn[ 'date' ][ 'hour' ] : 0,
				!empty( $fn[ 'date' ][ 'minute' ] ) ? $fn[ 'date' ][ 'minute' ] : 0,
				0,
				$fn[ 'date' ][ 'month' ],
				$fn[ 'date' ][ 'day' ],
				$fn[ 'date' ][ 'year' ]
			);

			$fn[ 'utime' ][ 'range' ] = $fn[ 'utime' ][ 'range' ] - 1;
			$fn[ 'utime' ][ 'end' ] = $fn[ 'utime' ][ 'start' ] + $fn[ 'utime' ][ 'range' ];
		}

		// If end is in the future, set it to now
		if ( $fn[ 'utime' ][ 'end' ] > date_i18n( 'U' ) ) {
			$fn[ 'utime' ][ 'end' ] = intval( date_i18n( 'U' ) );
		}

		// Turn the date_i18n filters back on
		wp_slimstat::toggle_date_i18n_filters( true );

		// Apply third-party filters
		$fn = apply_filters( 'slimstat_db_filters_normalized', $fn, $_filters_raw );

		return $fn;
	}

	// The following methods retrieve the information from the database

	public static function count_bouncing_pages() {
		$where = self::get_combined_where( 'visit_id > 0 AND content_type <> "404"', 'resource' );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource, visit_id
					FROM {$GLOBALS['wpdb']->prefix}slim_stats
					WHERE $where
					GROUP BY resource
					HAVING COUNT(visit_id) = 1
				) as ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_exit_pages() {
		$where = self::get_combined_where( 'visit_id > 0', 'resource' );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource, dt
					FROM {$GLOBALS['wpdb']->prefix}slim_stats
					WHERE $where
					GROUP BY resource
					HAVING dt = MAX(dt)
				) AS ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_records( $_column = 'id', $_where = '', $_use_date_filters = true ) {
		$distinct_column = ( $_column != 'id' ) ? "DISTINCT $_column" : $_column;
		$_where = self::get_combined_where( $_where, $_column, $_use_date_filters );

		return intval( self::get_var( "
			SELECT COUNT($distinct_column) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_records_having( $_column = 'id', $_where = '', $_having = '' ) {
		$distinct_column = ( $_column != 'id' ) ? "DISTINCT $_column" : $_column;
		$_where = self::get_combined_where( $_where, $_column );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits FROM (
				SELECT $distinct_column
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $_where
				GROUP BY $_column
				HAVING $_having
			) AS ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function get_data_for_chart( $_args = array() ) {
		// Determine the chart granularity based on the date range
		// - Up to 24 hours (86400 seconds): HOURLY
		// - Up to 120 days (10368000 seconds): DAILY
		// - Otherwise: MONTHLY
		$params = array();

		if ( self::$filters_normalized[ 'utime' ][ 'range' ] < 86400 ) {
			$params[ 'group_by' ] = "DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), HOUR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = ( strpos( number_format_i18n( 1000 ), '.' ) === false ) ? 'h a' : 'H';
			$params[ 'data_points_count' ] = ceil( self::$filters_normalized[ 'utime' ][ 'range' ] / 3600 );
			$params[ 'granularity' ] = 'HOUR';
		}
		else if ( self::$filters_normalized[ 'utime' ][ 'range' ] < 10368000 ) {
			$params[ 'group_by' ] = "MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = ( strpos( number_format_i18n( 1000 ), '.' ) === false ) ? 'm/d' : 'd/m';
			$params[ 'data_points_count' ] = ceil( self::$filters_normalized[ 'utime' ][ 'range' ] / 86400 );
			$params[ 'granularity' ] = 'DAY';
		}
		else {
			$params[ 'group_by' ] = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = 'm/y';
			$params[ 'data_points_count' ] = self::count_months_between( self::$filters_normalized[ 'utime' ][ 'start' ], self::$filters_normalized[ 'utime' ][ 'end' ] );
			$params[ 'granularity' ] = 'MONTH';
		}

		// Calculate the "previous/comparison" time range
		$params[ 'previous_end' ] = self::$filters_normalized[ 'utime' ][ 'start' ] - 1;
		$params[ 'previous_start' ] = $params[ 'previous_end' ] - self::$filters_normalized[ 'utime' ][ 'range' ];

		// Build the SQL query
		if ( empty( $_args[ 'where' ] ) ) {
			$_args[ 'where' ] = '';
		}

		$sql = "
			SELECT MIN(dt) AS dt, {$_args[ 'data1' ]} AS v1, {$_args[ 'data2' ]} AS v2
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE " . self::get_combined_where( $_args[ 'where' ], '*', false ) . " AND (dt BETWEEN {$params[ 'previous_start' ]} AND {$params[ 'previous_end' ]} OR dt BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ] . ")
			GROUP BY {$params[ 'group_by' ]}";

		// Get the data
		$results = self::get_results(
			$sql,
			'dt',
			'',
			$params[ 'group_by' ], 'SUM(v1) AS v1, SUM(v2) AS v2'
		);

		$output = array(
			'keys' => array()
		);

		// No data? No problem!
		if ( !is_array( $results ) || empty( $results ) ) {
			return $output;
		}

		// Generate the output array (sent to the chart library) by combining all the data collected so far
		
		// Let's start by initializing all the data points to zero
		for ( $i = 0; $i < $params[ 'data_points_count' ]; $i++ ) {
			$v1_label = date( $params[ 'data_points_label' ], strtotime( "+$i {$params[ 'granularity' ]}", self::$filters_normalized[ 'utime' ][ 'start' ] ) );
			$v3_label = date( $params[ 'data_points_label' ], strtotime( "+$i {$params[ 'granularity' ]}", $params[ 'previous_start' ] ) );

			$output[ 'keys' ][ $v1_label ] = $i;
			$output[ 'keys' ][ $v3_label ] = $i;

			// This is how AmCharts expects the data to be formatted
			$output[ $i ][ 'v1_label' ] = $v1_label;
			$output[ $i ][ 'v3_label' ] = $v3_label;
			$output[ $i ][ 'v4' ] = $output[ $i ][ 'v3' ] = $output[ $i ][ 'v2' ] = $output[ $i ][ 'v1' ] = 0;
		}

		// Now populate all the data points
		foreach ( $results as $a_result ) {
			$label = date( $params[ 'data_points_label' ], $a_result[ 'dt' ] );

			// Data out of range?
			if ( !isset( $output[ 'keys' ][ $label ] ) ) {
				continue;
			}

			// Does this value belong to the "current" range?
			if ( $a_result[ 'dt' ] >= self::$filters_normalized[ 'utime' ][ 'start' ] && $a_result[ 'dt' ] <= self::$filters_normalized[ 'utime' ][ 'end' ] ) {
				$output[ $output[ 'keys' ][ $label ] ][ 'v1' ] = intval( $a_result[ 'v1' ] );
				$output[ $output[ 'keys' ][ $label ] ][ 'v2' ] = intval( $a_result[ 'v2' ] );
			}
			else {
				$output[ $output[ 'keys' ][ $label ] ][ 'v3' ] = intval( $a_result[ 'v1' ] );
				$output[ $output[ 'keys' ][ $label ] ][ 'v4' ] = intval( $a_result[ 'v2' ] );
			}
		}

		return $output;
	}

	public static function get_data_size() {
		$suffix = 'KB';

		$sql = 'SHOW TABLE STATUS LIKE "'.$GLOBALS[ 'wpdb' ]->prefix.'slim_stats"';
		$table_details = wp_slimstat::$wpdb->get_row( $sql, 'ARRAY_A', 0 );

		$table_size = ( $table_details[ 'Data_length' ] / 1024 ) + ( $table_details[ 'Index_length' ] / 1024 );

		if ( $table_size > 1024 ) {
			$table_size /= 1024;
			$suffix = 'MB';
		}
		return number_format_i18n( $table_size, 2 ) . ' ' . $suffix;
	}

	public static function get_group_by( $_args = array() ) {
		$where = self::get_combined_where();

		if ( empty( $_args[ 'column_group' ] ) ) {
			 $_args[ 'column_group' ] = 'id';
		}

		if ( empty( $_args[ 'group_by' ] ) ) {
			 $_args[ 'group_by' ] = 'id';
		}

		return self::get_results( "
			SELECT {$_args[ 'group_by' ]}, COUNT(*) AS counthits, GROUP_CONCAT( DISTINCT {$_args[ 'column_group' ]} SEPARATOR ';;;' ) as column_group
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $where AND {$_args[ 'group_by' ]} IS NOT NULL
			GROUP BY {$_args[ 'group_by' ]}
			ORDER BY counthits DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			$_args[ 'group_by' ],
			$_args[ 'group_by' ] . ' ASC' );
	}

	public static function get_max_and_average_pages_per_visit() {
		$where = self::get_combined_where( 'visit_id > 0' );

		return self::get_results( "
			SELECT AVG(ts1.counthits) AS avghits, MAX(ts1.counthits) AS maxhits FROM (
				SELECT count(ip) counthits, visit_id
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $where
				GROUP BY visit_id
			) AS ts1",
			'blog_id',
			'',
			'',
			'AVG(avghits) AS avghits, MAX(maxhits) AS maxhits' );
	}

	public static function get_oldest_visit() {
		return self::get_var( "
			SELECT dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			ORDER BY dt ASC
			LIMIT 0, 1",
			'MIN(dt)' );
	}

	public static function get_overview_summary() {
		$days_in_range = ceil( ( wp_slimstat_db::$filters_normalized[ 'utime' ][ 'end' ] - wp_slimstat_db::$filters_normalized[ 'utime' ][ 'start' ] ) / 86400 );
		$results = array();
		
		// Turn date_i18n filters off
		wp_slimstat::toggle_date_i18n_filters( false );

		$results[ 0 ][ 'metric' ] = __( 'Pageviews', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format_i18n( self::$pageviews, 0 );
		$results[ 0 ][ 'tooltip' ] = __( 'A pageview is a request to load a single HTML page on your website.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Days in Range', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = $days_in_range;

		$results[ 2 ][ 'metric' ] = __( 'Average Daily Pageviews', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format_i18n( round( self::$pageviews / $days_in_range, 0 ) );
		$results[ 2 ][ 'tooltip' ] = __( 'How many daily pageviews have been generated on average.', 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'From Any SERP', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'searchterms IS NOT NULL' ) );
		$results[ 3 ][ 'tooltip' ] = __( 'Visitors who landed on your site after searching for a keyword on a search engine and clicking on the corresponding search result link. This value includes both internal and external search result pages.', 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Unique IPs', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'ip' ) );
		$results[ 4 ][ 'tooltip' ] = __( 'Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses.', 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Last 30 minutes', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'dt > ' . ( date_i18n( 'U' ) - 1800 ), false ) );

		$results[ 6 ][ 'metric' ] = __( 'Today', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'dt > ' . ( date_i18n( 'U', mktime( 0, 0, 0, date_i18n( 'm' ), date_i18n( 'd' ), date_i18n( 'Y' ) ) ) ), false ) );

		$results[ 7 ][ 'metric' ] = __( 'Yesterday', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'dt BETWEEN ' . ( date_i18n( 'U', mktime( 0, 0, 0, date_i18n( 'm' ), date_i18n( 'd' ) - 1, date_i18n( 'Y' ) ) ) ) . ' AND ' . ( date_i18n( 'U', mktime( 23, 59, 59, date_i18n( 'm' ), date_i18n( 'd' ) - 1, date_i18n( 'Y' ) ) ) ), false ) );

		// Turn date_i18n filters back on
		wp_slimstat::toggle_date_i18n_filters( true );

		return $results;
	}

	public static function get_recent( $_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '', $_more_columns = '', $_order_by = 'dt DESC' ) {
		// This function can be passed individual arguments, or an array of arguments
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = isset( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_more_columns = !empty( $_column[ 'more_columns' ] ) ? $_column[ 'more_columns' ] : '';
			$_order_by = !empty( $_column[ 'order_by' ] ) ? $_column[ 'order_by' ] : 'dt DESC';
			$_column = $_column[ 'columns' ];
		}

		$columns = $_column;
		if ( !empty( $_as_column ) ) {
			$columns = "$_column AS $_as_column";
		}

		// Add the IP column, used to display details about that visit
		if ( $_column != 'ip' ) {
			$columns .= ', ip';
		}

		if ( $_column != '*' ) {
			$columns .= ', dt';
			$group_by = 'GROUP BY ' . $_column;
		}
		else {
			$columns = 'id, ip, other_ip, username, email, country, city, location, referer, resource, searchterms, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, fingerprint, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, outbound_resource, tz_offset, dt_out, dt';
			$group_by = '';
		}

		if ( !empty( $_more_columns ) ) {
			$columns .= ', ' . $_more_columns;
		}

		$_where = self::get_combined_where( $_where, $_column, $_use_date_filters );

		return self::get_results( "
			SELECT $columns
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where
			$group_by
			ORDER BY $_order_by
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			$columns,
			'dt DESC' );
	}

	public static function get_recent_events() {
		return self::get_results( "
			SELECT te.*, t1.resource
			FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events te INNER JOIN {$GLOBALS[ 'wpdb' ]->prefix}slim_stats t1 ON te.id = t1.id
			WHERE " . wp_slimstat_db::get_combined_where( 'te.notes NOT LIKE "_ype:click%"', 'te.notes', true, 't1' ) . "
			ORDER BY te.dt DESC",
			'te.*, t1.resource',
			'dt DESC'
		);
	}

	public static function get_recent_outbound() {
		$mixed_outbound_resources = self::get_recent( 'outbound_resource' );
		$clean_outbound_resources = array();

		foreach ( $mixed_outbound_resources as $a_mixed_resource ) {
			$exploded_resources = explode( ';;;', $a_mixed_resource[ 'outbound_resource' ] );
			foreach ( $exploded_resources as $a_exploded_resource ) {
				$a_mixed_resource[ 'outbound_resource' ] = $a_exploded_resource;
				$clean_outbound_resources[] = $a_mixed_resource;
			}
		}

		return $clean_outbound_resources;
	}

	public static function get_top( $_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '' ) {
		// This function can be passed individual arguments, or an array of arguments
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = !empty( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_column = $_column[ 'columns' ];
		}

		$group_by_column = $_column;
		
		if ( !empty( $_as_column ) ) {
			$_column = "$_column AS $_as_column";
		}
		else {
			$_as_column = $_column;
		}

		$_where = self::get_combined_where( $_where, $_as_column, $_use_date_filters );

		return self::get_results( "
			SELECT $_column, COUNT(*) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where
			GROUP BY $group_by_column $_having
			ORDER BY counthits DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			( ( !empty( $_as_column ) && $_as_column != $_column ) ? $_as_column : $_column ),
			'counthits DESC',
			( ( !empty( $_as_column ) && $_as_column != $_column ) ? $_as_column : $_column ),
			'SUM(counthits) AS counthits' );
	}

	public static function get_top_aggr( $_column = 'id', $_where = '', $_outer_select_column = '', $_aggr_function = 'MAX' ) {
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = !empty( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_outer_select_column = !empty( $_column[ 'outer_select_column' ] ) ? $_column[ 'outer_select_column' ] : '';
			$_aggr_function = !empty( $_column[ 'aggr_function' ] ) ? $_column[ 'aggr_function' ] : '';
			$_column = $_column[ 'columns' ];
		}

		if ( !empty( $_as_column ) ) {
			$_column = "$_column AS $_as_column";
		}
		else {
			$_as_column = $_column;
		}

		$_where = self::get_combined_where( $_where, $_column );

		return self::get_results( "
			SELECT $_outer_select_column, ts1.aggrid as $_column, COUNT(*) counthits
			FROM (
				SELECT $_column, $_aggr_function(id) aggrid
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $_where
				GROUP BY $_column
			) AS ts1 JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.aggrid = t1.id
			GROUP BY $_outer_select_column
			ORDER BY counthits DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			$_outer_select_column,
			'counthits DESC',
			$_outer_select_column,
			"$_aggr_function(aggrid), SUM(counthits)" );
	}

	public static function get_top_events() {
		if ( empty( self::$filters_normalized[ 'columns' ] ) ) {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te";
			$where = wp_slimstat_db::get_combined_where( 'notes NOT LIKE "type:click%"', 'notes' );
		}
		else {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te INNER JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON te.id = t1.id";
			$where = wp_slimstat_db::get_combined_where( 'notes NOT LIKE "_ype:click%"', 'notes', true, 't1' );
		}

		return self::get_results( "
			SELECT te.notes, COUNT(*) counthits
			FROM $from
			WHERE $where
			GROUP BY te.notes
			ORDER BY counthits DESC",
			'notes',
			'counthits DESC',
			'notes',
			'SUM(counthits) AS counthits'
		);
	}

	public static function get_top_outbound() {
		$mixed_outbound_resources = self::get_recent( 'outbound_resource' );
		$clean_outbound_resources = array();

		foreach ( $mixed_outbound_resources as $a_mixed_resource ) {
			$exploded_resources = explode( ';;;', $a_mixed_resource[ 'outbound_resource' ] );
			foreach ( $exploded_resources as $a_exploded_resource ) {
				$clean_outbound_resources[] = $a_exploded_resource;
			}
		}

		$clean_outbound_resources = array_count_values( $clean_outbound_resources );
		arsort( $clean_outbound_resources );

		$sorted_outbound_resources = array();
		foreach ( $clean_outbound_resources as $a_resource => $a_count ) {
			$sorted_outbound_resources[] = array(
				'outbound_resource' => $a_resource,
				'counthits' => $a_count
			);
		}

		return $sorted_outbound_resources;
	}

	public static function get_traffic_sources_summary() {
		$results = array();
		$total_human_hits = wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1' );
		$new_visitors = wp_slimstat_db::count_records_having( 'ip', 'visit_id > 0', 'COUNT(visit_id) = 1' );
		$new_visitors_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) {
			$new_visitors_rate = '100';
		}

		$results[ 0 ][ 'metric' ] = __( 'Pageviews', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format_i18n( self::$pageviews );
		$results[ 0 ][ 'tooltip' ] = __( 'A pageview is a request to load a single HTML page on your website.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Unique Referrers', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'referer', "referer NOT LIKE '%{$_SERVER['SERVER_NAME']}%'" ) );
		$results[ 1 ][ 'tooltip' ] = __( 'A referrer (or referring site) is a site that a visitor previously visited before following a link to your site.', 'wp-slimstat' );

		$results[ 2 ][ 'metric' ] = __( 'Direct Pageviews', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'resource IS NULL' ) );
		$results[ 2 ][ 'tooltip' ] = __( "Visitors who typed your website URL directly into their browser address bar. It can also refer to visitors who clicked on one of their bookmarked links, untagged links within emails, or links in documents that don't include tracking variables.", 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'From External SERP', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', "searchterms IS NOT NULL AND referer IS NOT NULL AND referer NOT LIKE '%" . home_url() . "%'" ) );
		$results[ 3 ][ 'tooltip' ] = __( "Visitors who clicked on a link to your website listed on a search engine result page (SERP). This metric only counts visits coming from EXTERNAL search pages.", 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Unique Landing Pages', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'resource' ) );
		$results[ 4 ][ 'tooltip' ] = __( "A landing page is the first page on your website that a visitors opens, also known as <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on a page on your website, this page gets counted (for that visit) as a landing page.", 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Bounce Pages', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_bouncing_pages() );
		$results[ 5 ][ 'tooltip' ] = __( 'Number of single-page visits tracked over the selected period of time.', 'wp-slimstat' );

		$results[ 6 ][ 'metric' ] = __( 'New Visitors Rate', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format_i18n( $new_visitors_rate, 2 );
		$results[ 6 ][ 'tooltip' ] = __( 'Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.', 'wp-slimstat' );

		$results[ 7 ][ 'metric' ] = __( 'Currently from search engines', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', "searchterms IS NOT NULL  AND referer IS NOT NULL AND referer NOT LIKE '%" . home_url() . "%' AND dt > UNIX_TIMESTAMP() - 300", false ) );
		$results[ 7 ][ 'tooltip' ] = __( 'Visitors who clicked on a link to your website listed on a search engine result page (SERP), tracked in the last 5 minutes.', 'wp-slimstat' );

		return $results;
	}

	public static function get_visits_duration() {
		$total_human_visits = wp_slimstat_db::count_records( 'visit_id', 'visit_id > 0 AND browser_type <> 1' );
		$results = array();

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', '	GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) >= 0 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 30' );
		$average_time = 30 * $count_results;
		$results[ 0 ][ 'metric' ] = __( '0 - 30 seconds', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 0 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 30 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 60' );
		$average_time += 60 * $count_results;
		$results[ 1 ][ 'metric' ] = __( '31 - 60 seconds', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 1 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 60 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 180' );
		$average_time += 180 * $count_results;
		$results[ 2 ][ 'metric' ] = __( '1 - 3 minutes', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 2 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 180 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 300' );
		$average_time += 300 * $count_results;
		$results[ 3 ][ 'metric' ] = __( '3 - 5 minutes', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 3 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 300 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 420' );
		$average_time += 420 * $count_results;
		$results[ 4 ][ 'metric' ] = __( '5 - 7 minutes', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 4 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 420 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 600' );
		$average_time += 600* $count_results;
		$results[ 5 ][ 'metric' ] = __( '7 - 10 minutes', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 5 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 600' );
		$average_time += 900 * $count_results;
		$results[ 6 ][ 'metric' ] = __( 'More than 10 minutes', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format_i18n( ( 100 * $count_results / $total_human_visits ), 2 ) : 0 ) . '%';
		$results[ 6 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		if ( $total_human_visits > 0 ) {
			$average_time /= $total_human_visits;
			$average_time = date('m:s', intval($average_time));
		}
		else{
			$average_time = '0:00';
		}

		$results[ 7 ][ 'metric' ] = __( 'Average Visit Duration', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = $average_time;
		$results[ 7 ][ 'details' ] = '';

		return $results;
	}

	public static function get_visitors_summary() {
		$results = array();
		$total_visits = wp_slimstat_db::count_records( 'visit_id', 'browser_type <> 1');
		$single_page_visits = wp_slimstat_db::count_records_having( 'visit_id', 'browser_type <> 1', 'COUNT(id) = 1' );

		$bounce_rate = ( $total_visits > 0) ? ( 100 * $single_page_visits / $total_visits ) : 0;
		$metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit();
		if ( empty( $metrics_per_visit[ 0 ] ) ) {
			 $metrics_per_visit[ 0 ] = array( 'avghits' => 0, 'maxhits' => 0);
		}
		if ( intval( $bounce_rate ) > 99 ) {
			$bounce_rate = '100';
		}

		$results[ 0 ][ 'metric' ] = __( 'Visits', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'visit_id', 'visit_id > 0 AND browser_type <> 1' ) );
		$results[ 0 ][ 'tooltip' ] = __( 'A visit is a group of pageviews within a 30-minute time span. Returning visitors are counted multiple times if they start a new visit.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Unique IPs', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'ip', 'visit_id > 0 AND browser_type <> 1' ) );
		$results[ 1 ][ 'tooltip' ] = __( 'It includes only traffic generated by human visitors.', 'wp-slimstat' );

		$results[ 2 ][ 'metric' ] = __( 'Bounce rate', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format_i18n( $bounce_rate, 2 );
		$results[ 2 ][ 'tooltip' ] = __( 'Total number of one-page visits divided by the total number of entries to a website. Please see the <a href="https://support.google.com/analytics/answer/1009409?hl=en" target="_blank">official Google docs</a> for more information.', 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'Known visitors', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'username' ) );
		$results[ 3 ][ 'tooltip' ] = __( 'Visitors who have previously left a comment on your blog.', 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Single-page Visits', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format_i18n( $single_page_visits );
		$results[ 4 ][ 'tooltip' ] = __( 'Human users that generated one single page view on your website.', 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Bots', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format_i18n( wp_slimstat_db::count_records( 'id', 'browser_type = 1' ) );

		$results[ 6 ][ 'metric' ] = __( 'Pageviews per visit', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format_i18n( $metrics_per_visit[ 0 ][ 'avghits' ], 2 );

		$results[ 7 ][ 'metric' ] = __( 'Longest visit', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format_i18n( $metrics_per_visit[ 0 ][ 'maxhits' ] ) . ' ' . __( 'hits', 'wp-slimstat' );

		return $results;
	}

	public static function get_your_blog(){
		if ( false === ( $results = get_transient( 'slimstat_your_content' ) ) ) {
			$results = array();

			$results[ 0 ][ 'metric' ] = __( 'Content Items', 'wp-slimstat' );
			$results[ 0 ][ 'value' ] = number_format_i18n( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft'" ) );
			$results[ 0 ][ 'tooltip' ] = __( 'This value includes not only posts and pages, but any custom post type, regardless of their status.', 'wp-slimstat' );

			$results[ 1 ][ 'metric' ] = __( 'Posts', 'wp-slimstat' );
			$results[ 1 ][ 'value' ] = $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'post'" );

			$results[ 2 ][ 'metric' ] = __( 'Pages', 'wp-slimstat' );
			$results[ 2 ][ 'value' ] = number_format_i18n( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'page'" ) );

			$results[ 3 ][ 'metric' ] = __( 'Attachments', 'wp-slimstat' );
			$results[ 3 ][ 'value' ] = number_format_i18n( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ) );

			$results[ 4 ][ 'metric' ] = __( 'Revisions', 'wp-slimstat' );
			$results[ 4 ][ 'value' ] = number_format_i18n( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'revision'" ) );

			$results[ 5 ][ 'metric' ] = __( 'Comments', 'wp-slimstat' );
			$results[ 5 ][ 'value' ] = $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT( * ) FROM {$GLOBALS[ 'wpdb' ]->comments}" );

			$results[ 6 ][ 'metric' ] = __( 'Avg Comments per Post', 'wp-slimstat' );
			$results[ 6 ][ 'value' ] = !empty( $results[ 1 ][ 'value' ] ) ? number_format_i18n( $results[ 5 ][ 'value' ] / $results[ 1 ][ 'value' ] ) : 0;

			$results[ 7 ][ 'metric' ] = __( 'Avg Server Latency', 'wp-slimstat' );
			$results[ 7 ][ 'value' ] = number_format_i18n( wp_slimstat::$wpdb->get_var( "SELECT AVG(server_latency) FROM {$GLOBALS[ 'wpdb' ]->prefix }slim_stats WHERE server_latency <> 0" ) );
			$results[ 7 ][ 'tooltip' ] = __( 'Latency is the amount of time it takes for the host server to receive and process a request for a page object. The amount of latency depends largely on how far away the user is from the server.', 'wp-slimstat' );

			$results[ 1 ][ 'value' ] = number_format_i18n( $results[ 1 ][ 'value' ] );
			$results[ 5 ][ 'value' ] = number_format_i18n( $results[ 5 ][ 'value' ] );

			// Store values as transients for 30 minutes
			set_transient( 'slimstat_your_content', $results, 1800 );
		}

		return $results;
	}

	protected static function count_months_between( $min_timestamp = 0, $max_timestamp = 0 ) {
		$i = 0;
		$min_month = date( 'Ym', $min_timestamp );
		$max_month = date( 'Ym', $max_timestamp );

		while ( $min_month <= $max_month ) {
			$min_timestamp = strtotime( "+1 month", $min_timestamp );
			$min_month = date( 'Ym', $min_timestamp );
			$i++;
		}

		return $i;
	}
}